<?php

declare(strict_types=1);

use function fiber\go;
use function fiber\readable;
use function fiber\writable;
use Harness\Cancel;
use Harness\Http\Response;
use Harness\Http\StreamTransport;
use Harness\Http\TransportException;
use Harness\Testing\FakeTransport;
use Harness\Tools\Web\Html;
use Harness\Tools\Web\WebFetch;

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

/*
 * StreamTransport::body() (Http/StreamTransport.php) validates a chunked body's chunk-size line
 * strictly -- `preg_match('/^[0-9a-fA-F]{1,15}$/', $hex)`, throwing TransportException on anything
 * that doesn't match -- but the content-length path has no such check: `$remaining = (int)
 * $headers['content-length'];` casts whatever the server sent, and `while ($remaining > 0)` just
 * never runs for a negative (or non-numeric, or empty) value. The generator falls straight to its
 * `finally { fclose($socket); }` and returns with no chunks yielded and no error -- a response
 * whose server actually sent a body is silently turned into an empty one instead of a
 * TransportException naming the malformed header, which is what every other malformed-metadata
 * case in this file does (malformed chunk size, malformed status line, malformed header line).
 */
it('rejects a negative Content-Length instead of silently returning an empty body', function () {
    // Today: no exception is thrown -- [...$r->body()] comes back as [] and the 'hello' the
    // server actually sent is silently lost, instead of a TransportException naming the header
    // the way a malformed chunk size or status line does elsewhere in this class.
    expect(function () {
        \fiber\run(function () {
            $server = \stream_socket_server('tcp://127.0.0.1:0');
            $port   = (int) \substr(\strrchr(\stream_socket_get_name($server, false), ':'), 1);
            go(function () use ($server) {
                $conn    = \stream_socket_accept(readable($server), 0);
                $request = '';
                while (!\str_contains($request, "\r\n\r\n")) {
                    $request .= \fread(readable($conn), 65536);
                }
                \fwrite(writable($conn), "HTTP/1.1 200 OK\r\nContent-Length: -1\r\n\r\nhello");
                \fclose($conn);
                \fclose($server);
            });
            $r = (new StreamTransport())->get("http://127.0.0.1:$port/", [], new Cancel(static fn () => false));
            [...$r->body()];
        });
    })->toThrow(TransportException::class, 'Content-Length');
}); // #19: this returned status 200 with an empty body and no error

/*
 * WebFetch's ToolSpec calls `offset` a "character offset", and its class docblock says the text
 * "is cut at maxChars ... per call with the files tools' tail idiom". WebFetch::tail() (WebFetch.php)
 * implements that cut with `\strlen($text)` / `\substr($text, $offset, $maxChars)` -- both of
 * which count and slice BYTES, not characters. For any body with a multi-byte UTF-8 character
 * (accented letters, an em dash, CJK, emoji -- i.e. most non-English or typographically normal
 * text, since HTML entities are decoded to UTF-8 before this runs), a maxChars/offset boundary
 * that lands inside a multi-byte sequence splits it, handing the model invalid UTF-8 glued to a
 * "[N more characters]" note whose count is also in bytes, not characters.
 */
it('cuts maxChars by characters, not bytes, so it never splits a multi-byte character', function () {
    // "café" is 4 characters but 5 bytes in UTF-8 ('é' is 0xC3 0xA9, two bytes). maxChars: 4 is a
    // request for the whole word -- there is nothing left to split or to continue from.
    $transport = new FakeTransport(new Response(200, ['content-type' => 'text/plain'], ['café']));
    $fetch     = new WebFetch($transport, 'agent', maxChars: 4);
    $text      = $fetch->call(['url' => 'https://example.com/a.txt'], callFor())->content[0]->text;

    // Today: the byte-based cut lands after 4 bytes, i.e. inside 'é', and tail() appends a
    // continuation note straight onto the dangling first byte -- the result is not even valid
    // UTF-8, let alone the complete 4-character word the maxChars value asked for.
    expect($text)->toBe('café');
})->group('bug');
