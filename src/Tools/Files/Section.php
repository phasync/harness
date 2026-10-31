<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal one `*** Add/Delete/Update File:` section of a parsed V4A patch. */
final readonly class Section
{
    /** @param list<Hunk> $hunks */
    public function __construct(
        public SectionKind $kind,
        public string $path,
        public ?string $content = null,
        public ?string $moveTo = null,
        public array $hunks = [],
    ) {
    }
}
