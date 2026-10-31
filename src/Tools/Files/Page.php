<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Internal\Utf8;

/** @internal One page of a listing, with a tail that says how to get the next page. */
final class Page
{
    /**
     * A file name as one line of a listing: a name holding a control character (a newline, say)
     * is shown as a JSON string literal, quoted and escaped, so it cannot forge another entry and
     * still names the file exactly; invalid UTF-8 becomes U+FFFD.
     */
    public static function name(string $name): string
    {
        $name = Utf8::scrub($name);
        if (1 === \preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return \json_encode($name, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        return $name;
    }

    /** @param list<string> $items */
    public static function render(array $items, int $offset, int $limit, string $noun, string $empty): string
    {
        if ([] === $items) {
            return $empty;
        }
        $page = \array_slice($items, $offset, $limit);
        if ([] === $page) {
            return 'There are ' . \count($items) . " $noun; offset $offset is past the end";
        }
        $text = \implode("\n", $page);
        $rest = \count($items) - $offset - \count($page);
        if ($rest > 0) {
            $text .= "\n[$rest more $noun; pass offset=" . ($offset + \count($page)) . ' to see them]';
        }

        return $text;
    }
}
