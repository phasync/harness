# phasync/harness

The runtime between a model and an application: the tool loop, a typed event stream, one
pause-and-resume contract and two middleware seams. It renders nothing; a CLI, a Swerve route (SSE,
WebSocket) or any other host renders the events. Providers, file tools, the shell and sub-agents are
modules over the core's interfaces.

Concurrency goes through [fiber-interop/api](https://github.com/fiber-interop/api) only, so the
harness runs on phasync, Revolt (AMPHP, ReactPHP) or any other adapter, and behaves the same on each.

## Install

```bash
composer require phasync/harness fiber-interop/phasync   # or fiber-interop/revolt, /amp, /react
```

PHP 8.2+. Dependencies: `fiber-interop/api` and `psr/log`.

## The loop

```php
use Harness\{Call, ClosureTool, Hints, Message, Options, Result, Role, Run, Tools, ToolSpec};
use Harness\Event\TextDelta;
use Harness\Http\StreamTransport;
use Harness\Part\Text;
use Harness\Provider\OpenAI\ChatCompletions;

$model = new ChatCompletions(new StreamTransport(), 'http://127.0.0.1:8012/v1', 'qwen3.8-27b-exl3-sivert');

$clock = new ClosureTool(
    new ToolSpec('clock', 'The current time', ['type' => 'object'], new Hints(readOnly: true)),
    fn (array $input, Call $call): Result => Result::text(date('c')),
);

$run = new Run($model, new Tools($clock), system: 'Be brief.', options: new Options(maxOutputTokens: 2000));

fiber\run(function () use ($run) {
    foreach ($run->turn(new Message(Role::User, [new Text('What time is it?')])) as $event) {
        if ($event instanceof TextDelta) {
            echo $event->text;
        }
    }
});

$ended = $run->ended();   // reason, appended messages, pending calls, usage, error, errorKind, finish
```

A turn takes a user `Message`, or the `Answer`s to a turn that ended awaiting input. Each step builds
a `Prompt` from the system text, the messages so far and the current tools (the `ToolSource` is read
at every step), streams the model, appends the assistant message, runs its tool calls and appends
their results as one user message. It ends when:

| `Reason`        | when                                                                         |
| --------------- | ---------------------------------------------------------------------------- |
| `Done`          | a step produced no tool calls (see `finish`)                                 |
| `Stopped`       | a stop predicate returned true (default `[Stop::maxSteps(20)]`)              |
| `AwaitingInput` | a tool asked the host (`Call::needsInput()`); `Ended::$pending` lists the calls |
| `Cancelled`     | `$run->cancel()` was called, or the consumer stopped iterating               |
| `Failed`        | `Ended::$errorKind`: `ModelError`, `ContextTooLong`, `RateLimited`, `TransportError`, `ToolRetriesExceeded`, `MiddlewareError` |

`Done` only means "no tool calls". `Ended::$finish` (and `TurnEnded::$finish`) is the `FinishReason`
of the turn's last assistant message: a reply cut by the token limit is `Done` with finish `Length`,
a refused one `Done` with finish `Refusal`. A host that shows a reply as complete checks it.

Iterate a turn inside `fiber\run()`. The turn runs in its own coroutine, a new `\Scope` and its own
`fiber\run()`, so it ends with everything it started finished. A `\LogicException` (misuse, such as
an option the provider cannot honour) is rethrown from the iteration after `TurnEnded`; runtime
failures end the turn `Failed` and are in `ended()`.

`Run` keeps the conversation in memory: `messages()` returns it, `Ended::$appended` is what a turn
added (`Harness\Transcript` keeps it in a file). To continue elsewhere, pass `messages:` (and
`pending:`, see below) to a new `Run`. `isRunning()` is true from `turn()` until `TurnEnded` was handled.

### Cancellation

`$run->cancel()` (from a signal handler or another fiber) sets the turn's `Cancel` signal. The
`Prompt` carries it to the model and the model to its `Transport`, which checks it while it waits
(every 250 ms for `StreamTransport`) and throws `Cancelled`; running tools see `Call::isCancelled()`,
`Shell` kills its process group, nested runs are cancelled with their parent, and calls not yet
started get a "cancelled" result. The turn ends `Cancelled` without the partial assistant message:
`Ended::$appended` never holds it. `cancel()` with no turn running does nothing.

Latency: a turn waiting on the model ends within 250 ms of `cancel()` (the `StreamTransport` poll;
at once when a delta arrives first). A turn in a tool call ends when the tool returns: within
250 ms for `Shell` (which then kills the process group) and for `Mcp` over stdio, and for your own
tools as soon as they check `Call::isCancelled()`.

### Tools

`Tool::call(array $input, Call $call): Result`. A `ToolSpec`'s schema must have `"type": "object"`;
schema nodes PHP holds as empty arrays (`'properties' => []`) are sent as `{}`. `Result` keeps apart what the model sees
(`content`), what the application gets (`value`, in the `ToolEnded` event) and what is only logged
(`meta`); `error: true` marks a failure. `Call` carries the call `id`, the `run`, the host's
`context` (from `Run`), the called tool's `spec`, `progress()`, `isCancelled()` and `needsInput()`.

A tool fails in one of two ways. `throw new Retry('why')` gives the model an error result to correct,
counted against `Options::$toolRetries` (default 3, together with unknown tool names and malformed
input); past it the turn fails `ToolRetriesExceeded`. Any other throwable gives the model an error
result with its message, is emitted as `ToolEnded` with the throwable, and is logged.

The read-only calls of a step (`Hints::$readOnly`) run concurrently; the others run after them, one
at a time, in the model's order. Results go back in the model's order.

A `ToolSource` is read at every step, so its tools may change between steps:

```php
final class Unlockable implements ToolSource
{
    private bool $open = false;

    public function tools(): iterable
    {
        yield new ClosureTool(new ToolSpec('unlock', 'Unlocks the vault', ['type' => 'object']),
            function (array $in, Call $call): Result { $this->open = true; return Result::text('Unlocked; vault_read is available now'); });
        if ($this->open) {
            yield new ClosureTool(new ToolSpec('vault_read', 'Reads the vault', ['type' => 'object'], new Hints(readOnly: true)),
                fn (array $in, Call $call): Result => Result::text('42'));
        }
    }
}
```

## Events

Each item goes `started → delta* → ended`; every event has the `turn` number and the `parent` tool
call id when its run is nested (null at the top). `toArray()` and `json_encode()` give `type` plus the
fields, so an SSE route writes one line per event; `Events::fromArray()` rebuilds the typed event.

```
TurnStarted(turn)                       TurnEnded(turn, reason, usage, ?error, ?finish)
StepStarted(turn, n)                    StepEnded(turn, n)                    n = 0: resumed calls
MessageStarted(id)                      TextDelta(id, text)        ReasoningDelta(id, text)
ToolCallStarted(id, name)               ToolInputDelta(id, json)   ToolCallReady(id, name, input)
ToolDecided(id, decision)               ToolProgress(id, value)    ToolEnded(id, Result|Throwable)
MessageEnded(id, message, usage, finish)                           InputRequested(pending)
```

| class | `type` | class | `type` |
| --- | --- | --- | --- |
| `TurnStarted` | `turn_started` | `ToolCallStarted` | `tool_call_started` |
| `TurnEnded` | `turn_ended` | `ToolInputDelta` | `tool_input_delta` |
| `StepStarted` | `step_started` | `ToolCallReady` | `tool_call_ready` |
| `StepEnded` | `step_ended` | `ToolDecided` | `tool_decided` |
| `MessageStarted` | `message_started` | `ToolProgress` | `tool_progress` |
| `TextDelta` | `text_delta` | `ToolEnded` | `tool_ended` |
| `ReasoningDelta` | `reasoning_delta` | `MessageEnded` | `message_ended` |
| `InputRequested` | `input_requested` | | |

```json
{"type":"text_delta","turn":1,"parent":null,"id":"msg_ad35d61424070fe4","text":" green"}
{"type":"tool_call_ready","turn":1,"parent":null,"id":"call_2ce8","name":"remember","input":{"note":"Favourite colour: green"}}
{"type":"tool_ended","turn":1,"parent":null,"id":"call_2ce8","outcome":{"content":[{"type":"text","text":"Saved"}],"value":null,"meta":[],"error":false}}
{"type":"tool_ended","turn":1,"parent":null,"id":"call_9f1a","outcome":{"class":"RuntimeException","message":"disk full"}}
{"type":"input_requested","turn":1,"parent":null,"pending":[{"call":{"type":"tool_call","id":"call_2ce8","name":"remember","input":{"note":"Favourite colour: green"}},"needs":[{"type":"approval","id":"approval","reason":"Save a note"}],"state":null}]}
{"type":"turn_ended","turn":1,"parent":null,"reason":"awaiting_input","usage":{"input":396,"output":59,"cacheRead":0,"cacheWrite":0},"error":null,"finish":"tool_calls"}
```

Delivery is synchronous: the turn hands each event to the consumer and goes on only when the
consumer's `foreach` asks for the next one. What happens after an event (a middleware printing a
prompt, a tool writing a file) happens after the host handled that event, so the stream is ordered
with the side effects. The consumer sets the pace: a slow SSE write slows the model read. Concurrent
tool calls emit from their own fibers and wait their turn. Stopping the iteration cancels the turn.

A nested run (`Agents\AgentTool`) emits the same event classes into the same stream, with `parent`
set to the tool call it runs in. A renderer that reacts to turn boundaries (`TurnStarted`,
`StepStarted`, `StepEnded`, `TurnEnded`, `InputRequested`) checks `null === $event->parent` first,
or it renders a sub-agent's turn as the host's and a nested pause twice: the child's
`InputRequested`, then the parent's, which carries the same needs.

## Middleware

The only interception mechanism. A class implements `ModelMiddleware`, `ToolMiddleware` or both;
the array order is outermost first.

```php
// Policy: anything not read-only needs approval, unless the host answered this call already.
// (Harness\Policy does this from rules: docs/policy.md.)
final class ApproveWrites implements ToolMiddleware
{
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        if ([] === $context->answers() && null !== $context->spec && !$context->spec->hints->readOnly) {
            return $context->needsInput([new Approval(reason: "{$call->name} changes files")]);
        }

        return $next($call, $context);
    }
}
```

Deny by returning `new Result([...], error: true)`; truncate by rewriting the `Result`; compact by
rewriting the `Prompt` (`ModelMiddleware::stream($prompt, $next)`, passing `$prompt->cancel` on to
the new one; `Harness\Compact` does it); trace by wrapping both. To observe without altering, read
the events.

Retry by wrapping `$next`, but only before the first delta went on: the run's accumulator folds each
delta as it is yielded, so a stream restarted after that would add its text a second time. Past the
first delta, rethrow. A `ModelException` carries `kind` and `retryAfter`.

```php
final class RetryModel implements ModelMiddleware
{
    public function stream(Prompt $prompt, Closure $next): iterable
    {
        for ($try = 1; ; ++$try) {
            $yielded = false;
            try {
                foreach ($next($prompt) as $delta) {
                    $yielded = true;
                    yield $delta;
                }

                return;
            } catch (ModelException $e) {
                if ($yielded || $try === 3 || ErrorKind::RateLimited !== $e->kind) {
                    throw $e;
                }
                fiber\sleep($e->retryAfter ?? 2 ** $try);
            }
        }
    }
}
```

## Pause and resume

A tool (or a tool middleware) that needs the host returns `$call->needsInput($needs, $state)`. The
turn ends `AwaitingInput` once the step's other calls are done, listing `Pending(call, needs, state)`:
`needs` is a list of `Approval(id = 'approval', reason)` and `Question(id, text, choices)`, each
with an id unique within the call; `state` is the tool's own JSON-safe data. The next turn takes one
`Answer(callId, [needId => answer])` per pending call: a `Decision::allow()` or
`Decision::deny($reason)` for each approval, and for each question a value (one of `choices` when
given) or `Decision::deny($reason)` to decline it. The call is made again with `$call->answers()`
(`$call->decision()` is the answer to `approval`) and `$call->state()`.

**Who handles a denial.** State means "call me back with the answer, whatever it is". A call that
asked *with* a state is always made again, a denial included, and handles it itself
(`Agents\AgentTool` tells its sub-agent, `Mcp` declines the form). A call that asked *without* state
is not made again when one of its approvals is denied: the model gets the error result
`Denied: <reason>`. A declined question always reaches the tool.

`Pending::toArray()`, one approval, then two questions with state:

```json
{"call":{"type":"tool_call","id":"call_1","name":"write_file","input":{"path":"a.txt","content":"..."}},
 "needs":[{"type":"approval","id":"approval","reason":"write_file changes files"}],
 "state":null}
{"call":{"type":"tool_call","id":"call_2","name":"deploy","input":{}},
 "needs":[{"type":"question","id":"env","text":"Where to?","choices":["staging","prod"]},
          {"type":"question","id":"note","text":"Release note?","choices":null}],
 "state":{"build":42}}
```

Everything is plain data, so a web request can end the turn and another process resume it:

```php
// Request 1: persist what the browser must answer.
$store = ['messages' => array_map(fn ($m) => $m->toArray(), $run->messages()),
          'pending'  => array_map(fn ($p) => $p->toArray(), $run->ended()->pending)];

// Request 2: rebuild the run and answer.
$run = new Run($model, $tools, middleware: [new ApproveWrites()],
    messages: array_map(Message::fromArray(...), $store['messages']),
    pending: array_map(Pending::fromArray(...), $store['pending']));
foreach ($run->turn([new Answer($callId, ['approval' => Decision::allow()])]) as $event) { ... }
```

[`examples/web.php`](examples/web.php) is this host, one request per invocation.

A host that can answer at once (a CLI) does not end the turn: a middleware placed before (outside)
the one that asks calls `$next`, and while the call is pending asks the user, answers in place and
calls `$next` again. The same loop answers a paused sub-agent inline, round after round:

```php
final class Terminal implements ToolMiddleware
{
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        $result = $next($call, $context);
        while (null !== ($pending = $context->pending())) {
            $answers = [];
            $denied  = false;
            foreach ($pending->needs as $need) {
                if ($need instanceof Approval) {
                    $answers[$need->id] = ask("Allow {$call->name}? {$need->reason}") ? Decision::allow() : Decision::deny('the user said no');
                    $denied             = $denied || !$answers[$need->id]->allowed;
                } else {
                    $answers[$need->id] = ask($need->text);   // or Decision::deny() to decline
                }
            }
            $context->answer($answers);
            // The rule the run applies: without state, a denied approval is not called again.
            if ($denied && null === $pending->state) {
                return new Result([new Text('Denied: the user said no')], error: true);
            }
            $result = $next($call, $context);
        }

        return $result;
    }
}
$run = new Run($model, $tools, middleware: [new Terminal(), new ApproveWrites()]);
```

A host that only ever answers on the terminal can instead ask in a single middleware and call
`$next()` or return an error `Result` ([`examples/shell.php`](examples/shell.php)).
[`examples/files.php`](examples/files.php) and [`examples/policy.php`](examples/policy.php) use the loop.

A sub-agent that pauses pauses its parent: the parent's call carries every need of the sub-agent's
pending calls, ids prefixed with the child call id (`w1/approval`), and the answers go back down
([docs/agents.md](docs/agents.md)).

## Modules

Each lives in its own namespace; the core never imports one.

| Module | | |
| --- | --- | --- |
| `Harness\Http` (core) | `StreamTransport`, `Sse`, `Response` | [docs/http.md](docs/http.md) |
| `Harness\Provider\OpenAI` | `ChatCompletions`: llama.cpp, vLLM, exl3, Ollama, OpenAI | [docs/openai.md](docs/openai.md) |
| `Harness\Provider\Anthropic` | `Messages`: the Messages API with thinking blocks | [docs/anthropic.md](docs/anthropic.md) |
| `Harness\Tools\Files` | `ReadFile`, `WriteFile`, `EditFile`, `ListDir`, `Glob`, `Grep` under a `Root` | [docs/files.md](docs/files.md) |
| `Harness\Tools\Shell` | `Shell`: bash commands with streamed output | [docs/shell.md](docs/shell.md) |
| `Harness\Agents` | `AgentTool`: a sub-agent as a tool | [docs/agents.md](docs/agents.md) |
| `Harness\Mcp` | `McpServer`: an MCP server's tools, over stdio or Streamable HTTP | [docs/mcp.md](docs/mcp.md) |
| `Harness\Transcript` | `JsonlTranscript`, `JsonlEvents`: the conversation and the events in files | [docs/transcript.md](docs/transcript.md) |
| `Harness\Compact` | `Compact`: keeps the prompt within a token budget with a summary | [docs/compact.md](docs/compact.md) |
| `Harness\Policy` | `Policy`, `Rule`, `Verdict`: allow, deny or ask by tool name and hints | [docs/policy.md](docs/policy.md) |
| `Harness\Testing` | `FakeModel`, `FakeTransport`, `TestCall`, `TestPrompt` | [docs/testing.md](docs/testing.md) |

`Options::$provider` is keyed by the provider module's lowercase name (`openai`, `anthropic`); a
third-party provider documents its own key. `Sse` parses `text/event-stream` only; a backend that
streams NDJSON splits `body()` on newlines itself ([docs/http.md](docs/http.md)).

## Examples

Each is one file, runnable against any Chat Completions endpoint:

```bash
export HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8-27b-exl3-sivert
php examples/chat.php                       # streamed conversation; Ctrl-C cancels a reply
php examples/files.php ./project "task"     # file tools; writes need approval on the terminal
php examples/shell.php ./project "task"     # a shell; every command needs approval
php examples/subagent.php ./project "question"   # delegates reading to sub-agents, rendered per line
php examples/mcp.php "task"                 # a chat with an MCP server's tools (the test server by default)
php examples/web.php state.json say "hi"    # one turn per request, events as JSON lines, state in a file
php examples/compact.php [budget]           # a session that compacts to stay within a token budget
php examples/policy.php ./project "task"    # the file tools under a Policy: reads run, writes ask
```

## Tests

```bash
composer test                     # the unit suite under the phasync and the Revolt adapter
HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=... composer test:live
```

## License

MIT
