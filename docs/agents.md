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

- One pending child call passes its needs up unchanged: the child's `Approval`, or its questions.
- Several become one list of questions: an approval as question `<child call id>` with choices
  `allow`/`deny`, a question as `<child call id>/<question id>`.
- `state` holds the child's messages and pending calls, as JSON. The host answers the parent's call
  like any other (`new Answer($parentCallId, Decision::allow())`), in any process; `AgentTool`
  restores the child from the state, gives it the answers and lets it run on. A denial reaches the
  child's model as `Denied: <reason>`.

A policy in the parent that gates the sub-agent itself checks both `decision()` and `answers()`, so a
resumed call is not asked again (see the README's policy).

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
