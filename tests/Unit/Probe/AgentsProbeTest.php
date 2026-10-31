<?php

declare(strict_types=1);

use Harness\Agents\AgentTool;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\FinishReason;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;

it('agent tool with empty prompt throws Retry for invalid input', function () {
    $child  = new Run(new FakeModel(says('ok')), new Tools());
    $agent  = new AgentTool($child, 'test', 'Test agent');
    $parent = new Run(new FakeModel(calls(['p1', 'test', ['prompt' => '']]), says('done')), new Tools($agent));

    $events = turn($parent, user('test'));

    // The empty prompt should cause a Retry, which results in an error message in the tool result.
    // The tool error result contains the Retry message
    $toolResults = \array_values(\array_filter(
        \array_merge(...\array_map(static fn ($m) => $m->parts, $parent->messages())),
        static fn ($p) => $p instanceof Harness\Part\ToolResult,
    ));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->error)->toBeTrue()
        ->and($toolResults[0]->content[0]->text)->toContain("'prompt' is required");
});

it('two read-only child agents interleave and run concurrently in one parent step', function () {
    $order = [];

    // Build two read-only child agents that sleep to prove interleaving
    $childA = new Run(
        new FakeModel(function () use (&$order) {
            yield new TextDelta('A');
            \fiber\sleep(0.01);
            $order[] = 'A-continued';
            yield new TextDelta('1');
            yield new Finish(FinishReason::Stop);
        }),
        new Tools(),
    );
    $childB = new Run(
        new FakeModel(function () use (&$order) {
            $order[] = 'B-started';
            yield new TextDelta('B');
            \fiber\sleep(0.01);
            yield new TextDelta('2');
            yield new Finish(FinishReason::Stop);
        }),
        new Tools(),
    );

    // Both agents are read-only (no tools)
    $agentA = new AgentTool($childA, 'exploreA', 'Explore A', fresh: true);
    $agentB = new AgentTool($childB, 'exploreB', 'Explore B', fresh: true);

    // Parent calls both in parallel; the sleeps prove they interleave
    $parent = new Run(
        new FakeModel(
            calls(['a1', 'exploreA', ['prompt' => 'search A']], ['b1', 'exploreB', ['prompt' => 'search B']]),
            says('done'),
        ),
        new Tools($agentA, $agentB),
    );

    $events = turn($parent, user('go'));

    // Verify: B started before A continued (proves interleaving under the sleep)
    expect($order)->toEqual(['B-started', 'A-continued'])
        ->and(\array_filter($events, fn ($e) => $e instanceof Harness\Event\TextDelta))->not->toBeEmpty()
        ->and($parent->ended()->reason)->toBe(Harness\Reason::Done);
});
