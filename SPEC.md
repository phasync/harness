# phasync/harness — specification

Revision 4 (2026-10-31). Revisions 1–3 are 0.0.1-beta1–beta3, each tried by five users. Round 3
returned no change requests for the core, the pause contract, the events, MCP or Policy; every
real problem was in Compact, plus one unbounded message in the accumulator. This revision fixes
those, pins the dependencies to tags where tags exist, and closes the remaining documentation
gaps. The builder implements this document; sections marked *module* are part of this package
but live in their own namespace and are never imported by the core.

Changes since revision 3 are marked **[r4]**. Everything not marked is confirmed as built.

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

Namespace `Harness\`. PHP 8.2+. Dependencies: `fiber-interop/api ^0.3.1`, `psr/log`. Nothing
else. **[r4]** require-dev: `fiber-interop/phasync ^0.4.1` (tagged, scope ^0.5),
`fiber-interop/revolt` at `dev-main` until its tag with scope ^0.5 exists (check Packagist first;
if v0.3.1 or later is there, pin it). `fiber-interop/api` 0.3.1 makes `fiber\Deferred` public and
adds `fiber\current()`: the internal event queue may use `fiber\Deferred` instead of its own
then-able if that removes code; not required.

### Parts, Model, Transport, Tool, Call, Result, Run, Events, Middleware

As built through revision 3: the closed part union with one serialization; `Model::stream()`;
`Transport` with `Cancel`; `Tool`/`ToolSpec`/`Hints`/`Result`/`ToolSource`; `Call` with the
needs protocol (`Need`, `Approval`, `Question`, `Pending`, `Answer`, `Decision`); `Run::turn()`
with synchronous delivery; the events; `ModelMiddleware` and `ToolMiddleware`.

**[r4] Accumulator.** The error result for tool input that is not valid JSON embeds at most
500 bytes of the raw input plus its total length (`... (12,345 bytes total)`), never the whole
document: a long `write_file` argument cut by the token limit was being echoed back in full on
every retry.

**[r4] ToolDecided** carries the need id (`ToolDecided(id, needId, Decision)`) and is emitted
once per decision answered on a call: the approval, and a declined question. A call asking
approvals under custom ids therefore emits it too. `Events::fromArray()` follows.

**[r4] After `Failed`.** The same guarantee as `Cancelled`, made true and stated in the README:
the partial assistant message of the step in progress is discarded; `Ended::$appended` and
`Run::messages()` hold only complete steps, every `ToolCall` with its `ToolResult`, so a host
may build a new `Run` on `messages()` to retry.

**[r4] README additions** (each one short): `fiber\setup()` is once per process and `fiber\run()`
composes from a fiber the adapter did not create (a Swerve worker, a queue worker); a step may
end with several `Pending` calls (parallel tool calls that each asked), answered each by its own
call id, with the JSON of a parent's `Answer` for nested ids (`w1/approval`); the `turn` counter
increments on a resume too; `StepEnded(turn, parent, n)`; `Tool::spec()` and `Tools::tools()`
named where tools are introduced; a reply with `finish` `Length` and no tool call may hold the
backend's tool-call template as plain text (a local server's encoding cut mid-way), which is the
backend's doing; the `$event->parent` check appears inline in `examples/policy.php` and
`examples/compact.php`'s renderers, not only in docs/agents.md.

## Modules

### Compact (module, **[r4] fixed**)

```php
new Compact(Model $summarizer, int $budgetTokens, ?Closure $count = null, int $keepFirst = 1,
            ?LoggerInterface $log = null, Options $summarizerOptions = new Options(maxOutputTokens: 1024))
```

- **[r4] Own options for the side call.** The summarizer call uses `$summarizerOptions`, never the
  step's: a thinking model that spends the step's small output budget on reasoning starved the
  summary. The host sets provider options there (to turn thinking off, for instance).
- **[r4] A collapsed summary is a failure, not a summary.** A summary shorter than a quarter of
  the one it replaces, or empty, is retried once with the same prompt; a second collapse fails
  the step with a `RuntimeException` naming both sizes. `Compaction` gains
  `previousSummaryBytes`. A 25-byte summary after a 1,093-byte one was accepted silently in
  beta3 and the agent then invented file names.
- **[r4] Budget floor.** At first use the fixed part of the prompt (system text, tool specs, the
  first `keepFirst` messages) is counted; when it exceeds half the budget, Compact throws an
  instructive exception naming the fixed size and the budget. docs/compact.md states the rule:
  the budget leaves room above the fixed part for a summary and the recent half.
- **[r4] Self-calibrating counter.** The default counter stays bytes/4 with the per-message,
  per-spec and per-media overheads, but Compact observes the `Usage` delta of every step it
  passes through and scales its estimate by the ratio of the provider's reported input tokens to
  its own count for that prompt (last ratio wins; a reported count of zero is ignored). The
  approximation undercounted a Qwen template by half; after one step it no longer does. The
  ratio is in `Compaction` and in the log line.
- **[r4] Safe to share.** The reuse cache keeps the last 8 summaries keyed by their summarized
  prefix (compared as beta3 does), so one instance shared by forked sub-agents serves each
  conversation its own summary without recomputing them in turns. docs/compact.md and
  docs/agents.md say an instance may be shared and what it costs.
- Everything else as built: oldest half summarized in a side call with the fixed prompt, tool
  pairs never split, `Reasoning` dropped, the host's transcript untouched, `last()`, the
  repeat-until-fit loop with its warning when nothing is left. Tests for each [r4] item over
  a `FakeModel` summarizer, including the calibration (a FakeModel reporting usage twice the
  estimate, the next decision using the ratio) and the collapse retry and failure.

### Policy (module)

As built. **[r4]** docs/policy.md and docs/agents.md show Policy with AgentTool: an enclosing
Policy sees the agent tool's derived hints (`readOnly` only when every child tool is), so a
parent `Ask` default gates the delegation itself before any nested pause can happen; the
example allows the delegation by name and lets the child's own Policy gate its writes. The
inline-answering middleware is described as the inner one (listed after Policy in the array,
so Policy's `needsInput` is what it answers), with the array shown.

### Mcp, Transcript, Agents, Tools, Providers, Testing (modules)

As built. **[r4]** docs/mcp.md's "Writing a server" shows the JSON a server receives when the
final round's approval is declined, next to the accepted form. docs/agents.md documents
`AgentTool::spec()`'s hints derivation in a sentence (it was only in the source), and the
`Compact`-per-child pattern with a shared instance. Responses and Anthropic stay fake-tested
until keys exist; nothing to do.

## Examples and CLI

As built; renderers in `policy.php` and `compact.php` check `$event->parent` **[r4]**.

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
- Clean up only what you created: never delete by glob in a directory other agents share.
