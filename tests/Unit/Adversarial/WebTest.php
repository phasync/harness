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
