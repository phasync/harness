<?php

declare(strict_types=1);

use Harness\Part\Text;
use Harness\Reason;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\Tools\Plan\Plan;
use Harness\Tools\Plan\PlanSet;
use Harness\Tools\Plan\PlanUpdate;

it('runs plan_set then plan_update as a model would, each returning the rendered plan', function () {
    $plan  = new Plan();
    $tools = new Tools(new PlanSet($plan), new PlanUpdate($plan));

    $model = new FakeModel(
        calls(['s1', 'plan_set', ['steps' => ['Read the file', 'Write the fix']]]),
        calls(['u1', 'plan_update', ['step' => 1, 'status' => 'in_progress']]),
        says('Working on step 1.'),
    );
    $run    = new Run($model, $tools);
    $events = turn($run, user('Fix the bug'));

    $toolResults = \array_values(\array_map(
        static fn ($r) => \implode('', \array_map(static fn ($p) => $p instanceof Text ? $p->text : '', $r->content)),
        \array_filter(
            \array_merge(...\array_map(static fn ($m) => $m->parts, $run->ended()->appended)),
            static fn ($p) => $p instanceof Harness\Part\ToolResult,
        ),
    ));

    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and($toolResults)->toBe([
            "[ ] 1. Read the file\n[ ] 2. Write the fix",
            "[>] 1. Read the file\n[ ] 2. Write the fix",
        ])
        ->and($plan->steps())->toHaveCount(2)
        ->and(types($events))->toContain('tool_ended');
});

it('plan_update on an unknown step is an error result listing the ids, not a thrown exception', function () {
    $plan  = new Plan();
    $tools = new Tools(new PlanSet($plan), new PlanUpdate($plan));
    $plan->set(['only one step']);

    $model = new FakeModel(
        calls(['u1', 'plan_update', ['step' => 9, 'status' => 'done']]),
        says('Done.'),
    );
    $run = new Run($model, $tools);
    turn($run, user('Finish step 9'));

    $results = \array_values(\array_filter(
        \array_merge(...\array_map(static fn ($m) => $m->parts, $run->ended()->appended)),
        static fn ($p) => $p instanceof Harness\Part\ToolResult,
    ));

    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and($results[0]->error)->toBeTrue()
        ->and($results[0]->content[0]->text)->toBe('Step 9 does not exist; the plan has steps 1');
});
