<?php

declare(strict_types=1);

namespace Harness\Provider\Anthropic;

use Harness\Delta;
use Harness\Delta\Finish;
use Harness\Delta\ReasoningDelta;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\ErrorKind;
use Harness\FinishReason;
use Harness\Http\Sse;
use Harness\Http\Transport;
use Harness\Message;
use Harness\Model;
use Harness\ModelException;
use Harness\Part\Media;
use Harness\Part\Reasoning;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Role;
use Harness\ToolChoice;
use Harness\ToolSpec;
use Harness\UnsupportedContentException;
use Harness\UnsupportedOptionException;
use Harness\Usage;

/**
 * Anthropic's Messages API, streamed. `thinking` and `redacted_thinking` blocks become Reasoning
 * parts whose `raw['blocks']` holds the blocks verbatim; they are replayed unchanged to the same
 * model and dropped for another. Consecutive user messages are merged, tool results first, as the
 * API requires. `maxTokens` is used when `Options::$maxOutputTokens` is null (the API requires one).
 * `Options::$provider['anthropic']` is merged into the request body last: `thinking`,
 * `cache_control`, `output_config` and the rest go there.
 *
 * A `Prompt::$continue` is sent as a prefill: the assistant message goes last, without its
 * reasoning and with the trailing whitespace of its text cut (the API refuses both), and the
 * model's reply continues it. With thinking on the API takes no prefill, and with a tool call
 * last there is nothing to continue: both throw.
 */
final readonly class Messages implements Model
{
    /** @param array<string, string> $headers extra request headers (e.g. `anthropic-beta`) */
    public function __construct(
        private Transport $transport,
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.anthropic.com/v1',
        private int $maxTokens = 16000,
        private array $headers = [],
    ) {
    }

    public function stream(Prompt $prompt): iterable
    {
        $headers = [
            'Content-Type'      => 'application/json',
            'Accept'            => 'text/event-stream',
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ] + $this->headers;
        $body     = \json_encode($this->request($prompt), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response = $this->transport->post(\rtrim($this->baseUrl, '/') . '/messages', $headers, $body, $prompt->cancel);
        if (200 !== $response->status || !Sse::isEventStream($response)) {
            $raw   = \implode('', [...$response->body()]);
            $error = \json_decode($raw, true)['error'] ?? ['type' => 'http_' . $response->status, 'message' => \substr($raw, 0, 2000)];
            throw self::error($error, $response->status, $response->headers['retry-after'] ?? null);
        }

        /** @var array<int, array{type: string, id?: string, thinking?: string, signature?: string}> $blocks */
        $blocks    = [];
        $reasoning = [];
        $usage     = new Usage();
        $stop      = null;
        $called    = false;
        foreach (Sse::parse($response->body()) as $event => $data) {
            $e = self::json($data);
            switch ($event) {
                case 'message_start':
                    $usage = self::usage($e['message']['usage'] ?? [], $usage);
                    yield $usage;
                    break;
                case 'content_block_start':
                    $block                = $e['content_block'];
                    $blocks[$e['index']]  = ['type' => $block['type']];
                    if ('thinking' !== $block['type'] && 'redacted_thinking' !== $block['type']) {
                        $reasoning = [];
                    }
                    if ('tool_use' === $block['type']) {
                        $blocks[$e['index']]['id'] = $block['id'];
                        $called                    = true;
                        yield new ToolCallStart($block['id'], $block['name']);
                    } elseif ('thinking' === $block['type']) {
                        $blocks[$e['index']] += ['thinking' => '', 'signature' => ''];
                    } elseif ('redacted_thinking' === $block['type']) {
                        $reasoning[] = $block;
                        yield new ReasoningDelta('', ['model' => $this->model, 'blocks' => $reasoning]);
                    } elseif ('text' === $block['type'] && ($block['text'] ?? '') !== '') {
                        yield new TextDelta($block['text']);
                    }
                    break;
                case 'content_block_delta':
                    $delta = $e['delta'];
                    $i     = $e['index'];
                    switch ($delta['type']) {
                        case 'text_delta':
                            yield new TextDelta($delta['text']);
                            break;
                        case 'thinking_delta':
                            $blocks[$i]['thinking'] .= $delta['thinking'];
                            yield new ReasoningDelta($delta['thinking']);
                            break;
                        case 'signature_delta':
                            $blocks[$i]['signature'] .= $delta['signature'];
                            break;
                        case 'input_json_delta':
                            if ('' !== $delta['partial_json']) {
                                yield new ToolInputDelta($blocks[$i]['id'], $delta['partial_json']);
                            }
                            break;
                        default:
                            // citations_delta and future delta types carry nothing this model maps.
                            break;
                    }
                    break;
                case 'content_block_stop':
                    $block = $blocks[$e['index']];
                    if ('tool_use' === $block['type']) {
                        yield new ToolCallEnd($block['id']);
                    } elseif ('thinking' === $block['type']) {
                        $reasoning[] = ['type' => 'thinking', 'thinking' => $block['thinking'], 'signature' => $block['signature']];
                        yield new ReasoningDelta('', ['model' => $this->model, 'blocks' => $reasoning]);
                    }
                    break;
                case 'message_delta':
                    $stop  = $e['delta']['stop_reason'] ?? $stop;
                    $usage = self::usage($e['usage'] ?? [], $usage);
                    yield $usage;
                    break;
                case 'error':
                    throw self::error($e['error'], 200, $response->headers['retry-after'] ?? null);
                case 'message_stop':
                    if ('tool_use' === $stop && !$called) {
                        throw new ModelException(ErrorKind::ModelError, 'The reply ended with stop_reason tool_use but holds no tool_use block');
                    }
                    yield new Finish(match ($stop) {
                        'end_turn', 'stop_sequence'                   => FinishReason::Stop,
                        'tool_use'                                    => FinishReason::ToolCalls,
                        'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
                        'refusal'                                     => FinishReason::Refusal,
                        default                                       => throw new ModelException(ErrorKind::ModelError, 'Unsupported stop_reason ' . \var_export($stop, true)),
                    });

                    return;
                default:
                    // ping, and events added to the API later.
                    break;
            }
        }
        throw new ModelException(ErrorKind::TransportError, 'The stream ended before message_stop');
    }

    /** @param array<string, mixed> $u */
    private static function usage(array $u, Usage $was): Usage
    {
        $read  = (int) ($u['cache_read_input_tokens'] ?? $was->cacheRead);
        $write = (int) ($u['cache_creation_input_tokens'] ?? $was->cacheWrite);
        $input = isset($u['input_tokens']) ? (int) $u['input_tokens'] + $read + $write : $was->input;

        return new Usage($input, (int) ($u['output_tokens'] ?? $was->output), $read, $write);
    }

    /** @return array<string, mixed> */
    private static function json(string $data): array
    {
        try {
            return \json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModelException(ErrorKind::ModelError, 'The stream carried a line that is not JSON: ' . \substr($data, 0, 200), previous: $e);
        }
    }

    private static function error(array $error, int $status, ?string $retryAfter): ModelException
    {
        $type    = (string) ($error['type'] ?? '');
        $message = "Anthropic returned $type (HTTP $status): " . ($error['message'] ?? '');
        $kind    = match (true) {
            429 === $status || 529 === $status || 'rate_limit_error' === $type || 'overloaded_error' === $type    => ErrorKind::RateLimited,
            \str_contains(\strtolower((string) ($error['message'] ?? '')), 'prompt is too long')                  => ErrorKind::ContextTooLong,
            default                                                                                               => ErrorKind::ModelError,
        };

        return new ModelException($kind, $message, null !== $retryAfter && \is_numeric($retryAfter) ? (float) $retryAfter : null);
    }

    /** @return array<string, mixed> */
    private function request(Prompt $prompt): array
    {
        $o       = $prompt->options;
        $request = ['model' => $this->model, 'max_tokens' => $o->maxOutputTokens ?? $this->maxTokens, 'messages' => $this->messages($prompt->messages), 'stream' => true];
        if (null !== $prompt->system) {
            $request['system'] = $prompt->system;
        }
        if ([] !== $prompt->tools) {
            $request['tools'] = \array_map(static fn (ToolSpec $t) => [
                'name'         => $t->name,
                'description'  => $t->description,
                'input_schema' => $t->schema,
            ], $prompt->tools);
            $request['tool_choice'] = match ($o->toolChoice) {
                ToolChoice::Auto     => ['type' => 'auto'],
                ToolChoice::None     => ['type' => 'none'],
                ToolChoice::Required => ['type' => 'any'],
                ToolChoice::Tool     => ['type' => 'tool', 'name' => $o->tool],
            };
        } elseif (ToolChoice::Required === $o->toolChoice || ToolChoice::Tool === $o->toolChoice) {
            throw new UnsupportedOptionException("toolChoice {$o->toolChoice->name} needs at least one tool in the prompt");
        }
        if (ToolChoice::Tool === $o->toolChoice && !\in_array($o->tool, \array_map(static fn (ToolSpec $t): string => $t->name, $prompt->tools), true)) {
            throw new UnsupportedOptionException("toolChoice Tool names '{$o->tool}', which is not among the prompt's tools");
        }
        if (null !== $o->temperature && ($o->temperature < 0.0 || $o->temperature > 1.0)) {
            throw new UnsupportedOptionException("temperature {$o->temperature} is outside this API's range 0..1.0");
        }
        if (null !== $o->temperature) {
            $request['temperature'] = $o->temperature;
        }
        $request = \array_replace($request, $o->provider['anthropic'] ?? []);
        if ($prompt->continue) {
            $request['messages'] = $this->prefill($request, $prompt->messages[\count($prompt->messages) - 1]);
        }

        return $request;
    }

    /**
     * The request's messages with the last, the assistant message to continue, as a prefill.
     *
     * @param array<string, mixed> $request
     *
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function prefill(array $request, Message $last): array
    {
        if ('disabled' !== ($request['thinking']['type'] ?? 'disabled')) {
            throw new UnsupportedOptionException('The Messages API takes no prefill with thinking on, so it cannot continue an assistant message (Prompt::$continue, from a Resume). Turn thinking off for the resumed turn, or append the edit as a finished assistant message (a new Run with messages: [...$run->messages(), $edited]) and ask for the next one with a user message');
        }
        $content = $this->assistant(new Message(Role::Assistant, \array_values(\array_filter($last->parts, static fn (\Harness\Part $p) => !$p instanceof Reasoning))));
        $end     = \array_key_last($content);
        if (null === $end || 'text' !== $content[$end]['type']) {
            throw new UnsupportedContentException('The Messages API continues a prefill of text; the message to continue ' . (null === $end ? 'has no text' : "ends with a {$content[$end]['type']} block"));
        }
        $content[$end]['text'] = \rtrim($content[$end]['text']);
        if ('' === $content[$end]['text']) {
            \array_pop($content);
            if ([] === $content) {
                throw new UnsupportedContentException('The Messages API continues a prefill of text; the message to continue is only whitespace');
            }
        }
        $messages = $request['messages'];
        if ('assistant' === $messages[\count($messages) - 1]['role']) {
            \array_pop($messages);     // the edit as messages() sent it; it follows a user message
        }
        $messages[] = ['role' => 'assistant', 'content' => $content];

        return $messages;
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function messages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $role    = Role::User === $message->role ? 'user' : 'assistant';
            $content = Role::User === $message->role ? $this->user($message) : $this->assistant($message);
            if ([] === $content) {
                continue;
            }
            $last = \array_key_last($out);
            if (null !== $last && $out[$last]['role'] === $role) {
                $merged = \array_merge($out[$last]['content'], $content);
                if ('user' === $role) {
                    // Tool results must open the user turn.
                    \usort($merged, static fn (array $a, array $b) => ('tool_result' === $b['type']) <=> ('tool_result' === $a['type']));
                }
                $out[$last]['content'] = $merged;
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function user(Message $message): array
    {
        $results = [];
        $rest    = [];
        foreach ($message->parts as $part) {
            if ($part instanceof ToolResult) {
                $results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $part->callId,
                    'content'     => [] === $part->content ? [['type' => 'text', 'text' => $part->error ? 'Error' : '(no output)']] : \array_map($this->block(...), $part->content), // the API rejects an empty list
                    'is_error'    => $part->error,
                ];
            } else {
                $rest[] = $this->block($part);
            }
        }

        return [...$results, ...$rest];
    }

    /** @return list<array<string, mixed>> */
    private function assistant(Message $message): array
    {
        $out      = [];
        $unsigned = false;
        foreach ($message->parts as $part) {
            if ($part instanceof Reasoning) {
                if (($part->raw['model'] ?? null) === $this->model) {
                    foreach ($part->raw['blocks'] ?? [] as $block) {
                        if ('thinking' === ($block['type'] ?? null) && '' === ($block['signature'] ?? '')) {
                            $unsigned = true; // cut off (max_tokens) before it was signed: the API rejects it on replay

                            continue;
                        }
                        $out[] = $block;
                    }
                }
            } elseif ($part instanceof ToolCall) {
                if ($unsigned) { // with thinking on, a tool_use must follow its thinking block, and that one is gone
                    throw new UnsupportedContentException("The reply calling '{$part->name}' had its thinking cut off before it was signed (max_tokens) and cannot be replayed; drop that reply and its tool results, or allow more output tokens");
                }
                $out[] = ['type' => 'tool_use', 'id' => $part->id, 'name' => $part->name, 'input' => (object) $part->input];
            } elseif ($part instanceof Text) {
                if ('' !== $part->text) {
                    $out[] = ['type' => 'text', 'text' => $part->text];
                }
            } else {
                $out[] = $this->block($part);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function block(\Harness\Part $part): array
    {
        if ($part instanceof Text) {
            return ['type' => 'text', 'text' => $part->text];
        }
        if ($part instanceof Reference) {
            return ['type' => 'text', 'text' => null === $part->title ? $part->uri : "[{$part->title}]({$part->uri})"];
        }
        if ($part instanceof Media) {
            $source = null !== $part->uri
                ? ['type' => 'url', 'url' => $part->uri]
                : ['type' => 'base64', 'media_type' => $part->mime, 'data' => \base64_encode((string) $part->bytes)];
            if (\in_array($part->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return ['type' => 'image', 'source' => $source];
            }
            if ('application/pdf' === $part->mime) {
                return ['type' => 'document', 'source' => $source];
            }
            if ('text/plain' === $part->mime && null !== $part->bytes) {
                return ['type' => 'document', 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => $part->bytes]];
            }
            throw new UnsupportedContentException("The Messages API takes PNG, JPEG, GIF and WebP images, PDF and plain-text documents, not {$part->mime}");
        }
        throw new UnsupportedContentException(\get_debug_type($part) . ' cannot be sent as a content block');
    }
}
