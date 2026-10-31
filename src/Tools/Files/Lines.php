<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal renders lines numbered exactly as `read_file` does, for the region EditFile and ApplyPatch report. */
final class Lines
{
    /** @param list<string> $lines */
    public static function numbered(array $lines, int $first): string
    {
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = \str_pad((string) ($first + $i), 6, ' ', \STR_PAD_LEFT) . "\t" . $line;
        }

        return \implode("\n", $out);
    }

    /** The number of lines in `$content`; a trailing newline does not count as one more empty line. */
    public static function count(string $content): int
    {
        if ('' === $content) {
            return 0;
        }

        return \count(\explode("\n", \str_ends_with($content, "\n") ? \substr($content, 0, -1) : $content));
    }

    /** The region of `$content` from `$startLine` to `$endLine`, with 3 lines of context each side, numbered. */
    public static function region(string $content, int $startLine, int $endLine): string
    {
        $body  = \str_ends_with($content, "\n") ? \substr($content, 0, -1) : $content;
        $lines = \explode("\n", $body);
        $total = \count($lines);
        $from  = \max(1, $startLine - 3);
        $to    = \min($total, $endLine + 3);

        return self::numbered(\array_slice($lines, $from - 1, $to - $from + 1), $from);
    }
}
