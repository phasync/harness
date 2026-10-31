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

/*
 * `Shell`'s (the Policy rule class's) constructor validates `$program` against exactly two
 * things: `'' === $program` and `str_contains($program, '/')`, each with its own instructive
 * `InvalidArgumentException`. Nothing validates whitespace. But `matches()` for a Deny rule does
 * `basename($argv[0]) === $this->program` -- an *exact* string comparison against a word the
 * tokenizer in `parse()` already split on spaces and tabs, so `$argv[0]` (and its basename) can
 * never legitimately contain whitespace. A program name with leading, trailing or embedded
 * whitespace can therefore never equal any real `basename($argv[0])`, ever -- the rule is
 * constructed without complaint and then matches nothing, for the rest of its life.
 *
 * `deny()`'s own docblock promises it "matches the program by its base name (`rm`, `/bin/rm`,
 * `'r'm`, `r\m`), whatever its arguments" -- a promise this breaks completely, silently, for the
 * single most plausible authoring mistake there is: a stray trailing space from a copy-paste, a
 * trailing newline trimmed from a config line but not quite all the way, or a list of program
 * names built with `"$program "` string interpolation. `Shell::allow('rm ')` would merely degrade
 * to never-Allow (fail closed, harmless); `Shell::deny('rm ')` degrades to never-Deny -- the
 * dangerous direction -- with no exception, no warning, nothing: it just quietly stops
 * protecting against the exact program the host meant to block.
 *
 * Verified directly: `Shell::deny('rm ')` (one trailing space) constructs without error, and
 * `Shell::check('rm -rf /', [Shell::deny('rm ')])` comes back `Ask` ("the shell command 'rm -rf'
 * matches no allow rule"), never `Deny` -- for the exact command the host's rule was written to
 * stop.
 */
it('refuses a shell rule program name with whitespace, which could never match a real argv[0]', function () {
    // Today: this constructs fine, and the rule silently never matches anything -- a deny rule
    // for 'rm ' (one trailing space) never denies `rm`, degrading to whatever $otherwise is.
    expect(fn () => Shell::deny('rm '))
        ->toThrow(InvalidArgumentException::class, 'whitespace');
}); // #40: constructed fine and matched nothing, so 'rm -rf /' was Ask

/*
 * #37 established the precedent for this constructor: a parameter that could never do its job
 * (`maxOutput` below 2, which cannot hold even one byte on each side of a cut) is refused at
 * construction with an instructive `InvalidArgumentException`, not left to misbehave at call
 * time. `timeout` and `maxTimeout` get no such check -- `new Shell($cwd, timeout: 0)` and `new
 * Shell($cwd, timeout: -5.0)` both construct without complaint, even though every single future
 * call is now broken by it.
 *
 * The breakage lands on the *model*, not the host who misconfigured it: `Shell::call()` reads
 * `$timeout = $input['timeout'] ?? $this->timeout` and, when the model's tool call omits
 * `timeout` (the ordinary case -- the spec says it is optional, with "default
 * {$this->timeout} s"), the broken construction-time default flows straight into `if (...
 * $timeout <= 0) { throw new Retry("'timeout' must be a positive number of seconds"); }`. The
 * model gets told its input is wrong and should retry -- but the model supplied no `timeout` at
 * all; the host's own default is what failed the check. Every call to this tool, for as long as
 * it exists, hits the identical confusing `Retry`, for a mistake entirely outside the model's
 * control, when #37's own ruling says this class of misconfiguration belongs at construction.
 *
 * Verified directly: `new Shell($cwd, timeout: 0)` constructs successfully; calling it with
 * `['command' => 'echo hi']` (no `timeout` key, exactly as the spec invites) throws
 * `Harness\Retry: 'timeout' must be a positive number of seconds` -- never reaching bash at all.
 */
it('refuses a non-positive timeout at construction, like #37 refused a too-small maxOutput', function (float $timeout) {
    expect(fn () => new ShellTool(\sys_get_temp_dir(), timeout: $timeout))
        ->toThrow(InvalidArgumentException::class, 'positive number of seconds');
})->with([0.0, -5.0, \NAN, \INF]); // #43: constructed fine; every call without a 'timeout' input was a Retry blaming the model

it('refuses a maxTimeout below the default timeout and a cwd it cannot enter', function () {
    expect(fn () => new ShellTool(\sys_get_temp_dir(), timeout: 10.0, maxTimeout: 5.0))
        ->toThrow(InvalidArgumentException::class, 'at least the default timeout');
    if (0 === \posix_geteuid()) {
        return; // root enters any directory
    }
    $dir = \sys_get_temp_dir() . '/harness-locked-' . \bin2hex(\random_bytes(4));
    \mkdir($dir, 0o000);
    try {
        expect(fn () => new ShellTool($dir))->toThrow(InvalidArgumentException::class, 'cannot be entered');
    } finally {
        \chmod($dir, 0o700);
        \rmdir($dir);
    }
}); // #44: the first call failed with 'Could not start bash' and no word about the cwd

/*
 * The tool spec declares `timeout` as `['type' => 'number', ...]`, and `Shell::call()` validates
 * it itself, by hand, rather than through `Harness\Tools\Input` (which has no `float()` helper at
 * all -- only `string()`, `int()` and `bool()`). That hand-rolled check is
 * `!\is_int($timeout) && !\is_float($timeout) || $timeout <= 0`, which rejects a numeric string
 * like `"5"` outright with `Retry("'timeout' must be a positive number of seconds")` -- a message
 * that reads as if the *value* were wrong (negative, zero, NaN), when `"5"` is exactly the
 * positive number of seconds it asks for, just spelled as a JSON string rather than a JSON
 * number.
 *
 * This is a real possibility, not a contrived one: tool-call arguments are reconstructed from a
 * model's own generated JSON (or, for some providers/SDKs, from loosely-typed function-call
 * argument strings), and models are well known to sometimes emit a numeric field as a quoted
 * string. `Input::int()`, the project's own established helper for exactly this situation on
 * another numeric field, explicitly tolerates and coerces a digit-only string
 * (`'/^-?\d+$/'`) rather than rejecting it. `Shell::call()`'s bespoke `timeout` check does not
 * extend the same leniency to its own numeric field, so the one tool in this codebase that rolls
 * its own numeric parsing is stricter than the codebase's own convention for numeric input,
 * for no documented reason.
 *
 * Verified directly: calling with `['command' => 'sleep 5', 'timeout' => '0.3']` throws
 * `Harness\Retry: 'timeout' must be a positive number of seconds` immediately, never starting
 * bash at all, for a value that is unambiguously a valid, positive, well-formed number of
 * seconds.
 */
it('accepts timeout as a numeric string, as Input::int() already does for its own numeric field', function () {
    // Today: is_int('0.3') and is_float('0.3') are both false, so this throws a Retry blaming
    // the value as non-positive, even though "0.3" is a perfectly well-formed positive number.
    $r = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir()))->call(['command' => 'sleep 5', 'timeout' => '0.3'], callFor()));

    expect($r->content[0]->text)->toStartWith('Killed: timed out after 0.3 s');
}); // #51: a hand-rolled is_int/is_float check gave a Retry for '0.3'

/*
 * `Input::number()`'s docblock promises "a number", with `$min` as an *exclusive* lower bound
 * ("so a positive number is `$min = 0`"). It explicitly screens out NaN (`is_nan($value)`) and
 * anything at or below `$min` -- but nothing screens out positive infinity. `is_numeric('1e400')`
 * is true (it is syntactically a well-formed number), and `(float) '1e400'` overflows to `INF`,
 * which is neither NaN nor `<= 0`, so it sails straight through and `Input::number()` returns
 * `INF` as if it were an ordinary, usable number. The same is true for a raw PHP float `INF`
 * passed directly (no string involved at all) -- `is_float(INF)` is true, so the type check never
 * even considers it.
 *
 * `Shell::call()`'s own specific use of this happens not to be exploitable, purely by luck of
 * argument order: `\min(Input::number(...), $this->maxTimeout)` and PHP's `min(INF, $finite)`
 * returns the finite value. But `Input` is `@internal` shared infrastructure ("reads typed
 * fields from a tool's input"), not a Shell-only helper, and nothing in its own contract makes
 * that caller-side `min()` a requirement -- a different field on a different tool (a retry count,
 * a sleep duration, a byte limit, anything that does not separately clamp its result) would take
 * `Input::number($input, 'n', 1.0)` at its word that the return is a well-formed, finite number,
 * and hang or loop forever on a model-supplied `"1e400"`. #43 (this same round-trip) already
 * established that an infinite `timeout` is exactly the kind of value this codebase considers
 * invalid enough to reject outright at the `Shell` constructor; `Input::number()`, the shared
 * validator meant to produce exactly these numbers safely, does not hold its *own* output to that
 * standard.
 *
 * Verified directly: `Input::number(['n' => '1e400'], 'n', 1.0)` and
 * `Input::number(['n' => \INF], 'n', 1.0)` both return `INF` instead of throwing `Retry`.
 */
it('rejects an infinite number, not just NaN, the same way it already screens its own output', function (string|float $value) {
    // Today: is_nan(INF) is false and INF > 0 (or > any finite $min), so this returns INF instead
    // of throwing -- the one check this function exists to make (give back a usable number) is
    // not actually made for the one non-finite value is_nan() does not catch.
    expect(fn () => Harness\Tools\Input::number(['n' => $value], 'n', 1.0))
        ->toThrow(Harness\Retry::class, 'finite');
})->with(['1e400', \INF]); // #57: only NaN and values below the minimum were screened

/*
 * `proc_open()` fails for more reasons than a relative cwd (#23), an unenterable cwd (#44), or a
 * missing executable -- one more is a `command` too long for the OS to accept as a single argv
 * element: `execve()`/`posix_spawn()` fail with E2BIG once argv plus envp exceeds `ARG_MAX`
 * (`getconf ARG_MAX` on this machine: 2 MiB). Every one of those earlier fixes narrowed a
 * *construction-time* check (cwd resolution, permission), because cwd is fixed once per `Shell`
 * instance. `command`, by contrast, is the one input the model supplies fresh on every call --
 * there is no construction-time check that could ever catch this, and it is squarely within a
 * model's own reach to produce a very long command (a heredoc, a big embedded literal, output
 * from a previous step pasted back in).
 *
 * `Shell::call()` still has exactly one response to `proc_open()` returning `false`: `throw new
 * \RuntimeException('Could not start bash')`, uncaught, exactly like the two now-fixed cases.
 * Every *other* way this tool can fail -- a non-zero exit, a timeout, a cancellation -- is
 * documented and returned as an ordinary error `Result` the model can read and react to; this one
 * is not, and the model never learns its own command was the problem, let alone that length was
 * the reason, because the exception never reaches a `Result` at all.
 *
 * Verified directly: a `command` of 4 MiB (comfortably past this system's 2 MiB `ARG_MAX`) makes
 * `proc_open()` emit a `PHP Warning` ("Argument list too long") and return `false`, and
 * `Shell::call()` lets the `RuntimeException` escape uncaught instead of returning a `Result`.
 */
it('returns an error result, not an uncaught exception, when the command is too long for the OS to accept', function () {
    // Past ARG_MAX (2 MiB on this system; 4 MiB clears any ordinary ceiling). Today: proc_open()
    // returns false and Shell::call() throws RuntimeException('Could not start bash') uncaught,
    // the same unhandled-failure shape as #23 and #44, but for an input the model controls on
    // every call, which no construction-time check can ever guard against.
    $huge = \str_repeat('a', 4 * 1024 * 1024);
    $r    = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir()))->call(['command' => "echo $huge"], callFor()));

    expect($r)->toBeInstanceOf(Harness\Result::class)
        ->and($r->error)->toBeTrue();
}); // #60: RuntimeException('Could not start bash') plus a PHP warning

/*
 * docs/shell.md: "the output, the middle cut out past `maxOutput` bytes with a marker" -- a cut,
 * and its marker, should happen only once output goes *past* the budget. `$take()` computes
 * `$half = \intdiv($this->maxOutput, 2)` and keeps up to `$half` bytes in `$head` and the last
 * `$half` bytes in `$tail`, so the combined capacity of head-plus-tail is `2 * $half`. When
 * `maxOutput` is odd, integer division floors, so `2 * \intdiv($maxOutput, 2) ==  $maxOutput - 1`
 * -- one byte short of what was configured. `maxOutput` itself was never required to be even (and
 * nothing documents that it should be).
 *
 * The result: for an odd `maxOutput`, output of exactly `maxOutput` bytes -- not "past" the
 * budget by docs/shell.md's own wording, output that should come back whole with no marker at
 * all -- instead gets a `"[1 bytes cut]"` marker spliced into the middle, and the one byte that
 * marker claims was cut is not a decoding artifact (no UTF-8 is involved at all, here) -- it is a
 * real byte of the command's real output, gone, with no trace of it anywhere in the `Result` or
 * in any `ToolProgress` event. The same one-byte shortfall silently applies to every larger
 * output too (an odd `maxOutput` always holds one fewer total byte than it says it does), but the
 * exactly-at-the-budget case is the clearest demonstration, since it is the one case where the
 * tool's own documented contract says no cut should happen at all.
 *
 * Verified directly: `maxOutput: 11` running `printf 0123456789A` (exactly 11 bytes, pure ASCII,
 * no multi-byte characters anywhere near the cut) returns `"01234\n[1 bytes cut]\n6789A"` -- the
 * character `"5"` is gone, for output that never exceeded the configured budget.
 */
it('does not cut output that is only exactly maxOutput bytes, for an odd maxOutput', function () {
    // Today: half = intdiv(11, 2) = 5, so head+tail only ever hold 10 of the 11 bytes this budget
    // was supposed to allow; the byte at the exact midpoint ("5") is dropped and a spurious
    // "[1 bytes cut]" marker appears, even though 11 bytes of output never went past an 11-byte budget.
    $r = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir(), maxOutput: 11))->call(['command' => 'printf 0123456789A'], callFor()));

    expect($r->content[0]->text)->toBe("Exit code 0\n0123456789A")
        ->and($r->content[0]->text)->not->toContain('bytes cut');
}); // #70: intdiv floored both halves, one byte short of maxOutput

/*
 * Round 13 attacked the cut/progress contract from the host's side: does the sum of
 * `ToolProgress` chunks reconstruct the complete, uncut output, and does the `"[N bytes cut]"`
 * marker that `$take()` splices into `$head`/`$tail` for the final `Result` ever leak into a
 * `ToolProgress` event too? docs/shell.md promises "Output is reported as it arrives through
 * Call::progress()" and separately that the final text has "the middle cut out ... with a
 * marker" -- two different views of the same stream, and nothing in `$take()` ties them
 * together: progress is emitted from `$text` (the raw chunk plus any carried partial
 * character) *before* the head/tail/marker bookkeeping runs, so the two should be independent
 * by construction, but that is exactly the kind of claim worth pinning with a direct check
 * rather than trusting the reading of the code.
 *
 * Also attacked, per the round's suggestions, and found already safe (not reported as this
 * round's defect, since none is wrong in the dangerous direction):
 *  - Policy\Shell::check() against `$(rm -rf /)`, `` `rm` `` inside double quotes and inside
 *    single quotes, `eval 'rm -rf /'` / `eval rm -rf /`, `. script.sh` / `source script.sh`,
 *    `find . -exec rm {} \;` / `-exec rm {} +` / `-delete`, `xargs -I{} rm {}` and
 *    `xargs -I XYZ rm XYZ` (a non-brace placeholder) and bare `xargs -n 1 rm`: every one of
 *    these is an unmatched program word (`$`, `` ` ``, `eval`, `.`, `source`, `find`, `xargs`
 *    are not in Shell::WRAPPERS and are not peeled), so Shell::check() falls through to
 *    `otherwise` (Ask) rather than being misread as the allowed program -- the inner `rm` never
 *    becomes reachable through an Allow rule for something else.
 *  - `Shell::allow('git', ['status'])` against `git -C /elsewhere status`: `matches()` checks
 *    `$argv[1]` against `$subcommands`, and `$argv[1]` here is `-C`, not `status`, so this
 *    correctly falls through to Ask (over-blocking a legitimate invocation, not a security
 *    hole: `docs/policy.md` only ever promises matching "its first argument against
 *    $subcommands", which is exactly what happens).
 *
 * Verified directly: a command that `printf`s 2000 ASCII digit bytes against `maxOutput: 97`
 * (so the final `Result` text really does contain a `"[1903 bytes cut]"` marker) produces 25
 * `ToolProgress` events whose `value`s, concatenated in order, are exactly the original
 * 2000-byte string -- nothing lost, nothing duplicated, and `"bytes cut"` appears in none of
 * them.
 */
it('reports every byte of output as progress even when the final text is cut, and never leaks the cut marker into progress', function () {
    $events  = [];
    $call    = callFor('c1', $events);
    $digits  = \implode('', \array_map(static fn ($i) => (string) ($i % 10), \range(0, 1999)));
    $command = 'php -r \'for ($i = 0; $i < 2000; $i++) { echo $i % 10; }\'';
    $r       = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir(), maxOutput: 97))->call(['command' => $command], $call));

    $progress = \array_values(\array_filter($events, static fn ($e) => $e instanceof ToolProgress));
    $sum      = \implode('', \array_map(static fn ($e) => $e->value, $progress));

    expect($r->content[0]->text)->toContain('bytes cut]') // the final Result really is cut
        ->and($sum)->toBe($digits) // but progress alone reconstructs the whole, uncut output
        ->and($sum)->not->toContain('bytes cut'); // and the marker text is a Result-only artifact
}); // regression: round 13 found no new defect; this pins the progress/cut independence it checked

/*
 * Round 14 attacked a `timeout` input small enough that the deadline can pass before `bash` has
 * even started: `call()`'s kill path, once the main read loop decides the deadline is past, does
 * `posix_kill(-$status['pid'], 9)` exactly once, then only falls back to trying again once
 * `\microtime(true) > $deadline + 1.0`. `$status['pid']` is the pid `proc_open()` itself spawned
 * -- `setsid`, on Linux -- and `posix_kill()` with a negative pid signals the whole process
 * *group* `setsid` becomes the leader of once it calls `setsid()`, right before it `exec`s
 * `bash`. That `setsid()` call, the `exec`, and `bash`'s own startup all take real, if usually
 * tiny, wall-clock time; nothing in `call()` waits for any of it before deciding the deadline is
 * past and trying to kill.
 *
 * When `timeout` is small enough that the deadline check fires before `setsid()` has run, the
 * target process group does not exist yet: the kill is a silent no-op (ESRCH, "No such
 * process"), and -- since nothing retries it until a full extra second has gone by -- the command
 * keeps running, genuinely unkilled, for up to that whole extra second. This is a real kernel
 * scheduling race, not a one-off fluke of one tiny number: verified directly, with debug
 * instrumentation copying `call()`'s own kill logic line for line, that `posix_kill()` returns
 * `false` with `errno` `ESRCH` precisely when this happens, and separately, across repeated
 * single-call trials of `timeout: 0.001` against `sleep 3` on this (ordinarily busy, multi-user)
 * machine, that it is hit on roughly a quarter to two-thirds of trials, never 0% and never 100%,
 * under both adapters -- exactly the signature of a race rather than a deterministic outcome.
 * docs/shell.md promises "[t]he call ends when the shell exits, times out or the run is
 * cancelled", not "within an extra second of whichever of those is true", so a host relying on
 * that promise to bound how long a call can run gets a surprise roughly half the time it uses a
 * very small timeout.
 *
 * Also attacked per this round's suggestions, and found already correct: Policy\Shell::check()
 * on a trailing `&` (parsed and matched like `;`, so an allowed command followed by `&` is still
 * Allow -- correct, since the shell tool itself kills the whole process group at the end of the
 * call regardless of backgrounding); `cmd1 ; cmd2` and `cmd1 || rm -rf /` where one command is
 * denied (Deny always wins: `check()` returns on the first denied simple command it reaches,
 * whichever side of whichever operator it is on); a line that is only whitespace or only a `#`
 * comment (`parse()` returns no commands, so `check()` returns `Ask` -- "the shell command is
 * empty" -- never `Allow`); a here-doc (`parse()` rejects `<<` outright as unparseable before it
 * ever reaches the body, so a denied word inside the body cannot cause a `Deny`, and the command
 * is `Ask`, never silently `Allow`ed); `FOO=$(rm -rf /) ls` (the unquoted `$` makes the whole line
 * unparseable -- `Ask` -- before the environment-assignment check is ever reached). A NUL byte in
 * a command's output is also safe: NUL is valid UTF-8 (U+0000), so `Utf8::scrub()` leaves it
 * alone, and `json_encode()` on the resulting `Result` text (containing a literal `"\0"`) works
 * fine, producing `"\u0000"` -- no violated promise there either.
 */
it('kills a command promptly even for a timeout small enough to race the setsid startup', function () {
    // Today: roughly a quarter to two-thirds of trials hit the race and take ~1.0-1.03 s instead
    // of a few ms, because the first process-group kill silently failed (ESRCH) and nothing
    // retried it before the deadline + 1.0 s fallback. 25 independent trials make a false pass
    // (every one happening to land in the lucky, non-racing case) astronomically unlikely.
    for ($i = 0; $i < 25; ++$i) {
        $start   = \microtime(true);
        $r       = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir()))->call(['command' => 'sleep 3', 'timeout' => 0.001], callFor()));
        $elapsed = \microtime(true) - $start;

        expect($elapsed)->toBeLessThan(0.5) // comfortably above the 0.001 s timeout, comfortably below the ~1 s raced fallback
            ->and($r->content[0]->text)->toStartWith('Killed: timed out after 0.001 s');
    }
}); // #87: the group kill was a silent ESRCH before setsid ran, retried only after deadline + 1 s

/*
 * Round 15 attacked #87's fix (the setsid-startup race: group kill races the forked child's own
 * `setsid()`, the retry now happens at every 10 ms poll instead of waiting a full extra second).
 * `call()` reaches that retry loop through one `$killed` variable set identically by a timeout
 * (`$left <= 0`) and a cancellation (`$call->isCancelled()`) -- the fix should cover both the
 * same way, but nothing in the suite had ever driven Shell::call() under a *real* `Run`
 * cancellation (only a timeout) to check the generalization held.
 *
 * Also attacked this round and found already correct, not reported:
 *  - `proc_open`'s direct child is, per `setsid(1)`'s own man page, only ever a process group
 *    leader itself (forcing `setsid` to fork internally rather than exec in place) when it was
 *    already one before `setsid` ran. Verified directly that in this process tree `proc_open`'s
 *    child never starts as its own group leader (it inherits the ordinary, already-running
 *    group), so `setsid` always takes the no-fork path and `$status['pid']` really does become
 *    the real command's own pid and new pgid once `setsid()` completes -- the fallback-pid-kill
 *    branch for an already-forked `setsid` is correctly unreachable here, not a live gap.
 *  - The result text and `value` for a near-zero timeout (`0.001` s) stayed `"Killed: timed out
 *    after 0.001 s..."` / `null` across 30 trials, even though a good share of them still hit the
 *    race internally (the retry just resolves it inside ~10-20 ms now instead of leaving it wrong).
 *  - A character straddling the internal head/tail split point (not a real cut: total output ==
 *    `maxOutput` exactly, post-#70) reconstructs byte-for-byte, because `$head` and `$tail` are
 *    contiguous substrings of the same bytes when no `"[N bytes cut]"` marker is spliced between
 *    them; `Utf8::scrub()` runs on the rejoined whole, not on `$head`/`$tail` separately.
 *  - A command writing 50 MB far faster than `maxOutput: 1000` keeps it: `$head`/`$tail`/`$carry`
 *    stay bounded regardless of how much flows past them (`memory_get_peak_usage()` unchanged
 *    across the run), and it completes in a fraction of a second.
 *
 * Verified directly: a tool that calls `$run->cancel()` (the run is already marked "running", so
 * it takes effect) immediately before handing off to `Shell::call()` with a 10 s timeout -- long
 * enough that the timeout branch can never be what ends the call -- returns in ~10-25 ms every
 * time, 20 independent trials, never the ~1 s an unretried, raced kill used to cost.
 */
it('cancels a command promptly too, not just a timeout, even when the kill races the setsid startup', function () {
    for ($i = 0; $i < 20; ++$i) {
        $run  = null;
        $tool = tool('shell', function (array $in, Harness\Call $call) use (&$run) {
            $run->cancel(); // $run->running is already true: this cancellation takes effect

            return (new ShellTool(\sys_get_temp_dir()))->call(['command' => 'sleep 3', 'timeout' => 10.0], $call);
        });
        $run = new Harness\Run(new Harness\Testing\FakeModel(calls(['c1', 'shell', []])), new Harness\Tools($tool));

        $start   = \microtime(true);
        turn($run, user('go'));
        $elapsed = \microtime(true) - $start;
        $result  = $run->messages()[2]->parts[0];

        expect($elapsed)->toBeLessThan(0.5) // comfortably above a plain kill's ~10-25 ms, comfortably below the old ~1 s raced fallback
            ->and($result->content[0]->text)->toStartWith('Killed: cancelled');
    }
}); // regression: round 15 found no new defect; this pins #87's fix generalizing to cancel(), not just timeout

/*
 * Round 16 attacked the cut marker's placement: `$output = $head . "\n[" . N . " bytes
 * cut]\n" . $tail` unconditionally splices `"\n"` before and after the marker, regardless of
 * whether a real line break ever sat at that position in the command's actual output. A single
 * line that never contained a newline at all -- not an edge case, the ordinary shape of a lot of
 * real command output (a long `printf`, a minified JSON blob, a single CSV row, a progress bar
 * line rewritten with `\r`) -- comes back looking exactly like three separate lines, with nothing
 * in the text to tell a model that two of those three line breaks are not real: `docs/shell.md`
 * promises "the output, the middle cut out past maxOutput bytes with a marker", not "...with two
 * fabricated line breaks around the marker, even where the real output never had one there".
 * A model asked to count, reconstruct or quote lines from a cut command's output cannot
 * distinguish a genuine line boundary from one the marker itself invented.
 *
 * Also attacked per this round's suggestions, and found already correct, not reported:
 *  - `spec()`'s description after construction with a `cwd` holding quotes, spaces and non-ASCII
 *    (`'/tmp/weird "dir\' with spaces and ñ 日本語'`): the string is interpolated and handed back
 *    verbatim, `json_encode()` on it (what a model-API client actually does) succeeds -- nothing
 *    breaks, it is just whatever text the constructor was given.
 *  - `timeout` given as `'1e3'` (scientific-notation string, 1000 s) against a 10 s `maxTimeout`:
 *    `Input::number()` parses it correctly and `\min(..., $this->maxTimeout)` caps it to the real
 *    10 s regardless of the input's numeric format; the call is killed at 10 s, not 1000.
 *  - `Policy\Shell::check()` against a 200 000-word command line: time scales linearly (≈0.002 s
 *    at 1 000 words, ≈0.36 s at 200 000), no quadratic blow-up to worry about as a DoS vector.
 *  - Program-name matching stays case-sensitive throughout (no `strtolower()`/`strcasecmp()`
 *    anywhere in `Shell.php`), matching Linux's own case-sensitive `exec()`, so `RM` never folds
 *    onto a `deny('rm')` rule.
 *  - `allow('ls', flags: ['-l'])` against `ls -la`: `-la` is compared to `$flags` verbatim (no
 *    short-option splitting), is not `'-l'`, and correctly falls through to `Ask` -- over-blocking
 *    a combined flag, not an under-blocking bypass.
 */
it('does not insert line breaks the real output never had, when the cut marker splices into one unbroken line', function () {
    // A single-line, newline-free 40-byte output; only the "Exit code 0" header separator is a real line break.
    $r = \fiber\run(fn () => (new ShellTool(\sys_get_temp_dir(), maxOutput: 20))->call(['command' => "printf '%s' AAAAAAAAAABBBBBBBBBBCCCCCCCCCCDDDDDDDDDD"], callFor()));

    expect($r->content[0]->text)->toContain('bytes cut]') // the cut really did happen
        ->and(\substr_count($r->content[0]->text, "\n"))->toBe(1); // only the header separator is a real line break
}); // #99: the marker came wrapped in two line breaks the output never had
