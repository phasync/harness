<?php

declare(strict_types=1);

use Harness\Agents\AgentTool;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\FinishReason;
use Harness\Part\Text;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\Tools\Plan\Plan;
use Harness\Tools\Plan\Reminder;

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

it('reminder with every: 3 shows which of 7 model calls carry the goal and plan', function () {
    $plan = new Plan();
    $plan->set(['step 1', 'step 2', 'step 3']);
    $reminder = new Reminder($plan, every: 3);

    $tools = new Tools(
        tool('t1', fn () => Result::text('r1')),
        tool('t2', fn () => Result::text('r2')),
    );

    // Build a sequence: 7 model calls.
    // Calls 3 and 6 (1-indexed) should have the reminder appended (3 % 3 = 0, 6 % 3 = 0)
    $model = new FakeModel(
        calls(['a1', 't1', []]),    // call 1: no reminder
        calls(['a2', 't1', []]),    // call 2: no reminder
        calls(['a3', 't1', []]),    // call 3: HAS reminder (3 % 3 = 0)
        calls(['a4', 't2', []]),    // call 4: no reminder
        calls(['a5', 't2', []]),    // call 5: no reminder
        calls(['a6', 't2', []]),    // call 6: HAS reminder (6 % 3 = 0)
        says('done'),               // call 7: final response
    );

    $run = new Run($model, $tools, middleware: [$reminder]);
    turn($run, user('start'));

    // Check: reminder should appear in model prompts 3 and 6 (0-indexed: 2 and 5)
    // Count how many prompts have "Goal:" (indicator of reminder being added)
    $promptsWithGoal = \array_values(\array_filter(
        $model->prompts,
        fn ($p) => \str_contains(
            \implode('', \array_map(
                fn ($m) => \implode('', \array_map(
                    fn ($part) => $part instanceof Text ? $part->text : '',
                    $m->parts,
                )),
                $p->messages,
            )),
            'Goal:'
        ),
    ));

    // With every: 3, calls 3 and 6 should have the reminder (calls are 1-indexed)
    // That's prompts [2] and [5] in the 0-indexed array
    expect($promptsWithGoal)->toHaveCount(2)
        ->and($run->ended()->reason)->toBe(Harness\Reason::Done);
});
