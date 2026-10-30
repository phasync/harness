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
- Hints follow the child's tools: read-only when all are (so several sub-agents run concurrently),
  destructive or open-world when any is.

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
