# Harness\Compact

`Compact` is a `ModelMiddleware` that keeps each step's prompt within a token budget by replacing
the oldest messages with a summary.

```php
$summarizerOptions = new Options(maxOutputTokens: 1024, provider: ['openai' => ['chat_template_kwargs' => ['enable_thinking' => false]]]);
$compact = new Compact($summarizer, budgetTokens: 24000, log: $logger, summarizerOptions: $summarizerOptions);
$run     = new Run($model, $tools, middleware: [$compact]);
```

Thinking is off for the summarizer because a thinking model spends its output tokens on reasoning
and returns no summary, or one cut at the limit: both round-4 users' summarizers collapsed that way.

```php
new Compact(Model $summarizer, ?int $budgetTokens = null, ?Closure $count = null, int $keepFirst = 1,
            ?LoggerInterface $log = null, Options $summarizerOptions = new Options(maxOutputTokens: 1024),
            ?Calibration $calibration = null)
```

## The budget

`budgetTokens` is in the provider's tokens. Null takes the budget from the step's
`Options::$contextWindow` less a reserve of 20 % (a window of 32,768 gives 26,214), at first use;
with neither set, the first step throws a `\LogicException`. A budget over the window throws too.
The instance keeps the budget it took at first use.

## What it does

When the prompt counts over the budget:

- The system prompt, the tool specs and the first `$keepFirst` messages stay as they are (and a
  tool result message that answers the last of them).
- The oldest half of the remaining messages is replaced by one user message holding a summary,
  written by `$summarizer` in a side call (below).
- The cut moves back to a boundary that keeps every `ToolResult` with its `ToolCall` (forward over
  the pair when moving back leaves nothing to summarize).
- When the first kept message is a user message, an assistant acknowledgement follows the summary,
  so roles alternate from there on. The summary itself may follow a user message (the first one,
  with the default `keepFirst`); providers accept that (`Provider\Anthropic` merges the two).
- The rest goes on verbatim, and the prompt goes to `$next`. If it is still over the budget, the
  summary is summarized again together with the oldest half of what follows it, until it fits or
  nothing is left to summarize (logged as a warning: one step's tool results alone are over the
  budget, say).
- A round whose result would count no less than the prompt before it (two small messages replaced
  by a longer summary) is dropped with the same warning, and its summary's size is remembered: a
  later round runs only for a part larger than that, so no side call is spent per step on a part
  too small to shrink.

Only the prompt changes. `Run::messages()`, `Ended::$appended` and a transcript keep every message,
so a host persists the whole conversation. A continued prompt (`Prompt::$continue`, from a
`Resume`) is handed on as one; its last message, the newest, is never summarized.

## The side call

The side call has no tools, `$summarizerOptions` (never the step's options) and the turn's `Cancel`.
Its system prompt is Compact's own (summarize under four headings: Asked, Done, Known, Remaining),
and its one user message holds, in this order:

1. "The agent's instructions (context only, do not summarize them)": the run's system text, when
   there is one, so the summary keeps the task's terms.
2. "The start of the conversation, which the agent keeps in full (context only, do not summarize
   it)": the kept first messages, when `keepFirst` is above 0.
3. "The part to summarize, which follows it" (or "The conversation so far" without kept messages):
   the messages to replace, an earlier summary among them in a later round, rendered as
   `USER:`/`ASSISTANT:` text with tool calls as `[calls name {json}]` and results as `[result]` or
   `[error]` followed by their text. `Reasoning` parts are left out; media and references are
   named, not sent.

Any `Model` can summarize; a smaller, cheaper one is the usual choice.

A summary that comes back empty, cut at the output token limit (`FinishReason::Length`), or shorter
than a quarter of the earlier summary it replaces, is a collapse, not a summary: the agent would go
on without what it knew. It is asked for once more with the same prompt (logged as a warning), and
a second collapse fails the step with a `RuntimeException` naming what happened.
`Compaction::$finish` records how the accepted summary's side call ended.

## Counting

`Compact::approximate(Prompt): int` is the default counter: bytes / 4 over the system text, text and
reasoning, tool names and inputs, tool results and tool specs, plus 1000 per media part, 4 per message
and 10 per tool spec. Another counter is a closure:
`new Compact($model, 100000, fn (Prompt $p): int => $tokenizer->count($p))`.

Compact calibrates whichever counter it has, from the input tokens the provider reports for each
step it passes on (its `Usage`; a report of zero is ignored). A chat template adds a fixed preamble
(Qwen's tool instructions are some 270 tokens behind llama.cpp) and wraps every message, so the
calibrated count is `ratio * count + offset`, the line through the smallest and the largest prompt
reported, once the largest counts at least twice the smallest (two close prompts give a slope the
next large tool result would multiply). Until then the ratio is 1 and the offset the largest
prompt's difference.

The calibration is a value, `Calibration` (the low and high points, `ratio`, `offset`, `fitted`):
`calibration()` returns it, null before the first report, and the constructor takes one. A host
that builds a `Compact` per request (the web idiom) persists it next to the conversation and starts
the next request calibrated ([`examples/web.php`](../examples/web.php)):

```php
$compact = new Compact($model, null, calibration: isset($state['calibration']) ? Calibration::fromArray($state['calibration']) : null);
// ... the turn ...
$state['calibration'] = $compact->calibration()?->toArray();   // {"low": {"counted", "reported"}, "high": {...}, "ratio", "offset"}
```

## A prompt cut upstream

Once the calibration is a line, a reported input count under 70 % of the calibrated count means the
server cut the prompt: a server that evicts the oldest tokens silently when the prompt exceeds its
window (Ollama's default 4k `num_ctx`) drops the system prompt first. Compact logs a warning naming
both numbers, leaves the report out of the calibration, and sets `Compaction::$truncatedUpstream` on
that step's `last()` (with `removed` 0 when the step compacted nothing). Configure the server's
window and set `Options::$contextWindow` to it. Before there is a line, one report's difference may
belong to a long prompt, and a short one would report far under it without being cut, so nothing is
detected then.

## The budget floor

The budget must leave room above the fixed part of the prompt (system text, tool specs, the first
`$keepFirst` messages) for a summary and the recent half. Compact counts the fixed part at first use
and requires it to be at most half the budget, compared with the calibrated count once there is a
line (one report's difference, from a long restored conversation, says little about the
fixed part); otherwise the step throws a `\LogicException` naming the fixed size and the budget. A
budget that passes at first use can fail on a later step, once the provider's reports show the
counter undercounted. Upgrading from beta3: a budget tuned on bytes / 4 can fail the floor once the
calibration learns the real ratio, on a later step, not the first. The `\LogicException` ends the
turn `Failed` and is rethrown, in a sub-agent too.

## One instance, several conversations

Compact keeps the last 8 summaries, newest first, each under the list of messages it summarized:
the conversation's messages from the first up to the cut. A step reuses the entry whose list is the
longest prefix of its own messages, compared with `===`, so the same `Message` objects, as the run's
own list and a fork's copy of it hold; equal messages rebuilt from arrays do not match. While the
prompt fits the budget with it, the summary is reused, so a provider's prompt cache sees a stable
prefix; when the budget is exceeded again, a new round summarizes it with what follows.

So one instance may serve several conversations at once: the forks `Agents\AgentTool` runs share
the template's middleware, and each child gets its own summary, reused on its next step, not
recomputed in turn with the others. What it costs: beyond 8 conversations compacting at once, the
oldest summary is evicted and recomputed (one side call) when its conversation needs it again;
`last()` is the instance's latest step, of any conversation; the calibration, the budget and the
floor are the instance's, so share an instance only among conversations with the same model and
fixed part. A host that wants `last()` per child gives each template its own Compact. A host that
builds a Compact per request starts with no summaries, so each request over the budget writes its
summary again (one side call); persisting the calibration still saves it from starting uncalibrated.

## Seeing it

The core has no event for compaction. Each new summary is logged at `info` (messages removed,
summary size, budget, counter ratio and offset), and `last(): ?Compaction` tells what the last step
did: `removed`, `kept`, `summary`, `before` and `after` (calibrated tokens), `reused` (an earlier
summary served), `previousSummaryBytes` (the summary this one replaced, null for the first), `ratio`
and `offset` (the calibration), `finish` and `truncatedUpstream`. It is null when the step's prompt
went on unchanged and was not cut upstream. [`examples/compact.php`](../examples/compact.php)
prints both.
