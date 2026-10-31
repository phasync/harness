<?php

declare(strict_types=1);

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
})->group('bug');
