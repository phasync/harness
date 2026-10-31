<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal nothing matched at any level; the closest region by similarity, for the model to correct `old` against. */
final readonly class NoMatch
{
    public function __construct(
        public int $startLine,
        public int $endLine,
        public string $text,
    ) {
    }
}
