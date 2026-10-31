<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal parses the V4A patch format (`*** Begin Patch` ... `*** End Patch`) into `Section`s; never touches disk. */
final class PatchParser
{
    private function __construct()
    {
    }

    /** @return list<Section> */
    public static function parse(string $patch): array
    {
        $lines = \array_map(static fn (string $l): string => \rtrim($l, "\r"), \explode("\n", $patch));
        $n     = \count($lines);
        if ($n < 1 || '*** Begin Patch' !== \trim($lines[0])) {
            throw new \RuntimeException("apply_patch: expected '*** Begin Patch' at line 1");
        }

        $sections = [];
        $i        = 1;
        $ended    = false;
        while ($i < $n) {
            $line = $lines[$i];
            if ('*** End Patch' === \trim($line)) {
                $ended = true;
                ++$i;
                break;
            }
            if ('' === \trim($line)) {
                ++$i;
                continue;
            }
            if (1 === \preg_match('/^\*\*\* Add File: (.+)$/', $line, $m)) {
                [$section, $i] = self::parseAdd($lines, $n, $i + 1, \trim($m[1]));
            } elseif (1 === \preg_match('/^\*\*\* Delete File: (.+)$/', $line, $m)) {
                $section = new Section(SectionKind::Delete, \trim($m[1]));
                ++$i;
            } elseif (1 === \preg_match('/^\*\*\* Update File: (.+)$/', $line, $m)) {
                [$section, $i] = self::parseUpdate($lines, $n, $i + 1, \trim($m[1]));
            } else {
                throw new \RuntimeException('apply_patch: unexpected line ' . ($i + 1) . ": '$line'");
            }
            $sections[] = $section;
        }
        if (!$ended) {
            throw new \RuntimeException("apply_patch: expected '*** End Patch' at line $n");
        }
        if ([] === $sections) {
            throw new \RuntimeException('apply_patch: the patch has no Add, Delete or Update section');
        }

        return $sections;
    }

    /** @param list<string> $lines @return array{0: Section, 1: int} */
    private static function parseAdd(array $lines, int $n, int $i, string $path): array
    {
        $content = [];
        while ($i < $n && !self::isMarker($lines[$i])) {
            $line = $lines[$i];
            if (!\str_starts_with($line, '+')) {
                throw new \RuntimeException('apply_patch: line ' . ($i + 1) . " of 'Add File: $path' must start with '+', got: $line");
            }
            $content[] = \substr($line, 1);
            ++$i;
        }

        return [new Section(SectionKind::Add, $path, content: \implode("\n", $content)), $i];
    }

    /** @param list<string> $lines @return array{0: Section, 1: int} */
    private static function parseUpdate(array $lines, int $n, int $i, string $path): array
    {
        $moveTo = null;
        if ($i < $n && 1 === \preg_match('/^\*\*\* Move to: (.+)$/', $lines[$i], $m)) {
            $moveTo = \trim($m[1]);
            ++$i;
        }
        $hunks = [];
        while ($i < $n && !self::isMarker($lines[$i])) {
            if ('' === \trim($lines[$i]) || '*** End of File' === \trim($lines[$i])) {
                ++$i;
                continue;
            }
            if (!\str_starts_with($lines[$i], '@@')) {
                throw new \RuntimeException('apply_patch: line ' . ($i + 1) . " of 'Update File: $path' must start a hunk with '@@', got: {$lines[$i]}");
            }
            ++$i;
            [$hunk, $i] = self::parseHunk($lines, $n, $i, $path);
            $hunks[]    = $hunk;
        }
        if ([] === $hunks) {
            throw new \RuntimeException("apply_patch: 'Update File: $path' has no hunks");
        }

        return [new Section(SectionKind::Update, $path, moveTo: $moveTo, hunks: $hunks), $i];
    }

    /** @param list<string> $lines @return array{0: Hunk, 1: int} */
    private static function parseHunk(array $lines, int $n, int $i, string $path): array
    {
        $before  = [];
        $after   = [];
        $added   = 0;
        $removed = 0;
        while ($i < $n && !\str_starts_with($lines[$i], '@@') && !self::isMarker($lines[$i]) && '*** End of File' !== \trim($lines[$i])) {
            $line = $lines[$i];
            if ('' === $line) {
                $before[] = '';
                $after[]  = '';
                ++$i;
                continue;
            }
            $prefix = $line[0];
            $rest   = \substr($line, 1);
            if (' ' === $prefix) {
                $before[] = $rest;
                $after[]  = $rest;
            } elseif ('-' === $prefix) {
                $before[] = $rest;
                ++$removed;
            } elseif ('+' === $prefix) {
                $after[] = $rest;
                ++$added;
            } else {
                throw new \RuntimeException('apply_patch: hunk line ' . ($i + 1) . " of '$path' must start with ' ', '-' or '+', got: $line");
            }
            ++$i;
        }
        if ($i < $n && '*** End of File' === \trim($lines[$i])) {
            ++$i;
        }

        return [new Hunk(\implode("\n", $before), \implode("\n", $after), $added, $removed), $i];
    }

    private static function isMarker(string $line): bool
    {
        $t = \trim($line);

        return \str_starts_with($t, '*** Add File:') || \str_starts_with($t, '*** Delete File:') || \str_starts_with($t, '*** Update File:') || '*** End Patch' === $t;
    }
}
