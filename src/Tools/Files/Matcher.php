<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/**
 * @internal the tolerant matching cascade shared by `EditFile` and `ApplyPatch`: exact, then
 * line-trimmed, whitespace-normalized, indentation-flexible and block-anchor, each accepted only
 * when it matches exactly once. A level that matches more than once stops the cascade there
 * (`Ambiguous`); one with no match at all falls through to the next. Exhausting every level up to
 * `$maxLevel` returns the closest region by similarity (`NoMatch`).
 */
final class Matcher
{
    public const LEVEL_EXACT                 = 1;
    public const LEVEL_LINE_TRIMMED          = 2;
    public const LEVEL_WHITESPACE_NORMALIZED = 3;
    public const LEVEL_INDENTATION_FLEXIBLE  = 4;
    public const LEVEL_BLOCK_ANCHOR          = 5;

    /** Human text for "matched with ... on lines A-B", keyed by level; level 1 (exact) needs none. */
    public const DESCRIPTIONS = [
        self::LEVEL_LINE_TRIMMED          => 'leading/trailing whitespace differences',
        self::LEVEL_WHITESPACE_NORMALIZED => 'whitespace differences',
        self::LEVEL_INDENTATION_FLEXIBLE  => 'different indentation',
        self::LEVEL_BLOCK_ANCHOR          => 'a block anchor match (the middle lines differ)',
    ];

    private function __construct()
    {
    }

    /** Locates `$old` in `$content`, trying levels 1..`$maxLevel` in order. */
    public static function locate(string $content, string $old, int $maxLevel = self::LEVEL_BLOCK_ANCHOR): Located|NoMatch|Ambiguous
    {
        $exact = self::exact($content, $old);
        if (1 === \count($exact)) {
            return self::located(self::LEVEL_EXACT, $content, $exact[0], \strlen($old));
        }
        if (\count($exact) > 1) {
            return new Ambiguous(self::LEVEL_EXACT, \array_map(static fn (int $at) => \substr_count($content, "\n", 0, $at) + 1, $exact));
        }

        $fileLines = \explode("\n", $content);
        $oldLines  = \explode("\n", $old);
        $n         = \count($oldLines);

        foreach ([self::LEVEL_LINE_TRIMMED, self::LEVEL_WHITESPACE_NORMALIZED, self::LEVEL_INDENTATION_FLEXIBLE, self::LEVEL_BLOCK_ANCHOR] as $level) {
            if ($level > $maxLevel || (self::LEVEL_BLOCK_ANCHOR === $level && $n < 3)) {
                continue;
            }
            $hits = self::search($fileLines, $oldLines, self::predicate($level));
            if (1 === \count($hits)) {
                $located = self::window($content, $fileLines, $hits[0], $n, $level);
                if (self::LEVEL_INDENTATION_FLEXIBLE === $level) {
                    $window         = \array_slice($fileLines, $hits[0], $n);
                    $from           = self::commonIndent($oldLines);
                    $to             = self::commonIndent($window);
                    $located        = new Located($level, $located->offset, $located->length, $located->startLine, $located->endLine, $located->text, $from, $to);
                }

                return $located;
            }
            if ([] !== $hits) {
                return new Ambiguous($level, \array_map(static fn (int $i) => $i + 1, $hits));
            }
        }

        return self::closest($fileLines, $oldLines);
    }

    /** `$new`, re-indented from `$fromIndent` to `$toIndent` (a no-op when they are equal). */
    public static function reindent(string $new, string $fromIndent, string $toIndent): string
    {
        if ($fromIndent === $toIndent) {
            return $new;
        }
        $lines = \explode("\n", $new);
        foreach ($lines as &$line) {
            if ('' === \trim($line)) {
                continue;
            }
            if (\str_starts_with($line, $fromIndent)) {
                $line = $toIndent . \substr($line, \strlen($fromIndent));
            }
        }

        return \implode("\n", $lines);
    }

    /** True when `$content` uses CRLF line endings. */
    public static function isCrlf(string $content): bool
    {
        return \str_contains($content, "\r\n");
    }

    /** `$text` with its line endings normalized to match `$content`'s style (LF or CRLF). */
    public static function withEol(string $text, string $content): string
    {
        $lf = \str_replace(["\r\n", "\r"], "\n", $text);

        return self::isCrlf($content) ? \str_replace("\n", "\r\n", $lf) : $lf;
    }

    /** @return list<int> byte offsets of every occurrence of `$old` in `$content` */
    private static function exact(string $content, string $old): array
    {
        $out = [];
        $at  = -1;
        while (false !== ($at = \strpos($content, $old, $at + 1))) {
            $out[] = $at;
        }

        return $out;
    }

    private static function located(int $level, string $content, int $offset, int $length): Located
    {
        $text      = \substr($content, $offset, $length);
        $startLine = \substr_count($content, "\n", 0, $offset) + 1;

        return new Located($level, $offset, $length, $startLine, $startLine + \substr_count($text, "\n"), $text);
    }

    /** A window of `$n` lines of `$fileLines` starting at `$i`, as a `Located` (byte range reconstructed by re-joining). */
    private static function window(string $content, array $fileLines, int $i, int $n, int $level): Located
    {
        $before = \implode("\n", \array_slice($fileLines, 0, $i));
        $offset = \strlen($before) + ($i > 0 ? 1 : 0);
        $text   = \implode("\n", \array_slice($fileLines, $i, $n));

        return new Located($level, $offset, \strlen($text), $i + 1, $i + $n, $text);
    }

    /**
     * @param list<string>                              $fileLines
     * @param list<string>                              $oldLines
     * @param \Closure(list<string>,list<string>): bool $predicate
     *
     * @return list<int> 0-based start indices of every window matching the predicate
     */
    private static function search(array $fileLines, array $oldLines, \Closure $predicate): array
    {
        $n   = \count($oldLines);
        $max = \count($fileLines) - $n;
        $out = [];
        for ($i = 0; $i <= $max; ++$i) {
            if ($predicate($oldLines, \array_slice($fileLines, $i, $n))) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /** @return \Closure(list<string>,list<string>): bool */
    private static function predicate(int $level): \Closure
    {
        return match ($level) {
            self::LEVEL_LINE_TRIMMED          => static fn (array $old, array $window): bool => \array_map('trim', $old) === \array_map('trim', $window),
            self::LEVEL_WHITESPACE_NORMALIZED => static fn (array $old, array $window): bool => \array_map(self::normalizeWhitespace(...), $old) === \array_map(self::normalizeWhitespace(...), $window),
            self::LEVEL_INDENTATION_FLEXIBLE  => static fn (array $old, array $window): bool => self::stripIndent($old) === self::stripIndent($window),
            self::LEVEL_BLOCK_ANCHOR          => static fn (array $old, array $window): bool => self::anchors($old, $window),
            default                           => throw new \LogicException("No predicate for match level $level"),
        };
    }

    private static function normalizeWhitespace(string $line): string
    {
        return \trim(\preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    /** @param list<string> $lines @return list<string> each line with its block's common leading indent stripped */
    private static function stripIndent(array $lines): array
    {
        $indent = self::commonIndent($lines);

        return \array_map(static fn (string $line): string => '' === \trim($line) ? '' : (\str_starts_with($line, $indent) ? \substr($line, \strlen($indent)) : \ltrim($line)), $lines);
    }

    /** @param list<string> $lines */
    private static function commonIndent(array $lines): string
    {
        $indents = [];
        foreach ($lines as $line) {
            if ('' === \trim($line)) {
                continue;
            }
            \preg_match('/^[ \t]*/', $line, $m);
            $indents[] = $m[0];
        }
        if ([] === $indents) {
            return '';
        }
        $common = $indents[0];
        foreach ($indents as $indent) {
            $max = \min(\strlen($common), \strlen($indent));
            $len = 0;
            while ($len < $max && $common[$len] === $indent[$len]) {
                ++$len;
            }
            $common = \substr($common, 0, $len);
        }

        return $common;
    }

    /** @param list<string> $old @param list<string> $window Block anchor: first and last lines match exactly (trimmed); the lines between must be >= 90% similar. */
    private static function anchors(array $old, array $window): bool
    {
        $last = \count($old) - 1;
        if (\trim($old[0]) !== \trim($window[0]) || \trim($old[$last]) !== \trim($window[$last])) {
            return false;
        }
        for ($i = 1; $i < $last; ++$i) {
            if (self::similarity($old[$i], $window[$i]) < 0.9) {
                return false;
            }
        }

        return true;
    }

    private static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $percent = 0.0;
        \similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /** @param list<string> $fileLines @param list<string> $oldLines the window of `$fileLines` most similar to `$oldLines`, for a "not found" error */
    private static function closest(array $fileLines, array $oldLines): NoMatch
    {
        $n     = \min(\count($oldLines), \count($fileLines));
        $n     = \max($n, 1);
        $max   = \max(\count($fileLines) - $n, 0);
        $best  = 0;
        $score = -1.0;
        for ($i = 0; $i <= $max; ++$i) {
            $window = \array_slice($fileLines, $i, $n);
            $total  = 0.0;
            foreach ($window as $k => $line) {
                $total += self::similarity($oldLines[$k] ?? '', $line);
            }
            $avg = $total / $n;
            if ($avg > $score) {
                $score = $avg;
                $best  = $i;
            }
        }
        $text = \implode("\n", \array_slice($fileLines, $best, $n));

        return new NoMatch($best + 1, $best + $n, $text);
    }
}
