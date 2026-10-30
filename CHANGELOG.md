# Changelog

## 0.0.1-beta4

Implements SPEC.md revision 4: the round-3 users' problems were in `Compact`, plus one unbounded
message in the accumulator.

### Changed (breaking)

- **`ToolDecided(turn, parent, id, needId, decision)`**, emitted once per decision answered on a
  resumed call: every `Approval`, whatever its id, and every `Question` declined with
  `Decision::deny()`, in the order of the call's needs (before: only the answer to the need
  `approval`, without saying which need). A call asking approvals under custom ids (`McpTool`, a
  tool with several approvals) emitted nothing. `toArray()` adds `needId`, which
  `Events::fromArray()` requires. A sub-agent's approval now emits twice, which beta3 avoided: on
  the parent's call under the forwarded id (`w1/approval`, `parent` null) and on the child's call
  under its own (`approval`, `parent` set), each at its own level, as `ToolEnded` is.
- **A failed turn keeps only complete steps.** The partial assistant message of the step in
  progress, and the results of that step's calls that had finished, are discarded, as on
  `Cancelled`: before, a middleware that threw while a step's tools ran left the assistant's
  `ToolCall`s in `messages()` without results, and a new `Run` on them threw. A failed resume
  leaves the calls it answered in `Ended::$pending` (before: `pending` was emptied and the calls
  had no results); the same run takes the answers again.
- **`Compact`'s side call uses `summarizerOptions`** (new last constructor parameter, default
  `new Options(maxOutputTokens: 1024)`), never the step's `maxOutputTokens` and provider options: a
  thinking model spent the step's small output budget on reasoning and returned no summary.
- **`Compact` fails the step on a collapsed summary.** A summary that is empty, or shorter than a
  quarter of the earlier summary it replaces, is asked for once more; a second one throws a
  `RuntimeException` naming both sizes. Beta3 accepted a 25-byte summary after a 1,093-byte one, and
  the agent then invented file names. An empty summary used to fail at once.
- **`Compact` enforces a budget floor.** The fixed part of the prompt (system text, tool specs, the
  first `keepFirst` messages), counted at first use, must count at most half the budget, compared
  with the calibrated count; otherwise the step throws a `\LogicException` naming both and the
  budget to set. Beta3 ran a budget the fixed part nearly filled by summarizing every step.
- **`Compact` calibrates its count** from the input tokens the provider reports for the prompts it
  handed on (a report of zero is ignored): `ratio * count + offset`, the line through the smallest
  and the largest prompt reported, once the largest counts at least twice the smallest; until then
  ratio 1 and the largest one's difference as the offset. `Compaction::$before`/`$after` and the
  budget are in calibrated tokens. Bytes / 4 undercounted a Qwen template by half. SPEC r4 asked
  for a ratio alone (the last one wins); live on qwen3.8-flash-next, a first prompt of 86 counted
  tokens reported 354 (the template's tool preamble), so the ratio of 4.1 counted the next prompt
  as 3,063 tokens when it was 1,058, and compacted at half the budget. The line predicts the
  compacted prompt of `examples/compact.php` at 1,284 tokens, reported 1,285. A line through two
  close prompts (one `list_dir` step apart) had a ratio of 1.76 and overcounted the next file read
  by a quarter, hence the span it waits for. The budget floor compares calibrated once there is a
  line: one report's difference, from a long restored conversation, says little about the fixed
  part.
- **`Compact` gives the summarizer the kept first messages as context** ("the start of the
  conversation, which the agent keeps in full"), before the part to summarize. Live, the summary
  of a part that began after the user's question reported the task as unknown and the remaining
  work as "wait for the user's instruction".
- **require: `fiber-interop/api ^0.3.1`** (before `dev-main`); require-dev
  `fiber-interop/phasync ^0.4.1`. `fiber-interop/revolt` stays at `dev-main`: its only tag, v0.3.0,
  allows `fiber-interop/scope` up to ^0.3. The internal `Signal` is gone; `fiber\Deferred` does its
  work.

### Added

- `Compaction::$previousSummaryBytes` (the summary this one replaced, null for the first),
  `Compaction::$ratio` and `Compaction::$offset` (the counter's calibration); the info log line
  gives both.
- `Compact` keeps the last 8 summaries, each under the messages it summarized, so one instance
  shared by forked sub-agents serves each conversation its own summary, concurrent ones included,
  without recomputing them in turns (beta3 remembered one, so two children replaced each other's).

### Fixed

- The error result for tool input that is not valid JSON (or not an object) quotes at most 500
  bytes of it, cut at a character, with its length (`... (12,345 bytes total)`): a long
  `write_file` argument cut by the token limit was echoed back whole on every retry.

### Docs

- README: `fiber\setup()` once per process and `fiber\run()` from a foreign fiber; the Failed
  guarantee; several `Pending` calls in one step and the `Answer` JSON for nested ids; the `turn`
  counter on a resume; `StepEnded(turn, parent, n)`; `Tool::spec()` and `Tools::tools()`; a
  `Length` reply holding a backend's tool-call template as text; `ToolDecided`.
- docs/compact.md: the side call's options, collapses, calibration, the budget floor, sharing an
  instance and its cost. docs/policy.md and docs/agents.md: the answering middleware and the
  array it sits in, Policy with `AgentTool`, the hints derivation, `Compact` per child.
  docs/mcp.md: the JSON of a declined final round.
- `examples/policy.php` and `examples/compact.php` check `$event->parent` in their renderers;
  `compact.php` turns the summarizer's thinking off with `summarizerOptions`, and its default
  budget is 1500 (calibrated, the session's prompts stay under 2000 and never compacted); it
  prints the calibration's offset beside the ratio.
- README: `turn` counts the turns of one `Run` object; a run restored from saved messages starts
  again at 1.

### Tests

- require-dev allows `pestphp/pest` ^5 (PHPUnit 13).
- The live file-tools test reads a ticket number, not a password: qwen3.8-flash-next declined to
  repeat a password it had read.

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
