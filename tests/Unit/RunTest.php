<?php

declare(strict_types=1);

use Harness\Call;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\ErrorKind;
use Harness\Event\ToolEnded;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Http\TransportException;
use Harness\Message;
use Harness\ModelException;
use Harness\ModelMiddleware;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Reason;
use Harness\Result;
use Harness\Retry;
use Harness\Role;
use Harness\Run;
use Harness\Stop;
use Harness\Testing\FakeModel;
use Harness\ToolMiddleware;
use Harness\Tools;
use Harness\Usage;
use Psr\Log\AbstractLogger;

it('emits started, deltas and ended per item, in order', function () {
    $model  = new FakeModel(calls(['c1', 'echo', ['x' => 'hi']]), says('done'));
    $run    = new Run($model, new Tools(tool('echo', fn (array $in) => Result::text('echoed ' . $in['x']))));
    $events = turn($run, user('go'));
    expect(types($events))->toBe([
        'turn_started',
        'step_started', 'message_started', 'tool_call_started', 'tool_input_delta', 'tool_call_ready', 'message_ended', 'tool_ended', 'step_ended',
        'step_started', 'message_started', 'text_delta', 'message_ended', 'step_ended',
        'turn_ended',
    ]);
    foreach ($events as $e) {
        expect($e->turn)->toBe(1)->and($e->parent)->toBeNull();
    }
    $ended = $run->ended();
    expect($ended->reason)->toBe(Reason::Done)
        ->and($ended->usage)->toEqual(new Usage(20, 10))
        ->and($ended->appended)->toHaveCount(4)
        ->and($ended->appended[2]->parts)->toEqual([new ToolResult('c1', [new Text('echoed hi')])])
        ->and($run->messages())->toEqual($ended->appended);
});

it('serializes every event to JSON with a type', function () {
    $run    = new Run(new FakeModel(calls(['c1', 'echo', []]), says('ok')), new Tools(tool('echo', fn () => Result::text('x'))));
    $events = turn($run, user('go'));
    foreach ($events as $e) {
        $data = \json_decode(\json_encode($e, \JSON_THROW_ON_ERROR), true);
        expect($data['type'])->toBeString()->and($data['turn'])->toBe(1);
    }
    expect(\json_decode(\json_encode(\end($events)), true))->toBe(['type' => 'turn_ended', 'turn' => 1, 'parent' => null, 'reason' => 'done', 'usage' => ['input' => 20, 'output' => 10, 'cacheRead' => 0, 'cacheWrite' => 0], 'error' => null]);
});

it('gives the model the system text, the history and the current tools each step', function () {
    $model = new FakeModel(calls(['c1', 'a', []]), says('ok'));
    $run   = new Run($model, new Tools(tool('a', fn () => Result::text('A'))), system: 'Be brief');
    turn($run, user('go'));
    expect($model->prompts)->toHaveCount(2)
        ->and($model->prompts[0]->system)->toBe('Be brief')
        ->and($model->prompts[0]->messages)->toHaveCount(1)
        ->and($model->prompts[1]->messages)->toHaveCount(3)
        ->and($model->prompts[1]->tools[0]->name)->toBe('a');
});

it('continues a conversation across turns', function () {
    $run = new Run(new FakeModel(says('one'), says('two')), new Tools());
    turn($run, user('1'));
    $events = turn($run, user('2'));
    expect($events[0]->turn)->toBe(2)->and($run->messages())->toHaveCount(4);
});

it('applies middleware in array order, outermost first', function () {
    $log = [];
    $mw  = function (string $name) use (&$log) {
        return new class($name, $log) implements ModelMiddleware, ToolMiddleware {
            public function __construct(private string $name, private array &$log)
            {
            }

            public function stream(Prompt $prompt, Closure $next): iterable
            {
                $this->log[] = "model:{$this->name}:in";
                yield from $next($prompt);
                $this->log[] = "model:{$this->name}:out";
            }

            public function call(ToolCall $call, Call $context, Closure $next): Result
            {
                $this->log[] = "tool:{$this->name}:in";
                $r           = $next($call, $context);
                $this->log[] = "tool:{$this->name}:out";

                return $r;
            }
        };
    };
    $run = new Run(new FakeModel(calls(['c', 't', []]), says('ok')), new Tools(tool('t', function () use (&$log) {
        $log[] = 'tool';

        return Result::text('x');
    })), middleware: [$mw('outer'), $mw('inner')]);
    turn($run, user('go'));
    expect($log)->toBe([
        'model:outer:in', 'model:inner:in', 'model:inner:out', 'model:outer:out',
        'tool:outer:in', 'tool:inner:in', 'tool', 'tool:inner:out', 'tool:outer:out',
        'model:outer:in', 'model:inner:in', 'model:inner:out', 'model:outer:out',
    ]);
});

it('lets middleware rewrite the prompt and the result', function () {
    $model = new FakeModel(calls(['c', 't', ['v' => 1]]), says('ok'));
    $mw    = new class implements ModelMiddleware, ToolMiddleware {
        public function stream(Prompt $prompt, Closure $next): iterable
        {
            return $next(new Prompt('rewritten', $prompt->messages, $prompt->tools, $prompt->options));
        }

        public function call(ToolCall $call, Call $context, Closure $next): Result
        {
            $r = $next(new ToolCall($call->id, $call->name, ['v' => 2]), $context);

            return Result::text(\strtoupper($r->content[0]->text));
        }
    };
    $run = new Run($model, new Tools(tool('t', fn (array $in) => Result::text("got {$in['v']}"))), middleware: [$mw]);
    turn($run, user('go'));
    expect($model->prompts[0]->system)->toBe('rewritten')
        ->and($run->messages()[2]->parts[0])->toEqual(new ToolResult('c', [new Text('GOT 2')]));
});

it('turns Retry into an error result and fails the turn past the budget', function () {
    $model = new FakeModel(
        calls(['c1', 'f', []]),
        calls(['c2', 'f', []]),
        calls(['c3', 'f', []]),
        calls(['c4', 'f', []]),
        says('never'),
    );
    $run    = new Run($model, new Tools(tool('f', fn () => throw new Retry('use a relative path'))));
    $events = turn($run, user('go'));
    $ended  = $run->ended();
    expect($ended->reason)->toBe(Reason::Failed)
        ->and($ended->errorKind)->toBe(ErrorKind::ToolRetriesExceeded)
        ->and($model->prompts)->toHaveCount(4)
        ->and(\end($events)->error)->toBe(ErrorKind::ToolRetriesExceeded);
    // Every call still has its result: the transcript stays valid.
    expect($run->messages()[8]->parts)->toEqual([new ToolResult('c4', [new Text('use a relative path')], true)]);
});

it('allows exactly toolRetries correctable errors', function () {
    $model = new FakeModel(calls(['c1', 'f', []]), calls(['c2', 'nope', []]), says('ok'));
    $run   = new Run($model, new Tools(tool('f', fn () => throw new Retry('again'))), options: new Harness\Options(toolRetries: 2));
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Done);
});

it('tells the model about unknown tools and malformed input', function () {
    $model = new FakeModel(
        calls(['c1', 'missing', []]),
        [new Harness\Delta\ToolCallStart('c2', 't'), new Harness\Delta\ToolInputDelta('c2', '{"a":'), new Harness\Delta\ToolCallEnd('c2'), new Finish(FinishReason::ToolCalls)],
        says('ok'),
    );
    $called = false;
    $run    = new Run($model, new Tools(tool('t', function () use (&$called) {
        $called = true;

        return Result::text('x');
    })));
    turn($run, user('go'));
    $m = $run->messages();
    expect($m[2]->parts[0]->error)->toBeTrue()
        ->and($m[2]->parts[0]->content[0]->text)->toContain("Unknown tool 'missing'")->toContain('t')
        ->and($m[4]->parts[0]->error)->toBeTrue()
        ->and($m[4]->parts[0]->content[0]->text)->toContain('not valid JSON')
        ->and($called)->toBeFalse();
});

it('reports a tool exception as an error result, an event with the throwable, and a log entry', function () {
    $logger = new class extends AbstractLogger {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, $context['exception'] ?? null];
        }
    };
    $boom   = new RuntimeException('disk full');
    $run    = new Run(new FakeModel(calls(['c', 't', []]), says('ok')), new Tools(tool('t', fn () => throw $boom)), log: $logger);
    $events = turn($run, user('go'));
    $ended  = \array_values(\array_filter($events, fn ($e) => $e instanceof ToolEnded));
    expect($ended[0]->outcome)->toBe($boom)
        ->and($run->messages()[2]->parts[0])->toEqual(new ToolResult('c', [new Text('disk full')], true))
        ->and($logger->records[0])->toBe(['error', $boom])
        ->and($run->ended()->reason)->toBe(Reason::Done);
});

it('ends Failed with the kind of the model failure', function (Throwable $e, ErrorKind $kind) {
    $run = new Run(new FakeModel(fn () => throw $e), new Tools());
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->errorKind)->toBe($kind)
        ->and($run->ended()->error)->toBe($e)
        ->and($run->messages())->toHaveCount(1);
})->with([
    'rate limited'     => [new ModelException(ErrorKind::RateLimited, 'slow down', 2.0), ErrorKind::RateLimited],
    'context too long' => [new ModelException(ErrorKind::ContextTooLong, 'too long'), ErrorKind::ContextTooLong],
    'transport'        => [new TransportException('refused'), ErrorKind::TransportError],
    'anything else'    => [new RuntimeException('bad json'), ErrorKind::ModelError],
]);

it('ends Failed with MiddlewareError when a middleware throws', function () {
    $mw = new class implements ToolMiddleware {
        public function call(ToolCall $call, Call $context, Closure $next): Result
        {
            throw new RuntimeException('policy store down');
        }
    };
    $run = new Run(new FakeModel(calls(['c', 't', []])), new Tools(tool('t', fn () => Result::text('x'))), middleware: [$mw]);
    turn($run, user('go'));
    expect($run->ended()->errorKind)->toBe(ErrorKind::MiddlewareError);
});

it('fails when the model stream has no Finish', function () {
    $run = new Run(new FakeModel([new TextDelta('cut')]), new Tools());
    turn($run, user('go'));
    expect($run->ended()->errorKind)->toBe(ErrorKind::ModelError)
        ->and($run->ended()->error->getMessage())->toContain('without a Finish');
});

it('rethrows a LogicException from the iteration after TurnEnded', function () {
    $run    = new Run(new FakeModel(fn () => throw new Harness\UnsupportedOptionException('no temperature here')), new Tools());
    $events = [];
    expect(function () use ($run, &$events) {
        \fiber\run(function () use ($run, &$events) {
            foreach ($run->turn(user('go')) as $e) {
                $events[] = $e;
            }
        });
    })->toThrow(Harness\UnsupportedOptionException::class, 'no temperature here');
    expect(\end($events))->toBeInstanceOf(Harness\Event\TurnEnded::class)
        ->and($run->ended()->reason)->toBe(Reason::Failed);
});

it('stops on a stop predicate', function (array $stop, int $steps) {
    $model = new FakeModel(calls(['c1', 'a', []]), calls(['c2', 'b', []]), calls(['c3', 'a', []]), says('done'));
    $run   = new Run($model, new Tools(tool('a', fn () => Result::text('A')), tool('b', fn () => Result::text('B'))), stop: $stop);
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Stopped)->and($model->prompts)->toHaveCount($steps);
})->with([
    'maxSteps'   => [[Stop::maxSteps(2)], 2],
    'toolCalled' => [[Stop::toolCalled('b')], 2],
    'tokens'     => [[Stop::tokens(30)], 2],
    "OR'd"       => [[Stop::maxSteps(3), Stop::toolCalled('b')], 2],
]);

it('stops after 20 steps by default', function () {
    $replies = \array_map(fn ($i) => calls(["c$i", 'a', []]), \range(1, 21));
    $model   = new FakeModel(...$replies);
    $run     = new Run($model, new Tools(tool('a', fn () => Result::text('A'))));
    turn($run, user('go'));
    expect($run->ended()->reason)->toBe(Reason::Stopped)->and($model->prompts)->toHaveCount(20);
});

it('cancels while the model streams and discards the partial message', function () {
    $model = new FakeModel(function () {
        yield new TextDelta('a');
        \fiber\sleep(0.05);
        yield new TextDelta('b');
        yield new Finish(FinishReason::Stop);
    });
    $run    = new Run($model, new Tools());
    $events = \fiber\run(function () use ($run) {
        $out = [];
        foreach ($run->turn(user('go')) as $e) {
            $out[] = $e;
            if ($e instanceof Harness\Event\TextDelta) {
                $run->cancel();
            }
        }

        return $out;
    });
    expect($run->ended()->reason)->toBe(Reason::Cancelled)
        ->and($run->messages())->toHaveCount(1)
        ->and(types($events))->not->toContain('message_ended');
});

it('cancels when the consumer stops iterating, and the run() waits for the turn to finish', function () {
    $finished = false;
    $model    = new FakeModel(function () use (&$finished) {
        yield new TextDelta('a');
        \fiber\sleep(0.02);
        yield new TextDelta('b');
        $finished = true;
        yield new Finish(FinishReason::Stop);
    });
    $run = new Run($model, new Tools());
    \fiber\run(function () use ($run) {
        foreach ($run->turn(user('go')) as $e) {
            if ($e instanceof Harness\Event\TextDelta) {
                break;
            }
        }
    });
    expect($run->ended()->reason)->toBe(Reason::Cancelled)->and($finished)->toBeFalse();
});

it('gives unstarted calls a cancelled result and lets a running one see isCancelled()', function () {
    $seen = null;
    $run  = null;
    $slow = tool('slow', function (array $in, Call $call) use (&$seen, &$run) {
        $run->cancel();
        \fiber\sleep(0.01);
        $seen = $call->isCancelled();

        return Result::text('stopped early');
    });
    $run = new Run(new FakeModel(calls(['c1', 'slow', []], ['c2', 'slow', []])), new Tools($slow));
    turn($run, user('go'));
    expect($seen)->toBeTrue()
        ->and($run->ended()->reason)->toBe(Reason::Cancelled)
        ->and($run->messages()[2]->parts[1])->toEqual(new ToolResult('c2', [new Text('Cancelled before it ran')], true));
});

it('fails hard on misuse', function () {
    $run = new Run(new FakeModel(says('a')), new Tools());
    expect(fn () => turn($run, new Message(Role::Assistant, [new Text('x')])))->toThrow(InvalidArgumentException::class, 'user message')
        ->and(fn () => turn($run, [new Harness\Answer('c', Harness\Decision::allow())]))->toThrow(LogicException::class, 'Nothing awaits input')
        ->and(fn () => $run->ended())->toThrow(LogicException::class, 'No turn has run')
        ->and(fn () => new Run(new FakeModel(), new Tools(), middleware: [new stdClass()]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => turn(new Run(new FakeModel(says('x')), new Tools(tool('a', fn () => null), tool('a', fn () => null))), user('go')))->toThrow(LogicException::class, "Two tools are named 'a'");
});

it('refuses a second turn while one runs', function () {
    $run = new Run(new FakeModel(function () {
        \fiber\sleep(0.01);
        yield from says('a');
    }), new Tools());
    expect(fn () => \fiber\run(function () use ($run) {
        foreach ($run->turn(user('1')) as $e) {
            foreach ($run->turn(user('2')) as $_) {
            }
        }
    }))->toThrow(LogicException::class, 'already running');
});

it('forks a run with the same configuration and a copy of the messages', function () {
    $model = new FakeModel(says('one'), says('two'));
    $run   = new Run($model, new Tools(), system: 's');
    turn($run, user('1'));
    $fork = $run->fork();
    turn($fork, user('2'));
    expect($run->messages())->toHaveCount(2)
        ->and($fork->messages())->toHaveCount(4)
        ->and($model->prompts[1]->system)->toBe('s');
});

it('reads the tool source at every step', function () {
    $source = new class implements Harness\ToolSource {
        public int $reads = 0;

        public function tools(): iterable
        {
            ++$this->reads;

            return [tool('t' . $this->reads, fn () => Result::text('x'), new Hints(readOnly: true))];
        }
    };
    $model = new FakeModel(calls(['c', 't1', []]), says('ok'));
    turn(new Run($model, $source), user('go'));
    expect($model->prompts[0]->tools[0]->name)->toBe('t1')->and($model->prompts[1]->tools[0]->name)->toBe('t2');
});
