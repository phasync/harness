# phasync/harness — specification

Revision 2 (2026-10-30). Revision 1 was implemented as 0.0.1-beta1 and tried by five users; this
revision confirms the API as built, adds what they required, and names round 2's modules. The
builder implements this document; sections marked *module* are part of this package but live in
their own namespace and are never imported by the core.

Changes since revision 1 are marked **[r2]**.

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

Namespace `Harness\`. PHP 8.2+. Dependencies: `fiber-interop/api ^0.3`, `psr/log`. Nothing else.
**[r2]** `fiber-interop/api` now allows `fiber-interop/scope ^0.5` on its main branch; until
that is tagged, require `fiber-interop/api` at `dev-main` so that `phasync/phasync` resolves to
its latest 2.0.x in dev.

### Content parts and messages

A closed union, identical on the way in and out. Every part is a final, readonly value object
with `toArray(): array` and `static fromArray(array): static`; `Parts::fromArray()` decodes by
type. The transcript, the event stream and resume reuse that one serialization.

```
Text(string text)
Reasoning(string text, array raw)        opaque and model-bound: raw['model'] decides replay
                                         (verbatim to the same model, dropped for another)
ToolCall(string id, string name, array input)
ToolResult(string callId, Part[] content, bool error)    content: Text, Media, Reference
Media(string mime, ?string bytes, ?string uri)
Reference(string uri, ?string title)
Message(Role $role, Part[] $parts)       Role::User | Role::Assistant; checks which parts a role may hold
```

### Model

```php
interface Model {
    /** @return iterable<Delta> */
    public function stream(Prompt $prompt): iterable;
}
Prompt(?string system, Message[] messages, ToolSpec[] tools, Options options, Cancel cancel)   [r2] cancel
Delta: TextDelta(text) | ReasoningDelta(text, array raw = []) | ToolCallStart(id, name) | ToolInputDelta(id, json)
     | ToolCallEnd(id) | Usage(input, output, cacheRead, cacheWrite) | Finish(FinishReason)
FinishReason: Stop | ToolCalls | Length | Refusal
```

One method. `Accumulator` folds deltas into the assistant message in the order parts began;
malformed tool input is reported, not guessed; a provider bug is a `LogicException`. `Options`
holds `maxOutputTokens`, `temperature`, `toolChoice: ToolChoice::Auto|None|Required|Tool` with
`tool` for the forced name, `toolRetries` (default 3) and `provider: array<string, array>` keyed
by provider name, merged into the request last. A provider throws `UnsupportedOptionException`
for an option it cannot honour; nothing is dropped silently. **[r2]** Provider keys are the
module's lowercase name (`openai`, `anthropic`); a third-party provider documents its own key.

**[r2]** `Cancel` is a tiny readonly signal (`isCancelled(): bool`) created by the Run; the model
and the transport observe it (see Cancellation).

### Transport (streaming HTTP)

Namespace `Harness\Http`. Providers do not depend on an HTTP client:

```php
interface Transport {
    public function post(string $url, array $headers, string $body, Cancel $cancel): Response;   [r2] cancel
}
Response: status, headers, body(): iterable<string>    read once
```

`StreamTransport`: HTTP/1.1 over `stream_socket_client()` (`tcp://`, `tls://`), chunked,
content-length and close-delimited bodies, 1xx skipped, connect and idle timeouts, warnings
turned into `TransportException` without `@`, no redirects, proxies or compression. It waits
only with `fiber\readable()` / `fiber\writable()`. **[r2]** While waiting for the next chunk it
uses a short readable timeout (250 ms) and checks `$cancel`, so a cancelled turn ends within that
time instead of at the next delta. `Sse::parse()` turns a chunk iterable into `event => data`
pairs and is SSE-specific: a backend streaming NDJSON parses `body()` itself (documented).

### Tool, ToolSource, Call, Result

```php
interface Tool {
    public function spec(): ToolSpec;
    public function call(array $input, Call $call): Result;
}
ToolSpec(string name, string description, array schema, Hints hints = new Hints())
Hints(bool readOnly = false, bool destructive = false, bool idempotent = false, bool openWorld = false)
Result(Part[] content, mixed value = null, array meta = [], bool error = false)   Result::text('...')
interface ToolSource { /** @return iterable<Tool> */ public function tools(): iterable; }
```

**[r2]** `ToolSpec` normalizes JSON-schema object nodes that PHP would encode as `[]`
(`properties`, nested `properties`, `required: []` is fine) so every backend receives `{}`; a
schema whose `type` is not `object` throws. **[r2]** `Result::fromArray()` exists, mirroring
`toArray()`.

`Hints` are MCP's tool annotations, read by policy middleware and by the scheduler. `Result`
keeps apart what the model sees (`content`), what the application gets (`value`) and what is
only logged (`meta`). The loop reads `ToolSource::tools()` at every step and keeps the order it
gets. `ClosureTool(spec, Closure)` and `Tools(Tool ...$tools)` are in the core.

`Call` is the explicit context, always the second parameter: `id`, `run`, `context` (the host's
own object), `spec` (the called tool's `ToolSpec`, so middleware reads `Hints`), `progress(value)`,
`isCancelled()`, `emit(Event)` (forwarding, for nested runs), and the pause protocol:
`needsInput(Approval|Question[] needs, mixed state = null): Result`, `pending()`, `answer(Answer)`
(the answer-at-once idiom), `decision()`, `answers()`, `state()`.

Failure from a tool: `throw new Retry('why')`, an unknown tool name and malformed input all become
error results the model may correct, counted against `toolRetries`; past the budget the turn
fails `ToolRetriesExceeded`. Any other `Throwable` becomes an error `ToolResult` with the message,
a `ToolEnded` event carrying the throwable, and a log entry.

### Run and Turn

```php
$run = new Run(Model $model, ToolSource $tools, ?string $system = null, mixed $context = null,
               array $middleware = [], array $stop = [], ?LoggerInterface $log = null,
               Options $options = new Options(), array $messages = [], array $pending = []);
foreach ($run->turn(Message|Answer[] $input, ?Call $parent = null) as $event) { ... }   // iterable<Event>
$run->ended();      // Ended(Reason, Message[] appended, Pending[] pending, Usage usage,
                    //       ?Throwable error, ?ErrorKind errorKind, ?FinishReason finish)   [r2] finish
$run->messages();  $run->fork();  $run->cancel();  $run->isCancelled();  $run->isRunning();   [r2] isRunning
```

A step: build the `Prompt`; stream the model through the model middleware; append the assistant
message; run its tool calls through the tool middleware; append their results as one user
message. The turn ends `Done` when a step produced no tool calls, `Stopped` when a stop predicate
returns true (checked after steps that called tools; `AwaitingInput` wins over `Stopped`),
`AwaitingInput` when a tool asked for input, `Cancelled`, or `Failed` with `ErrorKind`
(`ModelError | ContextTooLong | RateLimited | TransportError | ToolRetriesExceeded | MiddlewareError`).
A `LogicException` from the model or a middleware ends the turn `Failed` and is rethrown from the
iteration after `TurnEnded`.

**[r2]** `Ended::$finish` is the `FinishReason` of the turn's last assistant message: `Done` only
means "no tool calls"; a reply cut by the token limit or refused is `Done` with `finish` `Length`
or `Refusal`, and the docs say so where `Reason` is explained. `Cancelled` discards the partial
assistant message of the step in progress: `Ended::$appended` never holds it (documented).

Stop predicates are closures `fn (Step $step): bool`, OR'd: `Stop::maxSteps(n)`,
`Stop::toolCalled(name)`, `Stop::tokens(n)`. Default `[Stop::maxSteps(20)]`.

Tool calls of one step run concurrently with `fiber\go()`: the read-only ones first, together;
then the others one at a time, in the model's order; results go back in the model's order as one
user message; calls that never started get a "cancelled before it ran" result. A turn runs inside
`fiber\run()` and inside a new `\Scope` seeded with its creator's superglobals.

### Cancellation

Cooperative, since fiber-interop 1.0 has none: `Run::cancel()` sets the `Cancel` signal; the
transport stops within its poll interval **[r2]**, the model stream is abandoned, running tools
see `Call::isCancelled()`, `Shell` kills its process group, a nested run is cancelled with its
parent. `cancel()` with no turn running is a no-op.

### Pause and resume

A turn that ends `AwaitingInput` lists `Pending(ToolCall call, Approval|Question[] needs, mixed
state)`. The next `turn()` takes `Answer(callId, Decision::allow() | Decision::deny(reason) | array
answers)` instead of a user message; it runs as step 0 and emits `ToolDecided`: approved calls
run, denied ones go back to the model as error results, a tool that asked questions is called
again with `$call->answers()` and `$call->state()`.

The host persists `Ended::$pending` (`Pending::toArray()`) next to the messages and restores with
`new Run(..., messages: ..., pending: ...)` in any process; the pending list is validated against
the unanswered tool calls in the messages. **[r2]** The JSON shapes of a `Pending` with an
`Approval` and with `Question[]` are shown in the README, and the discrimination is explicit:
`needs` is `{"type":"approval",...}` or `{"type":"questions","questions":[...]}`.

A host that can answer at once (a CLI) gates in one `ToolMiddleware`: call `$next()` or return an
error `Result`; it reaches for `needsInput` only when the turn must end (documented as the rule,
with `files.php` showing the two-middleware form once and saying why).

**[r2] Nested pauses.** When a child run (`AgentTool`) ends `AwaitingInput`, the parent's call ends
with `needsInput()` carrying the child's pendings, and `state` holds the child's messages and
pendings (serialized). On resume the parent's `AgentTool` restores the child, routes the answers
to it, and continues; a web host sees one `Pending` list whose `needs` nest no deeper than the
questions themselves. A child's approval therefore reaches the browser like any other.

### Events

Codex's shape: `started → delta* → ended` per item, each with an `id`, a `turn` and a `parent`.
Every event is a final readonly class on an abstract readonly `Event`, with `toArray()` and
`JsonSerializable`; `type` is the class name in snake case. **[r2]** `Events::fromArray()`
rebuilds the typed event, mirroring `Parts::fromArray()`.

```
TurnStarted(turn)                       TurnEnded(turn, Reason, Usage, ?ErrorKind, ?FinishReason)  [r2] finish
StepStarted(turn, n)                    StepEnded(turn, n)
MessageStarted(id)                      TextDelta(id, text)        ReasoningDelta(id, text)
ToolCallStarted(id, name)               ToolInputDelta(id, json)   ToolCallReady(id, name, input)
ToolDecided(id, Decision)               ToolProgress(id, value)    ToolEnded(id, Result|Throwable)
MessageEnded(id, Message, Usage, FinishReason)                     InputRequested(Pending[])
```

**[r2] Delivery is synchronous.** `emit()` returns only after the consumer's `foreach` has taken
the event. So anything that happens after an event was emitted (a middleware printing a prompt
in the tool's fiber) is seen by the host after it handled that event, and the stream is strictly
ordered with respect to side effects. The cost is one fiber switch per event. The consumer sets
the pace: a slow SSE write slows the model read, which is the intended backpressure. Concurrent
tool calls emit from their own fibers, each waiting its turn. A consumer that stops iterating
cancels the turn.

**[r2]** The README's Events section shows the wire format: a table `class → type` and one JSON
line per event family (`text_delta`, `tool_call_ready`, `tool_ended`, `input_requested`,
`turn_ended`).

### Middleware

The only interception mechanism, with two seams:

```php
interface ModelMiddleware { /** @return iterable<Delta> */ public function stream(Prompt $prompt, Closure $next): iterable; }
interface ToolMiddleware  { public function call(ToolCall $call, Call $context, Closure $next): Result; }
```

Order: array order, outermost first; one class may implement both. The core ships none but
documents the idioms: policy from `Hints` (deny with an error `Result`, or `needsInput(Approval)`),
truncation of a `Result` with an instructive tail, compaction rewriting the `Prompt`, retries
wrapping `$next`, tracing wrapping both. **[r2]** The retry idiom states its hazard: a model
retry is only safe before the first delta was yielded onward (the accumulator has already folded
it); past that, rethrow. The README shows the safe form.

## Modules

Each module is a namespace `Harness\<Module>\` with a page in docs/ and tests; the core never
imports a module.

### Provider\OpenAI (module) — as built in beta1

`ChatCompletions` over `Transport`: `stream: true` with usage, `delta.content`,
`delta.reasoning_content` and `delta.reasoning`, tool-call deltas by index, `role: tool` results,
images and audio, `provider['openai']` merged last, HTTP and in-stream errors mapped to error
kinds. Reasoning replayed only to the same model. Live tests skipped without
`HARNESS_OPENAI_URL`. Endpoints for development: local `http://127.0.0.1:8012/v1`
(`qwen3.8-27b-exl3-sivert`, thinking on, 4 parallel at most), black `http://black:8080/v1`
(`qwen3.8-flash-next`, stronger, 3 slots). Shared servers: no load tests.

### Provider\Anthropic (module) — as built in beta1

`Messages` over `Transport`; all SSE events; `thinking`/`redacted_thinking` kept verbatim in
`raw['blocks']` and replayed to the same model; consecutive user turns merged; `provider['anthropic']`
(cache control); errors including 529 mapped. A forced `tool_choice` a model rejects surfaces as
the API's error (`ModelError`); no capability table. Skipped live without `ANTHROPIC_API_KEY`.

### Tools\Files, Tools\Shell (modules) — as built in beta1

**[r2]** docs/files.md documents `Root`'s public API (`path`, containment rules); docs/shell.md
says that `Shell`'s hints (`destructive`, `openWorld`, not `readOnly`) are what a policy middleware
keys on, with the one-line policy shown.

### Agents\AgentTool (module)

`AgentTool(Run $child, string $name, string $description, bool $fresh = true)` **[r2]** `fresh`:
each call runs `$child->fork()` when true, the same child with its growing context when false.
The child's events are forwarded with `parent` set to the call id; the child is cancelled with
the parent; hints derive from the child's tools. The child's final text is the result;
**[r2]** when the child ended with `finish` `Length` or `Refusal` the result says so in a
trailing line and in `meta['finish']`. **[r2]** Nested pauses propagate (see Pause and resume).
**[r2]** docs/agents.md documents rendering concurrent siblings: buffer text per message id and
flush per line or at `MessageEnded`; never interleave two open messages' characters; and
`examples/subagent.php` renders that way.

### Testing (module)

`FakeModel`, `FakeTransport`, and **[r2]** `TestCall::make(?ToolSpec $spec = null, mixed $context
= null, string $id = 'test'): Call` to unit-test tools and tool middleware without a live run.
docs/testing.md documents it (no pointer into the harness's own tests/).

### Transcript (module, **[r2] this round**)

```php
interface Transcript { public function load(): array /* Message[] */; public function append(array $messages): void; public function clear(): void; }
JsonlTranscript(string $path)
JsonlEvents(string $path)     a sink: write(Event) appends one JSON line; replay(): iterable<Event> via Events::fromArray()
```

Writes handle partial `fwrite()` results (loop until the whole line is written; a non-blocking
stream waits with `fiber\writable()`), and the sink is tested with two concurrently streaming
`AgentTool` children over a `FakeModel` that yields with `fiber\sleep()` between deltas, several
thousand events, every line parsing. **[r2]** Round 1's research user saw 4 of 6482 lines
corrupted (two events concatenated, the first cut mid-string) with a sink written with plain
`fwrite()`; the builder reproduces that first with that user's tool
(`/home/frode/dev/phasync/harness-users/research`, `tmp/run1.jsonl`) and determines whether the
cause is a partial write or something in the harness's event delivery, and reports which.

### Mcp (module, **[r2] this round**)

An MCP server as a `ToolSource` under the 2026-07-28 spec: stateless requests carrying
`_meta['io.modelcontextprotocol/protocolVersion']` and `clientCapabilities`, `server/discover`
on first use, `tools/list` cached by its `ttlMs`, `tools/call`; `annotations` → `Hints`; content
`text|image|audio|resource|resource_link` → parts; `isError` → error result;
`resultType: "input_required"` → `$call->needsInput()` with `requestState` as the state, the retry
carrying `inputResponses`; a result without `resultType` is `complete`. Transports: stdio (a child
process over `proc_open`, newline-delimited JSON-RPC, read with `fiber\readable()`) and Streamable
HTTP over `Transport` (POST per message with `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`
headers; `application/json` or `text/event-stream` responses). `subscriptions/listen` is a later
round. A tiny stdio MCP server in PHP lives in `tests/` for the module's tests; a live test
against a public reference server is skipped without `HARNESS_MCP_COMMAND`.

## Examples and CLI

`examples/`: `chat.php`, `files.php`, `shell.php`, `subagent.php` as in beta1, plus **[r2]**
`mcp.php` (a chat with one MCP server's tools) and `web.php` (one turn per invocation, state in a
JSON file, events as JSON lines: the request/response host idiom). Each reads
`HARNESS_OPENAI_URL` and `HARNESS_MODEL`.

## Rules the code follows

- Fail hard and loud: misuse throws an instructive exception at the point of misuse; no guards
  that paper over a broken invariant; no `@`.
- Code as if type safe: `declare(strict_types=1)`, readonly value objects, enums for closed sets,
  no arrays where a value object fits.
- No convenience layers: one way to do each thing; document the idiom instead of adding a helper.
- Identical behaviour on every fiber-interop adapter; a difference is a bug, reported, not
  worked around.
- Docs concise: README (install, the loop, events with wire format, pause and resume with JSON
  shapes, middleware idioms with their hazards, modules), a page per module, CHANGELOG with a
  section per tag. Docblocks on every public symbol, one paragraph of fact.
- Tests with Pest under both adapters; live tests tagged and skipped without the endpoint.
