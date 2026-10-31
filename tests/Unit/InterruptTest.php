<?php

declare(strict_types=1);

use Harness\Agents\AgentTool;
use Harness\Approval;
use Harness\Call;
use Harness\Delivery;
use Harness\Delta\Finish;
use Harness\Delta\ReasoningDelta;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\ErrorKind;
use Harness\Event;
use Harness\Event\Interjected;
use Harness\Event\MessageEnded;
use Harness\Event\MessageResumed;
use Harness\Event\TurnEnded;
use Harness\Events;
use Harness\FinishReason;
use Harness\Message;
use Harness\Options;
use Harness\Part\Reasoning;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Reason;
use Harness\Result;
use Harness\Resume;
use Harness\Role;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\Usage;

/** A reply that streams `$text` word by word, a short sleep after each, then a stop. */
function slowly(string $text): Closure
{
    return function () use ($text) {
        foreach (\preg_split('/(?<= )/', $text) as $word) {
            yield new TextDelta($word);
            \fiber\sleep(0.005);
        }
        yield new Usage(10, 5);
        yield new Finish(FinishReason::Stop);
    };
}

/**
 * Runs a turn and calls `$act($run, $event)` on every event.
 *
 * @return list<Event>
 */
function turnActing(Run $run, Message|Resume|array $input, Closure $act): array
{
    return \fiber\run(static function () use ($run, $input, $act): array {
        $events = [];
        foreach ($run->turn($input) as $event) {
            $events[] = $event;
            $act($run, $event);
        }

        return $events;
    });
}

function textOf(Message $m): string
{
    return \implode('', \array_map(fn ($p) => $p instanceof Text ? $p->text : '', $m->parts));
}

it('interrupts a reply: the turn ends Interrupted with the partial message, which is nowhere else', function () {
    $run    = new Run(new FakeModel(slowly('One. Two. Three.')), new Tools());
    $seen   = '';
    $events = turnActing($run, user('count'), function (Run $run, Event $e) use (&$seen) {
        if ($e instanceof Event\TextDelta) {
            $seen .= $e->text;
            if (\str_ends_with($seen, 'Two. ')) {
                $run->interrupt();
            }
        }
    });
    $ended = $run->ended();
    expect($ended->reason)->toBe(Reason::Interrupted)
        ->and($ended->finish)->toBeNull()
        ->and($ended->partial)->toEqual(new Message(Role::Assistant, [new Text('One. Two. ')]))
        ->and($ended->appended)->toEqual([user('count')])
        ->and($run->messages())->toEqual([user('count')])
        ->and(types($events))->not->toContain('message_ended')
        ->and(\end($events))->toBeInstanceOf(TurnEnded::class)
        ->and(\end($events)->reason)->toBe(Reason::Interrupted)
        ->and(\end($events)->finish)->toBeNull()
        ->and($ended->toArray()['partial'])->toBe(['role' => 'assistant', 'parts' => [['type' => 'text', 'text' => 'One. Two. ']]]);
});

it('keeps reasoning and complete tool calls in the partial, not a call still streaming', function () {
    $model = new FakeModel(function () {
        yield new ReasoningDelta('think', ['model' => 'm']);
        yield new TextDelta('Reading. ');
        yield new ToolCallStart('c1', 'read');
        yield new ToolInputDelta('c1', '{"path":"a"}');
        yield new ToolCallEnd('c1');
        yield new ToolCallStart('c2', 'read');
        yield new ToolInputDelta('c2', '{"pa');
        \fiber\sleep(0.01);
        yield new ToolInputDelta('c2', 'th":"b"}');
        yield new ToolCallEnd('c2');
        yield new Finish(FinishReason::ToolCalls);
    });
    $run = new Run($model, new Tools(tool('read', fn () => Result::text('x'))));
    turnActing($run, user('go'), function (Run $run, Event $e) {
        if ($e instanceof Event\ToolInputDelta && 'c2' === $e->id) {
            $run->interrupt();
        }
    });
    expect($run->ended()->partial)->toEqual(new Message(Role::Assistant, [new Reasoning('think', ['model' => 'm']), new Text('Reading. '), new ToolCall('c1', 'read', ['path' => 'a'])]))
        ->and($run->messages())->toHaveCount(1);
});

it('ends Interrupted without a partial when the interrupt came while tools ran', function () {
    $run  = null;
    $slow = tool('slow', function (array $in, Call $call) use (&$run) {
        $run->interrupt();

        return Result::text('done');
    });
    $run = new Run(new FakeModel(calls(['c1', 'slow', []])), new Tools($slow));
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Interrupted)
        ->and($run->ended()->partial)->toBeNull()
        ->and($run->messages())->toHaveCount(3);
});

it('resumes an edited reply: the model sees it last with continue, the deltas extend it, MessageResumed replaces MessageStarted', function () {
    $model = new FakeModel(slowly('One. Two. Three.'), says('Five.'));
    $run   = new Run($model, new Tools());
    turnActing($run, user('count'), function (Run $run, Event $e) {
        if ($e instanceof Event\TextDelta && 'Two. ' === $e->text) {
            $run->interrupt();
        }
    });
    $edited = new Message(Role::Assistant, [new Text('One. Four. ')]);
    $events = turn($run, new Resume($edited));
    $prompt = $model->prompts[1];
    $ended  = \array_values(\array_filter($events, fn ($e) => $e instanceof MessageEnded));
    expect($prompt->continue)->toBeTrue()
        ->and($prompt->messages)->toEqual([user('count'), $edited])
        ->and(types($events))->toBe(['turn_started', 'step_started', 'message_resumed', 'text_delta', 'message_ended', 'step_ended', 'turn_ended'])
        ->and($ended[0]->message)->toEqual(new Message(Role::Assistant, [new Text('One. Four. Five.')]))
        ->and($ended[0]->id)->toBe($events[2]->id)
        ->and($run->messages())->toEqual([user('count'), new Message(Role::Assistant, [new Text('One. Four. Five.')])])
        ->and($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->ended()->appended)->toEqual([new Message(Role::Assistant, [new Text('One. Four. Five.')])])
        ->and($run->ended()->finish)->toBe(FinishReason::Stop);
});

it('runs the tool calls a resumed message ends with, and steps on as usual', function () {
    $model  = new FakeModel(calls(['c1', 'clock', []]), says('It is noon.'));
    $run    = new Run($model, new Tools(tool('clock', fn () => Result::text('12:00'))), messages: [user('time?')]);
    $edit   = new Message(Role::Assistant, [new Text('Let me look. ')]);
    $events = turn($run, new Resume($edit));
    expect(types($events))->toBe([
        'turn_started',
        'step_started', 'message_resumed', 'tool_call_started', 'tool_input_delta', 'tool_call_ready', 'message_ended', 'tool_ended', 'step_ended',
        'step_started', 'message_started', 'text_delta', 'message_ended', 'step_ended',
        'turn_ended',
    ])
        ->and($run->messages()[1])->toEqual(new Message(Role::Assistant, [new Text('Let me look. '), new ToolCall('c1', 'clock', [])]))
        ->and($run->messages()[2]->parts[0])->toEqual(new ToolResult('c1', [new Text('12:00')]))
        ->and($model->prompts[1]->continue)->toBeFalse();
});

it('keeps the edit in the partial when a resumed reply is interrupted again', function () {
    $run = new Run(new FakeModel(slowly('more and more')), new Tools(), messages: [user('go')]);
    turnActing($run, new Resume(new Message(Role::Assistant, [new Text('Edit: ')])), function (Run $run, Event $e) {
        if ($e instanceof Event\TextDelta) {
            $run->interrupt();
        }
    });
    expect($run->ended()->reason)->toBe(Reason::Interrupted)
        ->and($run->ended()->partial)->toEqual(new Message(Role::Assistant, [new Text('Edit: more ')]))
        ->and($run->messages())->toEqual([user('go')]);
});

it('takes a Resume only after a user message, with nothing pending', function () {
    $edit = new Resume(new Message(Role::Assistant, [new Text('x')]));
    $done = new Run(new FakeModel(says('a')), new Tools());
    turn($done, user('q'));
    expect(fn () => turn($done, $edit))->toThrow(LogicException::class, 'must be a user message')
        ->and(fn () => turn(new Run(new FakeModel(), new Tools()), $edit))->toThrow(LogicException::class, 'missing');

    $ask    = tool('w', fn (array $in, Call $call) => [] === $call->answers() ? $call->needsInput([new Approval()]) : Result::text('ok'));
    $paused = new Run(new FakeModel(calls(['c1', 'w', []])), new Tools($ask));
    turn($paused, user('go'));
    expect($paused->ended()->reason)->toBe(Reason::AwaitingInput)
        ->and(fn () => turn($paused, $edit))->toThrow(LogicException::class, 'pass one Answer per call');

    expect(fn () => new Resume(user('x')))->toThrow(InvalidArgumentException::class, 'assistant message')
        ->and(fn () => new Resume(new Message(Role::Assistant, [])))->toThrow(InvalidArgumentException::class);
});

it('rejects a continued prompt that does not end with an assistant message', function () {
    expect(fn () => Harness\Testing\TestPrompt::make(messages: [user('x')], continue: true))->toThrow(InvalidArgumentException::class);
});

it('lands an interjection after the step\'s tool results, before the next model call', function () {
    $model = new FakeModel(calls(['c1', 'slow', []]), says('As you wish.'));
    $run   = null;
    $slow  = tool('slow', function () use (&$run) {
        $run->interject(user('actually, stop'));
        $run->interject(user('and say hi'));
        \fiber\sleep(0.005);

        return Result::text('half done');
    });
    $run    = new Run($model, new Tools($slow));
    $events = turn($run, user('go'));
    $m      = $run->messages();
    expect($m)->toHaveCount(6)
        ->and($m[2]->parts[0])->toEqual(new ToolResult('c1', [new Text('half done')]))
        ->and($m[3])->toEqual(user('actually, stop'))
        ->and($m[4])->toEqual(user('and say hi'))
        ->and($model->prompts[1]->messages)->toEqual(\array_slice($m, 0, 5))
        ->and(\array_slice(types($events), 8, 4))->toBe(['step_ended', 'interjected', 'interjected', 'step_started'])
        ->and($events[9])->toBeInstanceOf(Interjected::class)
        ->and($events[9]->message)->toEqual(user('actually, stop'))
        ->and($events[9]->delivery)->toBe(Delivery::Steer)
        ->and($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->ended()->queued)->toBe([]);
});

it('answers an interjection that came while a reply without tool calls streamed', function () {
    $model  = new FakeModel(slowly('A long answer'), says('Short, then.'));
    $run    = new Run($model, new Tools());
    $events = turnActing($run, user('explain'), function (Run $run, Event $e) {
        if ($e instanceof Event\TextDelta && 'A ' === $e->text) {
            $run->interject(user('shorter please'));
        }
    });
    expect($run->messages())->toHaveCount(4)
        ->and($run->messages()[1])->toEqual(new Message(Role::Assistant, [new Text('A long answer')]))
        ->and($run->messages()[2])->toEqual(user('shorter please'))
        ->and(textOf($run->messages()[3]))->toBe('Short, then.')
        ->and(\count(\array_filter($events, fn ($e) => $e instanceof Interjected)))->toBe(1)
        ->and($run->ended()->reason)->toBe(Reason::Done);
});

it('leaves interjections that could not land in Ended::$queued', function () {
    $ask = tool('w', fn (array $in, Call $call) => [] === $call->answers() ? $call->needsInput([new Approval()]) : Result::text('ok'));
    $run = new Run(new FakeModel(calls(['c1', 'w', []])), new Tools($ask));
    turnActing($run, user('go'), function (Run $run, Event $e) {
        if ($e instanceof Event\ToolCallReady) {
            $run->interject(user('later'), Delivery::Queue);   // a steer would skip the call
        }
    });
    expect($run->ended()->reason)->toBe(Reason::AwaitingInput)
        ->and($run->ended()->queued)->toEqual([user('later')])
        ->and($run->messages())->toHaveCount(2);
});

it('skips the step\'s tool calls that have not started when the user steers, each with a paired error result', function () {
    $model = new FakeModel(calls(['c1', 'first', []], ['c2', 'second', []], ['c3', 'third', []]), says('Changing course.'));
    $run   = null;
    $ran   = [];
    $first = tool('first', function () use (&$run, &$ran) {
        $ran[] = 'first';
        $run->interject(user('stop, do something else'));
        \fiber\sleep(0.005);

        return Result::text('first done');
    });
    $other  = fn (string $name) => tool($name, function () use ($name, &$ran) {
        $ran[] = $name;

        return Result::text("$name done");
    });
    $run    = new Run($model, new Tools($first, $other('second'), $other('third')));
    $events = turn($run, user('go'));
    $m      = $run->messages();
    $skip   = [new Text('Skipped: the user sent a message')];
    expect($ran)->toBe(['first'])
        ->and($m[2]->parts)->toEqual([new ToolResult('c1', [new Text('first done')]), new ToolResult('c2', $skip, true), new ToolResult('c3', $skip, true)])
        ->and($m[3])->toEqual(user('stop, do something else'))
        ->and($model->prompts[1]->messages)->toEqual(\array_slice($m, 0, 4))
        ->and(\array_values(\array_filter($events, fn ($e) => $e instanceof Event\ToolEnded && $e->outcome->error)))->toHaveCount(2)
        ->and($run->ended()->reason)->toBe(Reason::Done);
});

it('skips the tool calls of a reply that streamed while the user steered', function () {
    $model  = new FakeModel(calls(['c1', 'write', []]), says('Ok.'));
    $write  = tool('write', fn () => throw new RuntimeException('must not run'));
    $run    = new Run($model, new Tools($write));
    turnActing($run, user('go'), function (Run $run, Event $e) {
        if ($e instanceof Event\ToolCallStarted) {
            $run->interject(user('wait, no'));
        }
    });
    expect($run->messages()[2]->parts)->toEqual([new ToolResult('c1', [new Text('Skipped: the user sent a message')], true)])
        ->and($run->messages()[3])->toEqual(user('wait, no'));
});

it('lands a queued message only when the turn would end Done, one step each, in order', function () {
    $model = new FakeModel(calls(['c1', 'slow', []]), says('Answer one.'), says('Answer two.'), says('Answer three.'));
    $run   = null;
    $slow  = tool('slow', function () use (&$run) {
        $run->interject(user('then this'), Delivery::Queue);
        $run->interject(user('and this'), Delivery::Queue);

        return Result::text('done');
    });
    $run    = new Run($model, new Tools($slow));
    $events = turn($run, user('go'));
    $m      = $run->messages();
    $landed = \array_values(\array_filter($events, fn ($e) => $e instanceof Interjected));
    expect($model->prompts)->toHaveCount(4)
        // Not after the tool results: the model answered the first message first.
        ->and($model->prompts[1]->messages)->toEqual(\array_slice($m, 0, 3))
        ->and(textOf($m[3]))->toBe('Answer one.')
        ->and($m[4])->toEqual(user('then this'))
        ->and(textOf($m[5]))->toBe('Answer two.')
        ->and($m[6])->toEqual(user('and this'))
        ->and(textOf($m[7]))->toBe('Answer three.')
        ->and(\array_map(fn ($e) => [$e->message, $e->delivery], $landed))->toEqual([[user('then this'), Delivery::Queue], [user('and this'), Delivery::Queue]])
        ->and(\array_slice(types($events), \array_search('interjected', types($events), true) - 1, 3))->toBe(['step_ended', 'interjected', 'step_started'])
        ->and($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->ended()->queued)->toBe([]);
});

it('leaves steered and queued messages that could not land in Ended::$queued, steered first', function () {
    $run = new Run(new FakeModel(says('ok')), new Tools(), stop: [fn () => true]);
    turnActing($run, user('go'), function (Run $run, Event $e) {
        if ($e instanceof Event\TextDelta) {
            $run->interject(user('queued'), Delivery::Queue);
            $run->interject(user('steered'));
        }
    });
    expect($run->ended()->reason)->toBe(Reason::Stopped)
        ->and($run->ended()->queued)->toEqual([user('steered'), user('queued')]);
});

it('refuses an interjection with no turn running, or one that is not a plain user message', function () {
    $run = new Run(new FakeModel(), new Tools());
    expect(fn () => $run->interject(user('x')))->toThrow(LogicException::class, 'turn()')
        ->and(fn () => $run->interject(new Message(Role::Assistant, [new Text('x')])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $run->interject(new Message(Role::User, [new ToolResult('c', [new Text('x')])])))->toThrow(InvalidArgumentException::class);
});

it('rebuilds MessageResumed and Interjected from their arrays', function () {
    foreach ([new MessageResumed(2, 'p1', 'msg_1'), new Interjected(1, null, user('hey'), Delivery::Queue)] as $e) {
        expect(Events::fromArray(\json_decode(\json_encode($e), true)))->toEqual($e);
    }
});

it('validates Options::$contextWindow and passes it on in the prompt', function () {
    $model = new FakeModel(says('ok'));
    turn(new Run($model, new Tools(), options: new Options(contextWindow: 32768)), user('x'));
    expect($model->prompts[0]->options->contextWindow)->toBe(32768)
        ->and(fn () => new Options(contextWindow: 0))->toThrow(InvalidArgumentException::class);
});

it('fails the turn on a LogicException from a tool and rethrows it after TurnEnded', function () {
    $misuse = new LogicException('budget floor');
    $run    = new Run(new FakeModel(calls(['c1', 't', []]), says('never')), new Tools(tool('t', fn () => throw $misuse)));
    $events = [];
    try {
        \fiber\run(function () use ($run, &$events) {
            foreach ($run->turn(user('go')) as $e) {
                $events[] = $e;
            }
        });
        $this->fail('not rethrown');
    } catch (LogicException $e) {
        expect($e)->toBe($misuse);
    }
    expect(\end($events))->toBeInstanceOf(TurnEnded::class)
        ->and($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->errorKind)->toBe(ErrorKind::ToolError)
        ->and($run->ended()->error)->toBe($misuse)
        ->and($run->messages())->toHaveCount(1);
});

it('fails the parent on a LogicException in a sub-agent\'s tool, instead of handing it back as a tool error', function () {
    $misuse = new LogicException('Compact: the fixed part is over half the budget');
    $child  = new Run(new FakeModel(calls(['k1', 't', []])), new Tools(tool('t', fn () => throw $misuse)));
    $parent = new Run(new FakeModel(calls(['p1', 'sub', ['prompt' => 'x']]), says('again?')), new Tools(new AgentTool($child, 'sub', 'Sub')));
    expect(fn () => turn($parent, user('go')))->toThrow(LogicException::class, 'over half the budget');
    expect($parent->ended()->reason)->toBe(Reason::Failed)
        ->and($parent->ended()->errorKind)->toBe(ErrorKind::ToolError)
        ->and($parent->messages())->toHaveCount(1);
});
