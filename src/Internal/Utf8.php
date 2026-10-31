<?php

declare(strict_types=1);

namespace Harness\Internal;

/** @internal text handed to a model must be valid UTF-8 and is measured in characters, not bytes */
final class Utf8
{
    private function __construct()
    {
    }

    /** `$text` with every byte sequence that is not valid UTF-8 replaced by U+FFFD (no mbstring needed). */
    public static function scrub(string $text): string
    {
        if (1 === \preg_match('//u', $text)) {
            return $text;
        }

        return \html_entity_decode(\htmlspecialchars($text, \ENT_SUBSTITUTE | \ENT_NOQUOTES, 'UTF-8'), \ENT_NOQUOTES | \ENT_HTML5, 'UTF-8');
    }

    /** The number of characters in valid UTF-8 `$text`. */
    public static function length(string $text): int
    {
        return \preg_match_all('/./su', $text);
    }

    /** Up to `$length` characters of valid UTF-8 `$text` from character `$offset`. */
    public static function slice(string $text, int $offset, int $length): string
    {
        return 1 === \preg_match('/^.{' . $offset . '}(.{0,' . $length . '})/su', $text, $m) ? $m[1] : '';
    }
}
