# Harness\Agents

`AgentTool` makes a sub-agent a tool. It takes a `Run` as the template for the child:

```php
$explorer = new Run($model, new Tools(new ReadFile($root), new Grep($root)), system: 'Explore and report.');
$parent   = new Run($model, new Tools(new AgentTool($explorer, 'explorer', 'Reads the project and answers a question')));
```

- Input: `prompt`, the whole task; the child sees nothing else of the parent's conversation.
- Each call runs a turn of `$child->fork()`: the child's configuration and a copy of its messages,
  so every call starts from the same context (empty, unless the host built the child with
  `messages:`).
- The child's events are forwarded to the parent's stream with `parent` set to the tool call id
  (nested agents nest the same way). Cancelling the parent cancels the child.
- The model gets the child's final assistant text; `Result::$value` is the child's `Ended`, `meta`
  its usage. A failed child, or one that ends awaiting input, is an error result: answer approvals
  inside the child with a terminal-style middleware.
- Hints follow the child's tools: read-only when all are (so several sub-agents run concurrently),
  destructive or open-world when any is.
