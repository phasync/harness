# phasync/harness — specification

Revision 3 (2026-10-31). Revisions 1 and 2 are 0.0.1-beta1 and 0.0.1-beta2, each tried by five
users. This revision generalizes the pause contract to several needs per call, adds the two
concepts the design still lacks as modules (compaction and policy), and closes the documentation
gaps the round-2 users named. The builder implements this document; sections marked *module* are
part of this package but live in their own namespace and are never imported by the core.

Changes since revision 2 are marked **[r3]**. Everything not marked is confirmed as built.

## Purpose

The runtime between a model and an application: the tool loop, a typed event stream, one
pause-and-resume contract, and two interception seams. It renders nothing: a CLI, a Swerve route
(SSE, WebSocket) or any other host renders the events. It speaks no protocol in its core:
providers, MCP, file tools and the rest are modules implementing core interfaces.

Concurrency goes through `fiber-interop/api` only (`fiber\go`, `await`, `await_all`, `run`,
`sleep`, `readable`, `writable`). The package never calls phasync, Revolt, Amp or React directly;
tests run under the `fiber-interop/phasync` adapter and under `fiber-interop/revolt`, and must
behave the same on both.

## Core

Namespace `Harness\`. PHP 8.2+. Dependencies: `fiber-interop/api`, `psr/log`. Nothing else.
**[r3]** `fiber-interop/phasync` and `fiber-interop/revolt` on their main branches now allow
`fiber-interop/scope ^0.5`; require-dev takes both at `dev-main` (with `fiber-interop/api` at
`dev-main`) so `phasync/phasync` resolves to its latest 2.0.x. Run the suites on that.

### Content parts and messages

A closed union, identical on the way in and out; every part is a final readonly value object
with `toArray()` and `static fromArray()`; `Parts::fromArray()` decodes by type.

```
Text(text)   Reasoning(text, raw)   ToolCall(id, name, input)   ToolResult(callId, content, error)
Media(mime, bytes, uri)   Reference(uri, title)   Message(Role, parts)
```

### Model, Transport

```php
interface Model { /** @return iterable<Delta> */ public function stream(Prompt $prompt): iterable; }
Prompt(?string system, Message[] messages, ToolSpec[] tools, Options options, Cancel cancel)
interface Transport { public function post(string $url, array $headers, string $body, Cancel $cancel): Response; }
```

As built: deltas, `Accumulator`, `Options` (with `provider[<module name>]` merged last and
`UnsupportedOptionException`), `Cancel`, `StreamTransport` with its 250 ms poll, `Sse`.
**[r3]** The README states the cancellation latency as a number (within 250 ms of `cancel()`
plus the time of the current tool's cooperation).

### Tool, ToolSource, Call, Result

As built: `Tool`, `ToolSpec` (schema normalization), `Hints`, `Result` (+`fromArray`),
`ToolSource`, `Tools`, `ClosureTool`, `Retry`, and `Call` with `id`, `run`, `context`, `spec`,
`progress()`, `isCancelled()`, `emit()`, `needsInput()`, `pending()`, `answer()`, `decision()`,
`answers()`, `state()`.

**[r3] Needs.** A pause carries a list of needs, each with an id, instead of one approval or a
list of questions:

```php
abstract readonly class Need { public string $id; }
Approval(string $id = 'approval', ?string $reason = null) extends Need
Question(string $id, string $text, ?array $choices = null) extends Need
Call::needsInput(Need[] $needs, mixed $state = null): Result
Pending(ToolCall $call, Need[] $needs, mixed $state)         needs JSON: [{"type":"approval",...},{"type":"question",...}]
Answer(string $callId, array<string, Decision|string|int|float|bool|array> $answers)   keyed by need id
Call::answers(): array<string, Decision|scalar|array>        Call::decision(): ?Decision  = answers()['approval'] when present
```

So `AgentTool` no longer flattens several pending child calls into questions with lost reasons:
each child need keeps its type and its id is prefixed with the child call id (`<callId>/<id>`),
and a `Decision::deny(reason)` reaches the child with its reason. A `Question` may be answered
with `Decision::deny(reason)` too: the asker is told the host declined (MCP's `decline`).

**[r3] Who handles a denial.** The rule built in beta2 (a denied approval asked with a non-null
state calls the tool again with `decision()` denied; one asked without state is answered by the
Run with `Denied: <reason>`) stays, and is stated as the rule in the `Approval` docblock and in
the README: *state means "call me back with the answer, whatever it is"*.

### Run and Turn, Cancellation, Events, Middleware

As built (revision 2). **[r3]** Two documentation additions in the README's Events section:
events of a nested run are the same classes with `parent` set, so a renderer that reacts to
turn-boundary events (`TurnStarted`, `StepStarted/Ended`, `TurnEnded`, `InputRequested`) must
check `parent` or it renders a nested pause twice; and the CLI idiom for resolving a nested
approval entirely inline (loop while `$context->pending()` is not null: ask, `answer()`, call
`$next` again), next to the `Answer`-based resume.

**[r3]** `Testing\TestPrompt::make(?string $system = null, array $messages = [], array $tools = [],
Options $options = new Options()): Prompt` with a never-cancelled `Cancel`, for unit-testing a
`ModelMiddleware`; documented in docs/testing.md next to `TestCall`.

## Modules

### Provider\OpenAI, Provider\Anthropic, Tools\Files, Tools\Shell, Agents, Testing, Transcript, Mcp

As built. **[r3]** docs/mcp.md gains a "Writing a server" section with the literal JSON of an
`input_required` result (`inputRequests`, `requestState`) and of the retry (`inputResponses`),
for one question and for two rounds; the rule that each resume carries `inputResponses` for the
latest round's `inputRequests` only, so a server keeps earlier answers in `requestState`; the
hints derivation (`readOnlyHint` true forces `destructive` false); a pointer to docs/openai.md
for images in tool results being relocated to a following user message; and `decline` for form
elicitations via `Decision::deny()`. docs/transcript.md shows `JsonlTranscript` and `JsonlEvents`
together in one host. `JsonlEvents` stays path-based (an SSE socket needs SSE framing, which a
Swerve route does itself with `json_encode($event)`).

### Compact (module, **[r3] this round**)

Context management, the one concept of the design still missing. A `ModelMiddleware` that keeps
a prompt within a budget by replacing its oldest messages with a summary:

```php
new Compact(Model $summarizer, int $budgetTokens, Closure $count = Compact::approximate(...), int $keepFirst = 1)
```

Rules, as the surveyed harnesses converged on them: triggered when `$count($prompt)` exceeds the
budget (the default counter is a documented approximation: bytes / 4, plus a fixed cost per
message and per tool spec); the system prompt, the tool specs and the first `$keepFirst`
messages are never touched; the oldest half of the remaining messages is replaced by one user
message holding a summary that the summarizer model produced in a side call, with its own fixed
prompt (what was asked, what was done, what is known, what remains), and an assistant
acknowledgement, so the transcript stays alternating; the recent half stays verbatim; tool-call
and tool-result pairs are never split (the cut moves back to a message boundary that keeps every
`ToolResult` with its `ToolCall`); `Reasoning` parts are dropped from what is summarized.
Compaction happens inside the prompt handed to `$next`, never in `Run::messages()`: the
conversation the host persists is complete, and the summary is recomputed only when the budget
is exceeded again (the middleware remembers the last cut point and summary, keyed by the message
count, so the prefix stays stable for the provider's cache between steps). The core gains no
event for it: the middleware logs each compaction through the PSR-3 logger it is given
(messages removed, summary size), and `Compact::last(): ?Compaction` tells a host what the last
step did. Tests over a `FakeModel` summarizer: a long transcript with tool pairs compacts at the
right boundary, the summary replaces exactly the oldest half, nothing after the cut changes, a
second step with no growth reuses the summary, `keepFirst` and the system prompt are intact.

### Policy (module, **[r3] this round**)

The approval layer every harness ended up with, as a `ToolMiddleware` over rules, so a host
does not hand-write `Hints` checks:

```php
new Policy(Rule[] $rules, Verdict $default = Verdict::Ask)
Rule::tool('write_file', Verdict::Ask)                  by name (exact or glob: 'mcp_*')
Rule::hints(readOnly: true, Verdict::Allow)             by annotation
Rule::when(Closure(ToolCall, Call): bool, Verdict)      anything else
Verdict::Allow | Deny | Ask
```

First matching rule wins; `Deny` returns an error result naming the rule; `Ask` calls
`$context->needsInput([new Approval(reason: <rule>)])` so a CLI answers inline through its own
middleware placed after the policy (the README idiom), and a web host gets `AwaitingInput`.
Matching binds only to the tool name and hints, never to argument content (the survey's lesson:
a pattern over a shell command is bypassable). A `Policy::describe()` returns the rules as text
for a system prompt. Tests with `TestCall`.

### Provider\OpenAI\Responses (module, **[r3] if time allows, after everything above**)

The Responses API over `Transport`: items `message`, `reasoning` (replayed by `encrypted_content`
when `store: false`), `function_call`/`function_call_output` by `call_id`; events
`response.output_text.delta`, `response.function_call_arguments.delta/.done`,
`response.completed`, `error`; strict schemas attempted by default. Skipped live without
`OPENAI_API_KEY`.

## Examples and CLI

As built, plus **[r3]** `examples/compact.php` (a long session that compacts, printing the
`Compacted` events) and `examples/policy.php` (the files agent with a `Policy` instead of a
hand-written gate). Each reads `HARNESS_OPENAI_URL` and `HARNESS_MODEL`.

## Rules the code follows

- Fail hard and loud: misuse throws an instructive exception at the point of misuse; no guards
  that paper over a broken invariant; no `@`.
- Code as if type safe: `declare(strict_types=1)`, readonly value objects, enums for closed sets,
  no arrays where a value object fits.
- No convenience layers: one way to do each thing; document the idiom instead of adding a helper.
- Identical behaviour on every fiber-interop adapter; a difference is a bug, reported, not
  worked around.
- Docs concise: README, a page per module, CHANGELOG with a section per tag (every public API
  change named, with why). Docblocks on every public symbol, one paragraph of fact.
- Tests with Pest under both adapters; live tests tagged and skipped without the endpoint.
