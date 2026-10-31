<?php

declare(strict_types=1);

use Harness\Tools\Files\EditFile;
use Harness\Tools\Files\ReadFile;
use Harness\Tools\Files\Root;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-files-probe-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir);
    $this->root = new Root($this->dir);
});

afterEach(function () {
    \exec('rm -rf ' . \escapeshellarg($this->dir));
});

it('reads an empty file and reports it as empty', function () {
    \file_put_contents($this->dir . '/empty.txt', '');
    $r = (new ReadFile($this->root))->call(['path' => 'empty.txt'], callFor());
    expect($r->content[0]->text)->toBe("'empty.txt' is empty");
});

it('deletes text through indentation-flexible matching with reindent on empty new', function () {
    // This tests EditFile with new='' (deletion) where the old spans an indented block.
    // The match succeeds through the tolerant cascade and reindent() is called on empty string.
    // Common indent extraction and re-indentation should handle empty string correctly.
    \file_put_contents($this->dir . '/code.txt', "function f() {\n    if (x) {\n        block();\n    }\n}\n");
    $e      = new EditFile($this->root);
    $result = $e->call(['path' => 'code.txt', 'old' => "  if (x) {\n    block();\n  }", 'new' => ''], callFor());
    expect($result->content[0]->text)->toContain("Edited 'code.txt'")
        ->and(\file_get_contents($this->dir . '/code.txt'))->toBe("function f() {\n\n}\n");
});
