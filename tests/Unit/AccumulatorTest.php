<?php

declare(strict_types=1);

use Harness\Accumulator;
use Harness\Delta\Finish;
use Harness\Delta\ReasoningDelta;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\FinishReason;
use Harness\Part\Reasoning;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Role;
use Harness\Usage;

function fold(array $deltas): Accumulator
{
    $acc = new Accumulator();
    foreach ($deltas as $d) {
        $acc->add($d);
    }

    return $acc;
}

it('folds deltas into parts in the order they began', function () {
    $acc = fold([
        new ReasoningDelta('think', ['model' => 'm']),
        new ReasoningDelta('ing'),
        new TextDelta('Hel'),
        new TextDelta('lo'),
        new ToolCallStart('c1', 'read'),
        new ToolInputDelta('c1', '{"pa'),
        new ToolInputDelta('c1', 'th":"a"}'),
        new ToolCallEnd('c1'),
        new TextDelta('after'),
        new Finish(FinishReason::ToolCalls),
    ]);
    $m = $acc->message();
    expect($m->role)->toBe(Role::Assistant)
        ->and($m->parts)->toEqual([
            new Reasoning('thinking', ['model' => 'm']),
            new Text('Hello'),
            new ToolCall('c1', 'read', ['path' => 'a']),
            new Text('after'),
        ])
        ->and($acc->finish())->toBe(FinishReason::ToolCalls);
});

it('merges reasoning raw with later keys winning', function () {
    $acc = fold([new ReasoningDelta('a', ['x' => 1, 'y' => 1]), new ReasoningDelta('', ['y' => 2])]);
    expect($acc->message()->parts)->toEqual([new Reasoning('a', ['x' => 1, 'y' => 2])]);
});

it('keeps the largest usage seen per field', function () {
    $acc = fold([new Usage(100, 1, 50, 0), new Usage(0, 40), new Finish(FinishReason::Stop)]);
    expect($acc->usage())->toEqual(new Usage(100, 40, 50, 0));
});

it('treats empty tool input as an empty object', function () {
    $acc = fold([new ToolCallStart('c', 'now'), new ToolCallEnd('c'), new Finish(FinishReason::ToolCalls)]);
    expect($acc->message()->parts[0])->toEqual(new ToolCall('c', 'now', []))
        ->and($acc->invalidInput('c'))->toBeNull();
});

it('reports malformed tool input instead of guessing', function (string $json) {
    $acc = fold([new ToolCallStart('c', 't'), new ToolInputDelta('c', $json), new ToolCallEnd('c')]);
    expect($acc->message()->parts[0])->toEqual(new ToolCall('c', 't', []))
        ->and($acc->invalidInput('c'))->toBeString();
})->with(['{"a":', '[1,2]', '"str"', '42']);

it('closes calls that never got ToolCallEnd when the message is taken', function () {
    $acc = fold([new ToolCallStart('c', 't'), new ToolInputDelta('c', '{"a":1}'), new Finish(FinishReason::Length)]);
    expect($acc->message()->parts[0])->toEqual(new ToolCall('c', 't', ['a' => 1]));
});

it('fails hard on provider bugs', function (array $deltas, string $message) {
    expect(fn () => fold($deltas))->toThrow(LogicException::class, $message);
})->with([
    'after finish'    => [[new Finish(FinishReason::Stop), new TextDelta('x')], 'after Finish'],
    'unknown call'    => [[new ToolInputDelta('nope', '{}')], 'was not started'],
    'started twice'   => [[new ToolCallStart('c', 't'), new ToolCallStart('c', 't')], 'started twice'],
    'input after end' => [[new ToolCallStart('c', 't'), new ToolCallEnd('c'), new ToolInputDelta('c', '{}')], 'already ended'],
]);
