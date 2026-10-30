<?php

declare(strict_types=1);

use Harness\Agents\AgentTool;
use Harness\Ended;
use Harness\Hints;
use Harness\Reason;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;

it('runs a nested turn, forwards its events with parent set and returns the final text', function () {
    $child  = new Run(new FakeModel(calls(['k1', 'look', []]), says('found it'), says('second call')), new Tools(tool('look', fn () => Result::text('L'), new Hints(readOnly: true))), system: 'child');
    $agent  = new AgentTool($child, 'researcher', 'Researches');
    $parent = new Run(new FakeModel(calls(['p1', 'researcher', ['prompt' => 'find x']]), calls(['p2', 'researcher', ['prompt' => 'again']]), says('done')), new Tools($agent));
    $events = turn($parent, user('go'));

    $nested = \array_values(\array_filter($events, fn ($e) => null !== $e->parent));
    expect($nested)->not->toBeEmpty()
        ->and(\array_values(\array_unique(\array_map(fn ($e) => $e->parent, $nested))))->toBe(['p1', 'p2'])
        ->and($nested[0]->toArray()['type'])->toBe('turn_started')
        ->and($parent->messages()[2]->parts[0]->content[0]->text)->toBe('found it')
        // Each call starts from the child's own (empty) context.
        ->and($parent->messages()[4]->parts[0]->content[0]->text)->toBe('second call')
        ->and($child->messages())->toBe([])
        ->and($agent->spec()->hints->readOnly)->toBeTrue();

    $toolEnded = \array_values(\array_filter($events, fn ($e) => $e instanceof Harness\Event\ToolEnded && null === $e->parent));
    expect($toolEnded[0]->outcome->value)->toBeInstanceOf(Ended::class)
        ->and($toolEnded[0]->outcome->value->reason)->toBe(Reason::Done);
    \json_encode($events, \JSON_THROW_ON_ERROR);
});

it('reports a failed child as an error result', function () {
    $child  = new Run(new FakeModel(fn () => throw new RuntimeException('down')), new Tools());
    $parent = new Run(new FakeModel(calls(['p', 'sub', ['prompt' => 'x']]), says('ok')), new Tools(new AgentTool($child, 'sub', 'Sub')));
    turn($parent, user('go'));
    expect($parent->messages()[2]->parts[0]->error)->toBeTrue()
        ->and($parent->messages()[2]->parts[0]->content[0]->text)->toContain("The agent 'sub' failed (model_error): down");
});

it('cancels the child when the parent is cancelled', function () {
    $child = new Run(new FakeModel(function () {
        yield new Harness\Delta\TextDelta('a');
        \fiber\sleep(0.05);
        yield new Harness\Delta\TextDelta('b');
        yield new Harness\Delta\Finish(Harness\FinishReason::Stop);
    }), new Tools());
    $parent = new Run(new FakeModel(calls(['p', 'sub', ['prompt' => 'x']])), new Tools(new AgentTool($child, 'sub', 'Sub')));
    \fiber\run(function () use ($parent) {
        foreach ($parent->turn(user('go')) as $e) {
            if ($e instanceof Harness\Event\TextDelta && 'p' === $e->parent) {
                $parent->cancel();
            }
        }
    });
    expect($parent->ended()->reason)->toBe(Reason::Cancelled)
        ->and($parent->messages()[2]->parts[0]->content[0]->text)->toBe("The agent 'sub' was cancelled");
});
