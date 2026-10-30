<?php

declare(strict_types=1);

namespace Harness\Provider\OpenAI;

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
use Harness\UnsupportedContentException;
use Harness\UnsupportedOptionException;
use Harness\Usage;

/**
 * The OpenAI Responses API, streamed: `POST <baseUrl>/responses`. Messages become input items
 * (`message`, `function_call` and `function_call_output` paired by `call_id`, `reasoning`); the
 * system text is `instructions`. With `store` false (the default) nothing is kept server side and
 * reasoning is replayed by its `encrypted_content` (requested with `include`); with `store` true it
 * is replayed by id. Reasoning is replayed to the same model only.
 *
 * With `strict` (the default) each tool's schema is sent strict when it can be made so: every
 * object closed (`additionalProperties: false`) with all properties required, the optional ones
 * made nullable; such a tool's arguments arrive whole at the end of the call, with the nulls of
 * optional properties removed, so the tool sees the input its own schema describes. A schema that
 * cannot be made strict (a free-form object, `oneOf`, `allOf`, `not`, `if`, pattern properties)
 * is sent with `strict: false` and streams as usual. `Options::$provider['openai']` is merged into
 * the request body last. A `Prompt::$continue` throws `UnsupportedOptionException`: the API has no
 * continuation of an assistant message.
 */
final readonly class Responses implements Model
{
    /** Keywords strict mode does not take; a schema using one is sent non-strict. */
    private const NOT_STRICT = ['oneOf', 'allOf', 'not', 'if', 'then', 'else', 'patternProperties', 'dependentRequired', 'dependentSchemas', 'unevaluatedProperties', 'propertyNames'];

    /** @param array<string, string> $headers extra request headers */
    public function __construct(
        private Transport $transport,
        private string $baseUrl,
        private string $model,
        private ?string $apiKey = null,
        private array $headers = [],
        private bool $strict = true,
        private bool $store = false,
    ) {
    }

    public function stream(Prompt $prompt): iterable
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'] + $this->headers;
        if (null !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        [$request, $whole] = $this->request($prompt);
        $body              = \json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response          = $this->transport->post(\rtrim($this->baseUrl, '/') . '/responses', $headers, $body, $prompt->cancel);
        if (200 !== $response->status) {
            $text  = \implode('', [...$response->body()]);
            $error = \json_decode($text, true)['error'] ?? null;
            throw self::error(\is_array($error) ? (string) ($error['code'] ?? $error['type'] ?? '') : '', "The Responses API returned HTTP {$response->status}: " . \substr($text, 0, 2000), $response->status, $response->headers['retry-after'] ?? null);
        }

        /** @var array<string, array{call: string, name: string, streamed: bool}> $calls item id => the call */
        $calls     = [];
        $reasoning = [];    // the reasoning items of the current Reasoning part, for replay
        $called    = false;
        $refused   = false;
        foreach (Sse::parse($response->body()) as $data) {
            $e = \json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
            switch ($e['type'] ?? null) {
                case 'response.output_item.added':
                    $item = $e['item'];
                    if ('reasoning' !== $item['type']) {
                        $reasoning = [];
                    }
                    if ('function_call' === $item['type']) {
                        $calls[$item['id'] ?? $item['call_id']] = ['call' => $item['call_id'], 'name' => $item['name'], 'streamed' => false];
                        $called                                 = true;
                        yield new ToolCallStart($item['call_id'], $item['name']);
                    } elseif ('reasoning' === $item['type']) {
                        yield new ReasoningDelta('', ['model' => $this->model]);
                    }
                    break;
                case 'response.output_text.delta':
                    yield new TextDelta($e['delta']);
                    break;
                case 'response.refusal.delta':
                    $refused = true;
                    yield new TextDelta($e['delta']);
                    break;
                case 'response.reasoning_summary_part.added':
                    if ($e['summary_index'] > 0) {
                        yield new ReasoningDelta("\n\n");
                    }
                    break;
                case 'response.reasoning_summary_text.delta':
                case 'response.reasoning_text.delta':
                    yield new ReasoningDelta($e['delta']);
                    break;
                case 'response.function_call_arguments.delta':
                    $c = $calls[$e['item_id']] ?? throw new ModelException(ErrorKind::ModelError, "Arguments for unknown function call item {$e['item_id']}");
                    if (!isset($whole[$c['name']])) {
                        $calls[$e['item_id']]['streamed'] = true;
                        yield new ToolInputDelta($c['call'], $e['delta']);
                    }
                    break;
                case 'response.function_call_arguments.done':
                    $c = $calls[$e['item_id']] ?? throw new ModelException(ErrorKind::ModelError, "Arguments for unknown function call item {$e['item_id']}");
                    if (isset($whole[$c['name']])) {
                        yield new ToolInputDelta($c['call'], self::withoutOptionalNulls($e['arguments'], $whole[$c['name']]));
                    } elseif (!$c['streamed']) {
                        yield new ToolInputDelta($c['call'], $e['arguments']);
                    }
                    yield new ToolCallEnd($c['call']);
                    break;
                case 'response.output_item.done':
                    if ('reasoning' === $e['item']['type']) {
                        $reasoning[] = $e['item'];
                        yield new ReasoningDelta('', ['items' => $reasoning]);
                    }
                    break;
                case 'response.completed':
                case 'response.incomplete':
                    $r = $e['response'];
                    if (isset($r['usage'])) {
                        yield new Usage((int) ($r['usage']['input_tokens'] ?? 0), (int) ($r['usage']['output_tokens'] ?? 0), (int) ($r['usage']['input_tokens_details']['cached_tokens'] ?? 0));
                    }
                    $why = $r['incomplete_details']['reason'] ?? null;
                    yield new Finish(match (true) {
                        'response.completed' === $e['type'] => $refused ? FinishReason::Refusal : ($called ? FinishReason::ToolCalls : FinishReason::Stop),
                        'max_output_tokens' === $why        => FinishReason::Length,
                        'content_filter' === $why           => FinishReason::Refusal,
                        default                             => throw new ModelException(ErrorKind::ModelError, 'The response is incomplete: ' . \var_export($why, true)),
                    });

                    return;
                case 'response.failed':
                    $err = $e['response']['error'] ?? [];
                    throw self::error((string) ($err['code'] ?? ''), 'The response failed: ' . \json_encode($err, \JSON_UNESCAPED_SLASHES), null, null);
                case 'error':
                    throw self::error((string) ($e['code'] ?? ''), 'The Responses stream failed: ' . ($e['message'] ?? \json_encode($e, \JSON_UNESCAPED_SLASHES)), null, null);
            }
        }
        throw new ModelException(ErrorKind::ModelError, 'The Responses stream ended without response.completed');
    }

    /**
     * The request body, and the original schema of each tool sent strict with optional properties,
     * whose arguments are cleaned before they go on.
     *
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function request(Prompt $prompt): array
    {
        if ($prompt->continue) {
            throw new UnsupportedOptionException('The Responses API cannot continue an assistant message (Prompt::$continue, from a Resume). The fallback is the host\'s to choose: append the edit as a finished assistant message (a new Run with messages: [...$run->messages(), $edited]) and ask for the next one with a user message');
        }
        $o       = $prompt->options;
        $request = ['model' => $this->model, 'input' => $this->input($prompt), 'stream' => true, 'store' => $this->store];
        if (null !== $prompt->system) {
            $request['instructions'] = $prompt->system;
        }
        if (!$this->store) {
            $request['include'] = ['reasoning.encrypted_content'];
        }
        $whole = [];
        if ([] !== $prompt->tools) {
            $request['tools'] = [];
            foreach ($prompt->tools as $t) {
                $optional = false;
                $strict   = $this->strict ? self::strict($t->schema, true, $optional) : null;
                if (null !== $strict && $optional) {
                    $whole[$t->name] = $t->schema;
                }
                $request['tools'][] = ['type' => 'function', 'name' => $t->name, 'description' => $t->description, 'parameters' => $strict ?? $t->schema, 'strict' => null !== $strict];
            }
            $request['tool_choice'] = match ($o->toolChoice) {
                ToolChoice::Auto     => 'auto',
                ToolChoice::None     => 'none',
                ToolChoice::Required => 'required',
                ToolChoice::Tool     => ['type' => 'function', 'name' => $o->tool],
            };
        } elseif (ToolChoice::Required === $o->toolChoice || ToolChoice::Tool === $o->toolChoice) {
            throw new UnsupportedOptionException("toolChoice {$o->toolChoice->name} needs at least one tool in the prompt");
        }
        if (null !== $o->maxOutputTokens) {
            $request['max_output_tokens'] = $o->maxOutputTokens;
        }
        if (null !== $o->temperature) {
            $request['temperature'] = $o->temperature;
        }

        return [\array_replace($request, $o->provider['openai'] ?? []), $whole];
    }

    /** @return list<array<string, mixed>> */
    private function input(Prompt $prompt): array
    {
        $items = [];
        foreach ($prompt->messages as $message) {
            \array_push($items, ...(Role::User === $message->role ? $this->user($message) : $this->assistant($message)));
        }

        return $items;
    }

    /** @return list<array<string, mixed>> the tool outputs first, then the rest as one user message */
    private function user(Message $message): array
    {
        $items   = [];
        $content = [];
        foreach ($message->parts as $part) {
            if ($part instanceof ToolResult) {
                $text = [];
                foreach ($part->content as $p) {
                    if ($p instanceof Media) {
                        $content[] = ['type' => 'input_text', 'text' => "(attached by tool call {$part->callId})"];
                        $content[] = $this->media($p);
                    } else {
                        $text[] = $p instanceof Text ? $p->text : self::reference($p);
                    }
                }
                $items[] = ['type' => 'function_call_output', 'call_id' => $part->callId, 'output' => ($part->error ? 'Error: ' : '') . \implode("\n", $text)];
            } else {
                $content[] = match (true) {
                    $part instanceof Text      => ['type' => 'input_text', 'text' => $part->text],
                    $part instanceof Media     => $this->media($part),
                    $part instanceof Reference => ['type' => 'input_text', 'text' => self::reference($part)],
                    default                    => throw new UnsupportedContentException(\get_debug_type($part) . ' in a user message'),
                };
            }
        }
        if ([] !== $content) {
            $items[] = ['type' => 'message', 'role' => 'user', 'content' => $content];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function assistant(Message $message): array
    {
        $items = [];
        $text  = null;
        foreach ($message->parts as $part) {
            if ($part instanceof Text || $part instanceof Reference) {
                $text ??= \count($items);
                $items[$text] = ['type' => 'message', 'role' => 'assistant', 'content' => ($items[$text]['content'] ?? '') . ($part instanceof Text ? $part->text : self::reference($part))];
                continue;
            }
            $text = null;
            if ($part instanceof Reasoning) {
                if (($part->raw['model'] ?? null) === $this->model) {
                    foreach ($part->raw['items'] ?? [] as $item) {
                        $replay = ['type' => 'reasoning', 'id' => $item['id'], 'summary' => $item['summary'] ?? []];
                        if ($this->store) {
                            $items[] = $replay;
                        } elseif (isset($item['encrypted_content'])) {
                            $items[] = $replay + ['encrypted_content' => $item['encrypted_content']];
                        }
                    }
                }
                continue;
            }
            $items[] = match (true) {
                $part instanceof ToolCall => ['type' => 'function_call', 'call_id' => $part->id, 'name' => $part->name, 'arguments' => [] === $part->input ? '{}' : \json_encode($part->input, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)],
                default                   => throw new UnsupportedContentException(\get_debug_type($part) . ' in an assistant message is not supported by the Responses API'),
            };
        }

        return \array_values($items);
    }

    /** @return array<string, mixed> */
    private function media(Media $m): array
    {
        $url = $m->uri ?? 'data:' . $m->mime . ';base64,' . \base64_encode((string) $m->bytes);
        if (\str_starts_with($m->mime, 'image/')) {
            return ['type' => 'input_image', 'image_url' => $url];
        }
        if ('application/pdf' === $m->mime) {
            return null === $m->uri ? ['type' => 'input_file', 'filename' => 'document.pdf', 'file_data' => $url] : ['type' => 'input_file', 'file_url' => $m->uri];
        }
        throw new UnsupportedContentException("The Responses API takes images and PDF files, not {$m->mime}");
    }

    private static function reference(Reference $r): string
    {
        return null === $r->title ? $r->uri : "[{$r->title}]({$r->uri})";
    }

    /**
     * The strict form of a schema node, or null when it has none. `$optional` is set when an
     * optional property was made nullable.
     *
     * @param array<string, mixed>|\stdClass $node
     *
     * @return array<string, mixed>|null
     */
    private static function strict(array|\stdClass $node, bool $root, bool &$optional): ?array
    {
        if ($node instanceof \stdClass || [] !== \array_intersect(self::NOT_STRICT, \array_keys($node))) {
            return null;    // {} (any value) and the keywords strict mode does not take
        }
        if (isset($node['anyOf'])) {
            foreach ($node['anyOf'] as $i => $branch) {
                $node['anyOf'][$i] = self::strict($branch, false, $optional);
                if (null === $node['anyOf'][$i]) {
                    return null;
                }
            }
        }
        $types = (array) ($node['type'] ?? []);
        if (\in_array('object', $types, true)) {
            $properties = $node['properties'] ?? ($root ? [] : null);
            if (null === $properties || (isset($node['additionalProperties']) && false !== $node['additionalProperties'])) {
                return null;    // a free-form object
            }
            $required = $node['required'] ?? [];
            $out      = [];
            foreach ((array) $properties as $name => $schema) {
                $s = self::strict($schema, false, $optional);
                if (null === $s) {
                    return null;
                }
                if (!\in_array((string) $name, $required, true)) {
                    $optional = true;
                    $s        = self::nullable($s);
                }
                $out[$name] = $s;
            }
            $node['properties']           = [] === $out ? new \stdClass() : $out;
            $node['required']             = \array_map(\strval(...), \array_keys($out));
            $node['additionalProperties'] = false;
        }
        if (\in_array('array', $types, true)) {
            if (!isset($node['items'])) {
                return null;
            }
            $node['items'] = self::strict($node['items'], false, $optional);
            if (null === $node['items']) {
                return null;
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function nullable(array $node): array
    {
        if (isset($node['type'])) {
            $node['type'] = \array_values(\array_unique([...(array) $node['type'], 'null']));
            if (isset($node['enum']) && !\in_array(null, $node['enum'], true)) {
                $node['enum'][] = null;
            }

            return $node;
        }

        return isset($node['anyOf']) ? ['anyOf' => [...$node['anyOf'], ['type' => 'null']]] + $node : ['anyOf' => [$node, ['type' => 'null']]];
    }

    /**
     * The arguments as JSON with the nulls of properties the original schema does not require
     * removed; JSON that does not decode to an object goes on as it is, for the run to report.
     *
     * @param array<string, mixed> $schema
     */
    private static function withoutOptionalNulls(string $arguments, array $schema): string
    {
        $value = \json_decode($arguments, true);
        if (!\is_array($value)) {
            return $arguments;
        }

        return \json_encode((object) self::clean($value, $schema), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<mixed>                   $value
     * @param array<string, mixed>|\stdClass $schema
     *
     * @return array<mixed>
     */
    private static function clean(array $value, array|\stdClass $schema): array
    {
        if ($schema instanceof \stdClass) {
            return $value;
        }
        if (isset($schema['properties']) && !\array_is_list($value)) {
            $required = $schema['required'] ?? [];
            foreach ((array) ($schema['properties'] ?? []) as $name => $s) {
                if (!\array_key_exists($name, $value)) {
                    continue;
                }
                if (null === $value[$name] && !\in_array((string) $name, $required, true)) {
                    unset($value[$name]);
                } elseif (\is_array($value[$name])) {
                    $value[$name] = self::clean($value[$name], $s);
                }
            }
        } elseif (isset($schema['items']) && \array_is_list($value)) {
            foreach ($value as $i => $v) {
                if (\is_array($v)) {
                    $value[$i] = self::clean($v, $schema['items']);
                }
            }
        }

        return $value;
    }

    private static function error(string $code, string $message, ?int $status, ?string $retryAfter): ModelException
    {
        $kind = match (true) {
            429 === $status, 'rate_limit_exceeded' === $code => ErrorKind::RateLimited,
            'context_length_exceeded' === $code              => ErrorKind::ContextTooLong,
            default                                          => ErrorKind::ModelError,
        };

        return new ModelException($kind, $message, null !== $retryAfter && \is_numeric($retryAfter) ? (float) $retryAfter : null);
    }
}
