<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal where `old` (or a hunk's context) was found: a byte range of `content`, plus what reindenting `new` needs when the match was indentation-flexible (level 4; `fromIndent === toIndent` otherwise). */
final readonly class Located
{
    public function __construct(
        public int $level,
        public int $offset,
        public int $length,
        public int $startLine,
        public int $endLine,
        public string $text,
        public string $fromIndent = '',
        public string $toIndent = '',
        /** @var array<string, string> `old`'s relative indents (beyond `fromIndent`) to the matched block's (beyond `toIndent`) */
        public array $indentMap = [],
    ) {
    }
}
