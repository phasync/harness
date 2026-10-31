# phasync/harness — specification

Revision 7 (2026-10-31). Revisions 1–6 are 0.0.1-beta1–beta6. Round 6: all five users call the
harness complete and found no defect; the research readers that looped in round 5 converge on
the sectioned checkpoint. This revision is the polish round before 0.1.0: it settles the three
questions the round-6 builder raised, reconciles two sentences with what was built, and closes
the documentation gaps the users named. No new feature. The builder implements this document;
sections marked *module* are part of this package but live in their own namespace and are never
imported by the core.

Changes since revision 6 are marked **[r7]**. Everything not marked is confirmed as built.

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

### Steering: two delivery modes

`Run::interject(Message $user, Delivery $delivery = Delivery::Steer)`:

- `Steer` (with one addition from pi): the message lands before the next model call,
  and **the step's tool calls that have not started yet are skipped**, each with an error result
  "Skipped: the user sent a message" so the transcript stays paired; running calls finish.
- `Queue`: the message waits until the turn would otherwise end `Done`, then starts
  another step with it (pi's follow-up, OpenCode's `queue`). Several queue in order.

`Interjected(message, delivery)` carries the mode. Leftovers at the end of a turn stay in
`Ended::$queued` as built. `examples/chat.php`: Enter while streaming steers, Alt+Enter (or a
`/queue` prefix, since terminals differ) queues.

### Shaping the tool list is a documented idiom

A `ModelMiddleware` may hand `$next` a `Prompt` with a different `tools` list (Gemini CLI's
`BeforeToolSelection`): fewer tools for a phase, a tool renamed, a tool added for one step. The
README shows it in the Middleware section with a test. **[r7] Confirmed as built:** a call runs
only if its tool was in the prompt's `tools` after middleware, so a tool the middleware removed
but the model still calls is the "unknown tool" error result; a resumed call at step 0 and a
step a middleware answered without the model are checked against the whole `ToolSource`. No
new seam.

### Documentation **[r7]**

Round 6's items (LogicException inside the foreach; cancel acts on the running process's `Run`;
the porting checklist) are built. This round adds, each one or two sentences where the reader
looks for it:

- README, Options: a thinking model spends output tokens on reasoning first; any `Run` with a
  tight `maxOutputTokens` against a thinking-capable provider turns thinking off explicitly (the
  provider knob, as Compact's `summarizerOptions` does) or it ends `Length` with no text.
  docs/agents.md repeats it for sub-agents, and names the case where the child spends its whole
  budget on reasoning and ends `Done` with an empty message (finish `Stop`, so AgentTool's
  cut-off line does not fire): the parent sees "(the agent gave no text)".
- README, Run: the default `Stop::maxSteps(20)` is stated next to `Reason::Stopped`.
- `Event\TurnEnded` docblock: it carries the `ErrorKind` only; the exception message is on
  `Ended::$error` after the generator is drained.
- CHANGELOG beta6 and README agree: `/queue` is the dependable queue gesture, Alt+Enter is what
  pi and OpenCode use and depends on the terminal.
- The `Testing` page lists the event fields a test reads (`ToolEnded::$outcome` is the bare
  `Result`; `StepStarted::$n`, `StepEnded::$n`).

## Modules

### Compact (module)

**[r7] Settled from round 6's live result:**

- The default `summarizerOptions` becomes `maxOutputTokens: 2048` (the model wrote 2.2–3.0 KB
  checkpoints at 1024 and 7 of 22 reader runs failed on the double cut). A checkpoint cut at
  the output limit twice stays a failure of the step: a prompt over budget must never be sent
  quietly.
- Readers re-reading a file listed under *Done* "to verify" is model behaviour; no prompt change.
- docs/compact.md states: (a) persisted `State` avoids a side call only while the checkpoint's
  view (its text plus the kept-recent tail) still fits the budget; a request further over budget
  costs one UPDATE call, never a from-scratch summary; (b) a conversation keeps only its
  most-covering checkpoint, older ones are evicted on each fold, so branching from an earlier
  point costs a side call; (c) a checkpoint's *Next* and *Known* may look one action behind for
  the steps still in the kept-recent tail, which the model sees verbatim: correct, not stale;
  (d) when none of the overflow targets can shrink the prompt (the declared `contextWindow` is
  far above the real limit) the ladder logs "nothing more to fold" for each and fails loudly.
  `Checkpoint`/`State` docblocks say (a) and (b) in one sentence each.

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

### Policy\Shell (module)

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

### Acp (module)

The Agent Client Protocol (agentclientprotocol.com, v1) over stdio, as an adapter over the event
stream: `initialize`, `session/new|load|prompt|cancel`; events → `session/update` (text →
`agent_message_chunk`, reasoning → `agent_thought_chunk`, tool start/update/end → `tool_call`
and `tool_call_update` with `kind` from `Hints` and `diff` content for `EditFile`/`WriteFile`;
**[r7]** shell output is plain text content, the `terminal` variant needs the client's
`terminal/*` methods which are not used); a Policy `Ask` → `session/request_permission` with
`allow_once|allow_always|reject_once|reject_always`, the answer resuming the run; `StopReason`
from `Reason`. Sub-agent pauses flatten into further permission requests; ACP's `fs/*` and
`terminal/*` client-side I/O is not used (the harness's own tools run). A PHP test client
(JSON-RPC over pipes) in tests/ drives it; `examples/acp.php` is the agent executable.
**[r7]** docs/acp.md shows one client-side exchange (`initialize`, `session/new` with its
required `mcpServers: []`, `session/prompt`, a `session/request_permission` answer) so a client
author needs no test file. Session `mcpServers` stay unconnected in 0.1; noted under "Not used".

### Policy, Mcp, Transcript, Agents, Tools, Providers, Testing (modules)

As built.

## Examples and CLI

As built. **[r7]** `CHANGELOG.md` gets a `## 0.0.1-beta7` section; the builder also writes the
`## 0.1.0` section header's text as "unreleased" notes so the next tag is a rename.

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
