<?php

declare(strict_types=1);

namespace Harness\Mcp;

use Harness\Cancel;
use Harness\Http\Sse;
use Harness\Http\Transport;

/**
 * An MCP server at a Streamable HTTP endpoint, over `Http\Transport`: one POST per request with
 * `MCP-Protocol-Version`, `Mcp-Method` and, for `tools/call`, `resources/read` and `prompts/get`,
 * `Mcp-Name` (base64-wrapped when it is not plain ASCII), plus `$headers` (authorization). The
 * response is `application/json` or a `text/event-stream` carrying the request's notifications and
 * then its response. There are no sessions; the transport's `Cancel` handling ends a cancelled request.
 */
final readonly class HttpConnection implements Connection
{
    /** @param array<string, string> $headers sent with every request */
    public function __construct(private Transport $transport, private string $url, private array $headers = [])
    {
    }

    public function request(string $method, array $params, Cancel $cancel, ?\Closure $notify = null, array $headers = []): array
    {
        $id = \bin2hex(\random_bytes(8));
        if (null !== $notify) {
            $params['_meta']['progressToken'] = $id;
        }
        $headers += [
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? throw new \LogicException('MCP params need _meta[io.modelcontextprotocol/protocolVersion]'),
            'Mcp-Method'           => $method,
        ];
        $name = $params['name'] ?? $params['uri'] ?? null;
        if (\in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true)) {
            $headers['Mcp-Name'] = self::headerValue((string) $name);
        }
        $body     = \json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response = $this->transport->post($this->url, $headers + $this->headers, $body, $cancel);
        $type     = \strtolower($response->headers['content-type'] ?? '');
        if (\str_starts_with($type, 'text/event-stream')) {
            foreach (Sse::parse($response->body()) as $data) {
                $message = self::decode($method, $data);
                if (($message['id'] ?? null) === $id) {
                    return self::result($method, $message);
                }
                if (isset($message['method']) && !isset($message['id']) && null !== $notify) {
                    $notify($message);
                }
            }
            throw new McpException("MCP $method: the event stream ended without a response (HTTP {$response->status})");
        }
        $body = \implode('', [...$response->body()]);
        if (!\str_starts_with($type, 'application/json')) {
            throw new McpException("MCP $method: HTTP {$response->status} with '" . ($response->headers['content-type'] ?? 'no content type') . "': " . \substr($body, 0, 500));
        }

        return self::result($method, self::decode($method, $body));
    }

    /**
     * A header value as is when it is plain ASCII, else in the `=?base64?...?=` form.
     */
    public static function headerValue(string $value): string
    {
        $plain = 1 === \preg_match('/^[\x21-\x7E]([\x20-\x7E\t]*[\x21-\x7E])?$/', $value)
            && !(\str_starts_with($value, '=?base64?') && \str_ends_with($value, '?='));

        return $plain ? $value : '=?base64?' . \base64_encode($value) . '?=';
    }

    /** @return array<string, mixed> */
    private static function decode(string $method, string $json): array
    {
        try {
            $message = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpException("MCP $method: the server sent something that is not JSON: " . \substr($json, 0, 500), previous: $e);
        }

        return \is_array($message) ? $message : throw new McpException("MCP $method: the server sent JSON that is not a message");
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function result(string $method, array $message): array
    {
        if (isset($message['error'])) {
            throw McpException::fromError($method, $message['error']);
        }

        return $message['result'] ?? throw new McpException("MCP $method: the response has neither result nor error");
    }
}
