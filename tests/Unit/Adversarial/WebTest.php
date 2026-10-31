<?php

declare(strict_types=1);

use Harness\Tools\Web\Html;

/*
 * Html::extract()'s docblock promises, flatly, that "`pre` is kept verbatim" -- no exception is
 * carved out for a <pre> that isn't a direct block-level child. HtmlTest.php only checks a
 * top-level <pre>, where blocks() handles it directly with rtrim($child->textContent, "\n"),
 * untouched by collapse().
 *
 * But a <pre> reached while flowing an <li> (also a <p>, <blockquote> or table cell) is walked by
 * inline(), not blocks(). inline() has explicit cases for 'br', 'a', 'code', 'ul'/'ol' and falls
 * back to `$out .= self::inline($child)` for anything else, including 'pre' -- so the pre's text
 * is merged into the same string that inline()'s final `return` passes through collapse(), which
 * does `preg_replace('/\s+/u', ' ', ...)`. Every blank line and indentation inside the <pre> is
 * flattened to single spaces, breaking the "verbatim" promise the moment a <pre> sits inside a
 * list item.
 */
it('keeps pre content verbatim even when nested inside a list item', function () {
    $html = "<ul><li>Item with code: <pre>line1\n   line2 (indent kept)\nline3</pre></li></ul>";

    // #10: this collapsed to "- Item with code: line1 line2 (indent kept) line3"; a pre is a block,
    // so it starts on its own line and its whitespace is kept
    expect(Html::extract($html))
        ->toBe("- Item with code:\nline1\n   line2 (indent kept)\nline3");
});

/*
 * inline() uses three Unicode Private Use Area code points -- U+E000 (line break), U+E001 (list
 * indent) and U+E002 (a slot where a verbatim <pre> gets spliced back in after collapse()) -- as
 * internal sentinels, on the assumption that real page text never contains them. That assumption
 * is false: PUA code points are exactly what icon fonts (old Font Awesome, many admin themes) put
 * into ordinary text nodes as glyphs, e.g. `<i class="icon">&#xE002;</i>`. collapse()'s
 * `preg_replace('/\s+/u', ' ', ...)` does not touch them (they are not whitespace), so a literal
 * U+E002 in the source text survives into $out and is then indistinguishable from a sentinel: the
 * closing `preg_replace_callback('/\x{E002}/u', ...)` in inline() (Html.php:138) shifts it off
 * $verbatim regardless of where it came from. With no real <pre> in scope, $verbatim is empty and
 * `array_shift($verbatim) ?? ''` silently deletes the character -- ordinary page text vanishes,
 * which nothing in the class's docblock allows (only whitespace is said to collapse; entities are
 * said to decode, not disappear).
 */
it('does not swallow a literal Private Use Area character from the page text', function () {
    $html = "<p>Hello \u{E002} World</p>";

    // #15: this came out as "Hello  World", the character taken for the extractor's own verbatim
    // sentinel; the sentinels are NUL-based now, which no page text can contain
    expect(Html::extract($html))->toBe("Hello \u{E002} World");
});
