# Harness\Compact

`Compact` is a `ModelMiddleware` that keeps each step's prompt within a token budget by folding the
older messages into a checkpoint: a sectioned record of the task, what was done and what is known,
which the agent continues from.

```php
$summarizerOptions = new Options(maxOutputTokens: 2048, provider: ['openai' => ['chat_template_kwargs' => ['enable_thinking' => false]]]);
$compact = new Compact($summarizer, budgetTokens: 24000, log: $logger, summarizerOptions: $summarizerOptions);
$run     = new Run($model, $tools, middleware: [$compact]);
```

Thinking is off for the summarizer because a thinking model spends its output tokens on reasoning
and returns no checkpoint, or one cut at the limit.

```php
new Compact(Model $summarizer, ?int $budgetTokens = null, ?Closure $count = null, int $keepFirst = 1,
            ?LoggerInterface $log = null, Options $summarizerOptions = new Options(maxOutputTokens: 2048),
            ?State $state = null, ?int $keepRecentTokens = null)
```

## The budget

`budgetTokens` is in the provider's tokens. Null takes the budget from the step's
`Options::$contextWindow` less a reserve of 20 % (a window of 32,768 gives 26,214), at first use;
with neither set, the first step throws a `\LogicException`. A budget over the window throws too.
The instance keeps the budget it took at first use.

## The checkpoint

The side call writes Markdown under fixed headings:

| Heading | holds |
| --- | --- |
| Goal | the task and its constraints, quoting the user |
| Progress: Done | every finished step with its result, and every file or resource already read, by path, so the agent does not read it again |
| Progress: In progress, Blocked | what was under way; what failed or waits, with the error |
| Decisions | choices made, with why |
| Known | facts with their sources: paths, names, numbers, quotes |
| Next | what remains, in order |
| Context | anything else the agent will need |

The prompt asks for completeness about facts and terseness in wording, and for at most a quarter of
the budget and three quarters of `summarizerOptions`' output tokens, in words, so the checkpoint
fits beside the rest and is not cut. The agent gets it as one user message headed "A checkpoint of
the earlier conversation ... What is under Done is finished: do not do it again", followed by an
assistant acknowledgement when the next message is a user message, so roles alternate.

## What it does

When the prompt counts over the budget:

- The system prompt, the tool specs and the first `$keepFirst` messages stay as they are (and a
  tool result message that answers the last of them).
- The most recent messages that count within `keepRecentTokens` (default a quarter of the budget)
  stay verbatim; everything between them and the kept first messages is folded into the
  checkpoint. The cut lands on a message boundary that keeps every `ToolResult` with its `ToolCall`.
- Whatever the reserve, the recent part holds the step in flight: the last assistant message and
  its results (or the latest user message when no reply follows it).
- The latest user message stays verbatim: when the cut falls after it (a tool loop answering it
  outgrew the reserve), it is folded into the checkpoint, so the Goal can quote it, and also kept
  right after the checkpoint, so the agent answers the current question, not an older one.
- While the conversation keeps the prefix a checkpoint covers and the prompt fits with it, the
  checkpoint is reused, so a provider's prompt cache sees a stable prefix. When the budget is
  exceeded again, the side call gets the current checkpoint and the messages since, with the
  instruction to keep every fact it holds (every entry under Done, Decisions and Known), move
  finished work to Done and add what the new messages establish. A checkpoint is never
  summarized from scratch.
- A checkpoint's *Next* and *Known* can look one action behind for the steps still in the kept-recent
  tail, since it is updated only on the next fold: the model sees those steps verbatim, so this is
  correct, not stale.
- When the checkpoint and the step in flight alone are over the budget, the prompt goes on and a
  warning is logged ("nothing more to summarize"). A round whose checkpoint would count no less
  than what it replaces is dropped with the same warning, and its size remembered: a later round
  runs only for a part larger than that, so no side call is spent per step on a part too small.

Only the prompt changes. `Run::messages()`, `Ended::$appended` and a transcript keep every message.
A continued prompt (`Prompt::$continue`) is handed on as one; its last message is never folded.

## The side call

It has no tools, `$summarizerOptions` (never the step's options) and the turn's `Cancel`. Its one
user message holds, in this order: "The agent's instructions (context only, do not summarize
them)", the run's system text when there is one; "The start of the conversation, which the agent
keeps in full (context only)", the kept first messages; then either "The messages to fold into a
new checkpoint" or "The current checkpoint" and "The messages since the checkpoint, to fold into
it". Messages are rendered as `USER:`/`ASSISTANT:` text, tool calls as `[calls name {json}]`,
results as `[result]` or `[error]` and their text; `Reasoning` is left out; media and references are
named, not sent. Any `Model` can summarize; a smaller one is the usual choice.

A checkpoint that comes back empty, cut at the output token limit (`FinishReason::Length`), or
shorter than a quarter of the checkpoint it updates, is a collapse: asked for once more (logged as a
warning; a checkpoint cut at the limit is asked for in 60 % of the words), and a second collapse
fails the step with a `RuntimeException` naming what happened. So does a summarizer whose own
window cannot take what it is asked to fold (its `ContextTooLong` is Compact's misconfiguration, a
`MiddlewareError` of the turn, never reported as the conversation's); its other failures pass
through with their kind. A checkpoint is longer than a prose
summary: give the summarizer output tokens to match (in a 2,000-token budget with 800 output tokens,
round 6's live readers wrote checkpoints of 2.2 to 2.9 KB, and some were cut).

## A prompt the provider rejects

When the provider rejects the prompt as too long (`ModelException` with `ErrorKind::ContextTooLong`)
before it streamed anything, though the estimate said it fits, Compact compacts the step again to
85 %, 70 %, 50 % and 35 % of the budget (the reserve shrinks with it) and retries, logging each
retry as a warning; a target that leaves the prompt as it was is skipped. After 35 % it logs an
error and rethrows, and the turn fails `ContextTooLong`. Any other error, or one after the first
delta, is rethrown at once.

A declared `Options::$contextWindow` far above the server's real limit defeats the ladder: none of
85 %, 70 %, 50 % or 35 % of a budget taken from the wrong window shrinks the prompt enough, each
rung logs "nothing more to fold", and the step fails loudly rather than retry quietly forever.

## Counting

`Compact::approximate(Prompt): int` is the default counter: bytes / 4 over the system text, text and
reasoning, tool names and inputs, tool results and tool specs, plus 1000 per media part, 4 per
message and 10 per tool spec. Another counter is a closure:
`new Compact($model, 100000, fn (Prompt $p): int => $tokenizer->count($p))`.

Compact calibrates whichever counter it has from the input tokens the provider reports for each
step it passes on (a report of zero is ignored): `ratio * count + offset`, the line through the
smallest and the largest prompt reported, once the largest counts at least twice the smallest.
Until then the ratio is 1 and the offset the largest prompt's difference. A chat template's fixed
preamble lands in the offset, its per-message wrapping in the ratio.

Once the calibration is a line, a reported count under 70 % of the calibrated one means the server
cut the prompt (a server that evicts the oldest tokens silently, such as Ollama with its default 4k
`num_ctx`, drops the system prompt first). Compact logs a warning naming both numbers, leaves the
report out of the calibration, and sets `Compaction::$truncatedUpstream` on that step's `last()`.
Configure the server's window and set `Options::$contextWindow` to it.

## The budget floor

The fixed part of the prompt (system text, tool specs, the first `$keepFirst` messages) must count
at most half the budget, by the calibrated count once there is a line; otherwise the step throws a
`\LogicException` naming the fixed size and the budget to set. `keepRecentTokens` over half the
budget throws too. A `\LogicException` ends the turn `Failed` and is rethrown, in a sub-agent too.

## State

`State` is what an instance learned, as a JSON value: the `Calibration` (null before the first
report) and the latest `Checkpoint`s, newest first, at most 8. A checkpoint is keyed by a content
hash of the messages it covers, so it is found for equal messages whether they are the same objects
or rebuilt from arrays. `state()` returns it and the constructor takes one, so a host that builds a
`Compact` per request (the web idiom) persists it next to the conversation
([`examples/web.php`](../examples/web.php)):

```php
$compact = new Compact($model, null, state: isset($saved['compact']) ? State::fromArray($saved['compact']) : null);
// ... the turn ...
$saved['compact'] = $compact->state()->toArray();   // {"calibration": {...}|null, "checkpoints": [{"hash", "covered", "kept", "lifted", "text", ...}]}
```

(a) A persisted `State` saves a side call only while the restored checkpoint's view (its text plus
the kept-recent tail) still fits the request's budget; once a request goes further over it, the
restored checkpoint still saves work by costing one UPDATE call, never a from-scratch summary.
(b) A conversation keeps only its most-covering checkpoint: a fold evicts the ones it supersedes, so
branching the conversation from an earlier point costs a side call again.

One instance may serve several conversations at once, each with its own checkpoint: the forks
`Agents\AgentTool` runs share the template's middleware. Beyond 8 conversations compacting at once,
the oldest checkpoint is evicted and written again when needed. `last()` is the instance's latest
step, of any conversation; the calibration, the budget and the floor are the instance's, so share
an instance only among conversations with the same model and fixed part.

## Seeing it

The core has no event for compaction. Each new checkpoint is logged at `info` (messages removed,
size, budget, counter ratio and offset) and in full at `debug`. `last(): ?Compaction` tells what the
last step did: `removed`, `kept` (messages after the checkpoint, verbatim), `summary` (the
checkpoint text), `before` and `after` (calibrated tokens), `reused`, `previousSummaryBytes` (the
checkpoint this one updated, null for the first), `ratio`, `offset`, `finish` and
`truncatedUpstream`. It is null when the step's prompt went on unchanged and was not cut upstream.
[`examples/compact.php`](../examples/compact.php) prints both.
