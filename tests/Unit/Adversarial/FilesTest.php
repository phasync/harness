<?php

declare(strict_types=1);

use Harness\Tools\Files\ApplyPatch;
use Harness\Tools\Files\EditFile;
use Harness\Tools\Files\Grep;
use Harness\Tools\Files\ListDir;
use Harness\Tools\Files\ReadFile;
use Harness\Tools\Files\Root;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-files-adversarial-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir);
    $this->root = new Root($this->dir);
});

afterEach(function () {
    \exec('rm -rf ' . \escapeshellarg($this->dir));
});

/*
 * docs/files.md promises the tolerant cascade handles "a block pasted at the wrong depth" by
 * re-indenting `new` by the delta between `old`'s common indent and the matched block's common
 * indent, and that this re-indentation runs for every tolerant level, not only the
 * indentation-flexible one. But `Matcher::reindent()` only strips/replaces each line's *common*
 * leading prefix (`fromIndent`) with the new one -- it never touches what comes after that prefix.
 *
 * When `old` is written with a different indent *character* than the file (spaces vs the file's
 * tabs), and the block has a nested line (indented deeper than the common prefix), the nested
 * line's extra indentation in `new` keeps `old`'s character (spaces) while the common prefix is
 * swapped to the file's character (a tab). The file ends up with a line that mixes a tab and
 * spaces for one indentation level -- a style the file never had and the edit was supposed to
 * preserve. This reproduces with a real tab-indented file and a 4-space `old`/`new`: the match
 * happens through the line-trimmed level (exact and indentation-flexible both reject it, since the
 * nested line's nested indent differs in character, not just in depth), and `reindent()` still
 * runs on the found delta.
 */
it('keeps the file\'s own tab indentation on a nested line instead of mixing it with old\'s spaces', function () {
    \file_put_contents($this->dir . '/a.php', "function f() {\n\tif (true) {\n\t\tbar();\n\t}\n}\n");
    $e = new EditFile($this->root);
    $e->call(['path' => 'a.php', 'old' => "    if (true) {\n        bar();\n    }", 'new' => "    if (true) {\n        baz();\n    }"], callFor());

    // #13: the nested line came out as "\t    baz();" (a tab plus old's four spaces); the relative
    // indentation is now translated line by line to the block's own style
    expect(\file_get_contents($this->dir . '/a.php'))->toBe("function f() {\n\tif (true) {\n\t\tbaz();\n\t}\n}\n");
});

/*
 * Round 2, attacking `Matcher::window()`'s new indentMap (post-#13 fix). The map is built by
 * `$map[$o[0]] ??= $w[0]` -- keyed by `old`'s *relative indent string*, first occurrence wins. A
 * single matched block can legitimately contain the same relative indent string in `old` twice
 * while the file's own indentation for those two spots is NOT the same (the file itself is
 * inconsistently indented, e.g. one nested body one tab deeper than a sibling one). The map has no
 * way to record two different translations for the same key, so every occurrence after the first
 * silently gets the FIRST one's translation instead of its own -- `reindent()` is keyed by string
 * content, not by line position, breaking the "translated line by line" promise in Matcher's
 * docblock whenever a block isn't self-consistently indented.
 *
 * Concretely: a file has two sibling `if` blocks, the first body one tab deeper than the `if`, the
 * second body two tabs deeper (the file's own, pre-existing inconsistency -- nothing to do with the
 * edit). `old`/`new` describe both blocks with a uniform 2-space relative indent (as a model would,
 * having no reason to notice the file's inconsistency). The first block's "  " maps correctly to
 * "\t" from the first sibling; the second sibling's "  " is the *same map key*, so it also gets
 * translated to "\t" -- one tab short of the "\t\t" the file actually had there. The edit silently
 * de-indents a line it was never asked to touch structurally.
 */
it('translates each occurrence of a relative indent by its own position, not the first one seen', function () {
    // second if's body is one tab deeper than the first if's body -- the file's own inconsistency
    \file_put_contents($this->dir . '/a.php', "function f() {\n\tif (a) {\n\t\tone();\n\t}\n\tif (b) {\n\t\t\ttwo();\n\t}\n}\n");
    $e   = new EditFile($this->root);
    $old = "if (a) {\n  one();\n}\nif (b) {\n  two();\n}";
    $new = "if (a) {\n  one();\n}\nif (b) {\n  TWO();\n}";
    $e->call(['path' => 'a.php', 'old' => $old, 'new' => $new], callFor());

    // #13, round 2: the second body came out as "\t\tTWO();", the first body's style; a line of new
    // at the position of a line of old with the same relative indent now keeps that file line's own
    expect(\file_get_contents($this->dir . '/a.php'))->toBe("function f() {\n\tif (a) {\n\t\tone();\n\t}\n\tif (b) {\n\t\t\tTWO();\n\t}\n}\n");
});

/*
 * Round 3. `Grep::call()` splits a file's whole content on "\n" (`\explode("\n", $content)`)
 * without stripping a trailing newline first. `read_file` does strip it before splitting -- its
 * own docblock (`Lines::count()`) says explicitly "a trailing newline does not count as one more
 * empty line" -- so a normal file ending in "\n" has one more element in Grep's line array than
 * `read_file` reports as its line count: a phantom, nonexistent last "line" that is always empty.
 * A pattern that can match an empty string (an ordinary way to find blank lines, "^$") matches that
 * phantom line too, so `grep` reports a `file:line:` the model can never find by reading the file.
 */
it('does not report a match on the phantom line past a file\'s trailing newline', function () {
    \file_put_contents($this->dir . '/f.txt', "alpha\n\nbeta\n");
    $g      = new Grep($this->root);
    $result = $g->call(['pattern' => '^$', 'path' => 'f.txt'], callFor());

    // BUG: today this is "f.txt:2: \nf.txt:4: " -- a second match on line 4, which does not exist;
    // the file has 3 lines (alpha, "", beta), as read_file's own line count agrees.
    // #22: this also reported f.txt:4 on a three-line file
    expect($result->content[0]->text)->toBe('f.txt:2: ');
});

/*
 * Round 4. ApplyPatch's docblock promises the patch "is built in memory first", so a failure
 * "[leaves] the directory untouched" -- but that's only true for a failure caught during
 * *planning* (an unlocatable or ambiguous hunk). `call()`'s execution loop writes every planned
 * file in a single `foreach`, in file order, with nothing to undo an earlier iteration's write
 * when a later one throws. A later `Add File` section whose parent path component is an existing
 * regular file (not a directory) only fails once its own `mkdir()` is reached -- by which point an
 * earlier `Add File` in the very same patch call has already landed on disk. One file in the
 * patch is left applied while the call as a whole reports failure to the model.
 */
it('leaves no file written when a later section in the same patch fails to create its directory', function () {
    \file_put_contents($this->dir . '/blocked', 'not a directory');
    $patch = new ApplyPatch($this->root);
    $p     = "*** Begin Patch\n*** Add File: ok.txt\n+hello\n*** Add File: blocked/x.txt\n+world\n*** End Patch";

    expect(fn () => $patch->call(['patch' => $p], callFor()))->toThrow(RuntimeException::class);

    // BUG: today 'ok.txt' already exists on disk -- an earlier section's write survives a later
    // section's failure, contradicting "leaving the directory untouched" when the call throws.
    expect(\is_file($this->dir . '/ok.txt'))->toBeFalse();
}); // #26: ok.txt was written before the second section's mkdir failed

/*
 * Round 5. `ReadFile`'s long-line cut (like `Grep`'s) is a raw byte-offset `substr()` with no
 * UTF-8 awareness and no scrub afterwards -- unlike `Shell`, which already does this exact fix
 * ("binary output and a character split by the cut become U+FFFD" via `Utf8::scrub()`). A
 * multi-byte UTF-8 character straddling `maxLineLength` is cut in half, leaving an invalid
 * trailing byte sequence in the text handed to the model. `read_file` is the model's main way to
 * see file content, and every provider encodes tool results with `JSON_THROW_ON_ERROR`
 * (`Anthropic\Messages`, `OpenAI\ChatCompletions`, `OpenAI\Responses`), so the very next turn that
 * carries this tool result as history throws a `JsonException` and the whole run dies -- exactly
 * the failure mode `Shell` was already fixed against (#25), just not here.
 */
it('produces valid UTF-8 when the long-line cut falls inside a multi-byte character', function () {
    $line = \str_repeat('a', 10) . "\u{20AC}" . 'more text after the euro sign to pad the line out';
    \file_put_contents($this->dir . '/f.txt', $line . "\n");
    $r    = (new ReadFile($this->root, maxLineLength: 11))->call(['path' => 'f.txt'], callFor());
    $text = $r->content[0]->text;

    // BUG: today this is invalid UTF-8 (the cut lands inside the euro sign's 3-byte sequence),
    // which fails json_encode(..., JSON_THROW_ON_ERROR) exactly where every provider call uses it.
    expect(1 === \preg_match('//u', $text))->toBeTrue();
}); // #30: the cut split the euro sign; lines are cut by characters now and scrubbed

/*
 * Round 6. `read_file` refuses a binary file outright ("'$path' is a binary file (N bytes)"), and
 * `grep`/`glob` skip binary files and say how many -- docs/files.md documents binary handling only
 * for those two. `edit_file` has no such check at all: when `old` doesn't match, `Matcher::locate`
 * falls through to `closest()`, and the "not found" error embeds that closest region's raw bytes
 * via `Lines::numbered`. For a binary file those bytes are typically invalid UTF-8, so the
 * RuntimeException's message is invalid UTF-8 -- the very failure mode `read_file` and `grep` were
 * just fixed against in #30, now reachable through `edit_file`'s error path on any binary file. The
 * ReadFile precedent is the harness's own answer for what a Files tool does with a binary file: it
 * refuses, clearly, before touching the content -- `edit_file` should do the same instead of
 * dumping bytes into the model-facing error text.
 */
it('refuses a binary file instead of dumping its raw bytes into the not-found error', function () {
    $binary = "PK\x03\x04\x00\x00\x00\x00binary\x00data\xFF\xFEmore\x80\x81stuff\n";
    \file_put_contents($this->dir . '/bin.dat', $binary);
    $e = new EditFile($this->root);

    // BUG: today this throws with the closest region's raw bytes embedded in the message (invalid
    // UTF-8, same crash class #30 fixed for read_file/grep), not a clear "binary file" refusal.
    expect(fn () => $e->call(['path' => 'bin.dat', 'old' => 'nonexistent text xyz', 'new' => 'replacement'], callFor()))
        ->toThrow(RuntimeException::class, 'binary file');
}); // #33: the closest-region error was made of the file's raw bytes

/*
 * Round 7. Preferring the sharper finding the coordinator invited: `list_dir` (and `glob`, `grep`)
 * join entries with `implode("\n", ...)` straight from `scandir()`/`Glob::files()`, with no check
 * that a name is itself free of a newline -- Unix filenames may contain one (only `/` and NUL are
 * forbidden). A single file named "evil.txt\nfake.php" lists as *two* lines, "evil.txt" and
 * "fake.php", indistinguishable from two real top-level files; the model would reasonably believe
 * "fake.php" exists and could `read_file`/`write_file` it, acting on a forged entry. This is the
 * exact defect class #34 already fixed for `web_search` (a hit's title with a newline forged an
 * extra numbered result) -- that fix's remedy (collapse whitespace runs to one space before
 * joining into the line-based listing) is the project's own established answer for this class, not
 * yet applied here.
 */
it('does not let a filename\'s embedded newline forge an extra directory entry', function () {
    \file_put_contents($this->dir . '/' . "evil.txt\nfake.php", 'x');
    \file_put_contents($this->dir . '/real.txt', 'y');
    $r = (new ListDir($this->root))->call([], callFor());

    expect(\explode("\n", $r->content[0]->text))->toBe(['"evil.txt\\nfake.php"', 'real.txt']);
}); // #38: three lines, as if "fake.php" were its own file; now the name is one JSON string literal

/*
 * Round 8, attacking the #38 fix. docs/files.md now says, of a name holding a control character:
 * "listed as a JSON string literal... pass that string back as the `path`." That is an explicit
 * instruction to the model to copy the exact quoted text `list_dir`/`glob`/`grep` showed it --
 * quotes and escapes included -- into `path`. `Root::resolve()` (and every tool built on it) does
 * no JSON-decoding at all: it treats the quote marks and the literal backslash-n as ordinary path
 * characters, so the one string the docs say to pass back is the one string that cannot resolve to
 * the file it names. Only the *decoded* name (with a real embedded newline) works -- the opposite
 * of what a model following the docs verbatim would send -- and the resulting "does not exist"
 * error gives no hint that the text needs unquoting.
 */
it('accepts the exact JSON-quoted name list_dir told it to pass back as the path', function () {
    \file_put_contents($this->dir . '/' . "evil.txt\nfake.php", "secret\n");
    $shown = \trim((new ListDir($this->root))->call([], callFor())->content[0]->text);
    expect($shown)->toBe('"evil.txt\nfake.php"'); // docs/files.md: "pass that string back as the path"

    $result = (new ReadFile($this->root))->call(['path' => $shown], callFor());
    expect($result->content[0]->text)->toContain('secret');
}); // #46: the literal the docs said to pass back was "does not exist"

/*
 * Round 9, attacking the #46 fix from the other side. `Root::resolve()` now treats any `path`
 * that starts and ends with `"` as a candidate JSON string literal, and commits to the decoded
 * form whenever that decoded form holds a control character -- it never checks whether the
 * *literal* text is itself an existing file first. A real file literally named `"a\nb"` (a quote,
 * `a`, a backslash, `n`, `b`, a quote -- six ordinary printable bytes, no control character at
 * all) is shown by `list_dir` completely unquoted, exactly as it is on disk, because
 * `Page::name()` only quotes a name that *itself* holds a control character. Docs/files.md
 * promises "every tool takes that literal... back as its path" -- but passing back precisely what
 * was shown (the plain, un-quoted-by-the-tool name) makes `resolve()` guess it is JSON syntax,
 * decode `\n` into a real newline, and look for an unrelated (and here nonexistent) file instead
 * of the one list_dir just named. Narrowing this (preferring an existing literal match over a
 * speculative decode) is the restrictive choice that doesn't break the #46 fix for names that
 * truly need quoting.
 */
it('lists a name wrapped in double quotes as a JSON literal so its pass-back is unambiguous', function () {
    \file_put_contents($this->dir . '/"a\nb"', "FILE A CONTENT\n");
    $shown = \trim((new ListDir($this->root))->call([], callFor())->content[0]->text);
    expect($shown)->toBe('"\"a\\\\nb\""'); // the JSON literal; shown plainly, "a\nb" would decode to a newline name

    $result = (new ReadFile($this->root))->call(['path' => $shown], callFor());
    expect($result->content[0]->text)->toContain('FILE A CONTENT');
}); // #50: shown plainly, and passing that back resolved to a + newline + b
