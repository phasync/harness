<?php

declare(strict_types=1);

use Harness\Answer;
use Harness\Approval;
use Harness\Call;
use Harness\Decision;
use Harness\Event\InputRequested;
use Harness\Event\ToolDecided;
use Harness\Hints;
use Harness\Message;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Pending;
use Harness\Question;
use Harness\Reason;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\ToolMiddleware;
use Harness\Tools;

/** A policy: every call to a non-read-only tool needs approval. */
function approvals(): ToolMiddleware
{
    return new class implements ToolMiddleware {
        public function call(ToolCall $call, Call $context, Closure $next): Result
        {
            if (null === $context->decision() && !$context->spec->hints->readOnly) {
                return $context->needsInput(new Approval("{$call->name} changes files"));
            }

            return $next($call, $context);
        }
    };
}

function writer(array &$written): Harness\ClosureTool
{
    return tool('write', function (array $in) use (&$written) {
        $written[] = $in['path'];

        return Result::text("wrote {$in['path']}");
    });
}

it('ends awaiting input, then runs approved calls and denies the others', function () {
    $written = [];
    $model   = new FakeModel(calls(['w1', 'write', ['path' => 'a']], ['w2', 'write', ['path' => 'b']], ['r1', 'read', []]), says('done'));
    $run     = new Run($model, new Tools(writer($written), tool('read', fn () => Result::text('R'), new Hints(readOnly: true))), middleware: [approvals()]);

    $events = turn($run, user('go'));
    $ended  = $run->ended();
    expect($ended->reason)->toBe(Reason::AwaitingInput)
        ->and(\array_map(fn (Pending $p) => $p->call->id, $ended->pending))->toBe(['w1', 'w2'])
        ->and($ended->pending[0]->needs)->toEqual(new Approval('write changes files'))
        ->and($events[\count($events) - 2])->toBeInstanceOf(InputRequested::class)
        ->and($written)->toBe([])
        // The read-only call's result is kept; the pending calls have none yet.
        ->and($run->messages()[2]->parts)->toEqual([new ToolResult('r1', [new Text('R')])]);

    $events = turn($run, [new Answer('w1', Decision::allow()), new Answer('w2', Decision::deny('not b'))]);
    expect($written)->toBe(['a'])
        ->and($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->messages()[3]->parts)->toEqual([
            new ToolResult('w1', [new Text('wrote a')]),
            new ToolResult('w2', [new Text('Denied: not b')], true),
        ])
        ->and(\array_values(\array_map(fn ($e) => [$e->id, $e->decision->allowed], \array_filter($events, fn ($e) => $e instanceof ToolDecided))))->toBe([['w1', true], ['w2', false]])
        ->and(types($events)[1])->toBe('step_started')
        ->and($events[1]->n)->toBe(0);
});

it('calls a tool that asked questions again with the answers and its state', function () {
    $asks = tool('deploy', function (array $in, Call $call) {
        if (null === $call->answers()) {
            return $call->needsInput([new Question('env', 'Where to?', ['staging', 'prod'])], ['build' => 42]);
        }

        return Result::text("deployed build {$call->state()['build']} to {$call->answers()['env']}");
    });
    $run = new Run(new FakeModel(calls(['d', 'deploy', []]), says('ok')), new Tools($asks));
    turn($run, user('go'));
    expect($run->ended()->pending[0]->state)->toBe(['build' => 42]);
    turn($run, [new Answer('d', ['env' => 'staging'])]);
    expect($run->messages()[2]->parts[0]->content[0]->text)->toBe('deployed build 42 to staging');
});

it('resumes across a serialize/unserialize boundary', function () {
    $written = [];
    $tools   = function () use (&$written) {
        return new Tools(writer($written));
    };
    $first   = new Run(new FakeModel(calls(['w1', 'write', ['path' => 'x']])), $tools(), middleware: [approvals()]);
    turn($first, user('go'));

    // What a web request persists: the messages and the pending calls, as JSON.
    $stored = \json_encode([
        'messages' => \array_map(fn (Message $m) => $m->toArray(), $first->messages()),
        'pending'  => \array_map(fn (Pending $p) => $p->toArray(), $first->ended()->pending),
    ], \JSON_THROW_ON_ERROR);
    unset($first);

    // Another process: a fresh Run from the stored data and the browser's answer.
    $data  = \json_decode($stored, true);
    $model = new FakeModel(says('all done'));
    $run   = new Run(
        $model,
        $tools(),
        middleware: [approvals()],
        messages: \array_map(Message::fromArray(...), $data['messages']),
        pending: \array_map(Pending::fromArray(...), $data['pending']),
    );
    turn($run, [Answer::fromArray(\json_decode(\json_encode((new Answer('w1', Decision::allow()))->toArray()), true))]);
    expect($written)->toBe(['x'])
        ->and($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->ended()->appended)->toHaveCount(2)
        ->and($model->prompts[0]->messages[2]->parts)->toEqual([new ToolResult('w1', [new Text('wrote x')])]);
});

it('lets middleware answer at once, inside the tool fiber (the CLI idiom)', function () {
    $written  = [];
    $terminal = new class implements ToolMiddleware {
        public function call(ToolCall $call, Call $context, Closure $next): Result
        {
            $result = $next($call, $context);
            if (null !== $context->pending()) {
                // A CLI reads the answer from the terminal here, with fiber\readable(STDIN).
                $context->answer(Decision::allow());
                $result = $next($call, $context);
            }

            return $result;
        }
    };
    $run = new Run(new FakeModel(calls(['w', 'write', ['path' => 'p']]), says('ok')), new Tools(writer($written)), middleware: [$terminal, approvals()]);
    turn($run, user('go'));
    expect($written)->toBe(['p'])->and($run->ended()->reason)->toBe(Reason::Done);
});

it('fails hard on answers that do not match what is pending', function (Closure $answers, string $message) {
    $written = [];
    $run     = new Run(new FakeModel(calls(['w1', 'write', ['path' => 'a']])), new Tools(writer($written)), middleware: [approvals()]);
    turn($run, user('go'));
    expect(fn () => turn($run, $answers()))->toThrow(Exception::class, $message);
})->with([
    'a message'       => [fn () => user('hello'), 'awaits input for tool calls [w1]'],
    'missing answer'  => [fn () => [], 'Answer every pending tool call'],
    'wrong call id'   => [fn () => [new Answer('zz', Decision::allow())], 'Answer every pending tool call'],
    'answers instead' => [fn () => [new Answer('w1', ['q' => 'x'])], 'answer with a Decision'],
]);

it('validates question answers against the choices', function () {
    $asks = tool('ask', fn (array $in, Call $c) => $c->needsInput([new Question('q', 'Pick', ['a', 'b'])]));
    $run  = new Run(new FakeModel(calls(['c', 'ask', []])), new Tools($asks));
    turn($run, user('go'));
    expect(fn () => turn($run, [new Answer('c', ['q' => 'z'])]))->toThrow(InvalidArgumentException::class, 'must be one of: a, b')
        ->and(fn () => turn($run, [new Answer('c', ['other' => 'a'])]))->toThrow(InvalidArgumentException::class, 'asked questions [q]');
});

it('refuses to restore messages and pending lists that disagree', function () {
    $messages = [user('go'), new Message(Harness\Role::Assistant, [new ToolCall('c1', 'write', [])])];
    expect(fn () => new Run(new FakeModel(), new Tools(), messages: $messages))->toThrow(InvalidArgumentException::class, 'without results');
});

it('calls a tool again on a denial when it asked for approval with state, and short-circuits without', function () {
    $seen = [];
    $own  = tool('own', function (array $in, Call $call) use (&$seen) {
        if (null === $call->decision()) {
            return $call->needsInput(new Approval('ok?'), ['plan' => 'x']);
        }
        $seen[] = [$call->decision()->allowed, $call->state()];

        return new Result([new Text('handled: ' . $call->decision()->reason)], error: true);
    });
    $bare = tool('bare', function (array $in, Call $call) use (&$seen) {
        if (null === $call->decision()) {
            return $call->needsInput(new Approval('ok?'));
        }
        $seen[] = 'bare ran';

        return Result::text('ran');
    });
    $run = new Run(new FakeModel(calls(['a', 'own', []], ['b', 'bare', []]), says('fine')), new Tools($own, $bare));
    turn($run, user('go'));
    turn($run, [new Answer('a', Decision::deny('no')), new Answer('b', Decision::deny('nope'))]);
    expect($seen)->toBe([[false, ['plan' => 'x']]])
        ->and($run->messages()[2]->parts[0]->content[0]->text)->toBe('handled: no')
        ->and($run->messages()[2]->parts[1]->content[0]->text)->toBe('Denied: nope');
});
