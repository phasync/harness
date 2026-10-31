<?php

declare(strict_types=1);

use Harness\Approval;
use Harness\Hints;
use Harness\Part\ToolCall;
use Harness\Policy\Policy;
use Harness\Policy\Rule;
use Harness\Policy\Shell;
use Harness\Policy\Verdict;
use Harness\Result;
use Harness\Testing\TestCall;
use Harness\ToolSpec;

/** The rules of the spec's example, plus grep. */
function shellRules(): array
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
function verdictOf(string $line, Verdict $otherwise = Verdict::Ask): Verdict
{
    return Shell::check($line, shellRules(), $otherwise)[0];
}

it('allows a command line whose every simple command matches an allow rule', function (string $line) {
    expect(verdictOf($line))->toBe(Verdict::Allow);
})->with([
    'git status', 'git diff --stat', 'git log -p --oneline -- src/', 'git diff -- *.php', 'ls', 'ls -la /tmp',
    'composer test', 'git status && ls', 'ls | grep -v x', 'ls 2>/dev/null', 'ls 2>&1 | grep x', 'ls < /dev/null',
    'ls # rm -rf /', "ls ';rm -rf /'", 'ls "a b" \'c d\'', "git status\nls", 'ls; git log', 'ls &',
    'nice -n 5 ls', 'timeout 5 ls', 'env -i ls', 'nohup ls', 'ls | xargs grep x', 'sudo -u www ls',
]);

it('denies a line in which any simple command matches a deny rule, wrappers peeled', function (string $line) {
    expect(verdictOf($line))->toBe(Verdict::Deny);
})->with([
    'rm -rf /', '/bin/rm x', 'env rm -rf /', 'git status; rm -rf /', 'git status && rm -rf x', 'ls || rm x',
    'ls | xargs rm', 'sudo rm x', 'sudo -u root rm x', 'timeout -s KILL 5 rm x', 'nice -n 10 rm x', 'nice -10 rm x',
    'nohup rm x &', 'env -i -- rm x', 'r\\m -rf /', "'rm' -rf /", '"r"m -rf /', 'xargs -n 1 rm', 'ls; rm x 2>/dev/null',
    'l*', 'r[m] -rf /', 'command rm x', 'builtin rm x', 'exec rm x',
]);

it('asks about anything it cannot parse or that no rule allows', function (string $line, string $why) {
    [$verdict, $reason] = Shell::check($line, shellRules());
    expect($verdict)->toBe(Verdict::Ask)
        ->and($reason)->toContain($why);
})->with([
    ['ls | sh', "'sh' matches no allow rule"],
    ['$(rm -rf /)', 'an expansion with $'],
    ['ls $(rm -rf /)', 'an expansion with $'],
    ['ls "$(rm)"', 'an expansion inside double quotes'],
    ['ls `rm -rf /`', 'backticks'],
    ['ls $HOME', 'an expansion with $'],
    ['cat <<EOF', 'here-document'],
    ['(rm -rf /)', 'subshell'],
    ['{rm,-rf,/}', 'braces'],
    ['ls > ~/.bashrc', "writes to '~/.bashrc'"],
    ['ls >> out.txt', "writes to 'out.txt'"],
    ['ls &> out.txt', "writes to 'out.txt'"],
    ['FOO=bar ls', "environment assignment 'FOO=bar'"],
    ['env LD_PRELOAD=x.so ls', "environment assignment 'LD_PRELOAD=x.so'"],
    ["env -S 'rm -rf /'", "'env -S' runs something"],
    ['sudo -s', "'sudo -s' runs something"],
    ['git push', "'git push' matches no allow rule"],
    ['git diff --output=/etc/passwd', "'git diff' matches no allow rule"],
    ['git -c core.pager=sh log', "'git -c' matches no allow rule"],
    ['git log *', "'git log' matches no allow rule"],
    ['composer require evil/pkg', "'composer require' matches no allow rule"],
    ['/tmp/x/ls', "'/tmp/x/ls' matches no allow rule"],
    ['bash -c ls', "'bash -c' matches no allow rule"],
    ["ls 'unterminated", 'unterminated single quote'],
    ['ls && ', 'an operator without a command on each side'],
    ['ls && ; ls', 'an operator without a command on each side'],
    ['| ls', 'an operator without a command on each side'],
    ['ls >', 'a redirect without a target'],
    ['', 'empty'],
    ['timeout --weird 5 ls', "the option '--weird' of 'timeout'"],
]);

it('denies a command no rule matches when otherwise is Deny, but still asks about what it cannot parse', function () {
    expect(verdictOf('curl example.com', Verdict::Deny))->toBe(Verdict::Deny)
        ->and(verdictOf('ls $(curl x)', Verdict::Deny))->toBe(Verdict::Ask)
        ->and(fn () => Rule::shell(shellRules(), otherwise: Verdict::Allow))->toThrow(InvalidArgumentException::class);
});

it('decides calls to the shell tool inside a Policy, naming the command, and leaves other tools to later rules', function () {
    $policy = new Policy([Rule::shell(shellRules()), Rule::hints(Verdict::Allow, readOnly: true)], Verdict::Deny);
    $shell  = new ToolSpec('shell', 'Runs a command', ['type' => 'object'], new Hints(destructive: true, openWorld: true));
    $run    = fn (string $command) => $policy->call(new ToolCall('c1', 'shell', ['command' => $command]), $call = TestCall::make($shell, id: 'c1'), fn () => Result::text('ran'));

    expect($run('git status')->content[0]->text)->toBe('ran')
        ->and($run('git status; rm -rf /')->content[0]->text)->toBe("Denied by the policy rule: the shell command 'rm' is never run");
    $call = TestCall::make($shell, id: 'c1');
    $policy->call(new ToolCall('c1', 'shell', ['command' => 'ls | sh']), $call, fn () => Result::text('ran'));
    expect($call->pending()->needs)->toEqual([new Approval(reason: "the shell command 'sh' matches no allow rule")]);
    expect($policy->call(new ToolCall('c1', 'shell', []), TestCall::make($shell, id: 'c1'), fn () => Result::text('ran'))->content[0]->text)->toBe('Denied by the policy rule: shell calls need a string "command"');

    $read = new ToolSpec('read_file', 'Reads', ['type' => 'object'], new Hints(readOnly: true));
    expect($policy->call(new ToolCall('c2', 'read_file', ['command' => 'rm -rf /']), TestCall::make($read, id: 'c2'), fn () => Result::text('read'))->content[0]->text)->toBe('read')
        ->and($policy->describe())->toBe(\implode("\n", [
            'Tool policy (the first matching rule applies):',
            "- 'shell' commands other than git status|diff|log (flags --stat, -p, --oneline), ls, grep, composer test|install (which run); rm (never), or that cannot be parsed: need the user's approval",
            '- tools that are read-only: run without asking',
            '- any other tool: never run',
        ]));
});

it('refuses rules it cannot apply', function () {
    expect(fn () => Shell::allow('/usr/bin/git'))->toThrow(InvalidArgumentException::class, 'without a path')
        ->and(fn () => Shell::allow('git', flags: ['stat']))->toThrow(InvalidArgumentException::class, 'does not start with -')
        ->and(fn () => Rule::shell([Rule::tool('x', Verdict::Allow)]))->toThrow(InvalidArgumentException::class, 'list of Shell rules');
});
