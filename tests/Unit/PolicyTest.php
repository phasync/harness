<?php

declare(strict_types=1);

use Harness\Answer;
use Harness\Approval;
use Harness\Call;
use Harness\Decision;
use Harness\Hints;
use Harness\Part\ToolCall;
use Harness\Policy\Policy;
use Harness\Policy\Rule;
use Harness\Policy\Verdict;
use Harness\Reason;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Testing\TestCall;
use Harness\Tools;
use Harness\ToolSpec;

/** Calls the policy for a tool of that name and hints; returns the result and the Call. */
function decide(Policy $policy, string $name, Hints $hints = new Hints(), array $input = []): array
{
    $call   = TestCall::make(new ToolSpec($name, $name, ['type' => 'object'], $hints), id: 'c1');
    $result = $policy->call(new ToolCall('c1', $name, $input), $call, fn () => Result::text('ran'));

    return [$result, $call];
}

function standard(): Policy
{
    return new Policy([
        Rule::tool('read_file', Verdict::Allow),
        Rule::hints(Verdict::Deny, destructive: true, idempotent: false),
        Rule::tool('mcp_*', Verdict::Ask),
        Rule::hints(Verdict::Allow, readOnly: true),
    ], Verdict::Deny);
}

it('lets the first matching rule decide: by name, by glob, by hints, else the default', function () {
    $outcome = function (string $name, Hints $hints = new Hints()): string {
        [$result, $call] = decide(standard(), $name, $hints);

        return null !== $call->pending() ? 'ask: ' . $call->pending()->needs[0]->reason : $result->content[0]->text;
    };
    expect($outcome('read_file', new Hints(destructive: true)))->toBe('ran')
        ->and($outcome('rm', new Hints(destructive: true)))->toBe('Denied by the policy rule: tools that are destructive and not idempotent')
        ->and($outcome('mcp_search', new Hints(readOnly: true)))->toBe("ask: tools matching 'mcp_*'")
        ->and($outcome('web_search', new Hints(readOnly: true)))->toBe('ran')
        ->and($outcome('write_file'))->toBe('Denied by the policy rule: any other tool')
        ->and($outcome('mcp'))->toBe('Denied by the policy rule: any other tool');
});

it('asks with one stateless approval named after the rule, and runs the call once the host allowed it', function () {
    $policy        = new Policy([Rule::tool('write_file', Verdict::Ask)], Verdict::Allow);
    [$r, $call]    = decide($policy, 'write_file');
    expect($call->pending()->needs)->toEqual([new Approval('approval', "the tool 'write_file'")])
        ->and($call->pending()->state)->toBeNull();
    $call->answer(['approval' => Decision::allow()]);
    expect($policy->call(new ToolCall('c1', 'write_file', []), $call, fn () => Result::text('ran'))->content[0]->text)->toBe('ran');
});

it('matches anything with when(), and binds to nothing but what the test reads', function () {
    $policy = new Policy([Rule::when(fn (ToolCall $c, Call $ctx) => 'billing' === $ctx->context, Verdict::Deny, 'tools in the billing context')], Verdict::Allow);
    $call   = TestCall::make(new ToolSpec('pay', 'Pays', ['type' => 'object']), context: 'billing');
    expect($policy->call(new ToolCall('test', 'pay', []), $call, fn () => Result::text('ran'))->content[0]->text)->toBe('Denied by the policy rule: tools in the billing context')
        ->and(decide($policy, 'pay')[0]->content[0]->text)->toBe('ran');

    $broken = new Policy([Rule::when(fn () => 1, Verdict::Deny, 'broken')]);
    expect(fn () => decide($broken, 'x'))->toThrow(LogicException::class, "The test of the rule 'broken' returned int, not bool");
});

it('leaves a tool the run does not have to the run', function () {
    $call = TestCall::make(null);
    expect((new Policy([], Verdict::Deny))->call(new ToolCall('test', 'nope', []), $call, fn () => Result::text('next'))->content[0]->text)->toBe('next');
});

it('describes its rules for a system prompt', function () {
    expect(standard()->describe())->toBe(<<<'TXT'
        Tool policy (the first matching rule applies):
        - the tool 'read_file': run without asking
        - tools that are destructive and not idempotent: never run
        - tools matching 'mcp_*': need the user's approval
        - tools that are read-only: run without asking
        - any other tool: never run
        TXT);
});

it('rejects rules it cannot use', function (Closure $make, string $message) {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no hints'    => [fn () => Rule::hints(Verdict::Allow), 'at least one of'],
    'empty name'  => [fn () => Rule::tool('', Verdict::Allow), 'needs a tool name'],
    'empty text'  => [fn () => Rule::when(fn () => true, Verdict::Allow, ''), 'needs a text'],
    'not a rule'  => [fn () => new Policy([Verdict::Allow]), 'list of Rule'],
]);

it('pauses a run, and a denial reaches the model as Denied with the rule', function () {
    $written = [];
    $write   = tool('write_file', function (array $in) use (&$written) {
        $written[] = $in;

        return Result::text('written');
    }, new Hints(destructive: true));
    $tools  = new Tools($write, tool('read_file', fn () => Result::text('contents'), new Hints(readOnly: true)));
    $policy = new Policy([Rule::hints(Verdict::Allow, readOnly: true)]);
    $run    = new Run(new FakeModel(calls(['r', 'read_file', []], ['w', 'write_file', []]), says('ok')), $tools, middleware: [$policy]);
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::AwaitingInput)
        ->and($run->ended()->pending[0]->needs)->toEqual([new Approval('approval', 'any other tool')])
        ->and($run->messages()[2]->parts[0]->content[0]->text)->toBe('contents');
    turn($run, [new Answer('w', ['approval' => Decision::deny('not now')])]);
    expect($written)->toBe([])
        ->and($run->messages()[3]->parts[0]->content[0]->text)->toBe('Denied: not now');
});
