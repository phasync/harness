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
        return \strlen(\preg_replace('/[\x80-\xBF]/', '', $text) ?? $text); // every byte but the continuation bytes starts a character
    }

    /** Up to `$length` characters of valid UTF-8 `$text` from character `$offset`. */
    public static function slice(string $text, int $offset, int $length): string
    {
        $start = self::byteOffset($text, 0, $offset);
        $end   = self::byteOffset($text, $start, $length);

        return \substr($text, $start, $end - $start);
    }

    /** The byte offset `$chars` characters past byte `$from`, or the end of `$text` if fewer remain. */
    private static function byteOffset(string $text, int $from, int $chars): int
    {
        for ($pos = $from; $chars > 0; $chars -= $n) { // in runs: PCRE refuses a {N} quantifier above 65535
            $n = \min($chars, 10000);
            if (1 !== \preg_match('/\G.{' . $n . '}/su', $text, $m, 0, $pos)) {
                return \strlen($text);
            }
            $pos += \strlen($m[0]);
        }

        return $pos;
    }
}
