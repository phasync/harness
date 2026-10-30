# phasync/harness — specification

Revision 1 (2026-10-30). The builder implements this document; the users of each round report
what it lacks, and the next revision folds that in. Sections marked *module* are part of this
package but live in their own namespace and are never imported by the core.

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

### Content parts and messages

A closed union, identical on the way in and out. Every part is a final, readonly value object
with `toArray(): array` and `static fromArray(array): static`; the transcript, the event stream
and resume reuse that one serialization.

```
Text(string text)
Reasoning(string text, array raw)        opaque and model-bound: replayed verbatim to the same
                                         model, dropped for another; never re-derived
ToolCall(string id, string name, array input)
ToolResult(string callId, Part[] content, bool error)
Media(string mime, ?string bytes, ?string uri)
Reference(string uri, ?string title)     a source, a resource link
Message(Role $role, Part[] $parts)       Role::User | Role::Assistant
```

Tool results travel in a user message (Anthropic's shape); a provider that needs another shape
maps it. A message may hold any mix of parts.

### Model

```php
interface Model {
    /** @return iterable<Delta> */
    public function stream(Prompt $prompt): iterable;
}
Prompt(?string system, Message[] messages, ToolSpec[] tools, Options options)
Delta: TextDelta(text) | ReasoningDelta(text) | ToolCallStart(id, name) | ToolInputDelta(id, json)
     | ToolCallEnd(id) | Usage(input, output, cacheRead, cacheWrite) | Finish(FinishReason)
FinishReason: Stop | ToolCalls | Length | Refusal
```

One method. The core folds deltas into the assistant `Message` with an `Accumulator`; a provider
that cannot stream yields the whole reply as deltas. `Options` holds the portable knobs
(`maxOutputTokens`, `temperature`, `toolChoice: Auto|None|Required|Tool(name)`) and
`provider: array<string, array>` keyed by provider name for the rest. A provider throws
`UnsupportedOptionException` for an option it cannot honour; nothing is dropped silently.

### Transport (streaming HTTP)

Providers do not depend on an HTTP client. They use:

```php
interface Transport {
    /** @return Response  status, headers, and body(): iterable<string> of chunks as they arrive */
    public function post(string $url, array $headers, string $body): Response;
}
```

The core ships `StreamTransport`: HTTP/1.1 over `stream_socket_client()` (`tcp://`, `tls://`),
chunked transfer decoding, waiting only with `fiber\readable()` / `fiber\writable()`. No
redirects, no proxies, no compression: a model endpoint needs none. A PSR-18 transport is a
module. `Sse` turns a chunk iterable into `(event, data)` pairs; providers share it.

### Tool, ToolSource, Call, Result

```php
interface Tool {
    public function spec(): ToolSpec;
    public function call(array $input, Call $call): Result;
}
ToolSpec(string name, string description, array schema, Hints hints)
Hints(bool readOnly = false, bool destructive = false, bool idempotent = false, bool openWorld = false)
Result(Part[] content, mixed value = null, array meta = [])     Result::text('...') for the common case
interface ToolSource { /** @return iterable<Tool> */ public function tools(): iterable; }
```

`Hints` are MCP's tool annotations, read by policy middleware and by the scheduler. `Result`
keeps apart what the model sees (`content`), what the application gets (`value`) and what is
only logged (`meta`). The loop reads `ToolSource::tools()` at every step and keeps the order it
gets: tool lists change, and providers cache the prompt prefix. `ClosureTool(spec, Closure)` and
`Tools(Tool ...$tools)` (a static `ToolSource`) are in the core.

`Call` is the explicit context, always the second parameter: `id`, `run`, `context` (the host's
own object, passed to `Run`), `progress(string|array)`, `isCancelled()`, and
`needsInput(Question[] questions, mixed state): Result`, which ends the turn awaiting the host
(see Pause and resume).

Failure from a tool has two forms. `throw new Retry('why')` becomes an error `ToolResult` the
model may correct, counted against `Options::$toolRetries` (default 3) after which the turn
fails. Any other `Throwable` becomes an error `ToolResult` with the message, is emitted as
`ToolEnded` with the throwable, and is logged; it never becomes a silent string.

### Run and Turn

```php
$run = new Run(Model $model, ToolSource $tools, ?string $system = null, mixed $context = null,
               array $middleware = [], array $stop = [], ?LoggerInterface $log = null);
foreach ($run->turn(Message|Answer[] $input) as $event) { ... }     // iterable<Event>
$ended = $run->ended();   // Ended(Reason reason, Message[] appended, Pending[] pending, Usage usage, ?Throwable error)
```

A step: build the `Prompt` from the system text, the messages so far and the current tools;
stream the model through the model middleware; append the assistant message; run its tool calls
through the tool middleware; append their results as one user message. The turn ends when a step
produced no tool calls (`Reason::Done`), when a stop predicate returns true (`Reason::Stopped`),
when a tool asked for input (`Reason::AwaitingInput`), when `$run->cancel()` was called
(`Reason::Cancelled`), or on failure (`Reason::Failed`, with `ErrorKind`:
`ModelError | ContextTooLong | RateLimited | TransportError | ToolRetriesExceeded | MiddlewareError`).

Stop predicates are closures `fn (Step $step): bool`, OR'd; the core provides `Stop::maxSteps(n)`,
`Stop::toolCalled(name)`, `Stop::tokens(n)`. Default: `[Stop::maxSteps(20)]`.

Tool calls of one step run concurrently with `fiber\go()`: the read-only ones first, together;
then the others one at a time, in the model's order. A turn runs inside `fiber\run()` and inside
a new `\Scope` (from `fiber-interop/scope`, as the api treats it), so it ends with every
coroutine it started finished.

`Run` keeps the messages of the conversation in memory for its lifetime; `Ended::$appended` is
what the turn added, so a host that persists its own history needs nothing more. `Run::messages()`
returns them all.

### Pause and resume

A turn that ends `AwaitingInput` lists `Pending(ToolCall call, Approval|Question[] needs, mixed
state)`. The next `turn()` takes `Answer(callId, Decision::Allow|Deny(reason) | array answers)`
instead of a user message: approved calls run, denied ones go back to the model as error
results, a tool that asked questions is called again with `$call->answers()` and its state. The
pending state is plain parts, so it survives a process boundary: a web request ends the turn,
the browser answers, another process resumes from the same messages plus the answers.

A host that wants to answer at once (a CLI reading the terminal) does it in middleware inside the
tool's fiber, with `fiber\readable(STDIN)`; the core does not know the difference.

### Events

Codex's shape: `started → delta* → ended` per item, each with an `id`, a `turn` and a `parent`
(the tool call id of an enclosing run, for nested agents). Every event is a final readonly
object with `toArray()`, and `JsonSerializable`, so an SSE route is one `json_encode()` per event.

```
TurnStarted(turn)                       TurnEnded(turn, Reason, Usage, ?ErrorKind)
StepStarted(turn, n)                    StepEnded(turn, n)
MessageStarted(id)                      TextDelta(id, text)        ReasoningDelta(id, text)
ToolCallStarted(id, name)               ToolInputDelta(id, json)   ToolCallReady(id, name, input)
ToolDecided(id, Decision)               ToolProgress(id, value)    ToolEnded(id, Result|Throwable)
MessageEnded(id, Message, Usage)        InputRequested(Pending[])
```

The loop runs in a coroutine and pushes events to a queue; the consumer's `foreach` awaits the
queue through a then-able (an object with `then(callable, callable)`, which every fiber-interop
adapter can `await`). Concurrent tool calls emit from their own fibers in the order they happen.
A consumer that stops iterating cancels the run.

### Middleware

The only interception mechanism, with two seams:

```php
interface ModelMiddleware { /** @return iterable<Delta> */ public function stream(Prompt $prompt, Closure $next): iterable; }
interface ToolMiddleware  { public function call(ToolCall $call, Call $context, Closure $next): Result; }
```

A class may implement both. Order: the array order, outermost first. The core ships none but
documents the idioms: a policy returns `Result` with `error: true` (deny) or `$context->needsInput(Approval)`;
truncation rewrites the `Result`; compaction rewrites the `Prompt`; retries wrap `$next`; tracing
wraps both. Observing without altering is what events are for.

## Modules

Each module is a namespace `Harness\<Module>\` with its own README section and tests; the core
never imports a module. A module may depend on another module and on third-party packages
declared in `suggest` (loaded by the host) or, when small and stable, in `require`.

### Provider\OpenAI (module, required in round 1)

OpenAI Chat Completions over `Transport`, the shape every local server speaks (llama.cpp,
vLLM, exl3, Ollama). Streams with `stream: true` and `stream_options: {include_usage: true}`;
maps `delta.content`, `delta.reasoning_content` (local servers) and `delta.reasoning`, tool-call
deltas by index with ids, `finish_reason`. Tool results map to `role: tool` messages. Reasoning
parts are replayed as `reasoning_content` when present. Config: base URL, model, optional API key
and extra headers. A `Responses` API variant is a later round.

Test endpoints available during development:
- local: `http://127.0.0.1:8012/v1`, model `qwen3.8-27b-exl3-sivert` (thinking on by default,
  emits `reasoning_content`, 4 parallel requests at most);
- black: `http://black:8080/v1`, model `qwen3.8-flash-next` (llama.cpp, 3 parallel slots, large
  context, the stronger one).
Neither needs a key. Tests must not depend on them: unit tests use a `FakeModel` and a
`FakeTransport`; the live tests are skipped without `HARNESS_OPENAI_URL`.

### Provider\Anthropic (module, round 1 if time allows)

Messages API over `Transport`: SSE events `message_start`, `content_block_*`, `message_delta`,
`message_stop`, `ping`, `error`; blocks `text`, `tool_use`, `tool_result`, `thinking`,
`redacted_thinking`, `image`, `document`; `cache_control` via `Options::$provider['anthropic']`.
Skipped live without `ANTHROPIC_API_KEY`.

### Tools\Files (module, round 1)

`ReadFile`, `WriteFile`, `EditFile` (exact string replace, unique match required), `ListDir`,
`Glob`, `Grep`, rooted at a directory the host passes; a path outside it is an error result.
Hints set correctly (`readOnly` for read, list, glob, grep). Output truncation with an
instructive tail ("N more lines; pass offset=...") rather than a silent cut.

### Tools\Shell (module, round 1)

`Shell(command, timeout)` through `proc_open`, reading with `fiber\readable()`, with a working
directory and an allow/deny policy left to middleware. `destructive: true`, `openWorld: true`.

### Mcp (module, round 2 unless trivial)

An MCP server as a `ToolSource` under the 2026-07-28 spec: stateless requests with `_meta`
protocol version and client capabilities, `server/discover`, `tools/list` cached by `ttlMs`,
`tools/call`; `annotations` → `Hints`; content `text|image|audio|resource|resource_link` → parts;
`isError` → error result; `resultType: "input_required"` → `$call->needsInput()` with
`requestState` as the state, the retry carrying `inputResponses`. Transports: stdio (child
process over `proc_open` + `fiber\readable()`), Streamable HTTP over `Transport`.

### Agents (module, round 1)

`AgentTool(Run $child, string $name, string $description)`: a `Tool` whose `call()` runs a nested
turn with the input as the user message and returns the final text; the child's events are
forwarded to the parent's stream with `parent` set. Fresh context each call unless the host
passes messages.

### Transcript (module, round 2)

`interface Transcript { load(): Message[]; append(Message[]); clear(); }` with `JsonlTranscript`,
and a `JsonlEvents` sink that writes every event of a turn as one line.

## Examples and CLI

`examples/` holds small, complete CLI programs, each one file, each runnable with the local
endpoint via `HARNESS_OPENAI_URL` and `HARNESS_MODEL`: `chat.php` (streamed conversation, Ctrl-C
clean), `files.php` (an agent with the file tools over a directory, approvals on the terminal
for writes), `shell.php`, `subagent.php`. The round's users build their own tools on top of the
same API; the examples are the reference, not a framework.

## Rules the code follows

- Fail hard and loud: misuse throws an instructive exception at the point of misuse; no guards
  that paper over a broken invariant; no `@`.
- Code as if type safe: declared types everywhere, `declare(strict_types=1)`, readonly value
  objects, enums for closed sets, no arrays where a value object fits.
- No convenience layers: one way to do each thing; document the idiom instead of adding a helper.
- Identical behaviour on every fiber-interop adapter; a difference is a bug in the harness or an
  adapter, reported, not worked around.
- Docs concise: README (install, the loop, events, middleware, modules), a page per module, a
  CHANGELOG with a section per tag. Docblocks on every public symbol, one paragraph of fact.
- Tests with Pest: unit tests over fakes for the loop, the accumulator, the scheduler, pause and
  resume, events order, both adapters; live tests tagged and skipped without the endpoint.
