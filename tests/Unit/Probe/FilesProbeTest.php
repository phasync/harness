<?php

declare(strict_types=1);

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
