# Harness\Tools\Files

Seven tools over a directory. A `Root` is the directory they may touch; every path is relative to it
(or absolute inside it), and one that leaves it, lexically or through a symlink, is an error result.

```php
$root  = new Root('/srv/project');
$tools = new Tools(new ReadFile($root), new ListDir($root), new Glob($root), new Grep($root),
                   new WriteFile($root), new EditFile($root), new ApplyPatch($root));
```

| Tool | Input | Hints |
| --- | --- | --- |
| `read_file` | `path`, `offset` (1-based line), `limit` | readOnly, idempotent |
| `list_dir` | `path`, `offset` | readOnly, idempotent |
| `glob` | `pattern` (`*`, `?`, `**`, `[ab]`, `{a,b}`), `path`, `offset` | readOnly, idempotent |
| `grep` | `pattern` (PCRE, no delimiters), `path`, `glob`, `ignore_case`, `offset` | readOnly, idempotent |
| `write_file` | `path`, `content` (creates parent directories) | destructive, idempotent |
| `edit_file` | `path`, `old`, `new` (`old` must match exactly once) | destructive |
| `apply_patch` | `patch` (the V4A format) | destructive |

Output is cut with a tail that says how to go on (`[120 more lines; pass offset=2001 to read on]`),
never silently. `read_file` returns PNG, JPEG, GIF and WebP files as Media and refuses other binaries;
lines past `maxLineLength` are cut with a marker. `glob` and `grep` skip `.git` and do not follow
symlinked directories; `grep` skips binary files and says how many.

## edit_file's matching cascade

`old` is tried byte-exact first; only when that matches zero times does a tolerant cascade run,
each level tried in order and accepted only when it alone matches exactly once: (2) line-trimmed
(each line of `old` against the file's lines with leading and trailing whitespace ignored), (3)
whitespace-normalized (runs of whitespace within a line collapsed), (4) indentation-flexible (the
relative indentation between `old`'s own lines must match, but the common indent the whole block
sits at may differ — `new` is re-indented by the same delta), and (5) block anchor (for `old` of
3+ lines: the first and last line match exactly and the lines between are at least 90% similar, for
a block with one changed line in the middle). A level that matches more than once stops the cascade
there: the ambiguous error names the level and lists every candidate's line; the cascade never falls
through to a looser level once one has matched more than once. Zero matches at every level is an
error quoting the closest region by similarity, so the model can correct `old`. A successful edit's
result shows the edited region with 3 lines of context each side, numbered as `read_file` numbers
them, and names the level when it was not exact ("matched with whitespace differences on lines
40-46"). The replacement always keeps the file's own line ending style (LF or CRLF).

`EditFile` and `WriteFile` take an optional `?\Closure $check` run after a successful write
(`fn (string $absolutePath): ?string`, an error message or null); a non-null message is appended to
the result as `Check: <message>` — the write stays, so the model sees the problem and can fix it in
a further call:

```php
new EditFile($root, check: function (string $abs): ?string {
    \exec("php -l " . \escapeshellarg($abs) . ' 2>&1', $out, $code);
    return 0 === $code ? null : \implode("\n", $out);
});
```

## apply_patch

Applies a patch in the V4A format (the format OpenAI's `apply_patch` uses):

```
*** Begin Patch
*** Add File: src/greet.php
+<?php
+function greet(): string { return 'hi'; }
*** Update File: src/app.php
@@
 require __DIR__ . '/greet.php';
-echo 'hello';
+echo greet();
*** Delete File: src/old.php
*** End Patch
```

`*** Update File:` may be followed by `*** Move to: <path>` to rename the file. Each `@@` starts a
hunk (optionally `@@ <context line>` naming what it is near, for a human reading the patch); its
lines are context (` `), removed (`-`) or added (`+`); `*** End of File` after the last hunk is
allowed. Hunks are located by their context and removed lines through `edit_file`'s cascade, levels
1-3 only (exact, line-trimmed, whitespace-normalized — not the indentation or block-anchor levels),
never by line numbers. The whole patch is built in memory first: a hunk whose context is not found,
or matches more than once, fails the **whole patch** with an error naming the file, the hunk's
index and the context, and no file is changed. The result lists each file with the lines added and
removed, and for an updated file, the edited region of each hunk as `edit_file` reports one.

## Journal

`WriteFile`, `EditFile` and `ApplyPatch` take an optional `?Journal $journal`; when given, each
records a changed or deleted path's content (or absence) before touching it, the first time that
path is touched. `Journal::undo(): list<string>` restores every recorded path to that first state,
in reverse order, and returns the paths; `Journal::changed(): list<string>` lists what has been
touched so far; `Journal::clear()` drops the record without touching disk. The host decides the
scope — a fresh `Journal` per turn undoes that turn's writes; one kept for a session undoes
everything since it was created.

```php
$journal = new Journal();
$tools   = new Tools(new EditFile($root, journal: $journal), new WriteFile($root, journal: $journal));
// ... a turn runs, writing files ...
if ($theUserSaysUndo) {
    $journal->undo();   // every path the turn touched, back to how it was
}
```

## Root

`new Root($dir)` resolves `$dir` with `realpath()` and throws `\InvalidArgumentException` unless it is
a directory. A host's own tool over the same directory uses its public API:

- `$root->path`: the absolute, symlink-free directory.
- `$root->resolve($path)`: the absolute path for `$path` (relative to the root, or absolute inside
  it), normalized lexically (`.`, `..`, repeated `/`), which need not exist. It throws
  `\RuntimeException` when the path leaves the root lexically, when its nearest existing ancestor
  resolves (through a symlink) outside the root, or when it holds a NUL byte.
- `$root->relative($abs)`: an absolute path inside the root as the model should see it, `.` for the
  root itself.

Containment is checked when a path is resolved; a symlink created afterwards is not seen, so a
tool reads or writes right after `resolve()`.

## Errors

Missing or mistyped input is a `Retry` (the model corrects it, counted against the budget); a path
outside the root, a missing file, a non-unique `old` or an unlocatable `apply_patch` hunk throws
`\RuntimeException`, which the model sees as an error result and the log records. A malformed patch
(missing `*** Begin Patch` / `*** End Patch`, a line that is neither a marker nor a prefixed content
line) is also a `\RuntimeException` naming the line. Limits are constructor arguments (`limit`,
`maxLineLength`).
