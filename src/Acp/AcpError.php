<?php

declare(strict_types=1);

namespace Harness\Acp;

/**
 * A JSON-RPC error: a handler throws it to answer a request with `{code, message}`, and
 * `Channel::request()` throws it when the client answers with one or the connection closes.
 */
final class AcpError extends \RuntimeException
{
    public function __construct(int $code, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
