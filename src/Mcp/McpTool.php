<?php

declare(strict_types=1);

namespace Harness\Mcp;

use Harness\Approval;
use Harness\Call;
use Harness\Cancel;
use Harness\Decision;
use Harness\Need;
use Harness\Part\Media;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Question;
use Harness\Result;
use Harness\Tool;
use Harness\ToolSpec;

/**
 * One tool of an `McpServer`, called with `tools/call`. Content becomes parts (`text` → Text,
 * `image` and `audio` → Media, `resource` → Text or Media, `resource_link` → Reference),
 * `structuredContent` the result's `value`, `isError` an error result; progress notifications
 * become `Call::progress()`.
 *
 * A result with `resultType: "input_required"` ends the call with `Call::needsInput()`: each form
 * elicitation becomes questions (`<key>/<field>` per field of its schema, enum values as choices),
 * or an `Approval` with id `<key>` for a schema without fields, and the state keeps the input
 * requests and the opaque `requestState`. On resume the call is retried with `inputResponses`
 * built from the answers and the same `requestState`: a request is `accept`ed with the values, or
 * `decline`d when the host denied its approval or any of its questions with `Decision::deny()`.
 * An `input_required` without input requests is retried at once. A result without `resultType`
 * is complete.
 */
final readonly class McpTool implements Tool
{
    /** At most this many immediate retries of an input_required without input requests. */
    private const RETRIES = 5;

    /** @param array<string, list<string>> $headers `x-mcp-header` name => argument path */
    public function __construct(private McpServer $server, private string $name, private ToolSpec $spec, private array $headers)
    {
    }

    public function spec(): ToolSpec
    {
        return $this->spec;
    }

    public function call(array $input, Call $call): Result
    {
        $params = ['name' => $this->name, 'arguments' => [] === $input ? new \stdClass() : $input];
        $state  = $call->state();
        if (\is_array($state) && isset($state['mcp'])) {
            if ([] !== $state['mcp']['inputRequests']) {
                $params['inputResponses'] = self::responses($state['mcp']['inputRequests'], $call->answers());
            }
            if (null !== $state['mcp']['requestState']) {
                $params['requestState'] = $state['mcp']['requestState'];
            }
        }
        $cancel = new Cancel($call->isCancelled(...));
        $notify = static function (array $message) use ($call): void {
            if ('notifications/progress' === $message['method']) {
                $p = $message['params'];
                $call->progress(\array_filter(['progress' => $p['progress'] ?? null, 'total' => $p['total'] ?? null, 'message' => $p['message'] ?? null], static fn ($v) => null !== $v));
            }
        };
        for ($try = 0; $try <= self::RETRIES; ++$try) {
            $result = $this->server->request('tools/call', $params, $cancel, $notify, $this->headerValues($input));
            if ('input_required' !== ($result['resultType'] ?? 'complete')) {
                return self::result($result);
            }
            $requests = $result['inputRequests'] ?? [];
            $request  = $result['requestState'] ?? null;
            if ([] !== $requests) {
                return $call->needsInput(self::needs($requests), ['mcp' => ['inputRequests' => $requests, 'requestState' => $request]]);
            }
            unset($params['inputResponses']);
            $params['requestState'] = $request ?? throw new McpException("MCP tool {$this->name}: input_required with neither inputRequests nor requestState");
        }
        throw new McpException("MCP tool {$this->name} kept answering input_required without input requests");
    }

    /**
     * @param array<string, array<string, mixed>> $requests
     *
     * @return list<Need>
     */
    private static function needs(array $requests): array
    {
        $needs = [];
        foreach ($requests as $key => $request) {
            $params = $request['params'] ?? [];
            if ('elicitation/create' !== ($request['method'] ?? null) || 'form' !== ($params['mode'] ?? 'form')) {
                throw new McpException('The MCP server asked for ' . ($request['method'] ?? '?') . ' (' . ($params['mode'] ?? '') . '), which this client did not declare; it handles form elicitation only');
            }
            $fields = $params['requestedSchema']['properties'] ?? [];
            if ([] === $fields) {
                $needs[] = new Approval((string) $key, (string) $params['message']);
                continue;
            }
            foreach ($fields as $field => $schema) {
                $label       = $schema['title'] ?? $schema['description'] ?? $field;
                $choices     = 'boolean' === ($schema['type'] ?? null) ? ['true', 'false'] : (isset($schema['enum']) ? \array_map(\strval(...), $schema['enum']) : null);
                $needs[]     = new Question("$key/$field", "{$params['message']} ($label)", $choices);
            }
        }

        return $needs;
    }

    /**
     * @param array<string, array<string, mixed>> $requests
     * @param array<string, mixed>                $answers
     *
     * @return array<string, array<string, mixed>>
     */
    private static function responses(array $requests, array $answers): array
    {
        $responses = [];
        foreach ($requests as $key => $request) {
            $fields = $request['params']['requestedSchema']['properties'] ?? [];
            $mine   = [] === $fields ? [$answers[$key]] : \array_map(static fn ($field) => $answers["$key/$field"], \array_keys($fields));
            foreach ($mine as $answer) {
                if ($answer instanceof Decision && !$answer->allowed) {
                    $responses[$key] = ['action' => 'decline'];
                    continue 2;
                }
            }
            if ([] === $fields) {
                $responses[$key] = ['action' => 'accept'];
                continue;
            }
            $content = [];
            foreach ($fields as $field => $schema) {
                $answer          = $answers["$key/$field"];
                $content[$field] = match ($schema['type'] ?? 'string') {
                    'integer' => \is_numeric($answer) ? (int) $answer : $answer,
                    'number'  => \is_numeric($answer) ? (float) $answer : $answer,
                    'boolean' => \is_bool($answer) ? $answer : 'true' === $answer,
                    default   => $answer,
                };
            }
            $responses[$key] = ['action' => 'accept', 'content' => $content];
        }

        return $responses;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function headerValues(array $input): array
    {
        $out = [];
        foreach ($this->headers as $name => $path) {
            $value = $input;
            foreach ($path as $key) {
                $value = \is_array($value) ? $value[$key] ?? null : null;
            }
            if (null !== $value && !\is_array($value)) {
                $out["Mcp-Param-$name"] = HttpConnection::headerValue(\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $result */
    private static function result(array $result): Result
    {
        $parts = [];
        foreach ($result['content'] ?? [] as $c) {
            $parts[] = match ($c['type'] ?? null) {
                'text'           => new Text((string) $c['text']),
                'image', 'audio' => new Media((string) $c['mimeType'], self::bytes($c['data'])),
                'resource'       => isset($c['resource']['text'])
                    ? new Text((string) $c['resource']['text'])
                    : new Media((string) ($c['resource']['mimeType'] ?? 'application/octet-stream'), self::bytes($c['resource']['blob'] ?? '')),
                'resource_link' => new Reference((string) $c['uri'], $c['title'] ?? $c['name'] ?? null),
                default         => throw new McpException('The MCP server returned content of unknown type ' . \var_export($c['type'] ?? null, true)),
            };
        }
        $value = $result['structuredContent'] ?? null;
        if ([] === $parts && null !== $value) {
            $parts[] = new Text(\json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }

        return new Result($parts, $value, error: (bool) ($result['isError'] ?? false));
    }

    private static function bytes(string $base64): string
    {
        $bytes = \base64_decode($base64, true);

        return false === $bytes ? throw new McpException('The MCP server sent content that is not valid base64') : $bytes;
    }
}
