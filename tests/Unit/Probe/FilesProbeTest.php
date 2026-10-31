<?php

declare(strict_types=1);

use Harness\Tools\Files\ApplyPatch;
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

it('fails to delete a file that does not exist in a patch', function () {
    // This covers ApplyPatch.php line 95 (planDelete check for non-existent file).
    // The patch references a file that doesn't exist; the whole patch must fail atomically.
    $patch = <<<'PATCH'
    *** Begin Patch
    *** Delete File: missing.txt
    *** End Patch
    PATCH;
    $apply = new ApplyPatch($this->root);
    expect(fn () => $apply->call(['patch' => $patch], callFor()))
        ->toThrow(RuntimeException::class, "'missing.txt' does not exist");
});

it('loses the second of two separate "Update File" sections for the same file, though both report success', function () {
    // BUG: two separate "*** Update File: config.php" sections (not two @@ hunks under one
    // section) targeting the same file. ApplyPatch::call() plans every section with
    // planUpdate(), which calls file_get_contents($abs) itself (ApplyPatch.php line 110) -
    // always from disk, never from an earlier section's planned content - and then merges every
    // section's planned write with `$writes = $writes + $fileWrites` (line 55). PHP's array `+`
    // keeps the LEFT operand's value on a colliding key, so the second section's write for the
    // same path is silently discarded, while both sections still report "Updated ... (+1 -1)" as
    // if they had landed.
    //
    // 5-line reproduction:
    //   file:  "<?php\n$a = 1;\n$b = 2;\n"
    //   patch: *** Update File: f  @@  $a=1; -$b=2; +$b=20;
    //          *** Update File: f  @@  <?php -$a=1; +$a=2;
    //   both sections report success; the file on disk ends up "$a = 1;\n$b = 20;\n" - the
    //   second section's $a=2 edit never reaches disk.
    \file_put_contents($this->dir . '/config.php', "<?php\n\$a = 1;\n\$b = 2;\n");

    $patch = <<<'PATCH'
    *** Begin Patch
    *** Update File: config.php
    @@
     $a = 1;
    -$b = 2;
    +$b = 20;
    *** Update File: config.php
    @@
     <?php
    -$a = 1;
    +$a = 2;
    *** End Patch
    PATCH;

    $apply  = new ApplyPatch($this->root);
    $result = $apply->call(['patch' => $patch], callFor());

    // Both sections claim to have updated the file...
    expect(\substr_count($result->content[0]->text, "Updated 'config.php' (+1 -1)"))->toBe(2)
        // ...and the file must actually hold both edits, not just the first section's.
        ->and(\file_get_contents($this->dir . '/config.php'))->toBe("<?php\n\$a = 2;\n\$b = 20;\n");
});

it('rejects a malformed section header mid-patch, naming the line', function () {
    // Tests PatchParser.php line 45 and ApplyPatchTest pattern "rejects a patch ... naming the line".
    // After a Delete File section (which has no content lines), an invalid line that doesn't
    // match any section marker should throw with the line number.
    // This covers PatchParser error handling for unexpected lines mid-patch.
    \file_put_contents($this->dir . '/a.txt', "one\ntwo\n");

    $patch = <<<'PATCH'
    *** Begin Patch
    *** Delete File: a.txt
    *** INVALID HEADER: this line is malformed
    *** End Patch
    PATCH;

    $apply = new ApplyPatch($this->root);
    expect(fn () => $apply->call(['patch' => $patch], callFor()))
        ->toThrow(RuntimeException::class, 'unexpected line 3');
});

it('moves a file to a nested non-existent target directory, creating the path', function () {
    // Tests ApplyPatch.php directory creation logic (lines 67-70) for a Move to a path
    // whose parent directories don't exist yet. This is checked against ApplyPatchTest
    // "adds, updates with two hunks, deletes and moves a file in one patch" which tests
    // moving to a target path that exists in the root.
    // Specifically exercises the mkdir() call at lines 68-69 to create intermediate directories,
    // since the target path "deep/nested/target.txt" has directories that don't exist yet.
    \file_put_contents($this->dir . '/source.txt', "original\ncontent\n");

    $patch = <<<'PATCH'
    *** Begin Patch
    *** Update File: source.txt
    *** Move to: deep/nested/subdir/target.txt
    @@
     original
    -content
    +modified
    *** End Patch
    PATCH;

    $apply  = new ApplyPatch($this->root);
    $result = $apply->call(['patch' => $patch], callFor());

    // The file must be moved to the nested target directory with all intermediate dirs created
    expect(\file_exists($this->dir . '/source.txt'))->toBeFalse()
        ->and(\file_get_contents($this->dir . '/deep/nested/subdir/target.txt'))->toBe("original\nmodified\n")
        ->and($result->content[0]->text)->toContain("Moved 'source.txt' to 'deep/nested/subdir/target.txt'");
});

it('fails the whole patch when an Update File references a missing file, leaving every file untouched', function () {
    // Tests ApplyPatch.php line 114 (file does not exist check in planUpdate).
    // Checked against ApplyPatchTest "fails the whole patch when a hunk context is not found,
    // leaving every file untouched" which tests when a hunk's context cannot be found.
    // Here we test when the file being updated doesn't exist at all.
    // The patch must fail atomically: no other section's changes should be written.
    \file_put_contents($this->dir . '/existing.txt', "keep me\n");

    $patch = <<<'PATCH'
    *** Begin Patch
    *** Add File: new.txt
    +hello
    *** Update File: missing.txt
    @@
     original
    -content
    +changed
    *** End Patch
    PATCH;

    $apply = new ApplyPatch($this->root);
    expect(fn () => $apply->call(['patch' => $patch], callFor()))
        ->toThrow(RuntimeException::class, "'missing.txt' does not exist");

    // The patch must have failed atomically; no new file should have been created
    expect(\is_file($this->dir . '/new.txt'))->toBeFalse()
        ->and(\file_get_contents($this->dir . '/existing.txt'))->toBe("keep me\n");
});

it('specifies apply_patch with patch property required and destructive hints', function () {
    // Tests ApplyPatch::spec() (lines 31-37), which was not previously asserted.
    // Checked against FilesTest "marks the read-only tools read-only and the others destructive"
    // which tests spec() hints for ReadFile/ListDir/Glob/Grep and WriteFile/EditFile, but not ApplyPatch.
    // This test verifies the specification that apply_patch requires:
    // - Tool name: 'apply_patch'
    // - Required property 'patch' of type string
    // - Destructive hints: true
    // - Description mentioning "*** Begin Patch"
    $apply = new ApplyPatch($this->root);
    $spec  = $apply->spec();

    expect($spec->name)->toBe('apply_patch')
        ->and($spec->hints->destructive)->toBeTrue()
        ->and($spec->hints->readOnly)->toBeFalse()
        ->and($spec->schema['type'])->toBe('object')
        ->and($spec->schema['required'])->toBe(['patch'])
        ->and($spec->schema['properties']['patch']['type'])->toBe('string')
        ->and($spec->description)->toContain('*** Begin Patch');
});
