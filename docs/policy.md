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

| `Rule::shell(list<Shell> $rules, string $tool = 'shell', Verdict $otherwise = Verdict::Ask)` | calls to the shell tool, decided by the parsed command (below) |

Bind rules to the tool's name and hints. A test over the arguments (a pattern over a shell command)
is bypassable: a model that is refused `rm -rf` writes `find -delete`. `when()` exists for what
names and hints cannot say (the host's `context`, a time of day), not for argument filters. The
shell tool is the one place where arguments decide, and `Rule::shell()` matches them parsed.

## Shell commands

`Harness\Policy\Shell` (the rules below) and `Harness\Tools\Shell\Shell` (the tool) share a name:
a file that uses both aliases one, `use Harness\Policy\Shell as ShellRule;`.

```php
$policy = new Policy([
    Rule::shell([
        Shell::allow('git', ['status', 'diff', 'log'], flags: ['--stat', '-p']),
        Shell::allow('ls'),
        Shell::deny('rm'),
        Shell::allow('composer', ['test', 'install']),
    ]),
    Rule::hints(Verdict::Allow, readOnly: true),
]);
```

The `command` input is parsed by a small POSIX-shell tokenizer (words, single and double quotes,
backslashes, `&&`, `||`, `;`, `|`, `&`, newlines, redirects, comments) into simple commands, and
each is matched, the first matching `Shell` rule deciding it:

- `Shell::allow($program, ?array $subcommands = null, ?array $flags = null)` matches the program by
  its exact name (`ls`, never `/tmp/x/ls`), its first argument against `$subcommands`, and every
  option before `--` (`-p`, `--stat`, the name of `--name=value`) against `$flags`, when given.
  Other arguments are free; a word with an unquoted glob never matches a list of flags, since it
  could expand to any option.
- `Shell::deny($program)` matches the program by its base name (`rm`, `/bin/rm`, `'r'm`, `r\m`),
  whatever its arguments. A program spelled with an unquoted glob (`r[m]`, `r?`) is denied outright,
  whatever the rules: it runs whatever the directory holds.
- The wrappers `env`, `nice`, `timeout`, `sudo`, `nohup`, `xargs`, `command`, `builtin` and `exec`
  are peeled with their options, and the wrapped command is matched (`env rm`, `sudo -u root rm`,
  `ls | xargs rm`, `command rm` are `rm`).

The line is denied when any command matches a deny rule, allowed when every command matches an
allow rule, and otherwise gets `$otherwise` (`Ask`, or `Deny`). It is asked about, whatever
`$otherwise`, when it cannot be checked: a `$` expansion, `$(...)`, backticks, a here-document,
parentheses, braces, an unknown wrapper option, `sudo -s`/`-i`, `env -S`; an environment
assignment (`PATH=... ls`, `env LD_PRELOAD=... ls`); output redirected to a file (`/dev/null` and
`2>&1` are fine). So `git status; rm -rf /` is denied, `ls | sh` and `ls $(rm -rf /)` are asked
about. The approval reason and the denial name the command that decided (`the shell command 'sh'
matches no allow rule`). Allowing an interpreter (`bash`, `sh`, `python`, `find` with `-exec`)
allows what it runs; give such programs a list of flags or leave them to `Ask`.

## Verdicts

- `Allow`: the call runs.
- `Deny`: the model gets the error result `Denied by the policy rule: <rule>`.
- `Ask`: the call ends with `needsInput([new Approval(reason: <rule>)])`, without state. A web host
  gets `AwaitingInput` and answers with an `Answer` in a later request; a denial reaches the model
  as `Denied: <reason>`. A CLI answers at once (below).

## Answering on the terminal

The answering middleware is the README's inline idiom. It wraps the policy: listed before `Policy`
in the array (outermost first), its `$next` runs the policy, so the `needsInput()` the policy
returns is what it sees in `$context->pending()`; it answers and calls `$next` again, and the policy
lets the answered call through ([`examples/policy.php`](../examples/policy.php)).

```php
$run = new Run($model, $tools, system: "...\n\n" . $policy->describe(), middleware: [$terminal, $policy]);
```

Listed after the policy, it would never run for a call the policy asks about: `Ask` returns without
calling `$next`.

## Policy with sub-agents

An `Agents\AgentTool` has hints derived from its child's tools: read-only only when every child
tool is. A sub-agent that can write is therefore not read-only, and a parent `Policy` whose default
is `Ask` gates the delegation itself, before the child ran and before any nested pause could happen.
Allow the delegation by name and let the child's own policy gate its writes:

```php
$childPolicy = new Policy([
    Rule::hints(Verdict::Allow, readOnly: true),
    Rule::tool('write_file', Verdict::Ask),
], default: Verdict::Deny);
$writer = new Run($model, new Tools(new ReadFile($root), new WriteFile($root)), middleware: [$childPolicy]);

$policy = new Policy([
    Rule::tool('writer', Verdict::Allow),          // the delegation; the child's policy asks per write
    Rule::hints(Verdict::Allow, readOnly: true),
]);                                                // anything else: Ask
$run = new Run($model, new Tools(new AgentTool($writer, 'writer', 'Writes files')), middleware: [$terminal, $policy]);
```

The child's `Ask` pauses the child, and through it the parent's `writer` call, with the need
`w1/approval` ([agents.md](agents.md)); the parent's `$terminal` answers it inline, and its `$next`
resumes the child. The child needs no answering middleware of its own.

A call the host answered already (`answers()` set) goes on unchecked, as does a call to a tool the
run does not have (the run tells the model). `describe()` returns the rules, one line each, for a
system prompt, so the model knows what will be refused or asked.

Test a policy without a run: `$policy->call($toolCall, TestCall::make($spec), $next)` ([testing.md](testing.md)).
