<?php

declare(strict_types=1);

namespace Harness\Delta;

use Harness\Delta;

/** A fragment of a tool call's JSON input. */
final readonly class ToolInputDelta implements Delta
{
    public function __construct(public string $id, public string $json)
    {
    }
}
