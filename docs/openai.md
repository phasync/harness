# Harness\Provider\OpenAI

`ChatCompletions` implements `Model` over OpenAI Chat Completions, the API every local server speaks
(llama.cpp, vLLM, exl3, Ollama) as well as OpenAI.

```php
$model = new ChatCompletions(
    new StreamTransport(),
    baseUrl: 'http://127.0.0.1:8012/v1',      // up to, not including, /chat/completions
    model: 'qwen3.8',
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
  `Options::$provider['openai']` (the key is the module's lowercase name) is merged into the request body last, so it sets anything else
  (`max_completion_tokens`, `top_p`, `chat_template_kwargs`, ...). `Required` or `Tool` with no tools,
  `Tool` naming a tool not in the prompt, and a `temperature` outside 0..2 throw
  `UnsupportedOptionException` before any request.
- HTTP 429 or an error code `rate_limit_exceeded`/`insufficient_quota` (whatever the status) →
  `RateLimited` (with `retryAfter`), a context-length error → `ContextTooLong`, anything else →
  `ModelError`; an `error` object inside the stream → `ModelError`.
- A `Prompt::$continue` (a `Resume`) sends the assistant message to continue last, its reasoning
  as `reasoning_content` for the same model. llama.cpp and exl3 continue it as they are; vLLM
  needs `Options::$provider['openai']` set to `['continue_final_message' => true,
  'add_generation_prompt' => false]` for the resumed turn. llama.cpp (tested with a Qwen thinking
  model) streams only the continuation and does not reason again. A server that does not continue
  starts a new reply after the edit instead; nothing detects that.

## Responses

`Responses` implements `Model` over the Responses API (`POST <baseUrl>/responses`).

```php
$model = new Responses(new StreamTransport(), 'https://api.openai.com/v1', 'gpt-5-mini', $apiKey,
    headers: [], strict: true, store: false);
```

- Input items: user messages (`input_text`, `input_image`, PDFs as `input_file`), assistant text as a
  `message`, tool calls as `function_call` and results as `function_call_output`, paired by
  `call_id` (an `error` result is prefixed `Error: `; images in a result follow in a user message).
  The system text is `instructions`.
- Reasoning: summary and reasoning text become reasoning, and each `reasoning` item is kept in
  `raw` with the model id. With `store: false` (the default) the request asks for
  `reasoning.encrypted_content` and replays items by it; with `store: true` by id. Only to the same model.
- Events: `response.output_text.delta` → text, `response.function_call_arguments.delta/.done` →
  tool input (the call starts at `response.output_item.added`), `response.completed` → usage and
  Stop or ToolCalls, `response.incomplete` → Length (`max_output_tokens`) or Refusal
  (`content_filter`), a refusal → Refusal, `response.failed` and `error` → `ModelException`. A stream
  that ends without `response.completed` is a `ModelError`.
- Strict schemas, attempted by default: a schema that can be made strict is sent with
  `strict: true`, every object closed with all properties required and the optional ones nullable.
  Such a tool's arguments arrive in one `ToolInputDelta` when the call is done, with the nulls of
  optional properties removed, so the tool sees what its own schema says. Free-form objects, `{}`,
  `oneOf`, `allOf`, `not`, `if` and pattern properties keep a schema non-strict. `strict: false`
  turns it off.
- `Options`: `maxOutputTokens` → `max_output_tokens`, `temperature`, `toolChoice` → `tool_choice`;
  `Options::$provider['openai']` is merged into the body last (`reasoning`, `text`, `truncation`, ...).
- HTTP 429 or `rate_limit_exceeded` → `RateLimited` (with `retryAfter`), `context_length_exceeded`
  → `ContextTooLong`, anything else → `ModelError`.
- A `Prompt::$continue` throws `UnsupportedOptionException`: the API cannot continue an assistant
  message. The host may choose the fallback itself: append the edit as a finished assistant message
  and ask for the next one.
