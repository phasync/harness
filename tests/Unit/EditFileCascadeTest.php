<?php

declare(strict_types=1);

use Harness\Tools\Files\EditFile;
use Harness\Tools\Files\Journal;
use Harness\Tools\Files\Matcher;
use Harness\Tools\Files\Root;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-cascade-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir);
    $this->root = new Root($this->dir);
});

afterEach(function () {
    \exec('rm -rf ' . \escapeshellarg($this->dir));
});

function write(string $dir, string $name, string $content): string
{
    $abs = $dir . '/' . $name;
    \file_put_contents($abs, $content);

    return $abs;
}

function edited(Harness\Result $r): string
{
    return $r->content[0]->text;
}

it('matches with internal whitespace-run differences through the whitespace-normalized level', function () {
    write($this->dir, 'a.txt', "one\nfunction  foo(  x  )  {\ntwo\n");
    $e      = new EditFile($this->root);
    $result = $e->call(['path' => 'a.txt', 'old' => 'function foo( x ) {', 'new' => 'function foo(y) {'], callFor());
    expect(edited($result))->toContain('matched with whitespace differences on lines 2-2')
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("one\nfunction foo(y) {\ntwo\n");
});

it('matches with trailing whitespace differences through the line-trimmed level', function () {
    write($this->dir, 'a.txt', "one \nmiddle  \nthree\n");
    $e      = new EditFile($this->root);
    $result = $e->call(['path' => 'a.txt', 'old' => "one\nmiddle", 'new' => 'REPLACED'], callFor());
    expect(edited($result))->toContain('matched with leading/trailing whitespace differences on lines 1-2')
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("REPLACED\nthree\n");
});

it('edits a CRLF file with an LF old and keeps the file CRLF', function () {
    write($this->dir, 'a.txt', "one\r\ntwo\r\nthree\r\n");
    $e = new EditFile($this->root);
    $e->call(['path' => 'a.txt', 'old' => 'two', 'new' => 'TWO'], callFor());
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe("one\r\nTWO\r\nthree\r\n");
});

it('is ambiguous at the exact level for a duplicated block, listing both candidates', function () {
    write($this->dir, 'a.txt', "x\nhello\ny\nhello\nz\n");
    $e = new EditFile($this->root);
    expect(fn () => $e->call(['path' => 'a.txt', 'old' => 'hello', 'new' => 'hi'], callFor()))
        ->toThrow(RuntimeException::class, 'occurs 2 times')
        ->and(fn () => $e->call(['path' => 'a.txt', 'old' => 'hello', 'new' => 'hi'], callFor()))
        ->toThrow(RuntimeException::class, 'lines 2, 4');
});

it('is ambiguous at the line-trimmed level when exact matches zero times, listing both candidates', function () {
    // "hello\nmiddle" is not a literal substring anywhere (trailing space/tab before the newline
    // differs both times), so exact matches zero times; trimmed, both occurrences match.
    write($this->dir, 'a.txt', "a\nhello \nmiddle\nb\nhello\t\nmiddle\nc\n");
    $e = new EditFile($this->root);
    expect(fn () => $e->call(['path' => 'a.txt', 'old' => "hello\nmiddle", 'new' => 'hi'], callFor()))
        ->toThrow(RuntimeException::class, 'matches 2 times')
        ->and(fn () => $e->call(['path' => 'a.txt', 'old' => "hello\nmiddle", 'new' => 'hi'], callFor()))
        ->toThrow(RuntimeException::class, 'lines 2, 5');
});

it('matches a block pasted at a different depth and re-indents new by the delta', function () {
    // #9: a uniform shift is caught by the indentation level before the line-trimmed one, so the
    // match carries the delta and `new` lands at the block's depth, not at the model's
    write($this->dir, 'a.txt', "function f() {\n  if (x) {\n    one();\n    two();\n  }\n}\n");
    $e      = new EditFile($this->root);
    $result = $e->call(['path' => 'a.txt', 'old' => "if (x) {\n  one();\n  two();\n}", 'new' => "if (x) {\n  three();\n}"], callFor());
    expect(edited($result))->toContain('matched with different indentation on lines 2-5')
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("function f() {\n  if (x) {\n    three();\n  }\n}\n");
});

it('re-indents new lines by the delta between two blocks\' common indent', function () {
    expect(Matcher::reindent("if (x) {\n  one();\n}", '', '    '))->toBe("    if (x) {\n      one();\n    }")
        ->and(Matcher::reindent("    if (x) {\n      one();\n    }", '    ', ''))->toBe("if (x) {\n  one();\n}")
        ->and(Matcher::reindent('unchanged', '  ', '  '))->toBe('unchanged');
});

it('matches a block anchor with a changed middle line', function () {
    write($this->dir, 'a.txt', "START\nold middle line here\nEND\n");
    $e      = new EditFile($this->root);
    $result = $e->call(['path' => 'a.txt', 'old' => "START\nold middle line here!\nEND", 'new' => 'REPLACED'], callFor());
    expect(edited($result))->toContain('matched with a block anchor match (the middle lines differ) on lines 1-3')
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("REPLACED\n");
});

it('reports the closest region when nothing matches at any level', function () {
    write($this->dir, 'a.txt', "alpha\nbeta\ngamma\n");
    $e = new EditFile($this->root);
    expect(fn () => $e->call(['path' => 'a.txt', 'old' => 'completely unrelated text', 'new' => 'x'], callFor()))
        ->toThrow(RuntimeException::class, 'closest region is lines');
});

it('appends a non-null check message and leaves the write in place', function () {
    $checked = null;
    $check   = function (string $abs) use (&$checked): ?string {
        $checked = $abs;

        return 'php -l failed';
    };
    write($this->dir, 'a.txt', "one\ntwo\nthree\n");
    $e      = new EditFile($this->root, check: $check);
    $result = $e->call(['path' => 'a.txt', 'old' => 'two', 'new' => 'TWO'], callFor());
    expect(edited($result))->toContain('Check: php -l failed')
        ->and($checked)->toBe($this->dir . '/a.txt')
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("one\nTWO\nthree\n");
});

it('does not append a check line when the check returns null', function () {
    write($this->dir, 'a.txt', "one\ntwo\nthree\n");
    $e      = new EditFile($this->root, check: fn (string $abs): ?string => null);
    $result = $e->call(['path' => 'a.txt', 'old' => 'two', 'new' => 'TWO'], callFor());
    expect(edited($result))->not->toContain('Check:');
});

it('records the original content in a Journal before editing', function () {
    write($this->dir, 'a.txt', "one\ntwo\nthree\n");
    $journal = new Journal();
    $e       = new EditFile($this->root, journal: $journal);
    $e->call(['path' => 'a.txt', 'old' => 'two', 'new' => 'TWO'], callFor());
    expect($journal->changed())->toBe([$this->dir . '/a.txt']);
    $journal->undo();
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe("one\ntwo\nthree\n");
});
