<?php

declare(strict_types=1);

use Harness\Event\ToolProgress;
use Harness\Policy\Shell;
use Harness\Policy\Verdict;
use Harness\Tools\Shell\Shell as ShellTool;

/*
 * Shell::deny()'s docblock promises it "matches the program by its base name (`rm`, `/bin/rm`,
 * `'r'm`, `r\m`), whatever its arguments" -- i.e. every syntactic way of spelling the denied
 * program's name is caught, however it is quoted or escaped. docs/policy.md repeats the same
 * list of tricks as caught.
 *
 * Shell::matches() treats the two verdicts asymmetrically: for Allow it refuses to match when
 * the program word holds an unquoted glob (`$globbed[0]`), because the glob "could expand to any
 * option" (correctly conservative -- fail closed). For Deny it does the opposite: it compares
 * `basename($argv[0])` to the literal, unexpanded word and ignores `$globbed[0]` entirely, so a
 * program name spelled with a glob metacharacter that bash would expand to the denied name (e.g.
 * `r[m]`, which expands to `rm` whenever a file named exactly "rm" sits in the shell's cwd) is
 * compared against the glob pattern itself, never matches, and falls through to $otherwise.
 *
 * Verified directly against real bash: with a file named "rm" in the cwd, `r[m] --version` prints
 * "rm (GNU coreutils) ...", i.e. bash really does run the genuine /bin/rm. The denied program is
 * one shell-glob trick away from running, and Rule::shell()'s default (and every example in
 * docs/policy.md) is `otherwise: Verdict::Ask`, so a `Shell::deny('rm')` rule -- meant to make rm
 * unrunnable -- degrades to asking a human for approval, with a reason string ("the shell command
 * 'r[m] -rf /tmp/x' matches no allow rule") that never spells out that the command is rm. This
 * is a materially weaker guarantee than what deny() documents, and is not the already-known
 * `command`/`builtin`/`exec` peeling gap (issue #4): the word here isn't a wrapper to peel, it's
 * the program name itself, misjudged by the glob check deny() skips.
 *
 * Shell::check() has no filesystem access and never resolves whether a glob would actually
 * expand to the denied name -- it only has the unexpanded text, same as the real parser -- so the
 * fix has to be conservative: a glob in the program word position must be treated as "could be
 * anything, including a denied program", not "definitely not the denied program".
 */
it('denies a command whose program name is spelled with a glob that bash would expand to the denied program', function () {
    $rules = [Shell::deny('rm')];

    // Today: Shell::matches() for Deny does `basename($argv[0]) === $this->program` without ever
    // looking at $globbed[0], so the literal word 'r[m]' (which real bash expands to 'rm' given a
    // file called "rm" in the cwd -- confirmed with a real bash + coreutils rm) is compared
    // against 'rm', doesn't match, and the command falls through to $otherwise (Ask, the default
    // for Rule::shell() and every example in docs/policy.md), not Deny.
    // #12: this fell through to Ask; a glob-spelled program is now denied whatever the rules
    expect(Shell::check('r[m] -rf /tmp/x', $rules)[0])->toBe(Verdict::Deny)
        ->and(Shell::check('r? -rf /tmp/x', $rules)[0])->toBe(Verdict::Deny);
});

it('peels command, builtin and exec so a deny rule still reaches the wrapped program', function () {
    // #4: these prefixes were not peeled, so `command rm` matched no rule and degraded to Ask
    $rules = [Shell::deny('rm')];
    expect(Shell::check('command rm -rf /tmp/x', $rules)[0])->toBe(Verdict::Deny)
        ->and(Shell::check('builtin rm /tmp/x', $rules)[0])->toBe(Verdict::Deny)
        ->and(Shell::check('exec -a sh rm -rf /tmp/x', $rules)[0])->toBe(Verdict::Deny)
        ->and(Shell::check('command -v rm', $rules)[0])->toBe(Verdict::Deny);
});

/*
 * docs/shell.md documents the Shell tool's Result unconditionally: "A non-zero exit, a timeout
 * or a cancellation is an error result; `value` is the exit code." All three listed conditions
 * are grouped in one sentence and both clauses ("is an error result" and "value is the exit
 * code") read as applying to all three -- there is no carve-out saying value is something else
 * for a timeout or a cancellation.
 *
 * Harness\Tools\Shell\Shell::call() does not keep that promise: its Result is built with
 * `value: null !== $killed ? null : $exit` -- i.e. whenever the call ends by timeout or
 * cancellation ($killed is set), `value` is hard-coded to `null`, never the exit code. Only a
 * plain non-zero exit (no kill) gets `value` set to the real exit code. `value` is not cosmetic:
 * Result's own docblock says it is "what the application gets (in the ToolEnded event)", so a
 * host that reads `ToolEnded->value` to record what a timed-out command did gets `null` instead
 * of the exit code docs/shell.md promises.
 *
 * Verified directly: a plain `exit 7` gives value=7 as documented; the identical command run
 * under a timeout that kills it gives value=null, not an int, under both adapters.
 */
it('gives a killed call a Result without a value, since a killed process has no exit code', function () {
    // #18: docs/shell.md promised the exit code for a timeout too; the code never had one to give
    $r = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir()))->call(['command' => 'sleep 5; exit 7', 'timeout' => 0.2], callFor()));

    expect($r->error)->toBeTrue()
        ->and($r->content[0]->text)->toStartWith('Killed: timed out after 0.2 s')
        ->and($r->value)->toBeNull();
});

/*
 * Shell's constructor takes `$cwd` and only ever checks `is_dir($cwd)` once, at construction
 * time, then keeps the given string verbatim in `private string $cwd`. `call()` later hands that
 * same string to `proc_open()` as the 4th argument (the child's working directory). Nothing in
 * the class ever resolves `$cwd` to an absolute path.
 *
 * docs/shell.md's own example constructs it with an absolute path (`cwd: '/srv/project'`), but
 * the constructor places no such requirement on the caller, and `is_dir()` happily accepts and
 * validates a relative one. A host is a long-running process (the whole point of a PHP agent
 * harness): many unrelated things can run between when one `Shell` instance is built and when
 * one of its calls actually executes -- another tool, another library, anything that calls
 * `chdir()` for its own purposes. If `$cwd` was relative, the *directory it resolves to* silently
 * depends on whatever the process's current directory happens to be at call time, not at
 * construction time, even though `is_dir($cwd)` gave every appearance of having pinned it down.
 *
 * Verified directly: constructing `new Shell('sub')` next to an existing `sub/` directory passes
 * the is_dir() check and runs correctly once; after the process `chdir()`s anywhere else (not
 * touching the Shell object at all), the *same* instance's next call() does not just run in the
 * wrong directory -- proc_open() fails outright ("posix_spawn(): No such file or directory") and
 * Shell::call() lets an uncaught RuntimeException ("Could not start bash") escape, instead of the
 * Result every other failure path in this class returns. One relative Shell tool anywhere in a
 * host's toolset is one unrelated chdir() elsewhere in the process away from crashing the run.
 */
it('keeps running commands in the directory it was built with, even after the process chdir()s elsewhere', function () {
    $base = \sys_get_temp_dir() . '/harness-shell-relcwd-' . \bin2hex(\random_bytes(4));
    \mkdir($base . '/sub', 0777, true);
    $resolved = \realpath($base . '/sub');
    $started  = \getcwd();
    try {
        \chdir($base);
        $shell = new ShellTool('sub'); // relative: valid right now, resolved against the cwd of this moment

        \chdir(\sys_get_temp_dir()); // something else in the process moves on, unrelated to $shell

        // Today: this throws an uncaught RuntimeException('Could not start bash') instead of
        // returning a Result, because 'sub' no longer exists relative to the new cwd.
        $r = \fiber\run(fn () => $shell->call(['command' => 'pwd'], callFor()));

        expect($r->error)->toBeFalse()
            ->and(\rtrim($r->content[0]->text, "\n"))->toBe("Exit code 0\n$resolved");
    } finally {
        \chdir($started);
        \exec('rm -rf ' . \escapeshellarg($base));
    }
}); // #23: the second call failed with 'Could not start bash', the relative cwd resolved against the new directory

/*
 * Shell::call()'s `$take` closure cuts the middle of long output by byte offset only: `$head`
 * grows by `substr($chunk, 0, $half - strlen($head))` until it reaches `intdiv(maxOutput, 2)`
 * bytes, and `$tail` is kept to the last `$half` bytes via `substr($tail . $chunk, -$half)`.
 * Neither cut point has any notion of a character boundary -- `strlen`/`substr` are byte
 * functions over the command's raw output.
 *
 * `Harness\Part\Text` wraps the result in a plain `string $text`, and a model is addressed over
 * JSON, which requires a valid UTF-8 string. docs/shell.md promises "the model gets ... the
 * output" for exactly this truncated-output path ("the middle cut out past maxOutput bytes").
 *
 * When the real output is UTF-8 text and the byte offset for `$half` lands inside a multi-byte
 * character, the cut slices the character in half: `$head` (or `$tail`) ends with a dangling
 * lead byte or an orphaned continuation byte, and the resulting text is not valid UTF-8 --
 * `json_encode()` on it returns `false`. No adversarial crafting of the command is needed for
 * this: any sufficiently long non-ASCII output (international text, emoji, accented names in a
 * tool's own diagnostics, ...) misaligns against whatever `maxOutput` the host chose about as
 * often as not, since UTF-8 characters are 1 to 4 bytes and the cut point never aims for a
 * boundary.
 *
 * Verified directly: 2000 repetitions of the 3-byte character "日" (U+65E5) through a real `yes
 * | head | tr` pipeline, cut at `maxOutput: 101` (half = 50, not a multiple of 3): the resulting
 * text fails `mb_check_encoding(..., 'UTF-8')`, and `json_encode()` on it returns `false` --
 * exactly the call a model-API client makes to put this tool result on the wire.
 */
it('cuts long multi-byte UTF-8 output on a character boundary, not a raw byte offset', function () {
    // 2000 copies of the 3-byte character U+65E5 ("日"), well past maxOutput; half=50 is not a
    // multiple of 3, so a byte-only cut is guaranteed to land inside a character, in the head or
    // the tail.
    $r = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir(), maxOutput: 101))->call(
        ['command' => "yes \xe6\x97\xa5 | head -n 2000 | tr -d '\\n'"],
        callFor()
    ));

    // Today: $r->content[0]->text is not valid UTF-8 (the cut severs a "日" mid-character), so
    // json_encode() on it -- what a model-API client does to send this tool result -- returns
    // false instead of the JSON string every other Result's text produces.
    expect(\mb_check_encoding($r->content[0]->text, 'UTF-8'))->toBeTrue()
        ->and(\json_encode($r->content[0]->text))->not->toBeFalse();
}); // #25: the cut left half a character, invalid UTF-8 that json_encode refuses

/*
 * `Event`'s own docblock states its contract plainly: `toArray()` gives the event's fields, "and
 * it is also what `json_encode()` writes" -- `Event implements \JsonSerializable` via
 * `jsonSerialize(): array { return $this->toArray(); }`. A host streams events (that is what
 * `ToolProgress` is for: so a host can render a command's output as it arrives), and the one
 * supported way to put an event on the wire, per that same docblock, is `json_encode()`.
 *
 * #25 fixed exactly this invalid-UTF-8-breaks-JSON failure for the *final* text: `Shell::call()`
 * now runs `$output` through `Utf8::scrub()` before building the `Result`'s `Text`. But
 * `$take()` reports every chunk to `$call->progress($chunk)` with the chunk exactly as read from
 * the pipe -- raw, unscrubbed bytes -- before any cutting or scrubbing happens at all.
 * `ToolProgress::$value` is that same raw string, forwarded into the event stream untouched.
 *
 * So the identical failure #25 fixed for the final `Result` text is still live one layer up, on
 * every `ToolProgress` event for any command whose output isn't clean UTF-8 (binary output, or
 * any chunk boundary that happens to split a multi-byte character) -- it just never gets
 * exercised by a test that only reads the final `Result`.
 *
 * Verified directly: a command printing four non-UTF-8 bytes produces a `ToolProgress` event
 * whose `value` is NOT valid UTF-8, and `json_encode()` on that event returns `false`
 * ("Malformed UTF-8 characters, possibly incorrectly encoded") -- even though the same call's
 * final `Result` text, built from the very same bytes, comes out scrubbed and valid.
 */
it('reports progress chunks a host can actually json_encode(), not just the final scrubbed text', function () {
    $events = [];
    $call   = callFor('c1', $events);
    $r      = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir()))->call(['command' => "printf '\\xff\\xfe\\x80invalid'"], $call));

    $progress = \array_values(\array_filter($events, static fn ($e) => $e instanceof ToolProgress));

    expect($progress)->not->toBeEmpty()
        ->and(\mb_check_encoding($r->content[0]->text, 'UTF-8'))->toBeTrue() // #25: the final text is scrubbed
        // Today: the raw chunk never went through Utf8::scrub(), so this event is not valid UTF-8
        // and json_encode() on it -- the one documented way to put an Event on the wire -- fails.
        ->and(\json_encode($progress[0]))->not->toBeFalse();
}); // #32: progress carried the raw pipe bytes

/*
 * `maxOutput`'s entire job, per its own constructor parameter name and docs/shell.md ("the
 * output, the middle cut out past `maxOutput` bytes with a marker"), is to bound how much of a
 * command's output the final `Result` keeps. `$take()` computes `$half =
 * intdiv($this->maxOutput, 2)` once per chunk and keeps `$tail = substr($tail . $chunk,
 * -$half)`.
 *
 * `substr()` is called here with only two arguments (string, start) -- no length. When `$half`
 * is `0` (which `intdiv($maxOutput, 2)` gives for both `maxOutput: 0` and `maxOutput: 1`, the
 * smallest values the constructor accepts -- there is no validation rejecting them),
 * `substr($string, -0)` is `substr($string, 0)`, since PHP has no negative zero for an int:
 * `-0 === 0`. With a bare `0` start and no length, `substr()` means "from the start to the end of
 * the string" -- i.e. the *whole* string, not "keep the last 0 characters" as the formula
 * intends. The same degenerate case means `$head` never grows either (`strlen($head) < $half` is
 * `0 < 0`, always false), so every byte that would have gone to `$head` falls through to this
 * same unbounded `$tail`.
 *
 * The result: at `maxOutput: 0` or `maxOutput: 1`, the cut mechanism is not merely off by one --
 * it does not cut at all. `$tail` keeps growing with every chunk, with no marker ever emitted
 * (the `$total > strlen($head) + strlen($tail)` check that would print "[N bytes cut]" is false
 * because `$tail` ends up holding the *entire* output), so the one guarantee `maxOutput` exists
 * to give -- a bounded amount of text reaching the model -- is silently absent at its own two
 * smallest accepted values.
 *
 * Verified directly: `new Shell($cwd, maxOutput: 1)` running `seq 1 5000` (ordinarily cut to
 * roughly 1 byte either side of a marker) returns the full, uncut ~24 KB of output with no
 * "bytes cut" marker at all.
 */
it('refuses a maxOutput of 0 or 1, which could not bound the output at all', function (int $maxOutput) {
    // #37: half = intdiv(maxOutput, 2) was 0 and substr($tail, -0) kept everything: `seq 1 5000` came back uncut
    expect(fn () => new ShellTool(\sys_get_temp_dir(), maxOutput: $maxOutput))
        ->toThrow(InvalidArgumentException::class, 'at least 2');
})->with([0, 1]);
