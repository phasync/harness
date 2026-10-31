# Changelog

## 0.2.0 (unreleased)

### Added

- **`Harness\Tools\Web`**: `WebSearch` and `WebFetch` tools, a `Search` interface with a `SearXng`
  implementation over a self-hosted instance's JSON API, and a dependency-free HTML-to-text
  extractor (a `<br>` is a line break; a nested list's items are indented under their parent
  item, #2; a `pre` inside a list item or paragraph stays verbatim on its own lines, #10). `WebFetch` guards against SSRF (private-network and non-http(s) URLs refused unless
  `allowPrivate`), caches a fetched page by `sha256(url)` when `cacheDir` is set, and cuts long
  text with the files tools' tail idiom. docs/web.md, examples/search.php.
- **`Harness\Tools\Plan`**: `Plan` (a `Step` list the `plan_set` and `plan_update` tools change),
  and `Reminder`, a `ModelMiddleware` that appends the goal and the open plan as a trailing message
  of the prompt it hands on, every `$every`-th model call, without touching `Run::messages()`.
  Placed after `Compact` in a `Run`'s middleware, it is never itself folded into a checkpoint.
  docs/plan.md, examples/plan.php.
- **`EditFile` gets a tolerant matching cascade** beneath its exact match: indentation-flexible
  (the block sits at another depth), line-trimmed, whitespace-normalized and block-anchor, each
  accepted only when it alone matches exactly once; a level matching more than once is the
  ambiguous error, naming every candidate line, and the cascade never falls through to a looser
  level once that happens. Every line-based match re-indents `new` by the delta between `old`'s
  common indent and the matched block's (#9), translating the indentation beyond it line by line so
  a tab-indented file stays tabs when `old` and `new` were written with spaces (#13). A successful edit's result now shows the edited
  region with 3 lines of context, as `read_file` numbers them; the replacement always keeps the
  file's line ending style. A failed edit quotes the closest region by similarity.
- **`EditFile` and `WriteFile` take an optional `?\Closure $check`**, run after a successful write;
  its message, if any, is appended to the result as `Check: <message>` (docs/files.md shows
  `php -l`).
- **`Harness\Tools\Files\ApplyPatch`** (`apply_patch`): applies a patch in the V4A format (add,
  delete, update with `@@` hunks, move) in one call. Hunks are located by context through
  `EditFile`'s cascade (exact, indentation-flexible, line-trimmed, whitespace-normalized), added
  lines re-indented by the matched block's delta; the whole patch is built in
  memory first, so a hunk whose context is not found, or is ambiguous, fails every file in the
  patch and changes nothing.
- **`Harness\Tools\Files\Journal`**: `WriteFile`, `EditFile` and `ApplyPatch` take an optional
  `?Journal $journal` and record a changed or deleted path's previous content (or absence) the
  first time they touch it; `Journal::undo()` restores every recorded path, in reverse order, and
  `Journal::changed()`/`clear()` round it out. The host decides the scope (a turn, a session).

### Fixed

- `Run::cancel()` while a tool asks for input: the call gets a "cancelled" error result and the
  turn ends `Cancelled`, as promised, instead of `AwaitingInput` with a stale pending call (#11).
- `Policy\Shell`: a program spelled with an unquoted glob (`r[m]`) is denied outright (#12), and
  `command`, `builtin` and `exec` are peeled like the other wrappers so a deny rule reaches the
  wrapped program (#4).
- `Provider\Anthropic\Messages`: a `ToolResult` with no content is sent as a one-line text block
  (`Error` or `(no output)`), not the empty list the API rejects (#14).

### Changed (breaking)

- **`Transport` gets `get(string $url, array $headers, Cancel $cancel): Response`**, implemented
  in `StreamTransport` (sharing its connection and request/response code with `post()`) and scripted
  in `FakeTransport`. Transport has `get()`; a hand-written `Transport` must add it. A transport never
  follows redirects: `Response::redirectTarget($url)` names the target and the caller follows it, so
  a guard can check every hop (`web_fetch` follows up to 5 and refuses a hop to a private address).

## 0.1.0 (2026-10-31)

phasync/harness is the runtime between a model and an application: the tool loop, a typed event
stream, one pause-and-resume contract for input and interruption, and two middleware seams
(`ModelMiddleware`, `ToolMiddleware`). It renders nothing and speaks no protocol in its core.
Modules over the core's interfaces add providers (OpenAI Chat Completions and Responses, Anthropic
Messages with thinking), file tools, a shell tool, MCP client support, sub-agents, a transcript,
a context-budget `Compact` middleware that folds the conversation into a sectioned checkpoint, a
`Policy` for tool approval including parsed-shell rules, fakes for testing without a model, and an
Agent Client Protocol agent for editors. Concurrency goes through `fiber-interop/api` only; the
suite runs identically under the `fiber-interop/phasync` and `fiber-interop/revolt` adapters.

## 0.0.1-beta7

Implements SPEC.md revision 7, the polish round before 0.1.0: round 6's five users called the
harness complete and found no defect, so this release changes no behaviour beyond one default, and
closes the documentation gaps the round-6 users named.

### Changed

- **`Compact`'s default `summarizerOptions` becomes `maxOutputTokens: 2048`** (from 1,024): live on
  the test server at the old default, round 6's readers wrote checkpoints of 2.2 to 3.0 KB and 7 of 22 runs
  failed on a checkpoint cut twice at the output limit.

### Docs

- README: the thinking-off idiom (a thinking model spends output tokens on reasoning first; turn it
  off through the provider's knob in `Options::$provider`, as `Compact`'s `summarizerOptions` does),
  next to where `Options::$provider` is introduced; Alt+Enter restored next to `/queue` in the
  steering section, to match the beta6 entry's wording.
- docs/agents.md: the same thinking-off idiom for a sub-agent's own `Run`, and the empty-Done case
  (finish `Stop`, so the cut-off line below does not fire; the parent sees `(the agent gave no
  text)`).
- docs/compact.md: the four facts about `State`, checkpoints and the overflow ladder from SPEC.md
  revision 7's Compact section, also stated in one sentence each on `Checkpoint` and `State`.
- `Event\TurnEnded` docblock: `error` carries the `ErrorKind` only; the exception's message is on
  `Ended::$error`, read once the turn's generator is drained.
- docs/testing.md: the event fields a test reads (`ToolEnded::$outcome` is the bare `Result`;
  `StepStarted::$n`, `StepEnded::$n`).
- docs/acp.md: one client-side exchange (`initialize`, `session/new` with `mcpServers: []`,
  `session/prompt`, a `session/request_permission` answer); a session's `mcpServers` stay
  unconnected in 0.1, noted under Not used.

## 0.0.1-beta6

Implements SPEC.md revision 6: Compact's summary becomes a checkpoint that is updated rather than
re-summarized, steering gets two delivery modes, the shell tool gets a policy over parsed argv.
Round 5's research reader looped after many compaction rounds. Live on the test server with this release (the
research tool's 2,000-token budget, 800 summarizer output tokens), 15 of 22 reader runs over three
sessions ended `Done` within 4 to 19 steps, every checkpoint listing the files read under Done; the
other 7 failed loudly on a checkpoint cut twice at the 800 output tokens, which the lead re-delegated.

### Changed (breaking)

- **`Compact` writes a sectioned checkpoint** (Goal; Progress: Done, In progress, Blocked;
  Decisions; Known; Next; Context) instead of a prose summary under four headings, and lists every
  file or resource already read under Done. The agent gets it headed "[A checkpoint of the earlier
  conversation ... What is under Done is finished: do not do it again.]" with the acknowledgement
  "Understood. I continue from the checkpoint." Round 5's reader re-read files and re-delegated
  because the summary said what was asked, not what was finished. The side call's ask, the log
  lines and the collapse messages name a checkpoint.
- **A later round updates the checkpoint**: the side call gets the current checkpoint and the
  messages since it, with the instruction to keep every fact it holds. Beta5 summarized the earlier
  summary again with the oldest half of what followed, round after round, and facts thinned out.
  One round per step now.
- **The most recent `keepRecentTokens` stay verbatim** (new last constructor parameter, default a
  quarter of the budget; over half the budget throws at first use) instead of the oldest half of
  the messages being cut. The step in flight (the last assistant message and its results) always
  stays, and the latest user message stays verbatim after the checkpoint when the cut falls after
  it: in round 5 a tight budget summarized the current question away and the agent answered an old
  one. `Compaction::$kept` counts the lifted message.
- **`Compact::state(): Compact\State` and the `state:` constructor parameter replace
  `calibration()` and `calibration:`.** `State` holds the `Calibration` and the latest checkpoints
  (at most 8), with `toArray()`/`fromArray()`, so a host that builds a Compact per request persists
  one value and never writes a checkpoint it already has (beta5 persisted only the calibration and
  re-summarized on every request over the budget). `Calibration` stays as the value inside it.
  Upgrade: `calibration: Calibration::fromArray($c)` becomes `state: State::fromArray($s)`, and
  `$compact->calibration()?->toArray()` becomes `$compact->state()->toArray()`.
- **Checkpoints are keyed by a content hash** of the messages they cover, not by the same `Message`
  objects: a conversation rebuilt from arrays finds its checkpoint.
- **`Run::interject(Message $user, Delivery $delivery = Delivery::Steer)`.** A steer now skips the
  step's tool calls that have not started, each with the error result "Skipped: the user sent a
  message"; running calls finish. Beta5 ran them all and then landed the message, so a user who
  said "stop, not that file" watched the remaining writes happen (pi skips them). A message that
  should wait instead is `Delivery::Queue` (below).
- **`Event\Interjected` carries `delivery`**: a new constructor argument, `toArray()` gives it, and
  `Events::fromArray()` requires it (a beta5 events file with an `interjected` line does not replay).
- **A call runs only against the tools the model was offered**, after middleware: a
  `ModelMiddleware` that hands `$next` fewer tools makes a call to a left-out tool the unknown-tool
  error result. Beta5 ran it from the `ToolSource` anyway, so a phase without write tools could
  still write. A resumed call (step 0) and a step whose middleware answered without calling the
  model are checked against the whole `ToolSource`, as before.
- The budget floor's message says "room above it for the checkpoint and the recent messages".

### Added

- **`Harness\Delivery`** (`Steer`, `Queue`). `Queue` waits until the turn would otherwise end
  `Done`, then starts another step with the message, one per step, in order (pi's follow-up,
  OpenCode's queue). Leftovers are in `Ended::$queued`, the steering ones first.
- **`Compact` retries a prompt the provider rejects as too long** (`ErrorKind::ContextTooLong`,
  before the first delta) at 85, 70, 50 and 35 % of the budget, each retry logged, then rethrows
  (OpenCode's overflow trigger). Any other error, or one after the first delta, is rethrown at once.
- **Each checkpoint is logged in full at `debug`**, after the `info` line with its sizes.
- **`Compact` bounds the checkpoint**: the ask says to stay under a quarter of the budget and three
  quarters of `summarizerOptions`' output tokens, in words, and a checkpoint cut at the output limit
  is asked for again in 60 % of the words. Live on the test server, 4 of 10 reader checkpoints were cut at
  the research tool's 800 output tokens before this.
- **`Compact\Checkpoint`**, a checkpoint as a value (`hash`, `covered`, `kept`, `lifted`, `text`,
  `previousBytes`, `finish`; `removed()`, `toArray()`, `fromArray()`).
- **`Policy\Shell` and `Rule::shell(list<Shell>, string $tool = 'shell', Verdict $otherwise =
  Verdict::Ask)`**: a rule for the shell tool that matches parsed argv (Crush's rule). A small
  POSIX-shell tokenizer splits the command into simple commands over `&&`, `||`, `;`, `|`, `&`;
  `env`, `nice`, `timeout`, `sudo`, `nohup` and `xargs` are peeled; a line is denied when any
  command matches `Shell::deny()`, allowed when every one matches `Shell::allow($program,
  $subcommands, flags:)`, and asked about when it cannot be parsed (`$`, substitution, backticks,
  here-documents, subshells, braces), sets the environment or redirects output to a file. Tested
  with `env rm`, `git status; rm -rf`, `ls | sh`, `$(rm)` and more.
- **`Harness\Acp`** (new module): `Agent`, an Agent Client Protocol v1 agent over stdio as an
  adapter over the event stream: `initialize`, `session/new|load|prompt|cancel`; text, reasoning and
  tool calls become `session/update`s (`kind` from `Hints`, `diff` content for `edit_file` and
  `write_file`, `shell` output as text content); a `Policy` `Ask`, a sub-agent's included, becomes
  `session/request_permission` with `allow_once|allow_always|reject_once|reject_always`, the answer
  resuming the run; `stopReason` from `Reason`. `examples/acp.php` is the agent executable; a PHP
  test client over pipes drives it in the tests. The client's `fs/*` and `terminal/*` are not used.
- **`Rule::decide(ToolCall, Call): ?Rule`**: the rule that decides a call, for `shell()` one whose
  verdict and text are that command's; `Policy` uses it.

### Docs

- README: a tool's or middleware's `\LogicException` is rethrown inside the consumer's `foreach`:
  wrap the loop in `try`; `cancel()`/`interrupt()` act on the `Run` of the process that runs the
  turn (keep it in the worker, or close the client's stream); the porting checklist for a
  hand-written `Model` (`Transport::post()` with `$prompt->cancel`, `Prompt::$continue`, the shallow
  `Options::$provider` merge); the two delivery modes; shaping the tool list in a `ModelMiddleware`,
  with a test.
- docs/compact.md rewritten around the checkpoint; docs/policy.md has a Shell commands section;
  docs/agents.md says checkpoints; docs/acp.md is new.
- `examples/chat.php`: a line typed while a reply streams steers it, one ended with Alt+Enter or
  starting with `/queue` is queued. `examples/web.php` persists `Compact\State`.

## 0.0.1-beta5

Implements SPEC.md revision 5: interrupt, edit and resume a reply; steer a running turn; a known
context window; the round-4 users' Compact findings; the last documentation gaps.

### Changed (breaking)

- **A `\LogicException` from a tool fails the turn** (`ErrorKind::ToolError`, new) and is rethrown
  from the iteration after `TurnEnded`, as one from the model or a middleware is. Before, it became
  an error result the model retried: in round 4 a lead agent re-delegated forever because a child's
  `Compact` budget-floor exception came back as a tool error. Through `Agents\AgentTool` a child's
  reaches the parent the same way. A tool that throws `\InvalidArgumentException` (a
  `\LogicException`) for bad model input now fails the turn: throw `Retry` for that.
- **`Reason::Interrupted`** and **`ErrorKind::ToolError`** are new cases: a `match` over `Reason`
  or `ErrorKind` without a default arm throws `UnhandledMatchError` when they occur.
- **`Compact` takes a summary cut at the output token limit (`FinishReason::Length`) as a
  collapse**: asked once more, and the step fails the second time. Beta4 accepted a cut summary.
- **`Compact`'s side call includes the run's system text** as "the agent's instructions", before
  the kept first messages, so summaries keep the task's terms. The ask starts differently.
- **`Compact` drops a round that would not shrink the prompt**, with the "nothing more to
  summarize" warning, instead of replacing two small messages by a larger summary, and runs a later
  round only for a part larger than the summary it dropped, so no side call is spent per step.
- **`Compact` stops calibrating on a report it takes as an upstream cut** (below).

### Added

- **`Run::interrupt()`**: as `cancel()`, but the turn ends `Reason::Interrupted` and
  **`Ended::$partial`** holds the assistant message as delivered so far (text as streamed,
  reasoning, complete tool calls); it is not in `messages()` or `appended`, and `finish` is null.
- **`Resume(Message $edited)`**, a turn's third kind of input: the edit is appended and a step runs
  with **`Prompt::$continue`** true (new last constructor parameter, default false; a middleware
  that rewrites a prompt passes it on). The accumulator is seeded with the edit, so the step's
  `MessageEnded` carries the whole message, and **`Event\MessageResumed(id)`** (`message_resumed`)
  replaces `MessageStarted`. Valid only after a user message with nothing pending; otherwise
  `turn()` throws. `Accumulator::__construct(?Message $seed = null)` and `Accumulator::partial()`
  serve it.
- Providers on `Prompt::$continue`: `ChatCompletions` sends the edit last (llama.cpp and exl3
  continue it; vLLM needs `continue_final_message` and `add_generation_prompt` in
  `provider['openai']`); `Anthropic\Messages` sends a prefill (reasoning left out, trailing
  whitespace cut) and throws `UnsupportedOptionException` with thinking on; `Responses` throws.
  The exceptions name the fallback a host may choose. Live on llama.cpp (qwen3.8-flash-next): an
  interrupt after "Number one.", replaced by four sentences, continued with " Number five." and only
  the continuation streamed.
- **`Run::interject(Message $user)`** queues a user message for the running turn; it lands after
  the step's tool results, before the next model call, with **`Event\Interjected(message)`**
  (`interjected`). A reply without tool calls does not end the turn while one waits.
  **`Ended::$queued`** lists those that could not land. With no turn running it throws.
- **`Options::$contextWindow`** (`?int`, tokens; new last constructor parameter), validated and
  passed on; the core does nothing else with it.
- **`Compact\Calibration`**, the counter's calibration as a JSON value (low and high points,
  `ratio`, `offset`, `fitted`; `toArray()`, `fromArray()`, `jsonSerialize()`).
  **`Compact::calibration(): ?Calibration`** and a constructor parameter `?Calibration
  $calibration = null` (last), so a host that builds a Compact per request starts calibrated.
- **`Compact`'s `budgetTokens` may be null** (now the default): the budget is the step's
  `Options::$contextWindow` less 20 %, taken at first use; with neither the step throws a
  `\LogicException`, and a budget over the window throws too.
- **`Compact` detects a prompt cut upstream**: once the calibration is a line, a reported input
  count under 70 % of the calibrated count logs a warning naming both and sets
  **`Compaction::$truncatedUpstream`**; the report is left out of the calibration. Only with a line:
  an offset from one long prompt flagged a later short one as cut in the tests.
- **`Compaction::$finish`**, how the accepted summary's side call ended.
- `Ended::toArray()` gives `partial` and `queued`; `TestPrompt::make()` takes `continue`.

### Docs

- README: interrupt, edit, resume with the providers and the limits (no resuming inside a
  reasoning block; no half tool call); steering a running turn; the context window, and that a
  server which evicts silently must be configured on the server (Ollama: `OLLAMA_CONTEXT_LENGTH` or
  `num_ctx` in the Modelfile); `Call`'s pause members; only phasync and Revolt are tested; a
  `tool_decided` line in the JSON sample and that the inline idiom emits none; a web host's answers
  nest by call id; the retry after `Failed` beside the resume after `AwaitingInput`.
- docs/compact.md: the thinking-off summarizer options as the first line, with why; the budget from
  the window; exactly what the summarizer sees; Length collapses; dropped rounds; `Calibration` and
  persisting it; upstream truncation; the upgrader's note on the floor; the summary cache as
  implemented (keyed by the same `Message` objects; a per-request Compact starts without summaries).
- docs/openai.md and docs/anthropic.md: continuation per provider. SPEC.md's Policy wording now
  says, as docs/policy.md does, that the answering middleware is listed before `Policy`.
- `examples/chat.php`: Ctrl-C interrupts, the partial's last line may be retyped, the reply
  continues; a line typed while it streams is an interjection. `examples/web.php` builds a Compact
  per request with the budget from `contextWindow` and persists its `Calibration` in the state file.

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
