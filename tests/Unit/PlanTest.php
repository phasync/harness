<?php

declare(strict_types=1);

use Harness\Tools\Plan\Plan;
use Harness\Tools\Plan\Status;
use Harness\Tools\Plan\Step;

it('starts empty and renders nothing', function () {
    $plan = new Plan();

    expect($plan->isEmpty())->toBeTrue()
        ->and($plan->steps())->toBe([])
        ->and($plan->open())->toBe([])
        ->and($plan->render())->toBe('');
});

it('sets a plan as pending steps numbered from 1', function () {
    $plan = new Plan();
    $plan->set(['Read the file', 'Write the fix', 'Run the tests']);

    expect($plan->isEmpty())->toBeFalse()
        ->and($plan->steps())->toEqual([
            new Step(1, 'Read the file', Status::Pending, null),
            new Step(2, 'Write the fix', Status::Pending, null),
            new Step(3, 'Run the tests', Status::Pending, null),
        ])
        ->and($plan->render())->toBe("[ ] 1. Read the file\n[ ] 2. Write the fix\n[ ] 3. Run the tests");
});

it('replaces the whole plan on a second set(), numbering from 1 again', function () {
    $plan = new Plan();
    $plan->set(['a', 'b']);
    $plan->update(1, Status::Done);
    $plan->set(['c']);

    expect($plan->steps())->toEqual([new Step(1, 'c', Status::Pending, null)]);
});

it('updates a step\'s status and note, and renders the mark and note', function () {
    $plan = new Plan();
    $plan->set(['a', 'b', 'c', 'd']);
    $plan->update(1, Status::Done);
    $plan->update(2, Status::InProgress, 'halfway there');
    $plan->update(4, Status::Skipped, 'not needed');

    expect($plan->render())->toBe(
        "[x] 1. a\n[>] 2. b — halfway there\n[ ] 3. c\n[-] 4. d — not needed"
    );
});

it('a later update without a note clears the previous note', function () {
    $plan = new Plan();
    $plan->set(['a']);
    $plan->update(1, Status::InProgress, 'working on it');
    $plan->update(1, Status::Done);

    expect($plan->render())->toBe('[x] 1. a');
});

it('open() lists pending and in_progress steps, in order, not done or skipped', function () {
    $plan = new Plan();
    $plan->set(['a', 'b', 'c', 'd']);
    $plan->update(1, Status::Done);
    $plan->update(2, Status::InProgress);
    $plan->update(4, Status::Skipped);

    expect($plan->open())->toEqual([
        new Step(2, 'b', Status::InProgress, null),
        new Step(3, 'c', Status::Pending, null),
    ]);
});

it('throws naming the ids that exist when the step id is unknown', function () {
    $plan = new Plan();
    $plan->set(['a', 'b', 'c']);

    $plan->update(5, Status::Done);
})->throws(InvalidArgumentException::class, 'Step 5 does not exist; the plan has steps 1, 2, 3');

it('throws naming that there are no steps when the plan is empty', function () {
    $plan = new Plan();

    $plan->update(1, Status::Done);
})->throws(InvalidArgumentException::class, 'Step 1 does not exist; the plan has no steps (call plan_set first)');

it('round-trips through toArray()/fromArray()', function () {
    $plan = new Plan();
    $plan->set(['a', 'b']);
    $plan->update(1, Status::Done, 'done early');

    $restored = Plan::fromArray($plan->toArray());

    expect($restored->steps())->toEqual($plan->steps())
        ->and($restored->render())->toBe($plan->render());
});
