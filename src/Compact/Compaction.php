<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * What `Compact` did to the prompt of a step: `removed` messages of the conversation were replaced
 * by a summary of `summary` bytes, `kept` messages after it went on verbatim, and the counter gave
 * `before` and `after` tokens. `reused` is true when the summary was an earlier step's.
 */
final readonly class Compaction
{
    public function __construct(
        public int $removed,
        public int $kept,
        public string $summary,
        public int $before,
        public int $after,
        public bool $reused,
    ) {
    }
}
