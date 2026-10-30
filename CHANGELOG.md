# Changelog

## 0.0.1-beta3

Implements SPEC.md revision 3, from what the round-2 users needed.

### Changed (breaking)

- **A pause is a list of needs, each with an id.** `Need` is the new abstract base of `Approval` and
  `Question`. `Call::needsInput(array $needs, mixed $state = null)` takes a non-empty list of them
  (before: one `Approval`, or a list of `Question`s), so one call can ask for an approval and
  questions at once, and a sub-agent's several pending calls no longer have to be flattened into
  questions that lost the approvals' type and reasons.
- **`Approval(string $id = 'approval', ?string $reason = null)`** (before `Approval(string $reason = '')`):
  write `new Approval(reason: '...')`. The reason is null when not given.
- **`Question(string $id, string $text, ?array $choices = null)`**: null means any answer (before an
  empty array did); an empty list throws. The id `approval` is reserved for `Approval`, so
  `Call::decision()` is never a question's answer.
- **`Pending::$needs` is a `list<Need>`**, and `toArray()` writes it as
  `[{"type": "approval", "id", "reason"}, {"type": "question", "id", "text", "choices"}]`.
  `Pending::fromArray()` throws, naming the beta2 shape, on a single `{"type": ...}` object.
- **`Answer(string $callId, array $answers)`** keyed by need id, a `Decision` or a value each (before
  `Decision|array`); empty throws. `toArray()` keeps decisions and values apart:
  `{"callId", "decisions": {id: {"allowed", "reason"}}, "values": {id: value}}`.
- **`Call::answer(array $answers)`** keyed by need id (before `Decision|array`): a CLI writes
  `$context->answer(['approval' => Decision::allow()])`.
- **`Call::answers()` returns an array, empty until the host answered** (before null); it holds every
  answer, decisions included. `Call::decision()` is `answers()['approval']`. The policy idiom
  "unless the host answered" is `[] === $context->answers()`.
- **A `Question` may be answered with `Decision::deny($reason)`**, declining it; the tool is called
  again with the `Decision` among its answers. `Decision::allow()` on a question throws.
- **The denial rule is now stated as a rule** (`Approval`'s docblock, the README): state means "call
  me back with the answer, whatever it is". A call asked without state is not called again when any
  of its approvals is denied, and the model gets `Denied: <reasons>` (joined with `; `); with state it
  is called again. Unchanged from beta2 for a single approval.
- **`ToolDecided` is emitted for the answer to the need `approval`** (`Call::decision()`), so for a
  sub-agent's approval only the child's call emits it (with `parent` set), not also the parent's
  call that forwarded it.
- **`Agents\AgentTool` passes every need of every pending child call up with its type and reason**,
  the id prefixed with the child's call id (`w1/approval`, `q1/colour`; nested agents prefix again),
  even for a single child call. Before, one pending call passed up unchanged and several became
  questions with choices allow/deny, so the reasons were lost and a denial reached the child as
  "The host denied it". Now each answer is routed to its child call, and a denial keeps its reason.
- **`Mcp\McpTool` asks a form without fields as an `Approval`** with the request key as id and the
  message as reason (before a question with choices accept/decline), and sends `decline` for a form
  whose approval or any question was answered with `Decision::deny()`. Questions for fields
  without `enum` have `choices: null`.
- **require-dev: `fiber-interop/phasync` and `fiber-interop/revolt` at `dev-main`** (with
  `fiber-interop/api`), whose main branches allow `fiber-interop/scope ^0.5`; the suites run on
  `phasync/phasync` 2.0.7 and `fiber-interop/scope` 0.5.0.

### Added

- Module `Harness\Compact`: `Compact`, a `ModelMiddleware` that keeps each step's prompt within a
  token budget by replacing the oldest half of the messages with a summary from a side call, never
  splitting a tool call from its result, reusing the summary while it fits; `Compact::approximate()`,
  the default counter; `Compaction`, what `Compact::last()` reports. The constructor takes a PSR-3
  logger (`log:`) and `?Closure $count = null` (PHP 8.2 cannot default a parameter to a closure).
- Module `Harness\Policy`: `Policy`, a `ToolMiddleware` over `Rule::tool()`, `Rule::hints()` and
  `Rule::when()` with a `Verdict` (`Allow`, `Deny`, `Ask`), and `Policy::describe()` for a system
  prompt. `Rule::hints()` takes the verdict first (`Rule::hints(Verdict::Allow, readOnly: true)`):
  PHP allows no positional argument after a named one. `Rule::when()` takes a text naming the rule.
- `Provider\OpenAI\Responses`: the Responses API over `Transport`, with reasoning replayed by
  `encrypted_content` (`store: false`, the default) or by id, `function_call`/`function_call_output`
  by `call_id`, and strict schemas attempted by default (optional properties made nullable, and their
  nulls removed from the arguments before the tool sees them). Tested over `FakeTransport`; its live
  test is skipped without `OPENAI_API_KEY`.
- `Testing\TestPrompt::make()`: a `Prompt` with a never-set `Cancel`, for testing a `ModelMiddleware`.
- Examples `compact.php` and `policy.php`; `files.php` and `mcp.php` answer any need.
- Docs: docs/compact.md, docs/policy.md; docs/mcp.md "Writing a server" (the JSON of
  `input_required` and its retry, one round and two, `requestState` carrying earlier answers),
  the hints derivation, images in results, `decline`; docs/transcript.md with both files in one
  host; docs/testing.md `TestPrompt`; the README's events of nested runs and the `parent` check,
  the inline answering loop, and cancellation latency in numbers.

## 0.0.1-beta2

Implements SPEC.md revision 2, from what the five round-1 users needed.

### Changed (breaking)

- **Events are delivered synchronously.** `emit()` returns once the consumer's `foreach` asked for
  the next event, so side effects after an event (a middleware prompting on the terminal) happen
  after the host handled it. Before, the turn ran ahead of the consumer through a queue and output
  of the two interleaved. The consumer now sets the pace; one fiber switch per event.
- **`Prompt` takes a `Cancel`** (`new Prompt($system, $messages, $tools, $options, $cancel)`), and
  **`Http\Transport::post()` takes `Cancel $cancel`** as its fourth parameter. Cancellation used to
  be noticed only at the next delta, so a silent server held a cancelled turn until its idle
  timeout. A middleware that rewrites the prompt passes `$prompt->cancel` on; a third-party
  `Transport` or a `Model` calling `post()` directly (round 1's `OllamaChat`) passes it too.
- **`Pending::toArray()` writes questions as `{"type": "questions", "questions": [...]}`** instead
  of a bare list, so `needs` always says what it is; `Pending::fromArray()` throws on anything else.
- **A denied approval that was asked with a `state` calls the tool again** (with `decision()`
  denied) instead of answering `Denied: <reason>` for it. Needed for nested pauses: a sub-agent's
  denial must reach the sub-agent. An approval without state behaves as before.
- **`Call::state()` is set after any answer**, approvals included (it was only set after questions).
- **`ToolSpec::$schema` is normalized**: `properties: []` and other schema nodes PHP holds as empty
  arrays become `stdClass`, so backends receive `{}` (round 1's tools needed `(object) []`).
- **`Testing\FakeTransport` closures receive the `Cancel`** as a fourth argument.
- **`fiber-interop/api` is required at `dev-main`** until its scope ^0.5 support is tagged.

### Added

- `Cancel` (the signal, `isCancelled()`) and `Cancelled` (thrown by whatever saw it set). The turn
  ends `Cancelled` when its own signal was set; elsewhere `Cancelled` is an ordinary exception.
  `StreamTransport` checks the signal every 250 ms while it waits.
- `Ended::$finish` and `TurnEnded::$finish`: the `FinishReason` of the turn's last assistant message,
  since `Reason::Done` only means "no tool calls" and hid replies cut by the token limit.
- `Run::isRunning()`; `Run::fork(?array $messages = null, array $pending = [])` restores a fork with
  the given conversation (used to resume a paused sub-agent).
- `Events::fromArray()` rebuilds a typed event from its array, mirroring `Parts::fromArray()`; a
  `ToolEnded` that carried a Throwable comes back with `ReplayedError` (class name and message).
- `Result::fromArray()`.
- `Agents\AgentTool`: `fresh` (default true; false continues the same child), a trailing line and
  `meta['finish']` when the child's reply was cut off or refused, and nested pauses: a child that
  ends awaiting input ends the parent's call with `needsInput()`, its state holding the child's
  messages and pending calls, so a web host answers a sub-agent's approval like any other.
- `Testing\TestCall::make(?ToolSpec $spec, mixed $context, string $id, ?Closure $emit)`: a `Call`
  for unit-testing tools and tool middleware without a run. `$emit` (beyond the spec's signature)
  collects the events the call emits, for tools that report progress.
- Module `Harness\Transcript`: `Transcript`, `JsonlTranscript` and the `JsonlEvents` sink, writing
  whole lines in append mode, finishing short writes with `fiber\writable()`.
- Module `Harness\Mcp`: `McpServer` (a `ToolSource`, protocol revision 2026-07-28), `McpTool`,
  `StdioConnection`, `HttpConnection`, `McpException`. `input_required` becomes `needsInput()`.
- Examples `mcp.php` and `web.php`; `subagent.php` renders concurrent sub-agents a line at a time.
- Docs: the events' wire format, `Pending` JSON shapes, the approval rule, the retry hazard, Done
  versus finish, provider option keys, a changing `ToolSource`, `Root`'s API, `Shell`'s hints,
  rendering concurrent sub-agents; docs/transcript.md and docs/mcp.md.

### Fixed

- The round-1 research user's corrupt JSONL lines were not a harness fault: two `research.php`
  processes wrote the same file through a sink opened with `fopen($path, 'w')`, each at its own
  offset, overwriting each other. `JsonlEvents` appends, which keeps every line whole; tested with
  two processes on one file.

## 0.0.1-beta1

First beta, implementing SPEC.md revision 1.

- Core: content parts (`Text`, `Reasoning`, `ToolCall`, `ToolResult`, `Media`, `Reference`) and
  `Message` with one `toArray()`/`fromArray()` serialization; `Model`, `Prompt`, `Options`, deltas and
  the `Accumulator`; `Tool`, `ToolSpec`, `Hints`, `Result`, `ToolSource`, `Tools`, `ClosureTool`,
  `Call`, `Retry`.
- `Run`: the tool loop with typed events, stop predicates (`Stop::maxSteps`, `toolCalled`, `tokens`),
  cancellation, read-only calls of a step run concurrently and the others one at a time,
  `ModelMiddleware` and `ToolMiddleware`, failure kinds, and pause and resume (`Pending`, `Approval`,
  `Question`, `Answer`, `Decision`) that survives a process boundary.
- `Http`: `StreamTransport` (HTTP/1.1 over non-blocking sockets, waits through fiber-interop) and `Sse`.
- Modules: `Provider\OpenAI\ChatCompletions`, `Provider\Anthropic\Messages`, `Tools\Files`,
  `Tools\Shell`, `Agents\AgentTool`, `Testing\FakeModel` and `FakeTransport`.
- Examples: `chat.php`, `files.php`, `shell.php`, `subagent.php`.
- Tests: the unit suite runs under the phasync and the Revolt adapter; live tests against a Chat
  Completions endpoint.
