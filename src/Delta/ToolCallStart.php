<?php

declare(strict_types=1);

namespace Harness\Delta;

use Harness\Delta;

/** The model began a tool call. Its input follows as `ToolInputDelta`s and it closes with `ToolCallEnd`. */
final readonly class ToolCallStart implements Delta
{
    public function __construct(public string $id, public string $name)
    {
    }
}
