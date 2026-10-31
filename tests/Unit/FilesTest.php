<?php

declare(strict_types=1);

use Harness\Part\Media;
use Harness\Retry;
use Harness\Tools\Files\EditFile;
use Harness\Tools\Files\Glob;
use Harness\Tools\Files\Grep;
use Harness\Tools\Files\ListDir;
use Harness\Tools\Files\ReadFile;
use Harness\Tools\Files\Root;
use Harness\Tools\Files\WriteFile;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-files-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir . '/src/sub', 0o777, true);
    \file_put_contents($this->dir . '/src/a.php', "<?php\nfunction alpha() {}\nfunction beta() {}\n");
    \file_put_contents($this->dir . '/src/sub/b.php', "<?php\nfunction gamma() {}\n");
    \file_put_contents($this->dir . '/README.md', "# Title\nAlpha and beta\n");
    \file_put_contents($this->dir . '/bin.dat', "x\0y");
    $this->root = new Root($this->dir);
});

afterEach(function () {
    \exec('rm -rf ' . \escapeshellarg($this->dir));
});

function text(Harness\Result $r): string
{
    return $r->content[0]->text;
}

it('marks the read-only tools read-only and the others destructive', function () {
    foreach ([ReadFile::class, ListDir::class, Glob::class, Grep::class] as $class) {
        expect((new $class($this->root))->spec()->hints->readOnly)->toBeTrue();
    }
    foreach ([WriteFile::class, EditFile::class] as $class) {
        expect((new $class($this->root))->spec()->hints)->readOnly->toBeFalse()->destructive->toBeTrue();
    }
});

it('reads numbered lines and says how to read on', function () {
    $r = (new ReadFile($this->root, limit: 2))->call(['path' => 'src/a.php'], callFor());
    expect(text($r))->toBe("     1\t<?php\n     2\tfunction alpha() {}\n[1 more lines; pass offset=3 to read on]");
    $r = (new ReadFile($this->root))->call(['path' => 'src/a.php', 'offset' => 3], callFor());
    expect(text($r))->toBe("     3\tfunction beta() {}");
});

it('returns images as media and refuses other binaries', function () {
    \file_put_contents($this->dir . '/p.png', "\x89PNG\0data");
    expect((new ReadFile($this->root))->call(['path' => 'p.png'], callFor())->content[0])->toEqual(new Media('image/png', "\x89PNG\0data"))
        ->and(fn () => (new ReadFile($this->root))->call(['path' => 'bin.dat'], callFor()))->toThrow(RuntimeException::class, 'binary');
});

it('keeps every path inside the root', function (string $path) {
    expect(fn () => (new ReadFile($this->root))->call(['path' => $path], callFor()))->toThrow(RuntimeException::class, 'outside the root');
})->with(['../etc/passwd', '/etc/passwd', 'src/../../x']);

it('does not follow a symlink out of the root', function () {
    \symlink('/etc', $this->dir . '/link');
    expect(fn () => (new ReadFile($this->root))->call(['path' => 'link/passwd'], callFor()))->toThrow(RuntimeException::class, 'symlink')
        ->and(fn () => (new WriteFile($this->root))->call(['path' => 'link/new', 'content' => 'x'], callFor()))->toThrow(RuntimeException::class, 'symlink');
});

it('writes files, creating directories', function () {
    $w = new WriteFile($this->root);
    expect(text($w->call(['path' => 'new/dir/f.txt', 'content' => 'hello'], callFor())))->toBe("Created 'new/dir/f.txt' (5 bytes)")
        ->and(\file_get_contents($this->dir . '/new/dir/f.txt'))->toBe('hello')
        ->and(text($w->call(['path' => 'new/dir/f.txt', 'content' => 'bye'], callFor())))->toStartWith('Replaced');
});

it('runs write_file\'s check after the write and appends its message', function () {
    $w = new WriteFile($this->root, check: fn (string $abs): ?string => \str_ends_with($abs, 'bad.txt') ? 'php -l failed' : null);
    expect(text($w->call(['path' => 'good.txt', 'content' => 'x'], callFor())))->not->toContain('Check:')
        ->and(text($w->call(['path' => 'bad.txt', 'content' => 'x'], callFor())))->toContain('Check: php -l failed')
        ->and(\file_get_contents($this->dir . '/bad.txt'))->toBe('x');
});

it('edits one unique occurrence', function () {
    $e = new EditFile($this->root);
    expect(text($e->call(['path' => 'src/a.php', 'old' => 'beta', 'new' => 'delta'], callFor())))->toBe("Edited 'src/a.php':\n     1\t<?php\n     2\tfunction alpha() {}\n     3\tfunction delta() {}")
        ->and(\file_get_contents($this->dir . '/src/a.php'))->toContain('function delta()')
        ->and(fn () => $e->call(['path' => 'src/a.php', 'old' => 'function', 'new' => 'fn'], callFor()))->toThrow(RuntimeException::class, 'occurs 2 times')
        ->and(fn () => $e->call(['path' => 'src/a.php', 'old' => 'nope', 'new' => 'x'], callFor()))->toThrow(RuntimeException::class, 'not found')
        ->and(fn () => $e->call(['path' => 'src/a.php', 'old' => '', 'new' => 'x'], callFor()))->toThrow(Retry::class);
});

it('lists, globs and greps with paging', function () {
    expect(text((new ListDir($this->root))->call([], callFor())))->toBe("README.md\nbin.dat\nsrc/")
        ->and(text((new Glob($this->root))->call(['pattern' => '**/*.php'], callFor())))->toBe("src/a.php\nsrc/sub/b.php")
        ->and(text((new Glob($this->root))->call(['pattern' => '*.php', 'path' => 'src'], callFor())))->toBe('src/a.php')
        ->and(text((new Glob($this->root))->call(['pattern' => '{README,x}.md'], callFor())))->toBe('README.md')
        ->and(text((new Glob($this->root, limit: 1))->call(['pattern' => '**'], callFor())))->toBe("README.md\n[3 more matches; pass offset=1 to see them]")
        ->and(text((new Grep($this->root))->call(['pattern' => 'function (alpha|gamma)'], callFor())))->toBe("src/a.php:2: function alpha() {}\nsrc/sub/b.php:2: function gamma() {}\n[1 binary files skipped]")
        ->and(text((new Grep($this->root))->call(['pattern' => 'ALPHA', 'ignore_case' => true, 'glob' => '*.md'], callFor())))->toBe('README.md:2: Alpha and beta')
        ->and(fn () => (new Grep($this->root))->call(['pattern' => '(unclosed'], callFor()))->toThrow(Retry::class, 'not a valid regular expression');
});

it('asks the model to correct missing or mistyped input', function () {
    expect(fn () => (new ReadFile($this->root))->call([], callFor()))->toThrow(Retry::class, "'path' is required")
        ->and(fn () => (new ReadFile($this->root))->call(['path' => 'x', 'offset' => 'two'], callFor()))->toThrow(Retry::class, "'offset' must be an integer");
});

it('compiles globs', function (string $glob, string $path, bool $match) {
    expect(1 === \preg_match(Glob::regex($glob), $path))->toBe($match);
})->with([
    ['*.php', 'a.php', true],
    ['*.php', 'src/a.php', false],
    ['**/*.php', 'a.php', true],
    ['**/*.php', 'x/y/a.php', true],
    ['src/**', 'src/x/y', true],
    ['?.md', 'ab.md', false],
    ['[ab].md', 'b.md', true],
    ['[!ab].md', 'c.md', true],
    ['{x,y}/*', 'y/z', true],
    ['a.b', 'axb', false],
]);
