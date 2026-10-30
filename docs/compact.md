# Harness\Compact

`Compact` is a `ModelMiddleware` that keeps each step's prompt within a token budget by replacing
the oldest messages with a summary.

```php
$compact = new Compact($model, budgetTokens: 24000, log: $logger);   // count: Compact::approximate(...), keepFirst: 1
$run     = new Run($model, $tools, middleware: [$compact]);
```

`new Compact(Model $summarizer, int $budgetTokens, ?Closure $count = null, int $keepFirst = 1, ?LoggerInterface $log = null)`

## What it does

When `$count($prompt)` is over the budget:

- The system prompt, the tool specs and the first `$keepFirst` messages stay as they are (and a
  tool result message that answers the last of them).
- The oldest half of the remaining messages is replaced by one user message holding a summary,
  written by `$summarizer` in a side call with its own fixed prompt: what was asked, what was done,
  what is known, what remains. `Reasoning` parts are left out of what it reads.
- The cut moves back to a boundary that keeps every `ToolResult` with its `ToolCall` (forward over
  the pair when moving back leaves nothing to summarize).
- When the first kept message is a user message, an assistant acknowledgement follows the summary,
  so roles alternate from there on. The summary itself may follow a user message (the first one,
  with the default `keepFirst`); providers accept that (`Provider\Anthropic` merges the two).
- The rest goes on verbatim, and the prompt goes to `$next`. If it is still over the budget, the
  summary is summarized again together with the oldest half of what follows it, until it fits or
  nothing is left to summarize (logged as a warning).

Only the prompt changes. `Run::messages()`, `Ended::$appended` and a transcript keep every message,
so a host persists the whole conversation.

The cut and summary are remembered: while the conversation keeps the same prefix and fits the budget
with the summary, later steps reuse it unchanged, so a provider's prompt cache sees a stable prefix.
The summary is recomputed only when the budget is exceeded again.

The side call has no tools and the step's `maxOutputTokens` and provider options, and is cancelled
with the turn. A summarizer that returns no text (all its tokens spent on reasoning, say) fails the
step with a `RuntimeException`. Any `Model` can summarize: a smaller, cheaper one is the usual choice.

## Counting

`Compact::approximate(Prompt): int` is the default: bytes / 4 over the system text, text and
reasoning, tool names and inputs, tool results and tool specs, plus 1000 per media part, 4 per message
and 10 per tool spec. It undercounts against real tokenizers and chat templates (by about half on
Qwen behind llama.cpp), so set the budget well below the context window, or pass a counter that
uses the provider's tokenizer: `new Compact($model, 100000, fn (Prompt $p): int => $tokenizer->count($p))`.

## Seeing it

The core has no event for compaction. Each new summary is logged at `info` (messages removed,
summary size), and `last(): ?Compaction` tells what the last step did: `removed`, `kept`,
`summary`, `before` and `after` (tokens by the counter), and `reused` (an earlier summary served).
It is null when the step's prompt went on unchanged. [`examples/compact.php`](../examples/compact.php)
prints both.
