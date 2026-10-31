<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal one level matched more than once; the cascade stops there, never falling through to a looser level. */
final readonly class Ambiguous
{
    /** @param non-empty-list<int> $lines the 1-based first line of each candidate */
    public function __construct(
        public int $level,
        public array $lines,
    ) {
    }
}
