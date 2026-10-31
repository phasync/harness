<?php

declare(strict_types=1);

use Harness\Policy\Shell;
use Harness\Policy\Verdict;

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
