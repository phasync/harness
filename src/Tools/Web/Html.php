<?php

declare(strict_types=1);

namespace Harness\Tools\Web;

/**
 * @internal the HTML-to-text extraction behind `WebFetch`; not a stable API
 *
 * `extract()` turns a page into readable text with no dependency beyond ext-dom: script, style,
 * noscript, svg, nav, footer, header, aside, form and comments are dropped; the first `<title>`
 * outside them becomes the first line; headings become `"# "`.."######" lines by level; paragraphs and blockquotes are
 * separated by a blank line; `li` becomes a `"- "` line (a list is one block, its items one per
 * line, a nested list's items on their own lines indented two spaces under the parent item); `a` becomes `"text (url)"`
 * only when the link text differs from the URL; `pre` is kept verbatim; a table becomes one row
 * per line, its cells joined with `" | "`; everything else has its whitespace collapsed to single
 * spaces and entities decoded (both by the DOM parser).
 */
final class Html
{
    private const DROP = ['script', 'style', 'noscript', 'svg', 'nav', 'footer', 'header', 'aside', 'form'];

    public static function extract(string $html): string
    {
        $dom      = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, \LIBXML_NOERROR | \LIBXML_NOWARNING);
        \libxml_use_internal_errors($previous);

        $lines = [];
        foreach ($dom->getElementsByTagName('title') as $title) { // the first title outside the dropped elements: an svg icon has its own
            if (!self::dropped($title)) {
                $text = self::collapse($title->textContent);
                if ('' !== $text) {
                    $lines[] = $text;
                }
                break;
            }
        }
        $root = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        if (null !== $root) {
            $body = \str_replace("\0i", '  ', \implode("\n\n", self::blocks($root)));
            if ('' !== $body) {
                $lines[] = $body;
            }
        }

        return \implode("\n\n", $lines);
    }

    /** Whether an ancestor is one of the dropped elements. */
    private static function dropped(\DOMNode $node): bool
    {
        for ($up = $node->parentNode; $up instanceof \DOMElement; $up = $up->parentNode) {
            if (\in_array(\strtolower($up->tagName), self::DROP, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> one entry per block-level element, text already collapsed */
    private static function blocks(\DOMNode $node): array
    {
        $blocks = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                self::add($blocks, self::collapse($child->textContent));
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = \strtolower($child->tagName);
            if (\in_array($tag, self::DROP, true)) {
                continue;
            }
            if (1 === \preg_match('/^h([1-6])$/', $tag, $m)) {
                self::add($blocks, \str_repeat('#', (int) $m[1]) . ' ' . self::inline($child));
            } elseif ('p' === $tag || 'blockquote' === $tag) {
                self::add($blocks, self::inline($child));
            } elseif ('li' === $tag) {
                self::add($blocks, '- ' . self::inline($child));
            } elseif ('ul' === $tag || 'ol' === $tag) {
                self::add($blocks, \implode("\n", self::blocks($child)));
            } elseif ('pre' === $tag) {
                self::add($blocks, \rtrim($child->textContent, "\n"));
            } elseif ('table' === $tag) {
                self::add($blocks, self::table($child));
            } elseif ('br' !== $tag) {
                foreach (self::blocks($child) as $b) {
                    self::add($blocks, $b);
                }
            }
        }

        return $blocks;
    }

    /** @param list<string> $blocks */
    private static function add(array &$blocks, string $text): void
    {
        if ('' !== $text) {
            $blocks[] = $text;
        }
    }

    /** One line of flowed text: `a` becomes `"text (url)"`, `code` stays verbatim, everything else recurses. */
    private static function inline(\DOMNode $node): string
    {
        $out      = '';
        $verbatim = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= $child->textContent;
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = \strtolower($child->tagName);
            if (\in_array($tag, self::DROP, true)) {
                continue;
            }
            if ('br' === $tag) {
                $out .= "\0n"; // a line break, see below
            } elseif ('a' === $tag) {
                $text = self::collapse(self::inline($child));
                $href = $child->getAttribute('href');
                $out .= ('' === $href || $text === $href) ? $text : "$text ($href)";
            } elseif ('code' === $tag) {
                $out .= $child->textContent;
            } elseif ('ul' === $tag || 'ol' === $tag) {
                foreach (self::blocks($child) as $item) { // a nested list: its items on their own lines, indented
                    $out .= "\0n\0i" . \str_replace("\n", "\0n\0i", $item);
                }
                $out .= "\0n";
            } elseif ('pre' === $tag) { // kept verbatim: spliced back in after the collapse, on its own lines
                $verbatim[] = \rtrim($child->textContent, "\n");
                $out .= "\0n\0v\0n";
            } else {
                $out .= self::inline($child);
            }
        }

        // "\0n" is a line break, "\0i" one level of list indentation and "\0v" a verbatim block: a
        // NUL cannot come from the page (libxml drops it), and all three survive the whitespace
        // collapse; extract() turns the indentation into spaces once the nesting is complete
        $text = \preg_replace('/ ?\x00n ?/', "\n", self::collapse($out)) ?? '';

        return \trim(\preg_replace_callback('/\x00v/', static fn (): string => \array_shift($verbatim) ?? '', $text) ?? '');
    }

    /** One line per `tr`, its `td`/`th` cells joined with `" | "`. */
    private static function table(\DOMElement $table): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && \in_array(\strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = self::inline($cell);
                }
            }
            if ([] !== $cells) {
                $rows[] = \implode(' | ', $cells);
            }
        }

        return \implode("\n", $rows);
    }

    private static function collapse(string $text): string
    {
        return \trim(\preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
