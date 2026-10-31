<?php

declare(strict_types=1);

use Harness\Policy\Shell;
use Harness\Policy\Verdict;

/** The rules of the spec's example, plus grep. */
function shellRulesForProbe(): array
{
    return [
        Shell::allow('git', ['status', 'diff', 'log'], flags: ['--stat', '-p', '--oneline']),
        Shell::allow('ls'),
        Shell::allow('grep'),
        Shell::deny('rm'),
        Shell::allow('composer', ['test', 'install']),
    ];
}

/** The verdict of the rules for a command line. */
function verdictOfProbe(string $line, Verdict $otherwise = Verdict::Ask): Verdict
{
    return Shell::check($line, shellRulesForProbe(), $otherwise)[0];
}

it('peels short options with attached values (like sudo -uroot)', function () {
    expect(verdictOfProbe('sudo -uroot ls'))->toBe(Verdict::Allow)
        ->and(verdictOfProbe('sudo -uroot rm'))->toBe(Verdict::Deny);
});

it('peels env - (empty environment flag) to expose the wrapped command', function () {
    // env - clears the environment and runs the wrapped command
    // Testing that 'env - rm' is peeled to 'rm' and correctly denied
    expect(verdictOfProbe('env - rm'))->toBe(Verdict::Deny)
        ->and(verdictOfProbe('env - ls'))->toBe(Verdict::Allow);
});

it('detects double semicolon (case statement) as unparseable', function () {
    [$verdict, $reason] = Shell::check('ls ;; ls', shellRulesForProbe());
    expect($verdict)->toBe(Verdict::Ask)
        ->and($reason)->toContain(';;');
});

it('validates flags with = value syntax against the allow rule flag list', function () {
    // --stat=value should be allowed (--stat is in the allowed flags list)
    expect(verdictOfProbe('git status --stat=file.txt'))->toBe(Verdict::Allow)
        // --oneline=custom should be allowed (--oneline is in the allowed flags list)
        ->and(verdictOfProbe('git diff --oneline=no'))->toBe(Verdict::Allow)
        // --unknown=value should ask (--unknown is not in the allowed flags list)
        ->and(verdictOfProbe('git status --unknown=value'))->toBe(Verdict::Ask);
});

it('peels wrapper options with = value syntax (env -C=/path)', function () {
    // env -C=/path changes directory and runs the wrapped command
    // Testing that 'env -C=/tmp ls' is peeled to 'ls' and allowed
    expect(verdictOfProbe('env -C=/tmp ls'))->toBe(Verdict::Allow)
        // And that 'env -C=/tmp rm' is peeled to 'rm' and denied
        ->and(verdictOfProbe('env -C=/tmp rm'))->toBe(Verdict::Deny);
});

it('detects <> (read-write redirect) and asks about file writes', function () {
    // <> opens a file for both reading and writing
    // Testing that 'ls <> file' is asked about because it writes to a file
    [$verdict, $reason] = Shell::check('ls <> output.txt', shellRulesForProbe());
    expect($verdict)->toBe(Verdict::Ask)
        ->and($reason)->toContain('writes to');
    // But 'ls <> /dev/null' is allowed because /dev/null is exempt from write checks
    expect(verdictOfProbe('ls <> /dev/null'))->toBe(Verdict::Allow);
});

it('judges bare wrappers with no wrapped command: env/sudo asked about, xargs defaults to echo', function () {
    // Rules that allow echo but not env, allowing us to distinguish xargs (defaults to echo) from other wrappers
    $rules = [
        Shell::allow('echo'),
        Shell::allow('ls'),
        Shell::deny('rm'),
    ];

    // Bare env is judged as the program 'env' itself (no rule matches, so Ask)
    [$verdict, $reason] = Shell::check('env', $rules);
    expect($verdict)->toBe(Verdict::Ask)
        ->and($reason)->toContain("'env' matches no allow rule");

    // Bare sudo is judged as the program 'sudo' itself (no rule matches, so Ask)
    [$verdict, $reason] = Shell::check('sudo', $rules);
    expect($verdict)->toBe(Verdict::Ask)
        ->and($reason)->toContain("'sudo' matches no allow rule");

    // Bare xargs defaults to echo (line 231-232), which matches the allow rule
    [$verdict] = Shell::check('xargs', $rules);
    expect($verdict)->toBe(Verdict::Allow);
});
