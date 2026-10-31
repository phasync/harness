# Harness\Provider\Anthropic

`Messages` implements `Model` over Anthropic's Messages API, streamed.

```php
$model = new Messages(
    new StreamTransport(),
    apiKey: getenv('ANTHROPIC_API_KEY'),
    model: 'claude-opus-5',
    baseUrl: 'https://api.anthropic.com/v1',
    maxTokens: 16000,          // used when Options::$maxOutputTokens is null; the API requires one
    headers: [],               // e.g. ['anthropic-beta' => '...']
);
```

- SSE events `message_start`, `content_block_start|delta|stop`, `message_delta`, `message_stop`,
  `ping` and `error` are mapped; blocks `text`, `tool_use`, `thinking` and `redacted_thinking`.
- Thinking is opaque and model-bound: a run of consecutive `thinking`/`redacted_thinking` blocks
  becomes one Reasoning part whose `raw['blocks']` holds the blocks verbatim (with signatures; a block
  cut off before its signature arrived is dropped on replay, since the API rejects it). They
  are replayed unchanged to the same model and dropped for another.
- Consecutive user messages are merged into one turn, tool results first, as the API requires; two
  assistant messages in a row (the user message between them had nothing sendable), or a
  conversation opening with an assistant message, are an `UnsupportedContentException`.
  `ToolResult` maps to `tool_result` with `is_error`; images (PNG, JPEG, GIF, WebP) map to `image`,
  PDF and plain text to `document`, inline or by URL.
- `stop_reason`: `end_turn`/`stop_sequence` → Stop, `tool_use` → ToolCalls,
  `max_tokens`/`model_context_window_exceeded` → Length, `refusal` → Refusal.
- `Usage::$input` counts all input: `input_tokens` plus cache reads and writes, which are also given
  as `cacheRead` and `cacheWrite`.
- `toolChoice`: Auto → `auto`, None → `none`, Required → `any`, Tool → `tool` (some models reject the
  forced forms; the API's 400 then surfaces as a `ModelError`).
- `Options::$provider['anthropic']` (the key is the module's lowercase name) is merged into the request body last: `thinking`,
  `output_config`, `cache_control` (top-level automatic caching) and the rest go there.
- HTTP 429 and 529, `rate_limit_error` and `overloaded_error` → `RateLimited`; "prompt is too long" →
  `ContextTooLong`; a stream that ends before `message_stop` → `TransportError`. A 200 whose
  `Content-Type` is not `text/event-stream` (a JSON error body from a proxy) is read as an error
  body and mapped the same way; a stream line that is not JSON → `ModelError`.
- A `Prompt::$continue` (a `Resume`) is sent as a prefill: the assistant message goes last,
  without its reasoning and with the trailing whitespace of its last text cut, as the API requires,
  and the reply continues it. With `thinking` on the API takes no prefill: it throws
  `UnsupportedOptionException`, naming the fallback (append the edit as a finished assistant
  message and ask for the next one). A message that ends with a tool call throws
  `UnsupportedContentException`.

Not live-tested in this round (no key); the live test runs with `ANTHROPIC_API_KEY` set.
