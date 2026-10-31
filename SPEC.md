# phasync/harness — specification

Revision 6 (2026-10-31). Revisions 1–5 are 0.0.1-beta1–beta5. Round 5: four of five users call
the harness complete; the fifth saw a sub-agent loop after many compaction rounds, and a survey of
pi, OpenCode, Crush, Gemini CLI, Cline/Roo and ACP showed what the mature harnesses do
differently about compaction and steering. This revision redesigns Compact's summary, splits
steering into two delivery modes, hardens the shell policy, and names the first adapter module.
The builder implements this document; sections marked *module* are part of this package but
live in their own namespace and are never imported by the core.

Changes since revision 5 are marked **[r6]**. Everything not marked is confirmed as built.

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

### Parts, Model, Transport, Tool, Call, Result, Run, Events, Middleware

As built through revision 5 (interrupt-edit-resume, interject, `Options::$contextWindow`, a
tool's `LogicException` fails the turn with `ErrorKind::ToolError`, `Ended::$queued`).

### [r6] Steering: two delivery modes

`Run::interject(Message $user, Delivery $delivery = Delivery::Steer)`:

- `Steer` (as built, with one addition from pi): the message lands before the next model call,
  and **the step's tool calls that have not started yet are skipped**, each with an error result
  "Skipped: the user sent a message" so the transcript stays paired; running calls finish.
- `Queue` **[r6]**: the message waits until the turn would otherwise end `Done`, then starts
  another step with it (pi's follow-up, OpenCode's `queue`). Several queue in order.

`Interjected(message, delivery)` carries the mode. Leftovers at the end of a turn stay in
`Ended::$queued` as built. `examples/chat.php`: Enter while streaming steers, Alt+Enter (or a
`/queue` prefix, since terminals differ) queues.

### [r6] Shaping the tool list is a documented idiom

A `ModelMiddleware` may hand `$next` a `Prompt` with a different `tools` list (Gemini CLI's
`BeforeToolSelection`): fewer tools for a phase, a tool renamed, a tool added for one step. The
README shows it in the Middleware section with a test; the loop runs whatever call the model
makes against the run's `ToolSource` by name, so a tool the middleware removed from the prompt
but the model still calls is the existing "unknown tool" error result. No new seam.

### Documentation **[r6]**

- A tool's or middleware's `LogicException` is rethrown **inside** the consumer's `foreach`
  (from the iteration after `TurnEnded`): wrap the loop in `try`, not the code after it.
- Interrupt and cancel act on the `Run` object of the process that runs the turn: a web host
  that wants to stop a turn from another request keeps the run in that worker (Swerve) or ends
  it by closing the client's stream, which cancels the turn as documented.
- A porting checklist for a hand-written `Model`: `Transport::post()`'s current signature with
  `$prompt->cancel`, `Prompt::$continue`, `Options::$provider` merging being shallow (a provider
  whose request nests its knobs under one key must merge that key itself, as Ollama's `options`).

## Modules

### Compact (module) **[r6] redesigned summary**

The mechanism stays (a `ModelMiddleware`; the prompt only; the host's transcript untouched;
calibrated counting; the floor; collapse detection; one instance shared by forks; truncation
detection). What changes is what a summary is and how it grows, following pi and OpenCode:

- **A sectioned checkpoint, not a prose summary.** Fixed headings: *Goal* (the task and its
  constraints, quoting the user), *Progress* with *Done*, *In progress*, *Blocked*, *Decisions*,
  *Known* (facts with their sources: paths, names, numbers, quotes), *Next* (what remains, in
  order), *Context* (anything else the agent will need). The prompt says to be complete about
  facts and terse in wording, and to list every file or resource already read under *Done* so
  the agent does not read it again (round 5's loop).
- **Re-compaction updates the checkpoint.** When a summary already exists, the side call gets
  the previous checkpoint and the new messages to fold in, with the instruction to preserve
  every fact of the previous checkpoint and add the new progress (pi's `UPDATE` prompt,
  OpenCode's "update the existing checkpoint"). It never re-summarizes a summary from scratch.
- **The latest user message stays verbatim**, whatever the cut (round 5: a tight budget
  summarized the current question away and the agent answered an old one), and so does the step
  in flight: the recent part always holds at least the last user message and its reply so far.
- **Reserve, not half.** Compaction keeps the most recent `keepRecentTokens` (default: a quarter
  of the budget) verbatim and folds everything older into the checkpoint, rather than cutting
  at half the messages; the cut lands on a message boundary that keeps tool pairs together.
- **Overflow trigger** (OpenCode): when the provider rejects the request as too long
  (`ErrorKind::ContextTooLong`) despite the estimate, Compact retries the step with a smaller
  target (85 %, 70 %, 50 %, 35 % of the budget), then fails. Each retry is logged.
- **State is a value.** `Compact::state(): State` and `state:` in the constructor replace
  `Calibration`: `State` holds the calibration and the current checkpoint(s) keyed by a content
  hash of the messages they cover (not object identity), with `toArray`/`fromArray`. A web host
  that builds a `Compact` per request persists `State` and never recomputes a checkpoint it has.
- **The summary text is loggable.** Each accepted checkpoint is logged at `debug` in full (the
  `info` line keeps the sizes); `Compaction::$summary` as built.
- docs/compact.md rewritten around the checkpoint; the FakeModel tests cover each rule
  (update preserves facts; latest user message kept; reserve cut; overflow retry sequence;
  State round trip reusing a checkpoint in a fresh instance).

### Policy\Shell (module, **[r6] this round**)

Crush's rule for the one tool where arguments matter: a `Policy` rule for the shell tool that
matches **parsed argv**, not the command string.

```php
Rule::shell([Shell::allow('git', ['status', 'diff', 'log'], flags: ['--stat', '-p']),
             Shell::allow('ls'), Shell::deny('rm'), Shell::allow('composer', ['test', 'install'])])
```

The command is parsed (a small POSIX-shell tokenizer in the module: words, quotes, `&&`, `||`,
`;`, `|`, redirects); every simple command in a pipeline or list must match an allow rule for
the whole to be allowed; wrappers (`env`, `nice`, `timeout`, `sudo`, `nohup`, `xargs`) are
peeled and the wrapped command is matched; anything the tokenizer cannot parse (substitution,
backticks, here-docs) is `Ask`. Tested with the usual bypasses (`env rm`, `git status; rm -rf`,
`ls | sh`, `$(rm)`).

### Acp (module, **[r6] if time allows, after everything above is green**)

The Agent Client Protocol (agentclientprotocol.com, v1) over stdio, as an adapter over the event
stream: `initialize`, `session/new|load|prompt|cancel`; events → `session/update` (text →
`agent_message_chunk`, reasoning → `agent_thought_chunk`, tool start/update/end → `tool_call`
and `tool_call_update` with `kind` from `Hints` and `diff` content for `EditFile`/`WriteFile`,
`terminal` content for `Shell`); a Policy `Ask` → `session/request_permission` with
`allow_once|allow_always|reject_once|reject_always`, the answer resuming the run; `StopReason`
from `Reason`. Sub-agent pauses flatten into further permission requests; ACP's `fs/*` and
`terminal/*` client-side I/O is not used (the harness's own tools run). A PHP test client
(JSON-RPC over pipes) in tests/ drives it; `examples/acp.php` is the agent executable.

### Policy, Mcp, Transcript, Agents, Tools, Providers, Testing (modules)

As built.

## Examples and CLI

As built; `chat.php` gains the queue mode **[r6]**; `web.php` persists `Compact\State` **[r6]**;
`examples/acp.php` if the module ships.

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
