# Harness\Policy

`Policy` is a `ToolMiddleware` that decides each tool call by rules, so a host does not hand-write
`Hints` checks.

```php
$policy = new Policy([
    Rule::hints(Verdict::Allow, readOnly: true),        // reads run
    Rule::tool('mcp_billing_*', Verdict::Deny),         // never
    Rule::tool('write_file', Verdict::Ask),
    Rule::hints(Verdict::Ask, destructive: false),
], default: Verdict::Deny);
$run = new Run($model, $tools, system: "...\n\n" . $policy->describe(), middleware: [$policy]);
```

## Rules

The first rule that matches decides; with none, `$default` (`Verdict::Ask` unless given).

| Rule | matches |
| --- | --- |
| `Rule::tool(string $name, Verdict)` | the tool name, exactly or as a glob (`*`, `?`, `[...]`) |
| `Rule::hints(Verdict, ?bool $readOnly, ?bool $destructive, ?bool $idempotent, ?bool $openWorld)` | tools whose `Hints` have every value given |
| `Rule::when(Closure(ToolCall, Call): bool, Verdict, string $text)` | anything else; `$text` names the rule |

Bind rules to the tool's name and hints. A test over the arguments (a pattern over a shell command)
is bypassable: a model that is refused `rm -rf` writes `find -delete`. `when()` exists for what
names and hints cannot say (the host's `context`, a time of day), not for argument filters.

## Verdicts

- `Allow`: the call runs.
- `Deny`: the model gets the error result `Denied by the policy rule: <rule>`.
- `Ask`: the call ends with `needsInput([new Approval(reason: <rule>)])`, without state. A web host
  gets `AwaitingInput` and answers with an `Answer` in a later request; a denial reaches the model
  as `Denied: <reason>`. A CLI answers at once in a middleware placed before (outside) the policy,
  the README's inline idiom ([`examples/policy.php`](../examples/policy.php)).

A call the host answered already (`answers()` set) goes on unchecked, as does a call to a tool the
run does not have (the run tells the model). `describe()` returns the rules, one line each, for a
system prompt, so the model knows what will be refused or asked.

Test a policy without a run: `$policy->call($toolCall, TestCall::make($spec), $next)` ([testing.md](testing.md)).
