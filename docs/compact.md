# Harness\Compact

`Compact` is a `ModelMiddleware` that keeps each step's prompt within a token budget by replacing
the oldest messages with a summary.

```php
$compact = new Compact($summarizer, budgetTokens: 24000, log: $logger,
    summarizerOptions: new Options(maxOutputTokens: 1024, provider: ['openai' => ['chat_template_kwargs' => ['enable_thinking' => false]]]));
$run = new Run($model, $tools, middleware: [$compact]);
```

```php
new Compact(Model $summarizer, int $budgetTokens, ?Closure $count = null, int $keepFirst = 1,
            ?LoggerInterface $log = null, Options $summarizerOptions = new Options(maxOutputTokens: 1024))
```

## What it does

When the prompt counts over the budget:

- The system prompt, the tool specs and the first `$keepFirst` messages stay as they are (and a
  tool result message that answers the last of them).
- The oldest half of the remaining messages is replaced by one user message holding a summary,
  written by `$summarizer` in a side call with its own fixed prompt: what was asked, what was done,
  what is known, what remains. The summarizer sees the kept first messages as context (the task)
  and the part to summarize; `Reasoning` parts are left out.
- The cut moves back to a boundary that keeps every `ToolResult` with its `ToolCall` (forward over
  the pair when moving back leaves nothing to summarize).
- When the first kept message is a user message, an assistant acknowledgement follows the summary,
  so roles alternate from there on. The summary itself may follow a user message (the first one,
  with the default `keepFirst`); providers accept that (`Provider\Anthropic` merges the two).
- The rest goes on verbatim, and the prompt goes to `$next`. If it is still over the budget, the
  summary is summarized again together with the oldest half of what follows it, until it fits or
  nothing is left to summarize (logged as a warning: one step's tool results alone are over the
  budget, say).

Only the prompt changes. `Run::messages()`, `Ended::$appended` and a transcript keep every message,
so a host persists the whole conversation.

A summary is reused while the conversation keeps the same prefix and fits the budget with it, so a
provider's prompt cache sees a stable prefix. It is recomputed only when the budget is exceeded again.

## The side call

The side call has no tools, `$summarizerOptions` (never the step's options) and the turn's `Cancel`.
Set the summarizer's provider options there: a thinking model that spends its output tokens on
reasoning returns no summary, so turn thinking off (`chat_template_kwargs` on llama.cpp and vLLM)
or give it more tokens. Any `Model` can summarize; a smaller, cheaper one is the usual choice.

A summary that comes back empty, or shorter than a quarter of the earlier summary it replaces, is a
collapse, not a summary: the agent would go on without what it knew. It is asked for once more with
the same prompt (logged as a warning), and a second collapse fails the step with a
`RuntimeException` naming both sizes.

## Counting

`Compact::approximate(Prompt): int` is the default counter: bytes / 4 over the system text, text and
reasoning, tool names and inputs, tool results and tool specs, plus 1000 per media part, 4 per message
and 10 per tool spec. Another counter is a closure:
`new Compact($model, 100000, fn (Prompt $p): int => $tokenizer->count($p))`.

Compact calibrates whichever counter it has. After each step it passes on, it divides the input
tokens the provider reported for that step (its `Usage`) by its own count of the prompt it handed
on, and multiplies later counts by that ratio; the last ratio wins, and a step that reports no input
tokens leaves it. Bytes / 4 undercounts a chat template (by about half on Qwen behind llama.cpp, more
with many tool specs); after one step the count is the provider's. The budget is in the provider's
tokens: set it below the context window by the output tokens a step needs.

## The budget floor

The budget must leave room above the fixed part of the prompt (system text, tool specs, the first
`$keepFirst` messages) for a summary and the recent half. Compact counts the fixed part at first use
and requires it to be at most half the budget, compared with the calibrated count; otherwise the
step throws a `\LogicException` naming the fixed size and the budget. A budget that passes at first
use can fail on a later step, once the provider's report shows the counter undercounted.

## One instance, several conversations

Compact keeps the last 8 summaries, each under the messages it summarized (the same `Message`
objects, as a fork's copy of the messages holds), so one instance may serve several conversations
at once: the forks `Agents\AgentTool` runs share the template's middleware, and each child gets its
own summary, reused on its next step, not recomputed in turn with the others. What it costs: beyond
8 conversations compacting at once, the oldest summary is evicted and recomputed (one side call)
when its conversation needs it again; `last()` is the instance's latest step, of any conversation;
the ratio and the floor are the instance's, so share an instance only among conversations with the
same model and fixed part. A host that wants `last()` per child gives each template its own Compact.

## Seeing it

The core has no event for compaction. Each new summary is logged at `info` (messages removed,
summary size, budget, counter ratio), and `last(): ?Compaction` tells what the last step did:
`removed`, `kept`, `summary`, `before` and `after` (calibrated tokens), `reused` (an earlier
summary served), `previousSummaryBytes` (the summary this one replaced, null for the first) and
`ratio`. It is null when the step's prompt went on unchanged.
[`examples/compact.php`](../examples/compact.php) prints both.
