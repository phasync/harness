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
            if ([] === $context->answers() && !$context->spec->hints->readOnly) {
                return $context->needsInput([new Approval(reason: "{$call->name} changes files")]);
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
        ->and($ended->pending[0]->needs)->toEqual([new Approval('approval', 'write changes files')])
        ->and($events[\count($events) - 2])->toBeInstanceOf(InputRequested::class)
        ->and($written)->toBe([])
        // The read-only call's result is kept; the pending calls have none yet.
        ->and($run->messages()[2]->parts)->toEqual([new ToolResult('r1', [new Text('R')])]);

    $events = turn($run, [new Answer('w1', ['approval' => Decision::allow()]), new Answer('w2', ['approval' => Decision::deny('not b')])]);
    expect($written)->toBe(['a'])
        ->and($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->messages()[3]->parts)->toEqual([
            new ToolResult('w1', [new Text('wrote a')]),
            new ToolResult('w2', [new Text('Denied: not b')], true),
        ])
        ->and(\array_values(\array_map(fn ($e) => [$e->id, $e->needId, $e->decision->allowed], \array_filter($events, fn ($e) => $e instanceof ToolDecided))))->toBe([['w1', 'approval', true], ['w2', 'approval', false]])
        ->and(types($events)[1])->toBe('step_started')
        ->and($events[1]->n)->toBe(0);
});

it('calls a tool that asked questions again with the answers and its state', function () {
    $asks = tool('deploy', function (array $in, Call $call) {
        if ([] === $call->answers()) {
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
    turn($run, [Answer::fromArray(\json_decode(\json_encode((new Answer('w1', ['approval' => Decision::allow()]))->toArray()), true))]);
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
                $context->answer(['approval' => Decision::allow()]);
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
    'wrong call id'   => [fn () => [new Answer('zz', ['approval' => Decision::allow()])], 'Answer every pending tool call'],
    'wrong need id'   => [fn () => [new Answer('w1', ['q' => 'x'])], 'needs [approval], answered [q]'],
    'a value'         => [fn () => [new Answer('w1', ['approval' => 'yes'])], 'answer it with a Decision, got string'],
]);

it('validates question answers against the choices', function () {
    $asks = tool('ask', fn (array $in, Call $c) => $c->needsInput([new Question('q', 'Pick', ['a', 'b'])]));
    $run  = new Run(new FakeModel(calls(['c', 'ask', []])), new Tools($asks));
    turn($run, user('go'));
    expect(fn () => turn($run, [new Answer('c', ['q' => 'z'])]))->toThrow(InvalidArgumentException::class, 'must be one of: a, b')
        ->and(fn () => turn($run, [new Answer('c', ['other' => 'a'])]))->toThrow(InvalidArgumentException::class, 'needs [q], answered [other]')
        ->and(fn () => turn($run, [new Answer('c', ['q' => Decision::allow()])]))->toThrow(InvalidArgumentException::class, 'decline it with Decision::deny()');
});

it('refuses to restore messages and pending lists that disagree', function () {
    $messages = [user('go'), new Message(Harness\Role::Assistant, [new ToolCall('c1', 'write', [])])];
    expect(fn () => new Run(new FakeModel(), new Tools(), messages: $messages))->toThrow(InvalidArgumentException::class, 'without results');
});

it('calls a tool again on a denial when it asked for approval with state, and short-circuits without', function () {
    $seen = [];
    $own  = tool('own', function (array $in, Call $call) use (&$seen) {
        if (null === $call->decision()) {
            return $call->needsInput([new Approval(reason: 'ok?')], ['plan' => 'x']);
        }
        $seen[] = [$call->decision()->allowed, $call->state()];

        return new Result([new Text('handled: ' . $call->decision()->reason)], error: true);
    });
    $bare = tool('bare', function (array $in, Call $call) use (&$seen) {
        if (null === $call->decision()) {
            return $call->needsInput([new Approval(reason: 'ok?')]);
        }
        $seen[] = 'bare ran';

        return Result::text('ran');
    });
    $run = new Run(new FakeModel(calls(['a', 'own', []], ['b', 'bare', []]), says('fine')), new Tools($own, $bare));
    turn($run, user('go'));
    turn($run, [new Answer('a', ['approval' => Decision::deny('no')]), new Answer('b', ['approval' => Decision::deny('nope')])]);
    expect($seen)->toBe([[false, ['plan' => 'x']]])
        ->and($run->messages()[2]->parts[0]->content[0]->text)->toBe('handled: no')
        ->and($run->messages()[2]->parts[1]->content[0]->text)->toBe('Denied: nope');
});

it('asks for several needs at once and gets every answer by id', function () {
    $seen = [];
    $tool = tool('publish', function (array $in, Call $call) use (&$seen) {
        if ([] === $call->answers()) {
            return $call->needsInput([new Approval(reason: 'publishes'), new Question('channel', 'Where?', ['blog', 'feed']), new Question('title', 'Title?')]);
        }
        $seen[] = $call->answers();

        return Result::text("published {$call->answers()['title']} to {$call->answers()['channel']}");
    });
    $run = new Run(new FakeModel(calls(['p', 'publish', []]), says('ok')), new Tools($tool));
    turn($run, user('go'));
    expect(\json_encode($run->ended()->pending[0]->toArray()['needs']))->toBe('[{"type":"approval","id":"approval","reason":"publishes"},{"type":"question","id":"channel","text":"Where?","choices":["blog","feed"]},{"type":"question","id":"title","text":"Title?","choices":null}]');
    turn($run, [new Answer('p', ['approval' => Decision::allow(), 'channel' => 'blog', 'title' => 'Hi'])]);
    expect($seen)->toEqual([['approval' => Decision::allow(), 'channel' => 'blog', 'title' => 'Hi']])
        ->and($run->messages()[2]->parts[0]->content[0]->text)->toBe('published Hi to blog');
});

it('calls a tool again when its question is declined, even without state, and short-circuits a denied approval', function () {
    $seen = [];
    $ask  = tool('ask', function (array $in, Call $call) use (&$seen) {
        if ([] === $call->answers()) {
            return $call->needsInput([new Question('name', 'Name?')]);
        }
        $seen[] = $call->answers()['name'];

        return Result::text('declined: ' . $call->answers()['name']->reason);
    });
    $both = tool('both', function (array $in, Call $call) use (&$seen) {
        if ([] === $call->answers()) {
            return $call->needsInput([new Approval('a1', 'first'), new Approval('a2', 'second'), new Question('q', 'Why?')]);
        }
        $seen[] = 'both ran';

        return Result::text('ran');
    });
    $run = new Run(new FakeModel(calls(['a', 'ask', []], ['b', 'both', []]), says('fine')), new Tools($ask, $both));
    turn($run, user('go'));
    $events = turn($run, [new Answer('a', ['name' => Decision::deny('private')]), new Answer('b', ['a1' => Decision::deny('no'), 'a2' => Decision::deny('nor this'), 'q' => 'because'])]);
    expect($seen)->toEqual([Decision::deny('private')])
        ->and($run->messages()[2]->parts[0]->content[0]->text)->toBe('declined: private')
        ->and($run->messages()[2]->parts[1]->content[0]->text)->toBe('Denied: no; nor this')
        // One ToolDecided per decision: the declined question and both approvals under their own
        // ids, in the order of the needs; the question answered with a value has none.
        ->and(\array_values(\array_map(fn ($e) => [$e->id, $e->needId, $e->decision], \array_filter($events, fn ($e) => $e instanceof ToolDecided))))->toEqual([
            ['a', 'name', Decision::deny('private')],
            ['b', 'a1', Decision::deny('no')],
            ['b', 'a2', Decision::deny('nor this')],
        ]);
});
