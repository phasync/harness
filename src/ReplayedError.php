<?php

declare(strict_types=1);

namespace Harness;

/**
 * The Throwable of a `ToolEnded` event rebuilt by `Events::fromArray()`: the original `class` name
 * and message, since a serialized event keeps no more. It serializes back to the same array.
 */
final class ReplayedError extends \RuntimeException
{
    public function __construct(public readonly string $class, string $message)
    {
        parent::__construct($message);
    }
}
