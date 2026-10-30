# Harness\Provider\OpenAI

`ChatCompletions` implements `Model` over OpenAI Chat Completions, the API every local server speaks
(llama.cpp, vLLM, exl3, Ollama) as well as OpenAI.

```php
$model = new ChatCompletions(
    new StreamTransport(),
    baseUrl: 'http://127.0.0.1:8012/v1',      // up to, not including, /chat/completions
    model: 'qwen3.8-27b-exl3-sivert',
    apiKey: null,                              // sent as Authorization: Bearer
    headers: [],                               // extra request headers
);
```

- Streams with `stream: true` and `stream_options: {include_usage: true}`; usage that arrives after
  `finish_reason` is still reported.
- `delta.content` becomes text; `delta.reasoning_content` (local servers) or `delta.reasoning`
  becomes reasoning, stamped with the model id in `raw`, and is replayed as `reasoning_content` to the
  same model only.
- Tool-call deltas are matched by `index`; a server that omits the call id gets a generated one.
- `finish_reason`: `stop` → Stop, `tool_calls` → ToolCalls, `length` → Length, `content_filter` →
  Refusal; a missing or unknown one is a `ModelException`.
- Tool results become `role: tool` messages (an `error` result is prefixed `Error: `); images in a
  tool result follow in a user message. User images go as `image_url`, wav/mp3 as `input_audio`;
  other media throw `UnsupportedContentException`.
- `Options`: `maxOutputTokens` → `max_tokens`, `temperature`, `toolChoice` → `tool_choice`.
  `Options::$provider['openai']` is merged into the request body last, so it sets anything else
  (`max_completion_tokens`, `top_p`, `chat_template_kwargs`, ...). `Required` or `Tool` with no tools
  throws `UnsupportedOptionException`.
- HTTP 429 → `RateLimited` (with `retryAfter`), a context-length error → `ContextTooLong`, anything
  else → `ModelError`; an `error` object inside the stream → `ModelError`.

The Responses API is a later round.
