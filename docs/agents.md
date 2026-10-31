# Harness\Agents

`AgentTool` makes a sub-agent a tool. It takes a `Run` as the template for the child:

```php
$explorer = new Run($model, new Tools(new ReadFile($root), new Grep($root)), system: 'Explore and report.');
$parent   = new Run($model, new Tools(new AgentTool($explorer, 'explorer', 'Reads the project and answers a question')));
```

- Input: `prompt`, the whole task; the child sees nothing else of the parent's conversation.
- `fresh: true` (the default): each call runs a turn of `$child->fork()`, the child's configuration
  and a copy of its messages, so every call starts from the same context (empty, unless the host
  built the child with `messages:`). `fresh: false`: each call continues the same child, whose
  context grows across calls (in this process; two calls at once fail, since a run has one turn).
- The child's events are forwarded to the parent's stream with `parent` set to the tool call id
  (nested agents nest the same way). Cancelling the parent cancels the child.
- The model gets the child's final assistant text. A reply cut by the token limit or refused gets a
  trailing line saying so; `meta['finish']` is the child's `FinishReason`, `meta['usage']` and
  `meta['reason']` the rest, and `Result::$value` the child's `Ended`. A failed child is an error
  result.
- `AgentTool::spec()` derives the hints from the child's tools each time it is called: `readOnly`
  when every child tool is read-only (so several sub-agents run concurrently), `destructive` and
  `openWorld` when any child tool is; `idempotent` is false.
- A thinking child needs the same idiom as the README's: turn thinking off through the provider's
  knob in the child `Run`'s own `Options::$provider`, or a tight `maxOutputTokens` is spent on
  reasoning with nothing left to answer with. When that happens anyway, the child still ends
  `Done` (finish `Stop`, not `Length`, so the cut-off line below does not fire) with an empty
  message, and the parent's model sees `(the agent gave no text)` in the tool result.

## Nested pauses

When the child ends awaiting input, the parent's call ends with `needsInput()`, so the parent turn
ends `AwaitingInput` too, and a web host sees one `Pending` list:

- The parent's call lists every need of every pending child call, each keeping its type and
  reason, its id prefixed with the child's call id: the child's `Approval` `approval` on call `w1`
  becomes `Approval('w1/approval', 'write_file changes files')`. Nested agents prefix again
  (`m1/w1/approval`).
- `state` holds the child's messages and pending calls, as JSON. The host answers the parent's call
  like any other, by need id (`new Answer($parentCallId, ['w1/approval' => Decision::allow()])`),
  in any process; `AgentTool` restores the child from the state, routes each answer to its call
  and lets the child run on. A `Decision::deny($reason)` reaches the child with its reason: the
  child's model gets `Denied: <reason>`, or the child's tool its denied `Decision` when it asked
  with state.

A policy in the parent that gates the sub-agent itself checks `answers()`, so a resumed call is not
asked again (`[] === $context->answers()`; see the README's policy, and `Harness\Policy`).

A CLI can answer nested needs at once, without ending the turn: the README's inline idiom loops
while `$context->pending()` is set, and each `$next()` resumes the child with the answers.

## Sub-agents and Policy

A parent `Policy` sees the agent tool's derived hints, so a sub-agent with any writing tool is not
read-only, and a parent default of `Ask` gates the delegation itself, before the child runs. Allow
the delegation by name and let the child's own `Policy` gate its writes; the parent's answering
middleware, listed before the parent's policy, answers the child's asks inline:

```php
$writer = new Run($model, new Tools(new ReadFile($root), new WriteFile($root)), middleware: [new Policy([
    Rule::hints(Verdict::Allow, readOnly: true),
    Rule::tool('write_file', Verdict::Ask),
], default: Verdict::Deny)]);
$policy = new Policy([Rule::tool('writer', Verdict::Allow), Rule::hints(Verdict::Allow, readOnly: true)]);
$run    = new Run($model, new Tools(new AgentTool($writer, 'writer', 'Writes files')), middleware: [$terminal, $policy]);
```

[policy.md](policy.md) has the rest.

## Compact per child

A child's context grows with what it reads; give the template a `Compact`, and every fork runs it
(a fork shares the template's middleware objects):

```php
$compact  = new Compact($summarizer, 8000, log: $log);
$explorer = new Run($model, new Tools(new ReadFile($root), new Grep($root)), middleware: [$compact]);
$parent   = new Run($model, new Tools(new AgentTool($explorer, 'explorer', 'Reads the project')));
```

One instance may serve every child, concurrent ones included: it keeps the last 8 checkpoints, each
keyed by a content hash of the messages it covers, so each child gets, reuses and updates its own.
The cost: beyond 8 children compacting at once a checkpoint is evicted and written again when its
child needs it, and `last()`,
the counter's calibration and the budget floor are the instance's, shared by all children. Give each
template its own `Compact` for `last()` per child ([compact.md](compact.md)).

## Rendering concurrent sub-agents

Read-only sub-agents run at once, so their `TextDelta`s interleave in the stream. Buffer text per
message id and print a whole line at a time, the rest at `MessageEnded`; never print characters of
two open messages in between each other. Indent by nesting: a call's depth is that of the event that
announced it (`ToolCallReady`) plus one.

```php
$open[$e->id] = ($open[$e->id] ?? '') . $e->text;                    // TextDelta
while (($eol = strpos($open[$e->id], "\n")) !== false) {
    echo $pad, substr($open[$e->id], 0, $eol), "\n";
    $open[$e->id] = substr($open[$e->id], $eol + 1);
}
```

[`examples/subagent.php`](../examples/subagent.php) renders this way.
