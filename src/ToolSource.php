<?php

declare(strict_types=1);

namespace Harness;

/**
 * Where a run gets its tools. Read at every step, in the order given (tool lists change, and
 * providers cache the prompt prefix, so a stable order matters). Names must be unique.
 */
interface ToolSource
{
    /** @return iterable<Tool> */
    public function tools(): iterable;
}
