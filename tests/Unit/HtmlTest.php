<?php

declare(strict_types=1);

use Harness\Tools\Web\Html;

it('keeps the title as the first line', function () {
    expect(Html::extract('<html><head><title>  A   Page  </title></head><body><p>Hi</p></body></html>'))
        ->toBe("A Page\n\nHi");
});

it('turns headings into # lines by level', function () {
    expect(Html::extract('<h1>One</h1><h3>Three</h3>'))->toBe("# One\n\n### Three");
});

it('separates paragraphs with a blank line and collapses whitespace', function () {
    expect(Html::extract("<p>First   paragraph\n  with   extra space.</p><p>Second.</p>"))
        ->toBe("First paragraph with extra space.\n\nSecond.");
});

it('renders list items with a dash, one per line', function () {
    expect(Html::extract('<ul><li>Alpha</li><li>Beta</li></ul>'))->toBe("- Alpha\n- Beta");
});

it('renders a link as "text (url)" only when the text differs from the url', function () {
    expect(Html::extract('<p>See <a href="https://example.com/x">the docs</a>.</p>'))
        ->toBe('See the docs (https://example.com/x).')
        ->and(Html::extract('<p>Visit <a href="https://example.com">https://example.com</a> now</p>'))
        ->toBe('Visit https://example.com now');
});

it('renders a table as one row per line, cells joined by " | "', function () {
    expect(Html::extract('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'))
        ->toBe("A | B\n1 | 2");
});

it('keeps pre content verbatim', function () {
    expect(Html::extract("<pre>  line one\n    line two  </pre>"))->toBe("  line one\n    line two  ");
});

it('drops script, style and comments entirely', function () {
    expect(Html::extract('<p>Before</p><script>alert(1)</script><style>.x{}</style><!-- hidden --><p>After</p>'))
        ->toBe("Before\n\nAfter");
});

it('decodes entities', function () {
    expect(Html::extract('<p>Caf&eacute; &amp; Bar &mdash; 5 &lt; 6</p>'))->toBe('Café & Bar — 5 < 6');
});

it('drops nav, footer, header and aside', function () {
    expect(Html::extract('<nav>Menu</nav><header>Head</header><p>Main</p><footer>Foot</footer><aside>Side</aside>'))
        ->toBe('Main');
});
