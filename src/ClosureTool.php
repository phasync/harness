<?php

declare(strict_types=1);

namespace Harness;

/** A tool from a spec and a closure `fn (array $input, Call $call): Result`. */
final readonly class ClosureTool implements Tool
{
    public function __construct(private ToolSpec $spec, private \Closure $call)
    {
    }

    public function spec(): ToolSpec
    {
        return $this->spec;
    }

    public function call(array $input, Call $call): Result
    {
        return ($this->call)($input, $call);
    }
}
