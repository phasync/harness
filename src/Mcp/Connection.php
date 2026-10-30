<?php

declare(strict_types=1);

namespace Harness\Mcp;

use Harness\Cancel;

/**
 * One way to reach an MCP server: `request()` sends a JSON-RPC request and returns its `result`,
 * throwing `McpException` for a JSON-RPC error. Notifications the server sends about the request
 * (progress) go to `$notify`. `StdioConnection` and `HttpConnection` implement it; `McpServer`
 * builds the params, including `_meta`.
 */
interface Connection
{
    /**
     * @param array<string, mixed>                        $params  with `_meta`
     * @param (\Closure(array<string, mixed>): void)|null $notify  receives each notification's message
     * @param array<string, string>                       $headers extra HTTP headers (`Mcp-Param-*`); stdio has none
     *
     * @return array<string, mixed> the result
     */
    public function request(string $method, array $params, Cancel $cancel, ?\Closure $notify = null, array $headers = []): array;
}
