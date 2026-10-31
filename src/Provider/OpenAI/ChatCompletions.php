<?php

declare(strict_types=1);

namespace Harness\Provider\OpenAI;

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
 * OpenAI Chat Completions, streamed: the API every local server speaks (llama.cpp, vLLM, exl3,
 * Ollama). `baseUrl` ends before `/chat/completions` (e.g. `http://127.0.0.1:8012/v1`). Reasoning
 * from `delta.reasoning_content` or `delta.reasoning` is replayed as `reasoning_content` to the same
 * model only. Tool results become `role: tool` messages (images in them follow as a user message).
 * `Options::$provider['openai']` is merged into the request body last, so it can set anything.
 */
final readonly class ChatCompletions implements Model
{
    /** @param array<string, string> $headers extra request headers */
    public function __construct(
        private Transport $transport,
        private string $baseUrl,
        private string $model,
        private ?string $apiKey = null,
        private array $headers = [],
    ) {
    }

    public function stream(Prompt $prompt): iterable
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'] + $this->headers;
        if (null !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $body     = \json_encode($this->request($prompt), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response = $this->transport->post(\rtrim($this->baseUrl, '/') . '/chat/completions', $headers, $body, $prompt->cancel);
        if (200 !== $response->status || !Sse::isEventStream($response)) {
            throw self::error($response->status, \implode('', [...$response->body()]), $response->headers['retry-after'] ?? null);
        }

        /** @var array<int, array{string, string}> $ids tool call index => [id, function name] */
        $ids    = [];
        $finish = null;
        $usage  = null;
        foreach (Sse::parse($response->body()) as $data) {
            if ('[DONE]' === $data) {
                break;
            }
            $chunk = self::json($data);
            if (isset($chunk['error'])) {
                throw new ModelException(ErrorKind::ModelError, 'The model stream failed: ' . \json_encode($chunk['error'], \JSON_UNESCAPED_SLASHES));
            }
            if (isset($chunk['usage'])) {
                $u     = $chunk['usage'];
                $usage = new Usage(
                    (int) ($u['prompt_tokens'] ?? 0),
                    (int) ($u['completion_tokens'] ?? 0),
                    (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0),
                );
            }
            foreach ($chunk['choices'] ?? [] as $choice) {
                if (0 !== ($choice['index'] ?? 0)) {
                    throw new ModelException(ErrorKind::ModelError, 'The stream carries a second choice; Chat Completions is asked for one reply (n = 1)');
                }
                yield from $this->delta($choice['delta'] ?? [], $ids);
                if (isset($choice['finish_reason'])) {
                    $finish = $choice['finish_reason'];
                }
            }
        }
        foreach ($ids as [$id]) {
            yield new ToolCallEnd($id);
        }
        if (null !== $usage) {
            yield $usage;
        }
        if (('tool_calls' === $finish || 'function_call' === $finish) && [] === $ids) {
            throw new ModelException(ErrorKind::ModelError, "The reply ended with finish_reason $finish but holds no tool call");
        }
        yield new Finish(match ($finish) {
            'stop'                          => FinishReason::Stop,
            'tool_calls', 'function_call'   => FinishReason::ToolCalls,
            'length'                        => FinishReason::Length,
            'content_filter'                => FinishReason::Refusal,
            null                            => throw new ModelException(ErrorKind::ModelError, 'The stream ended without a finish_reason'),
            default                         => throw new ModelException(ErrorKind::ModelError, "Unknown finish_reason '$finish'"),
        });
    }

    /**
     * @param array<string, mixed>              $delta
     * @param array<int, array{string, string}> $ids
     *
     * @return \Generator<int, Delta>
     */
    private function delta(array $delta, array &$ids): \Generator
    {
        $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
        if (\is_string($reasoning) && '' !== $reasoning) {
            yield new ReasoningDelta($reasoning, ['model' => $this->model]);
        }
        if (isset($delta['content']) && '' !== $delta['content']) {
            yield new TextDelta($delta['content']);
        }
        if (isset($delta['refusal']) && '' !== $delta['refusal']) { // a structured-output refusal: its text is the reply
            yield new TextDelta($delta['refusal']);
        }
        foreach ($delta['tool_calls'] ?? [] as $call) {
            $index = (int) ($call['index'] ?? 0);
            if (!isset($ids[$index])) {
                $name        = $call['function']['name'] ?? throw new ModelException(ErrorKind::ModelError, "Tool call $index began without a function name");
                $ids[$index] = [$call['id'] ?? ('call_' . \bin2hex(\random_bytes(8))), $name];
                yield new ToolCallStart($ids[$index][0], $name);
            } elseif (isset($call['id']) && $call['id'] !== $ids[$index][0]) {
                throw new ModelException(ErrorKind::ModelError, "Tool call $index changed its id from '{$ids[$index][0]}' to '{$call['id']}' mid-stream; a second call takes its own index");
            } elseif (isset($call['function']['name']) && $call['function']['name'] !== $ids[$index][1]) {
                throw new ModelException(ErrorKind::ModelError, "Tool call $index changed its name from '{$ids[$index][1]}' to '{$call['function']['name']}' mid-stream; a second call takes its own index");
            }
            $arguments = $call['function']['arguments'] ?? '';
            if ('' !== $arguments) {
                yield new ToolInputDelta($ids[$index][0], $arguments);
            }
        }
    }

    /** @return array<string, mixed> */
    private function request(Prompt $prompt): array
    {
        $o       = $prompt->options;
        $request = ['model' => $this->model, 'messages' => $this->messages($prompt), 'stream' => true, 'stream_options' => ['include_usage' => true]];
        if ([] !== $prompt->tools) {
            $request['tools'] = \array_map(static fn (ToolSpec $t) => [
                'type'     => 'function',
                'function' => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->schema],
            ], $prompt->tools);
            $request['tool_choice'] = match ($o->toolChoice) {
                ToolChoice::Auto     => 'auto',
                ToolChoice::None     => 'none',
                ToolChoice::Required => 'required',
                ToolChoice::Tool     => ['type' => 'function', 'function' => ['name' => $o->tool]],
            };
        } elseif (ToolChoice::Required === $o->toolChoice || ToolChoice::Tool === $o->toolChoice) {
            throw new UnsupportedOptionException("toolChoice {$o->toolChoice->name} needs at least one tool in the prompt");
        }
        if (null !== $o->maxOutputTokens) {
            $request['max_tokens'] = $o->maxOutputTokens;
        }
        if (null !== $o->temperature) {
            $request['temperature'] = $o->temperature;
        }

        if (1 !== ($o->provider['openai']['n'] ?? 1)) {
            throw new UnsupportedOptionException('Chat Completions streams one reply; n must be 1');
        }

        return \array_replace($request, $o->provider['openai'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    private function messages(Prompt $prompt): array
    {
        $out = [];
        if (null !== $prompt->system) {
            $out[] = ['role' => 'system', 'content' => $prompt->system];
        }
        foreach ($prompt->messages as $message) {
            \array_push($out, ...(Role::User === $message->role ? $this->user($message) : [$this->assistant($message)]));
        }

        return $out;
    }

    /** @return list<array<string, mixed>> tool messages first, then the rest as one user message */
    private function user(Message $message): array
    {
        $tools   = [];
        $content = [];
        foreach ($message->parts as $part) {
            if ($part instanceof ToolResult) {
                $text = [];
                foreach ($part->content as $p) {
                    if ($p instanceof Media) {
                        $content[] = ['type' => 'text', 'text' => "(attached by tool call {$part->callId})"];
                        $content[] = $this->media($p);
                    } else {
                        $text[] = $p instanceof Text ? $p->text : $this->reference($p);
                    }
                }
                $tools[] = ['role' => 'tool', 'tool_call_id' => $part->callId, 'content' => ($part->error ? 'Error: ' : '') . \implode("\n", $text)];
            } else {
                $content[] = match (true) {
                    $part instanceof Text      => ['type' => 'text', 'text' => $part->text],
                    $part instanceof Media     => $this->media($part),
                    $part instanceof Reference => ['type' => 'text', 'text' => $this->reference($part)],
                    default                    => throw new UnsupportedContentException(\get_debug_type($part) . ' in a user message'),
                };
            }
        }
        if ([] !== $content) {
            $only    = 1 === \count($content) && 'text' === $content[0]['type'];
            $tools[] = ['role' => 'user', 'content' => $only ? $content[0]['text'] : $content];
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private function assistant(Message $message): array
    {
        $text      = '';
        $reasoning = '';
        $calls     = [];
        foreach ($message->parts as $part) {
            match (true) {
                $part instanceof Text      => $text .= $part->text,
                $part instanceof Reasoning => ($part->raw['model'] ?? null) === $this->model ? $reasoning .= $part->text : null,
                $part instanceof ToolCall  => $calls[] = [
                    'id'       => $part->id,
                    'type'     => 'function',
                    'function' => ['name' => $part->name, 'arguments' => [] === $part->input ? '{}' : \json_encode($part->input, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)],
                ],
                $part instanceof Reference => $text .= $this->reference($part),
                default                    => throw new UnsupportedContentException(\get_debug_type($part) . ' in an assistant message is not supported by Chat Completions'),
            };
        }
        $out = ['role' => 'assistant', 'content' => '' === $text && [] !== $calls ? null : $text];
        if ('' !== $reasoning) {
            $out['reasoning_content'] = $reasoning;
        }
        if ([] !== $calls) {
            $out['tool_calls'] = $calls;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function media(Media $m): array
    {
        if (\str_starts_with($m->mime, 'image/')) {
            return ['type' => 'image_url', 'image_url' => ['url' => $m->uri ?? 'data:' . $m->mime . ';base64,' . \base64_encode((string) $m->bytes)]];
        }
        if (('audio/wav' === $m->mime || 'audio/mpeg' === $m->mime) && null !== $m->bytes) {
            return ['type' => 'input_audio', 'input_audio' => ['data' => \base64_encode($m->bytes), 'format' => 'audio/wav' === $m->mime ? 'wav' : 'mp3']];
        }
        throw new UnsupportedContentException("Chat Completions takes images (inline or by URL) and inline wav/mp3 audio, not {$m->mime}" . (null !== $m->uri ? ' by URI' : ''));
    }

    private function reference(Reference $r): string
    {
        return null === $r->title ? $r->uri : "[{$r->title}]({$r->uri})";
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

    private static function error(int $status, string $body, ?string $retryAfter): ModelException
    {
        $message = "Chat Completions returned HTTP $status: " . \substr($body, 0, 2000);
        $lower   = \strtolower($body);
        $error   = \json_decode($body, true)['error'] ?? [];
        $code    = \is_array($error) ? \strtolower((string) ($error['code'] ?? $error['type'] ?? '')) : '';
        $kind    = match (true) {
            429 === $status || \in_array($code, ['rate_limit_exceeded', 'rate_limit_error', 'insufficient_quota'], true)                                                                            => ErrorKind::RateLimited,
            \str_contains($lower, 'context_length_exceeded') || \str_contains($lower, 'context length') || \str_contains($lower, 'maximum context') || \str_contains($lower, 'too many tokens')     => ErrorKind::ContextTooLong,
            default                                                                                                                                                                                 => ErrorKind::ModelError,
        };

        return new ModelException($kind, $message, null !== $retryAfter && \is_numeric($retryAfter) ? (float) $retryAfter : null);
    }
}
