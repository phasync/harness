<?php

declare(strict_types=1);

use Harness\Tools\Files\ApplyPatch;
use Harness\Tools\Files\EditFile;
use Harness\Tools\Files\Journal;
use Harness\Tools\Files\Root;
use Harness\Tools\Files\WriteFile;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-journal-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir);
    $this->root = new Root($this->dir);
});

afterEach(function () {
    \exec('rm -rf ' . \escapeshellarg($this->dir));
});

it('restores every path to its first recorded state, in reverse order, and reports what changed', function () {
    $journal = new Journal();
    \file_put_contents($this->dir . '/kept.txt', "one\ntwo\n");

    $write = new WriteFile($this->root, journal: $journal);
    $edit  = new EditFile($this->root, journal: $journal);
    $patch = new ApplyPatch($this->root, journal: $journal);

    $write->call(['path' => 'kept.txt', 'content' => "one\ntwo\nTHREE\n"], callFor());
    $edit->call(['path' => 'kept.txt', 'old' => 'two', 'new' => 'TWO'], callFor());
    $patch->call(['patch' => "*** Begin Patch\n*** Add File: added.txt\n+brand new\n*** End Patch"], callFor());

    expect(\file_get_contents($this->dir . '/kept.txt'))->toBe("one\nTWO\nTHREE\n")
        ->and(\file_get_contents($this->dir . '/added.txt'))->toBe('brand new')
        ->and($journal->changed())->toBe([$this->dir . '/kept.txt', $this->dir . '/added.txt']);

    $undone = $journal->undo();

    expect($undone)->toBe([$this->dir . '/added.txt', $this->dir . '/kept.txt'])
        ->and(\file_get_contents($this->dir . '/kept.txt'))->toBe("one\ntwo\n")
        ->and(\is_file($this->dir . '/added.txt'))->toBeFalse()
        ->and($journal->changed())->toBe([]);
});

it('keeps only the first recorded state of a path touched more than once', function () {
    \file_put_contents($this->dir . '/a.txt', 'original');
    $journal = new Journal();
    $write   = new WriteFile($this->root, journal: $journal);
    $write->call(['path' => 'a.txt', 'content' => 'first write'], callFor());
    $write->call(['path' => 'a.txt', 'content' => 'second write'], callFor());
    $journal->undo();
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe('original');
});

it('clears its record without touching disk', function () {
    \file_put_contents($this->dir . '/a.txt', 'original');
    $journal = new Journal();
    $write   = new WriteFile($this->root, journal: $journal);
    $write->call(['path' => 'a.txt', 'content' => 'changed'], callFor());
    $journal->clear();
    expect($journal->changed())->toBe([]);
    $journal->undo();
    expect(\file_get_contents($this->dir . '/a.txt'))->toBe('changed');
});
