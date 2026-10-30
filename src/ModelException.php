<?php

declare(strict_types=1);

namespace Harness;

/**
 * A model call failed. Providers throw it with the kind that fits (ModelError, ContextTooLong,
 * RateLimited, TransportError); the turn then ends `Reason::Failed` with that kind. `retryAfter` is
 * the server's hint in seconds, when it gave one.
 */
final class ModelException extends \RuntimeException
{
    public function __construct(
        public readonly ErrorKind $kind,
        string $message,
        public readonly ?float $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
