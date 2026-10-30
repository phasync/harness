<?php

declare(strict_types=1);

namespace Harness;

/** A fixed list of tools. */
final readonly class Tools implements ToolSource
{
    /** @var list<Tool> */
    private array $tools;

    public function __construct(Tool ...$tools)
    {
        $this->tools = \array_values($tools);
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
