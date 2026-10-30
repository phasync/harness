<?php

declare(strict_types=1);

namespace Harness;

use Harness\Part\ToolCall;
use Harness\Part\ToolResult;

/** A finished step, as stop predicates see it. */
final readonly class Step
{
    /**
     * @param list<ToolCall>   $calls   the tool calls of `message`
     * @param list<ToolResult> $results the results appended in this step
     */
    public function __construct(
        public int $n,
        public Message $message,
        public array $calls,
        public array $results,
        public Usage $usage,
        public Usage $turnUsage,
    ) {
    }
}
