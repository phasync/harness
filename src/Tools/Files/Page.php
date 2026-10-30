<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/** @internal One page of a listing, with a tail that says how to get the next page. */
final class Page
{
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
