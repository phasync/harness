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
use Harness\Usage;

/**
 * Anthropic's Messages API, streamed. `thinking` and `redacted_thinking` blocks become Reasoning
 * parts whose `raw['blocks']` holds the blocks verbatim; they are replayed unchanged to the same
 * model and dropped for another. Consecutive user messages are merged, tool results first, as the
 * API requires. `maxTokens` is used when `Options::$maxOutputTokens` is null (the API requires one).
 * `Options::$provider['anthropic']` is merged into the request body last: `thinking`,
 * `cache_control`, `output_config` and the rest go there.
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
        $response = $this->transport->post(\rtrim($this->baseUrl, '/') . '/messages', $headers, $body);
        if (200 !== $response->status) {
            $raw   = \implode('', [...$response->body()]);
            $error = \json_decode($raw, true)['error'] ?? ['type' => 'http_' . $response->status, 'message' => \substr($raw, 0, 2000)];
            throw self::error($error, $response->status, $response->headers['retry-after'] ?? null);
        }

        /** @var array<int, array{type: string, id?: string, thinking?: string, signature?: string}> $blocks */
        $blocks    = [];
        $reasoning = [];
        $usage     = new Usage();
        $stop      = null;
        foreach (Sse::parse($response->body()) as $event => $data) {
            $e = \json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
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
                    throw self::error($e['error'], 200, null);
                case 'message_stop':
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

    /** @param array<string, mixed> $error */
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
            throw new \Harness\UnsupportedOptionException("toolChoice {$o->toolChoice->name} needs at least one tool in the prompt");
        }
        if (null !== $o->temperature) {
            $request['temperature'] = $o->temperature;
        }

        return \array_replace($request, $o->provider['anthropic'] ?? []);
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
                    'content'     => \array_map($this->block(...), $part->content),
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
        $out = [];
        foreach ($message->parts as $part) {
            if ($part instanceof Reasoning) {
                if (($part->raw['model'] ?? null) === $this->model) {
                    \array_push($out, ...($part->raw['blocks'] ?? []));
                }
            } elseif ($part instanceof ToolCall) {
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
