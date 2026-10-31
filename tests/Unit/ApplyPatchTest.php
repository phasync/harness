<?php

declare(strict_types=1);

use Harness\Tools\Files\ApplyPatch;
use Harness\Tools\Files\Journal;
use Harness\Tools\Files\Root;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-patch-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir);
    $this->root = new Root($this->dir);
});

afterEach(function () {
    \exec('rm -rf ' . \escapeshellarg($this->dir));
});

function patched(Harness\Result $r): string
{
    return $r->content[0]->text;
}

it('adds, updates with two hunks, deletes and moves a file in one patch', function () {
    \file_put_contents($this->dir . '/update.txt', "one\ntwo\nthree\nfour\nfive\n");
    \file_put_contents($this->dir . '/gone.txt', "bye\n");
    \file_put_contents($this->dir . '/old.txt', "keep me\n");

    $patch = <<<'PATCH'
    *** Begin Patch
    *** Add File: new.txt
    +hello
    +world
    *** Update File: update.txt
    @@
     one
    -two
    +TWO
     three
    @@
     three
    -four
    +FOUR
     five
    *** Delete File: gone.txt
    *** Update File: old.txt
    *** Move to: moved.txt
    @@
     keep me
    *** End Patch
    PATCH;

    $apply  = new ApplyPatch($this->root);
    $result = patched($apply->call(['patch' => $patch], callFor()));

    expect(\file_get_contents($this->dir . '/new.txt'))->toBe("hello\nworld")
        ->and(\file_get_contents($this->dir . '/update.txt'))->toBe("one\nTWO\nthree\nFOUR\nfive\n")
        ->and(\is_file($this->dir . '/gone.txt'))->toBeFalse()
        ->and(\is_file($this->dir . '/old.txt'))->toBeFalse()
        ->and(\file_get_contents($this->dir . '/moved.txt'))->toBe("keep me\n")
        ->and($result)->toContain("Added 'new.txt' (2 lines)")
        ->and($result)->toContain('Updated \'update.txt\' (+2 -2)')
        ->and($result)->toContain("Deleted 'gone.txt' (1 lines)")
        ->and($result)->toContain("Moved 'old.txt' to 'moved.txt' (+0 -0)");
});

it('fails the whole patch when a hunk context is not found, leaving every file untouched', function () {
    \file_put_contents($this->dir . '/a.txt', "one\ntwo\nthree\n");
    \file_put_contents($this->dir . '/b.txt', "x\n");

    $patch = <<<'PATCH'
    *** Begin Patch
    *** Add File: new.txt
    +hello
    *** Update File: a.txt
    @@
     one
    -nope, not here
    +changed
     three
    *** End Patch
    PATCH;

    $apply = new ApplyPatch($this->root);
    expect(fn () => $apply->call(['patch' => $patch], callFor()))
        ->toThrow(RuntimeException::class, "'a.txt', hunk 1");

    expect(\is_file($this->dir . '/new.txt'))->toBeFalse()
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("one\ntwo\nthree\n")
        ->and(\file_get_contents($this->dir . '/b.txt'))->toBe("x\n");
});

it('accepts the End of File marker', function () {
    \file_put_contents($this->dir . '/a.txt', "one\ntwo\n");
    $patch = <<<'PATCH'
    *** Begin Patch
    *** Update File: a.txt
    @@
     one
    -two
    +TWO
    *** End of File
    *** End Patch
    PATCH;
    $apply = new ApplyPatch($this->root);
    $apply->call(['patch' => $patch], callFor());
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe("one\nTWO\n");
});

it('rejects a patch missing the Begin or End markers, naming the line', function () {
    $apply = new ApplyPatch($this->root);
    expect(fn () => $apply->call(['patch' => "*** Update File: a.txt\n@@\n one\n*** End Patch"], callFor()))
        ->toThrow(RuntimeException::class, 'line 1')
        ->and(fn () => $apply->call(['patch' => "*** Begin Patch\n*** Add File: a.txt\n+x"], callFor()))
        ->toThrow(RuntimeException::class, 'End Patch');
});

it('records every changed and deleted path in a Journal', function () {
    \file_put_contents($this->dir . '/a.txt', "one\ntwo\n");
    $journal = new Journal();
    $apply   = new ApplyPatch($this->root, journal: $journal);
    $patch   = <<<'PATCH'
    *** Begin Patch
    *** Add File: new.txt
    +hello
    *** Update File: a.txt
    @@
     one
    -two
    +TWO
    *** End Patch
    PATCH;
    $apply->call(['patch' => $patch], callFor());
    expect($journal->changed())->toContain($this->dir . '/a.txt', $this->dir . '/new.txt');
    $journal->undo();
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe("one\ntwo\n")
        ->and(\is_file($this->dir . '/new.txt'))->toBeFalse();
});
