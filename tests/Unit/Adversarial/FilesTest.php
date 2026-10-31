<?php

declare(strict_types=1);

use Harness\Tools\Files\EditFile;
use Harness\Tools\Files\Grep;
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
