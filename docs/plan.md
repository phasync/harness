# Harness\Tools\Plan

A plan the agent keeps, and a reminder that keeps the goal and the open plan in front of the model.

```php
$plan = new Plan();
$tools = new Tools(new PlanSet($plan), new PlanUpdate($plan));
$run   = new Run($model, $tools, middleware: [new Reminder($plan)]);
```

## Why

The system prompt names the task once, at the start, and is never shown again; a small model
drifts from the original ask after many tool calls, loses track of what remains, or redoes a step
it already finished. `Reminder` is the cheapest fix: it puts the goal and the open plan back in
front of the model at the end of every prompt (or every `$every`-th one), so it never has to recall
either from a context window it may have half forgotten.

## Plan

`Plan` holds an ordered list of `Step` (`id` from 1, `text`, `Status` and an optional `note`).

| Method | |
| --- | --- |
| `set(list<string> $texts): void` | replaces the whole plan; every step starts `Status::Pending` with no note, numbered from 1 |
| `update(int $id, Status $status, ?string $note = null): void` | changes one step's status and note; an unknown `$id` throws `\InvalidArgumentException` naming the ids that exist |
| `steps(): list<Step>` | every step, in order |
| `open(): list<Step>` | the steps not yet finished: `Pending` and `InProgress` |
| `isEmpty(): bool` | |
| `render(): string` | the checklist below |
| `toArray()` / `fromArray()` | a JSON-safe value, persisted like `Compact\State` |

`render()` is one line per step: `[x]` done, `[>]` in progress, `[ ]` pending, `[-]` skipped, each
`<mark> <id>. <text>`, with ` — <note>` when the step has one:

```
[x] 1. Read the failing test
[>] 2. Find the off-by-one — in bounds.php, checking the loop condition
[ ] 3. Fix it and rerun the test
```

## The tools

| Tool | Input | Hints |
| --- | --- | --- |
| `plan_set` | `steps`: a list of 1-30 short descriptions, in order | idempotent |
| `plan_update` | `step` (its number), `status` (`pending`, `in_progress`, `done`, `skipped`), optional `note` | idempotent |

Both return the rendered plan on success. `plan_set` replaces the plan, starting every step over at
`pending`; the model is told to call it before starting work on a task with more than two steps, and
that a short task needs none. `plan_update` is told to mark a step `in_progress` when starting it and
`done` when finishing it, and never to leave one `in_progress` while working on another, so the
checklist always shows where the agent actually is, not where it was when it last looked. An
unknown `step` is an error result naming the ids that exist, not a thrown exception: the model
corrects it and goes on, as a `Retry` would, but is reached through `Plan::update()`'s own
`\InvalidArgumentException` rather than the tool throwing `Retry` itself.

Neither tool is `readOnly` (both change the plan the agent keeps), `destructive` or `openWorld`.

## Reminder

`Reminder` is a `ModelMiddleware`. On every `$every`-th model call counted from its construction
(`$every = 1`: every call) it builds one message:

```
Goal: <the first user message of the conversation, cut at $goalChars with an ellipsis>

Plan:
<Plan::render()>
```

the `Plan:` part only while the plan holds steps. The message is appended after the prompt's last
message, which is where a user message may go: when the last message is already a user one (the
step's own turn — the user's text, or a step's tool results), the reminder becomes an extra text
part on a *copy* of that message instead of a second user message, since some providers reject two
user messages in a row. Either way only the copy of the prompt handed to `$next` changes;
`Run::messages()` and a transcript never see the reminder, the same way `Compact` leaves them alone.

```php
new Reminder(Plan $plan, int $every = 1, int $goalChars = 600)
```

## Order with Compact

Both are `ModelMiddleware`; list `Reminder` after `Compact`:

```php
$run = new Run($model, $tools, middleware: [$compact, $reminder]);
```

The array is outermost first, so `Compact` runs first and folds the older messages into its
checkpoint from the prompt as `Run` built it — the reminder is not in that prompt yet, so it is
never folded into the checkpoint. `Reminder` then runs on the already-folded prompt and adds the
reminder on top of it, right before the model call. Reversing the order would let `Compact` see and
possibly fold the reminder itself, which would then recur inside the checkpoint text it keeps
updating.

## Persistence

`Plan` is a plain value: a web host that rebuilds its `Run` per request persists `$plan->toArray()`
next to the conversation and restores it with `Plan::fromArray()`, the same idiom `Compact\State`
uses ([docs/compact.md](compact.md)). `Reminder` holds no state worth persisting beyond the `Plan`
it was given: its call counter resets with the process, which only changes where `$every` lands,
never what a reminder says.

[`examples/plan.php`](../examples/plan.php) runs an agent with the file tools, the plan tools and a
`Reminder`, printing the plan after each `plan_set`/`plan_update` call.
