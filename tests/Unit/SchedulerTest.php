<?php

declare(strict_types=1);

use Harness\Hints;
use Harness\Part\Text;
use Harness\Part\ToolResult;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;

/** A tool that logs start and end around a sleep. */
function timed(string $name, bool $readOnly, array &$log, float $seconds = 0.02): Harness\ClosureTool
{
    return tool($name, function (array $in, Harness\Call $call) use ($name, &$log, $seconds) {
        $log[] = "start {$call->id}";
        \fiber\sleep($seconds);
        $log[] = "end {$call->id}";

        return Result::text("$name done");
    }, new Hints(readOnly: $readOnly));
}

it('runs the read-only calls of a step together', function () {
    $log = [];
    $run = new Run(new FakeModel(calls(['r1', 'read', []], ['r2', 'read', []], ['r3', 'read', []]), says('ok')), new Tools(timed('read', true, $log)));
    $t   = \microtime(true);
    turn($run, user('go'));
    expect(\array_slice($log, 0, 3))->toBe(['start r1', 'start r2', 'start r3'])
        ->and(\microtime(true) - $t)->toBeLessThan(0.055);
});

it('runs mutating calls one at a time, in the model order, after the read-only ones', function () {
    $log = [];
    $run = new Run(
        new FakeModel(calls(['w1', 'write', []], ['r1', 'read', []], ['w2', 'write', []], ['r2', 'read', []]), says('ok')),
        new Tools(timed('read', true, $log), timed('write', false, $log, 0.01)),
    );
    turn($run, user('go'));
    expect($log)->toBe(['start r1', 'start r2', 'end r1', 'end r2', 'start w1', 'end w1', 'start w2', 'end w2']);
});

it('returns the results in the model order in one user message', function () {
    $log = [];
    $run = new Run(
        new FakeModel(calls(['w1', 'write', []], ['r1', 'read', []]), says('ok')),
        new Tools(timed('read', true, $log), timed('write', false, $log)),
    );
    turn($run, user('go'));
    expect($run->messages()[2]->parts)->toEqual([
        new ToolResult('w1', [new Text('write done')]),
        new ToolResult('r1', [new Text('read done')]),
    ]);
});

it('emits the events of concurrent calls in the order they happen', function () {
    $fast = tool('fast', function (array $in, Harness\Call $c) {
        $c->progress('fast');

        return Result::text('f');
    }, new Hints(readOnly: true));
    $slow = tool('slow', function (array $in, Harness\Call $c) {
        \fiber\sleep(0.02);
        $c->progress(['step' => 1]);

        return Result::text('s');
    }, new Hints(readOnly: true));
    $events = turn(new Run(new FakeModel(calls(['s', 'slow', []], ['f', 'fast', []]), says('ok')), new Tools($fast, $slow)), user('go'));
    $tool   = \array_values(\array_filter($events, fn ($e) => $e instanceof Harness\Event\ToolProgress || $e instanceof Harness\Event\ToolEnded));
    expect(\array_map(fn ($e) => $e->toArray()['type'] . ':' . $e->id, $tool))->toBe(['tool_progress:f', 'tool_ended:f', 'tool_progress:s', 'tool_ended:s'])
        ->and($tool[2]->value)->toBe(['step' => 1]);
});
