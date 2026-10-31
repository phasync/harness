# phasync/harness — specification

Revision 5 (2026-10-31). Revisions 1–4 are 0.0.1-beta1–beta4. In round 4 every user called the
harness complete for their use; what they still found is minor and in Compact. This revision
fixes those, adds three features the owner asked for (interrupt-edit-resume, steering a running
turn, a known context window with truncation detection), and closes the last documentation
gaps. The builder implements this document; sections marked *module* are part of this package
but live in their own namespace and are never imported by the core.

Changes since revision 4 are marked **[r5]**. Everything not marked is confirmed as built.

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

Namespace `Harness\`. PHP 8.2+. Dependencies: `fiber-interop/api ^0.3.1`, `psr/log`. require-dev
`fiber-interop/phasync ^0.4.1`, `fiber-interop/revolt` pinned to a tag if Packagist has one that
allows scope ^0.5, else `dev-main`.

### Parts, Model, Transport, Tool, Call, Result, Events, Middleware

As built through revision 4.

### [r5] Interrupt, edit, resume

The host stops a reply mid-generation, edits what the model has said so far, and the model goes
on from the edit.

```php
$run->interrupt();                       // like cancel(), but the turn ends Reason::Interrupted
$run->ended()->partial;                  // ?Message: the assistant message as delivered so far
                                         //   (Text as streamed; completed ToolCalls; Reasoning)
$run->turn(new Resume($edited));         // $edited: an assistant Message the host built from it
Prompt::$continue                        // bool: the last message is an assistant message to
                                         //   continue, not a finished turn
```

- `Interrupted` keeps the partial message in `Ended::$partial` and nowhere else: `messages()`
  and `appended` hold only complete steps, as after `Cancelled` and `Failed`. The turn ends
  within the transport's poll interval; `Ended::$finish` is null.
- `Resume` appends the edited assistant message and runs a step with `Prompt::$continue` true.
  The accumulator is seeded with the edited message, so deltas extend it and the final
  `MessageEnded` carries the whole message; `MessageStarted` is not emitted again, a
  `MessageResumed(id)` event is **[r5]**. Tool calls the continued message ends with run as in
  any step. A `Resume` is only valid when the run's last message is a user message (the partial
  is not in `messages()`); anything else throws.
- Providers: `ChatCompletions` sends the assistant message last, which llama.cpp, exl3 and
  vLLM continue (vLLM needs `continue_final_message: true` and `add_generation_prompt: false`,
  documented as `provider['openai']` keys); `Anthropic\Messages` prefills when the request has no
  thinking and throws `UnsupportedOptionException` with thinking on; `Responses` throws
  (`UnsupportedOptionException`: the API has no continuation). The exception names the fallback
  the host may choose explicitly: append the edit as a finished assistant message and ask for
  the next one. Nothing emulates silently.
- Limits, stated in the README: a continuation cannot resume inside a reasoning block (the edit
  applies to the visible text); a model interrupted mid tool call has no half call to continue.
- `examples/chat.php`: Ctrl-C during a reply interrupts; the partial text is shown; the user types
  a replacement for its last line (or nothing to keep it) and the reply continues. A live test
  against the llama.cpp endpoint: interrupt after the first sentence, replace it, assert the
  continuation starts after the replacement.

### [r5] Steering a running turn

`Run::interject(Message $user)`: queues a user message that the loop appends at the next step
boundary (after the step's tool results, before the next model call), so the host can redirect a
long turn without losing the step in flight. Several interjections queue in order; an
interjection with no turn running throws (use `turn()`). An event `Interjected(turn, message)`
**[r5]** marks where it landed. `examples/chat.php`: a line typed while a reply streams is an
interjection.

### [r5] A known context window

`Options::$contextWindow` (`?int`, tokens): the model's window as the host knows it. The core does
nothing with it but validate it and pass it on; `Compact` uses it (below). The README states that
the harness assumes a server that errors when the prompt exceeds the window (hosted APIs,
llama.cpp without context shift) and that a server that evicts silently (Ollama's default
`num_ctx` of 4k) must be configured (`provider['ollama']['num_ctx']`) because the system prompt is
the first thing it drops.

### [r5] A LogicException from a tool is misuse

As from the model or a middleware: the turn ends `Failed` and the exception is rethrown from the
iteration after `TurnEnded`, instead of becoming an error result the model retries. Through
`AgentTool` it reaches the parent the same way. Round 4 saw a lead agent re-delegating forever
because a child's `Compact` budget-floor `LogicException` came back as a tool error.

### Documentation **[r5]**

- README `### Tools`: `Call`'s member list includes `pending()`, `answer()`, `answers()`,
  `decision()`, `state()`, or points to Pause and resume.
- README Install: only phasync and revolt are run in this repo's own suite; amp and react are
  fiber-interop adapters the harness has not been tested on.
- README Events: a `tool_decided` JSON line in the sample, and the note that the inline-answering
  idiom emits no `ToolDecided` (the Run never saw a pause).
- README Pause and resume: the answer body of a web host nests by call id
  (`{"<callId>": {"<needId>": ...}}`), because need ids repeat across calls; a code block for
  "after Failed" next to the AwaitingInput one, with the sentence that the user message that
  started the failed step is already in `messages()`.
- SPEC/docs/policy.md agree: the answering middleware is listed *before* Policy in the array
  (outermost), since Policy's `Ask` returns without calling `$next`.

## Modules

### Compact (module) **[r5]**

- **Truncated summaries are collapses.** A side call that ends with `FinishReason::Length` is
  retried once and then fails, like a short or empty one; `Compaction` records the finish.
- **The summarizer sees the instructions.** The side call's ask includes the run's system text
  (as "the agent's instructions") before the kept first messages, so summaries keep the task's
  terms. docs/compact.md says exactly what the summarizer sees.
- **A round that does not shrink the prompt is dropped**, with the existing warning, instead of
  replacing two small messages by a larger summary and spending a side call every step.
- **Calibration is a value.** `Compact::calibration(): ?Calibration` (low and high points, ratio,
  offset) and a constructor parameter `?Calibration $calibration = null`, both JSON round-trip,
  so a host that builds a `Compact` per request (the web idiom) persists it next to the
  conversation and starts calibrated.
- **Budget from the window.** `new Compact($summarizer, budgetTokens: null, ...)` takes the budget
  from `Options::$contextWindow` minus a reserve of 20 % at first use; neither set throws.
- **Silent truncation is detected.** Once calibrated, a reported input count below 70 % of the
  calibrated estimate means the server cut the prompt: a `warning` log line naming both numbers,
  and `Compaction::$truncatedUpstream` on that step. Tested with a FakeModel reporting half.
- docs/compact.md: the thinking-off summarizer options are the first example's first line, with
  one sentence why (both round-4 users collapsed on a thinking summarizer); a sentence for
  upgraders that a budget tuned on bytes/4 can fail the floor once calibration learns the real
  ratio; the cache key described as it is implemented.

### Policy, Mcp, Transcript, Agents, Tools, Providers, Testing (modules)

As built. `Responses` and `Anthropic` stay fake-tested until keys exist. A host that answers
`Policy`'s `Ask` inline lists its answering middleware *before* `Policy` in the middleware array
(outermost): `Ask` returns without calling `$next`, so a middleware listed after it never sees the
pause. docs/policy.md says the same.

## Examples and CLI

As built; `chat.php` gains interrupt-edit-resume and interjection **[r5]**; `web.php` persists
and restores the `Calibration` **[r5]**.

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
- Clean up only what you created, by name; never delete by glob in a shared directory.
