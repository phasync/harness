<?php

declare(strict_types=1);

use Harness\Tools\Files\ApplyPatch;
use Harness\Tools\Files\EditFile;
use Harness\Tools\Files\Glob;
use Harness\Tools\Files\Grep;
use Harness\Tools\Files\Journal;
use Harness\Tools\Files\ListDir;
use Harness\Tools\Files\ReadFile;
use Harness\Tools\Files\Root;
use Harness\Tools\Files\WriteFile;

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

/*
 * Round 10, the partial-path question. `glob` and `grep` wrap the WHOLE relative path in one
 * `Page::name()` call, so a control character anywhere in it quotes the entire string and
 * `Root::resolve()`'s whole-string check (`$path[0]` and `$path[-1]` both `"`) decodes it back
 * correctly. `list_dir`, though, lists one directory's immediate children as bare basenames --
 * `ListDir::call()` does `Page::name($name) . (is_dir(...) ? '/' : '')` on just the name, with no
 * directory prefix. For a file inside a subdirectory, the only way a model can turn that basename
 * into a root-relative `path` for `read_file`/`edit_file` is to prepend the directory itself
 * (`list_dir`'s own `path` input is relative to the root, not to what it lists), e.g.
 * `'sub/' . $basename`. When the basename needed quoting, that composition puts the directory
 * prefix *before* the opening quote, so `resolve()`'s check (the first character of the whole
 * string) never fires -- the quoted literal `list_dir` showed cannot be reached at all once it's
 * inside a subdirectory, and the resulting "does not exist" says nothing about why.
 */
it('resolves a directory-prefixed path composed from list_dir\'s quoted basename', function () {
    \mkdir($this->dir . '/sub');
    \file_put_contents($this->dir . '/sub/' . "evil.txt\nfake.php", "secret in sub\n");
    $basename = \trim((new ListDir($this->root))->call(['path' => 'sub'], callFor())->content[0]->text);
    expect($basename)->toBe('"evil.txt\nfake.php"');

    // BUG: today this throws "'sub/"evil.txt\nfake.php"' does not exist" -- composing the
    // directory with the quoted basename list_dir showed (the only way to reach a file in a
    // subdirectory whose name has a control character) does not resolve.
    $result = (new ReadFile($this->root))->call(['path' => 'sub/' . $basename], callFor());
    expect($result->content[0]->text)->toContain('secret in sub');
}); // #56: only a whole-path literal was decoded; sub/"..." was 'does not exist'

/*
 * Round 11. While attacking the #56 segment-quoting fix (directory form, both-segments-quoted
 * composition, and a quote-wrapped plain name all round-tripped correctly -- see the report), a
 * sharper, unrelated defect turned up one level earlier: `glob` can fail to *find* a file whose
 * path holds a control character at all, before any quoting question even arises.
 * `Glob::regex('**')` compiles to `#^.*$#` -- PCRE's `.` does not match a newline unless the `s`
 * (dotall) modifier is set, which this regex never passes. A file whose relative path contains a
 * newline (exactly the kind of file the quoting feature exists to let the model address) fails to
 * match its own `**`, the catch-all pattern docs/files.md itself uses as the leading example
 * (`**` then a slash then `*.php`). Plain `*` and `?` are unaffected (they compile to `[^/]*` and
 * `[^/]`, character classes that do match `\n`); only the "any depth" `**` form is broken this way.
 */
it('matches a file whose path holds a newline with the all-files pattern "**"', function () {
    \file_put_contents($this->dir . '/' . "evil\nfile.txt", 'x');
    $result = (new Glob($this->root))->call(['pattern' => '**'], callFor());

    // BUG: today this is "No files match '**'" -- the file's own embedded newline defeats the
    // catch-all pattern, since Glob::regex('**')'s '.*' cannot cross a '\n' without the 's' modifier.
    expect($result->content[0]->text)->not->toContain('No files match');
}); // #62: .* without the s modifier stopped at the newline

/*
 * Round 12. Attacked the #62 fix briefly first (grep's glob filter, `**` then a slash then
 * `*.txt`, against a newline-named file, and glob's `[!a]` class and `{a,b}` alternation against newline-named
 * entries) -- all round-tripped correctly; `Glob::regex()`'s shared `s` modifier fixes grep's
 * filter too, and character classes/alternation were never affected by dotall in the first place.
 *
 * Of the three carried-over items, "Move onto an existing target" turned up something sharper than
 * the silent-overwrite gap flagged in earlier rounds: it is not just silent, it can destroy the
 * source file with the content recoverable nowhere. #26's planning-phase guard (`call()`'s loop
 * before any delete or write) checks that every write target's *nearest existing ancestor* is a
 * directory -- but never checks whether the target itself already IS a directory. A `Move to:` an
 * existing directory passes that guard (its parent is a real directory), so the Delete-then-write
 * execution loop proceeds: the source file is unlinked first (Deletes always run before Writes),
 * and only then does `file_put_contents()` discover the target is a directory and throw. Without a
 * `Journal`, the source file's content is gone -- `apply_patch`'s whole "build it in memory first,
 * fail before touching anything" promise (docs/files.md, the #26 fix itself) is broken by the one
 * case that same fix didn't cover.
 */
it('leaves the source file untouched when a Move target is an existing directory', function () {
    \file_put_contents($this->dir . '/a.txt', "line1\nline2\n");
    \mkdir($this->dir . '/b.txt');
    $patch = new ApplyPatch($this->root);
    $p     = "*** Begin Patch\n*** Update File: a.txt\n*** Move to: b.txt\n@@\n line1\n line2\n*** End Patch";

    expect(fn () => $patch->call(['patch' => $p], callFor()))->toThrow(RuntimeException::class);

    // BUG: today 'a.txt' is already deleted by the time the write to the directory fails -- the
    // source file is permanently lost (with no Journal) even though the whole call reports failure.
    expect(\is_file($this->dir . '/a.txt'))->toBeTrue();
}); // #69: the source was unlinked before the target was found to be a directory

/*
 * Round 13, attacking the #69 fix and its siblings. The planning loop #69 added only compares each
 * write target's path against the filesystem (an existing directory) -- it never compares write
 * targets against *each other*. `planUpdate()`'s pending-continuation check (`#8`'s fix) detects
 * two sections updating the *same source path* in a row, but it keys on each section's own source
 * path, not on where a `Move to:` sends it. Two sections moving two *different* source files onto
 * the *same* target produce two separate entries in the per-section `$fileWrites`, both keyed by
 * that one target path; the main loop folds them into `$writes` with a plain `$writes[$abs] =
 * $content` assignment, so the second section's content silently clobbers the first's. Both
 * sources are unlinked (both are in `$deletes`), the call reports *both* moves as having succeeded
 * ("Moved 'a.txt' to 'target.txt'" and "Moved 'b.txt' to 'target.txt'"), and the first source's
 * content is gone from disk entirely -- worse than #69's case, since there is no error at all, not
 * even one after partial damage: the whole call reports a confident, complete success.
 */
it('does not silently discard one file when two sections move different sources onto the same target', function () {
    \file_put_contents($this->dir . '/a.txt', "CONTENT FROM A\n");
    \file_put_contents($this->dir . '/b.txt', "CONTENT FROM B\n");
    $patch = new ApplyPatch($this->root);
    $p     = "*** Begin Patch\n"
        . "*** Update File: a.txt\n*** Move to: target.txt\n@@\n CONTENT FROM A\n"
        . "*** Update File: b.txt\n*** Move to: target.txt\n@@\n CONTENT FROM B\n"
        . '*** End Patch';

    // BUG: today this succeeds with no exception, reporting both moves done, while a.txt's content
    // is permanently gone (only b.txt's content survives at target.txt).
    expect(fn () => $patch->call(['patch' => $p], callFor()))->toThrow(RuntimeException::class);
}); // #71: the later write replaced the earlier; both sources deleted, both moves reported

/*
 * Round 14, attacking the #71/#72 fixes and their siblings. Four scenarios tried:
 *
 * - "Delete b then Move a to b" in one patch: the Move section's existing-target check
 *   (`is_file($target)`) runs during planning, before any delete has actually happened, so it
 *   sees b.txt still on disk and refuses -- even though the model did exactly what the refusal's
 *   own wording says to do ("delete it first"). Judged this an acceptable, narrow-first refusal
 *   for now (a model can always split it into two calls, delete then move) rather than pinning it.
 * - "Add File then Update File of the same new path": confirmed this already works correctly
 *   (the writers' same-section-path exception lets a section continue its own earlier result).
 * - "Update of a file an earlier section deleted": also wrong (the Update re-reads the file from
 *   disk during planning, before the Delete has actually run, so the deleted content comes right
 *   back -- reported as "Deleted ... Updated ..." while the file is untouched) -- a real defect,
 *   left for a future round since only one fits this round's test.
 * - "Move a to b, then Move b to c" in one patch: THE defect pinned below. The second section's
 *   `Move to:` deletes its own source (b.txt) via `$deletes`, but b.txt was never actually written
 *   to disk -- it only exists as a pending in-memory entry from the first section's `$writes`,
 *   which nothing removes once the second section consumes it as its *source*. Deletes run before
 *   writes, so `unlink()` on the never-created b.txt fails (silently: its return value is never
 *   checked, unlike every other disk operation in this file), and then the leftover `$writes` entry
 *   for b.txt (still queued from the first section) recreates it right after c.txt is written --
 *   leaving a stale duplicate of the moved file's content under its old name.
 */
it('leaves no stale file behind when a patch chains two moves (a to b, then b to c)', function () {
    \file_put_contents($this->dir . '/a.txt', "chain content\n");
    $patch = new ApplyPatch($this->root);
    $p     = "*** Begin Patch\n"
        . "*** Update File: a.txt\n*** Move to: b.txt\n@@\n chain content\n"
        . "*** Update File: b.txt\n*** Move to: c.txt\n@@\n chain content\n"
        . '*** End Patch';
    $patch->call(['patch' => $p], callFor());

    // BUG: today b.txt is left behind holding the same content as c.txt -- the chained move's
    // intermediate file was never deleted, just silently recreated after a failed unlink().
    expect(\is_file($this->dir . '/b.txt'))->toBeFalse();
}); // #78: the delete of b ran before b was written; the pending write then recreated it

it('refuses to update a file an earlier section of the same patch deleted, and lets a later section reuse its path', function () {
    \file_put_contents($this->dir . '/a.txt', "old\n");
    \file_put_contents($this->dir . '/b.txt', "b\n");
    $patch = new ApplyPatch($this->root);

    $p = "*** Begin Patch\n*** Delete File: a.txt\n*** Update File: a.txt\n@@\n-old\n+new\n*** End Patch\n";
    expect(fn () => $patch->call(['patch' => $p], callFor()))->toThrow(RuntimeException::class, 'deleted earlier in this patch')
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("old\n");

    $p = "*** Begin Patch\n*** Delete File: a.txt\n*** Update File: b.txt\n*** Move to: a.txt\n@@\n b\n*** End Patch\n";
    $patch->call(['patch' => $p], callFor());
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe("b\n")
        ->and(\is_file($this->dir . '/b.txt'))->toBeFalse();
}); // #79: planUpdate re-read the deleted file from disk; the delete-then-move case was refused

/*
 * Round 15. Attacked the #78/#79 single-plan rewrite with five ApplyPatch scenarios -- all
 * verified correct by running them against the real code:
 * - Add x then Delete x in one patch, x never on disk: reported as both "Added" and "Deleted",
 *   but no disk write or delete happens at all (the final planned state is null and was never on
 *   disk, so the delete loop's `is_file || is_link` guard skips it).
 * - Move a to b then Delete b: a.txt is removed from disk, b.txt never comes into existence (the
 *   planned state for b lands on null before the write loop runs).
 * - Delete a then Add a with new content: the file is replaced in place (no unlink/recreate pair;
 *   the final plan is non-null, so only the write loop touches it), the Journal records exactly
 *   once -- the true original content -- and undo restores it.
 * - Update a then Update-and-Move a to b in two sections: b ends up with both edits applied in
 *   sequence; a is gone.
 * None of those broke a documented promise, so this pins the subtlest of the two edit_file
 * invariants the coordinator asked about instead: `old` matching exactly once must be taken
 * immediately, with the tolerant cascade never even consulted -- not just "not reported as
 * ambiguous" but structurally skipped, per Matcher::locate()'s early return on a single exact hit.
 * Checked against tests/: EditFileCascadeTest.php's two ambiguity tests cover exact matching zero
 * times (falls through to a tolerant level) and exact matching more than once (ambiguous at the
 * exact level itself) -- neither covers exact matching exactly once while a *different* region
 * would also match through a tolerant level, which would make that looser level's own search
 * ambiguous were it ever run.
 */
it('takes the single exact match without ever consulting the tolerant cascade', function () {
    \file_put_contents($this->dir . '/a.txt', "foo(x)\nbar\n  foo( x )\nbaz\n");
    $e      = new EditFile($this->root);
    $result = $e->call(['path' => 'a.txt', 'old' => 'foo(x)', 'new' => 'REPLACED'], callFor());

    expect($result->content[0]->text)->not->toContain('matched with')
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("REPLACED\nbar\n  foo( x )\nbaz\n");
});

/*
 * Round 16, moving to ReadFile (fewer rounds spent here than apply_patch/edit_file). `read_file`
 * decides whether a file "is" an image purely from its extension (`self::IMAGES[strtolower(
 * pathinfo($abs, PATHINFO_EXTENSION))]`), checked before the binary-content sniff and with no look
 * at the bytes at all. A file named `fake.png` that actually holds plain text comes back as
 * `Media('image/png', ...)`, wrapping the raw text bytes and telling the caller (and, through
 * `toArray()`'s base64 encoding, the model's provider) that this is a real PNG. A provider trying
 * to decode those bytes as PNG image data will fail; the model never gets to see the text at all,
 * even though it is perfectly readable, valid content read_file could have shown as text.
 */
it('does not report a .png file as an image when its content is plain text', function () {
    \file_put_contents($this->dir . '/fake.png', "<?php\necho 'this is actually PHP source, not a PNG';\n");
    $result = (new ReadFile($this->root))->call(['path' => 'fake.png'], callFor());

    // BUG: today this comes back as Media('image/png', ...) wrapping the raw text bytes --
    // ReadFile trusts the extension alone and never checks the content against real PNG magic
    // bytes, so a provider asked to decode this as an image will fail on perfectly good text.
    expect($result->content[0])->toBeInstanceOf(Harness\Part\Text::class);
}); // #85: the extension alone decided

/*
 * Round 17. Attacked the #85 magic-bytes fix: a real PNG named `.jpg` (magic disagrees with the
 * extension) is now read as text or refused as binary depending on its bytes -- judged this an
 * acceptable, narrow ruling (the fix's point was to require both signals to agree, not to let
 * either one decide alone; a model that wants the image back can still read it by its real
 * extension or `.png`). A WebP shorter than 12 bytes and a GIF87a signature both round-tripped
 * correctly (no crash, no false negative on the 87a variant).
 *
 * `grep`'s `ignore_case` option has no such caveat in its docs or its own spec text ("Case-
 * insensitive (default false)") -- but `Grep::call()` builds its regex with only the `i` modifier,
 * never `u` (PCRE_UTF8). Without `u`, PCRE's case-insensitive matching is ASCII-only; a non-ASCII
 * letter's upper and lower forms are different multi-byte sequences that `i` alone never folds
 * together. `ignore_case: true` silently stops working the moment the pattern or the matched text
 * leaves ASCII.
 */
it('matches case-insensitively on a non-ASCII letter, not just ASCII ones', function () {
    \file_put_contents($this->dir . '/cafe.txt', "café content here\n");
    $result = (new Grep($this->root))->call(['pattern' => 'CAFÉ', 'ignore_case' => true, 'path' => 'cafe.txt'], callFor());

    // BUG: today this is "No lines match 'CAFÉ'" -- the 'i' modifier alone never case-folds 'É'
    // against 'é' because the regex is missing PCRE's 'u' (UTF-8) modifier.
    expect($result->content[0]->text)->toContain('café content here');
}); // #88: the i modifier without u folds ASCII only

/*
 * Round 18, attacking the #88 fix. `Grep::call()`'s regex now always carries PCRE's `u` modifier
 * (case folding and `.` need it to reach beyond ASCII). But `u` applies to the *subject* too: when
 * a line is not valid UTF-8, `preg_match($regex, $line)` returns `false` (a PCRE error,
 * PREG_BAD_UTF8_ERROR), not `0`. The loop only checks `1 === \preg_match(...)`, so a `false` result
 * is treated exactly like "no match" -- the line is silently dropped from the results, with
 * nothing like the "[N binary files skipped]" counter to say so. The file as a whole is still
 * scanned (it isn't flagged binary -- that check only looks for a NUL byte in the first 8192
 * bytes, which this line doesn't have), so a model searching a file that happens to contain one
 * line of invalid UTF-8 (stray Latin-1 bytes, a truncated multi-byte sequence) gets back fewer
 * matches than actually exist, with no indication anything was skipped. `Utf8::scrub()` is only
 * applied to a line *after* it already matched, which is too late to let an otherwise-matching but
 * invalid line be found at all.
 */
it('still finds a match on a line that is not valid UTF-8, in an otherwise text file', function () {
    $content = "needle on a clean line\nneedle next to invalid bytes: \xA9\xA9\nend\n";
    \file_put_contents($this->dir . '/f.txt', $content);
    $result = (new Grep($this->root))->call(['pattern' => 'needle', 'path' => 'f.txt'], callFor());

    // BUG: today only line 1 is reported; line 2 (also containing "needle") is silently dropped
    // because preg_match() with the 'u' modifier returns false, not 0, on an invalid-UTF-8 subject.
    expect($result->meta['count'])->toBe(2);
}); // #91: preg_match returned false under u and the line was taken as no match

/*
 * Round 19. Attacked the #91 fix quickly: a literal non-ASCII pattern against a line that scrubs
 * to U+FFFD correctly still matches on the parts of the line that stayed valid; a pattern of
 * literal U+FFFD correctly matches a scrubbed line's replacement character. Neither broke.
 *
 * Worked through the carried-over list in order -- ReadFile (offset past the end, a file of
 * exactly `limit` lines, a file of only "\n", CRLF line counting and the maxLineLength cut on a
 * CRLF line), WriteFile (a NUL byte in content, onto a dangling symlink, under a parent that is a
 * file) -- all verified correct by running them against the real code; nothing broke.
 *
 * Pinning the last item instead: Journal's "first touch wins" invariant, verified here across two
 * *separate* `apply_patch` calls sharing one Journal (not two calls of the same simple `write_file`,
 * which JournalTest.php already covers in "keeps only the first recorded state of a path touched
 * more than once") -- apply_patch's own planning/journal-recording path is materially different
 * (hunks, Matcher, a per-path plan), so this is a distinct code path for the same invariant: the
 * second patch's `journal?->record()` call is a no-op because the path was already recorded by the
 * first, and `undo()` restores all the way back to the content from before either patch ran.
 */
it('keeps the true original across two separate apply_patch calls sharing one Journal', function () {
    \file_put_contents($this->dir . '/a.txt', "TRUE ORIGINAL\n");
    $journal = new Journal();
    $patch   = new ApplyPatch($this->root, journal: $journal);
    $patch->call(['patch' => "*** Begin Patch\n*** Update File: a.txt\n@@\n-TRUE ORIGINAL\n+AFTER PATCH 1\n*** End Patch"], callFor());
    $patch->call(['patch' => "*** Begin Patch\n*** Update File: a.txt\n@@\n-AFTER PATCH 1\n+AFTER PATCH 2\n*** End Patch"], callFor());

    expect(\file_get_contents($this->dir . '/a.txt'))->toBe("AFTER PATCH 2\n");
    $journal->undo();
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe("TRUE ORIGINAL\n");
});

it('refuses write_file under a parent that is a file, without a PHP warning', function () {
    \file_put_contents($this->dir . '/notes', 'a file');
    $tool = new WriteFile($this->root);

    expect(fn () => $tool->call(['path' => 'notes/sub/x.txt', 'content' => 'x'], callFor()))
        ->toThrow(RuntimeException::class, "'notes/sub/x.txt' cannot be created: 'notes' is a file, not a directory");
}); // #95: mkdir() warned before "Could not create the directory" was thrown

/*
 * Round 20, attacking the #95 fix. Three sub-attacks on write_file's new ancestor walk:
 * - A dangling symlink as an ancestor: `Root::resolve()`'s own probe loop (`!file_exists($probe)
 *   && !is_link($probe)`) stops at the dangling symlink itself and fails to `realpath()` it, so
 *   the call never even reaches WriteFile's new check -- refused earlier, conservatively, as
 *   "leads outside the root through a symlink". No warning, no new defect.
 * - An ancestor that is a symlink to a real FILE: `file_exists()` follows the symlink to the file
 *   (true), so #95's walk correctly stops there, `is_dir()` on it is false (a file, not a
 *   directory, through the symlink), and the clean "'notes' is a file, not a directory" message
 *   fires -- exactly the case #95 was built for, confirmed working through a symlink too.
 * - An ancestor that genuinely IS an existing directory, but is not writable: #95's walk only
 *   checks `is_dir($dir)` -- never writability -- so this sibling case sails past the new guard
 *   straight into the still-unguarded `mkdir()` call, and mkdir()'s own native PHP warning
 *   ("mkdir(): Permission denied") leaks exactly as the "is a file" case used to before #95,
 *   just for a different underlying cause.
 */
it('does not leak a native PHP warning from mkdir() when a directory ancestor is not writable', function () {
    \mkdir($this->dir . '/readonly', 0755);
    \chmod($this->dir . '/readonly', 0555); // read + execute, no write
    $w        = new WriteFile($this->root);
    $warnings = [];
    \set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $w->call(['path' => 'readonly/sub/x.txt', 'content' => 'hello'], callFor());
    } catch (RuntimeException $e) {
    } finally {
        \restore_error_handler();
        \chmod($this->dir . '/readonly', 0755);
    }

    expect($warnings)->toBe([])
        ->and($e->getMessage())->toBe("Could not create the directory for 'readonly/sub/x.txt': Permission denied");
}); // #101: "mkdir(): Permission denied" escaped as a warning; now it is the error's reason

/*
 * Round 21: every write/delete path write_file, edit_file and apply_patch use now goes through
 * `Harness\Internal\Fs`, which turns a failed `mkdir()`/`file_put_contents()`/`unlink()` into a
 * clean `RuntimeException` and never lets the native PHP warning escape. `Journal::undo()` was
 * never touched by that refactor -- it still calls `\file_put_contents($abs, $original)` and
 * `\unlink($abs)` directly, with no error handler and no check of the return value.
 *
 * Recorded here with a real failure, not a hypothesis: journal a `write_file` that changes a
 * file, then make that file read-only before calling `undo()`. Two things go wrong at once:
 *   - `file_put_contents()` inside `undo()` fails and its native warning
 *     ("file_put_contents(...): Failed to open stream: Permission denied") escapes uncaught --
 *     exactly the class of leak #95/#101 closed for write_file/edit_file/apply_patch, still open
 *     here.
 *   - `undo()`'s docblock promises `@return list<string> the paths restored`, but the return
 *     value includes this path regardless of whether the restore actually happened: the file's
 *     content is still the CHANGED value, not the original, while `undo()` reports it as
 *     restored -- a documented promise the code does not keep.
 */
it('names a path Journal::undo() cannot write back, keeps it recorded, and leaks no PHP warning', function () {
    \file_put_contents($this->dir . '/a.txt', "ORIGINAL\n");
    $journal = new Journal();
    $w       = new WriteFile($this->root, journal: $journal);
    $w->call(['path' => 'a.txt', 'content' => 'CHANGED'], callFor());

    \chmod($this->dir . '/a.txt', 0444); // the file itself, not its directory, so undo()'s write fails

    $warnings = [];
    \set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        expect(fn () => $journal->undo())->toThrow(RuntimeException::class, "Could not write '{$this->dir}/a.txt': Permission denied");
    } finally {
        \restore_error_handler();
        \chmod($this->dir . '/a.txt', 0644);
    }

    expect($warnings)->toBe([])
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe('CHANGED')
        ->and($journal->changed())->toBe([$this->dir . '/a.txt']) // kept for another undo()
        ->and($journal->undo())->toBe([$this->dir . '/a.txt'])
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("ORIGINAL\n");
}); // #105: the warning escaped and the path was returned as restored while it held CHANGED
