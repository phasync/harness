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

$ended = $run->ended();   // reason, appended messages, pending calls, usage, error and errorKind
```

A turn takes a user `Message`, or the `Answer`s to a turn that ended awaiting input. Each step builds
a `Prompt` from the system text, the messages so far and the current tools (the `ToolSource` is read
at every step), streams the model, appends the assistant message, runs its tool calls and appends
their results as one user message. It ends when:

| `Reason`        | when                                                                         |
| --------------- | ---------------------------------------------------------------------------- |
| `Done`          | a step produced no tool calls                                                |
| `Stopped`       | a stop predicate returned true (default `[Stop::maxSteps(20)]`)              |
| `AwaitingInput` | a tool asked the host (`Call::needsInput()`); `Ended::$pending` lists the calls |
| `Cancelled`     | `$run->cancel()` was called, or the consumer stopped iterating               |
| `Failed`        | `Ended::$errorKind`: `ModelError`, `ContextTooLong`, `RateLimited`, `TransportError`, `ToolRetriesExceeded`, `MiddlewareError` |

Iterate a turn inside `fiber\run()`. The turn runs in its own coroutine, a new `\Scope` and its own
`fiber\run()`, so it ends with everything it started finished. A `\LogicException` (misuse, such as
an option the provider cannot honour) is rethrown from the iteration after `TurnEnded`; runtime
failures end the turn `Failed` and are in `ended()`.

`Run` keeps the conversation in memory: `messages()` returns it, `Ended::$appended` is what a turn
added. To continue elsewhere, pass `messages:` (and `pending:`, see below) to a new `Run`.

### Tools

`Tool::call(array $input, Call $call): Result`. `Result` keeps apart what the model sees
(`content`), what the application gets (`value`, in the `ToolEnded` event) and what is only logged
(`meta`); `error: true` marks a failure. `Call` carries the call `id`, the `run`, the host's
`context` (from `Run`), the called tool's `spec`, `progress()`, `isCancelled()` and `needsInput()`.

A tool fails in one of two ways. `throw new Retry('why')` gives the model an error result to correct,
counted against `Options::$toolRetries` (default 3, together with unknown tool names and malformed
input); past it the turn fails `ToolRetriesExceeded`. Any other throwable gives the model an error
result with its message, is emitted as `ToolEnded` with the throwable, and is logged.

The read-only calls of a step (`Hints::$readOnly`) run concurrently; the others run after them, one
at a time, in the model's order. Results go back in the model's order.

## Events

Each item goes `started → delta* → ended`; every event has the `turn` number and the `parent` tool
call id when its run is nested (null at the top). `toArray()` and `json_encode()` give `type` plus the
fields, so an SSE route writes one line per event.

```
TurnStarted(turn)                       TurnEnded(turn, reason, usage, ?error)
StepStarted(turn, n)                    StepEnded(turn, n)                    n = 0: resumed calls
MessageStarted(id)                      TextDelta(id, text)        ReasoningDelta(id, text)
ToolCallStarted(id, name)               ToolInputDelta(id, json)   ToolCallReady(id, name, input)
ToolDecided(id, decision)               ToolProgress(id, value)    ToolEnded(id, Result|Throwable)
MessageEnded(id, message, usage, finish)                           InputRequested(pending)
```

The turn pushes events to a queue from its own fibers, concurrent tool calls in the order things
happen; the consumer awaits the queue. Stopping the iteration cancels the turn.

## Middleware

The only interception mechanism. A class implements `ModelMiddleware`, `ToolMiddleware` or both;
the array order is outermost first.

```php
// Policy: anything not read-only needs approval.
final class ApproveWrites implements ToolMiddleware
{
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        if (null === $context->decision() && null !== $context->spec && !$context->spec->hints->readOnly) {
            return $context->needsInput(new Approval("{$call->name} changes files"));
        }

        return $next($call, $context);
    }
}
```

Deny by returning `new Result([...], error: true)`; truncate by rewriting the `Result`; compact by
rewriting the `Prompt` (`ModelMiddleware::stream($prompt, $next)`); retry by wrapping `$next` (a
`ModelException` carries `kind` and `retryAfter`); trace by wrapping both. To observe without
altering, read the events.

## Pause and resume

A turn that ends `AwaitingInput` lists `Pending(call, needs, state)`: `needs` is an `Approval` or a
list of `Question`s, `state` is the tool's own JSON-safe data. The next turn takes one
`Answer(callId, Decision::allow() | Decision::deny($reason) | ['questionId' => answer])` per pending
call: approved calls run (`$call->decision()` is set), denied ones go to the model as error results,
a tool that asked questions is called again with `$call->answers()` and `$call->state()`.

Everything is plain data, so a web request can end the turn and another process resume it:

```php
// Request 1: persist what the browser must answer.
$store = ['messages' => array_map(fn ($m) => $m->toArray(), $run->messages()),
          'pending'  => array_map(fn ($p) => $p->toArray(), $run->ended()->pending)];

// Request 2: rebuild the run and answer.
$run = new Run($model, $tools, middleware: [new ApproveWrites()],
    messages: array_map(Message::fromArray(...), $store['messages']),
    pending: array_map(Pending::fromArray(...), $store['pending']));
foreach ($run->turn([new Answer($callId, Decision::allow())]) as $event) { ... }
```

A host that answers at once (a CLI) does it in a middleware outside the policy, in the tool's fiber:
after `$next()` returns with `$context->pending()` set, it asks the terminal
(`fgets(fiber\readable(STDIN))`), calls `$context->answer(...)` and calls `$next()` again.
[`examples/files.php`](examples/files.php) does exactly this.

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
| `Harness\Testing` | `FakeModel`, `FakeTransport` | [docs/testing.md](docs/testing.md) |

## Examples

Each is one file, runnable against any Chat Completions endpoint:

```bash
export HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8-27b-exl3-sivert
php examples/chat.php                       # streamed conversation; Ctrl-C cancels a reply
php examples/files.php ./project "task"     # file tools; writes need approval on the terminal
php examples/shell.php ./project "task"     # a shell; every command needs approval
php examples/subagent.php ./project "question"   # delegates reading to a sub-agent
```

## Tests

```bash
composer test                     # the unit suite under the phasync and the Revolt adapter
HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=... composer test:live
```

## License

MIT
