# Harness\Tools\Files

Six tools over a directory. A `Root` is the directory they may touch; every path is relative to it
(or absolute inside it), and one that leaves it, lexically or through a symlink, is an error result.

```php
$root  = new Root('/srv/project');
$tools = new Tools(new ReadFile($root), new ListDir($root), new Glob($root), new Grep($root),
                   new WriteFile($root), new EditFile($root));
```

| Tool | Input | Hints |
| --- | --- | --- |
| `read_file` | `path`, `offset` (1-based line), `limit` | readOnly, idempotent |
| `list_dir` | `path`, `offset` | readOnly, idempotent |
| `glob` | `pattern` (`*`, `?`, `**`, `[ab]`, `{a,b}`), `path`, `offset` | readOnly, idempotent |
| `grep` | `pattern` (PCRE, no delimiters), `path`, `glob`, `ignore_case`, `offset` | readOnly, idempotent |
| `write_file` | `path`, `content` (creates parent directories) | destructive, idempotent |
| `edit_file` | `path`, `old`, `new` (`old` must occur exactly once) | destructive |

Output is cut with a tail that says how to go on (`[120 more lines; pass offset=2001 to read on]`),
never silently. `read_file` returns PNG, JPEG, GIF and WebP files as Media and refuses other binaries;
lines past `maxLineLength` are cut with a marker. `glob` and `grep` skip `.git` and do not follow
symlinked directories; `grep` skips binary files and says how many.

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
outside the root, a missing file or a non-unique `old` throws `\RuntimeException`, which the model
sees as an error result and the log records. Limits are constructor arguments (`limit`,
`maxLineLength`).
