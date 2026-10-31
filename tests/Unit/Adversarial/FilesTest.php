<?php

declare(strict_types=1);

use Harness\Tools\Files\EditFile;
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
