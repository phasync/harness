<?php

declare(strict_types=1);

use Harness\Compact\Compact;
use Harness\Message;
use Harness\Part\Text;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Role;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Testing\TestPrompt;
use Harness\Tools;
use Harness\Tools\Plan\Plan;
use Harness\Tools\Plan\Reminder;
use Harness\Tools\Plan\Status;

it('appends a Goal and Plan message as a new user message when the last is not one', function () {
    $plan = new Plan();
    $plan->set(['Read the file', 'Write the fix']);
    $plan->update(1, Status::Done);
    $reminder = new Reminder($plan);

    $prompt = TestPrompt::make(messages: [
        user('Fix the off-by-one bug'),
        new Message(Role::Assistant, [new Text('Looking into it.')]),
    ]);
    $seen = null;
    \iterator_to_array($reminder->stream($prompt, function (Prompt $p) use (&$seen): array {
        $seen = $p;

        return [];
    }), false);

    expect($seen->messages)->toHaveCount(3)
        ->and($seen->messages[0])->toBe($prompt->messages[0])
        ->and($seen->messages[1])->toBe($prompt->messages[1])
        ->and($seen->messages[2]->role)->toBe(Role::User)
        ->and($seen->messages[2]->parts)->toHaveCount(1)
        ->and($seen->messages[2]->parts[0]->text)->toBe("Goal: Fix the off-by-one bug\n\nPlan:\n[x] 1. Read the file\n[ ] 2. Write the fix")
        ->and($prompt->messages)->toHaveCount(2);   // the prompt handed to Reminder is untouched
});

it('folds the reminder into a copy of the trailing user message instead of a second one', function () {
    $plan     = new Plan();   // empty: no Plan section
    $reminder = new Reminder($plan, goalChars: 10);

    $toolResult = new Message(Role::User, [new ToolResult('c1', [new Text('4')])]);
    $prompt     = TestPrompt::make(messages: [user('A long question that gets cut'), $toolResult]);

    $seen = null;
    \iterator_to_array($reminder->stream($prompt, function (Prompt $p) use (&$seen): array {
        $seen = $p;

        return [];
    }), false);

    expect($seen->messages)->toHaveCount(2)
        ->and($seen->messages[1]->role)->toBe(Role::User)
        ->and($seen->messages[1]->parts)->toHaveCount(2)
        ->and($seen->messages[1]->parts[0])->toBe($toolResult->parts[0])
        ->and($seen->messages[1]->parts[1]->text)->toBe('Goal: A long que…')
        ->and($prompt->messages[1])->toBe($toolResult);   // the original message is untouched
});

it('every=2 adds the reminder only on even calls, skipping the odd ones', function () {
    $plan     = new Plan();
    $reminder = new Reminder($plan, every: 2);
    // Two messages, the last an assistant one, so an added reminder shows up as a third message.
    $prompt = TestPrompt::make(messages: [user('Task'), new Message(Role::Assistant, [new Text('working')])]);

    $first  = null;
    $second = null;
    \iterator_to_array($reminder->stream($prompt, function (Prompt $p) use (&$first): array {
        $first = $p;

        return [];
    }), false);
    \iterator_to_array($reminder->stream($prompt, function (Prompt $p) use (&$second): array {
        $second = $p;

        return [];
    }), false);

    expect($first->messages)->toHaveCount(2)        // call 1: skipped, unchanged
        ->and($second->messages)->toHaveCount(3)     // call 2: the reminder added
        ->and($second->messages[2]->parts[0]->text)->toStartWith('Goal:');
});

it('never reaches the host transcript: Run::messages() keeps the plain user message after a turn', function () {
    $plan = new Plan();
    $plan->set(['one step']);
    $reminder = new Reminder($plan);
    $model    = new FakeModel(says('ok'));
    $run      = new Run($model, new Tools(), middleware: [$reminder]);

    turn($run, user('Do the thing'));

    // The model's prompt has the reminder folded into the turn's one user message (2 parts).
    expect($model->prompts[0]->messages)->toHaveCount(1)
        ->and($model->prompts[0]->messages[0]->parts)->toHaveCount(2)
        ->and($model->prompts[0]->messages[0]->parts[1]->text)->toContain('Plan:')
        // Run::messages() keeps what the host sent: one message, one part, no reminder.
        ->and($run->messages())->toHaveCount(2)
        ->and($run->messages()[0]->parts)->toHaveCount(1);
});

it('works with Compact in front: the reminder reaches the model, never the checkpoint', function () {
    $plan       = new Plan();
    $plan->set(['investigate', 'fix']);
    $summarizer = new FakeModel(says('CHECKPOINT'));
    $counter    = fn (Prompt $p): int => \strlen(\json_encode(\array_map(static fn (Message $m): array => $m->toArray(), $p->messages)));
    $compact    = new Compact($summarizer, 400, $counter);
    $reminder   = new Reminder($plan);

    $messages = [user('Fix it')];
    for ($i = 1; $i <= 5; ++$i) {
        $messages[] = new Message(Role::Assistant, [new Text("step $i")]);
        $messages[] = new Message(Role::User, [new Text(\str_repeat("filler $i ", 10))]);
    }
    $prompt = TestPrompt::make(null, $messages);

    // A plain closure here, not an arrow function: an arrow function auto-captures $seenByModel by
    // value, and the inner closure's use (&$seenByModel) would then bind to that local copy, never
    // reaching this test's variable.
    $seenByModel = null;
    \iterator_to_array($compact->stream($prompt, function (Prompt $p) use ($reminder, &$seenByModel): iterable {
        return $reminder->stream($p, function (Prompt $p2) use (&$seenByModel): array {
            $seenByModel = $p2;

            return [];
        });
    }), false);

    $last     = $seenByModel->messages[\count($seenByModel->messages) - 1];
    $lastText = \implode('', \array_map(static fn ($part): string => $part instanceof Text ? $part->text : '', $last->parts));
    expect($compact->last())->not->toBeNull()                 // it did fold: the checkpoint is a separate side call
        ->and($lastText)->toContain('Goal: Fix it')->toContain('Plan:')->toContain('investigate')
        ->and($summarizer->prompts)->toHaveCount(1)
        ->and($summarizer->prompts[0]->messages[0]->parts[0]->text)->not->toContain('Goal:')->not->toContain('Plan:');
});
