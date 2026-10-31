<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal one `@@` hunk of a V4A patch's Update File section: its before/after text (context plus removed, context plus added) and the lines it adds and removes. */
final readonly class Hunk
{
    public function __construct(
        public string $before,
        public string $after,
        public int $added,
        public int $removed,
    ) {
    }
}
