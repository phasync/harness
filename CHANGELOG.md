# Changelog

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
