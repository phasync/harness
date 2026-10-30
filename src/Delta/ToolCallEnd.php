<?php

declare(strict_types=1);

namespace Harness\Delta;

use Harness\Delta;

/** A tool call's input is complete. */
final readonly class ToolCallEnd implements Delta
{
    public function __construct(public string $id)
    {
    }
}
