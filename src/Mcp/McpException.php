<?php

declare(strict_types=1);

namespace Harness\Mcp;

/**
 * An MCP server answered with a JSON-RPC error (`code`, `data`), broke the protocol, or could not
 * be reached. In a tool call it becomes an error result for the model.
 */
final class McpException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $rpcCode = null, public readonly mixed $data = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string, mixed> $error a JSON-RPC error object */
    public static function fromError(string $method, array $error): self
    {
        return new self("MCP $method failed ({$error['code']}): {$error['message']}", (int) $error['code'], $error['data'] ?? null);
    }
}
