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
