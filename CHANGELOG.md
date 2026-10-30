# Changelog

## 0.0.1-beta1

First beta, implementing SPEC.md revision 1.

- Core: content parts (`Text`, `Reasoning`, `ToolCall`, `ToolResult`, `Media`, `Reference`) and
  `Message` with one `toArray()`/`fromArray()` serialization; `Model`, `Prompt`, `Options`, deltas and
  the `Accumulator`; `Tool`, `ToolSpec`, `Hints`, `Result`, `ToolSource`, `Tools`, `ClosureTool`,
  `Call`, `Retry`.
- `Run`: the tool loop with typed events, stop predicates (`Stop::maxSteps`, `toolCalled`, `tokens`),
  cancellation, read-only calls of a step run concurrently and the others one at a time,
  `ModelMiddleware` and `ToolMiddleware`, failure kinds, and pause and resume (`Pending`, `Approval`,
  `Question`, `Answer`, `Decision`) that survives a process boundary.
- `Http`: `StreamTransport` (HTTP/1.1 over non-blocking sockets, waits through fiber-interop) and `Sse`.
- Modules: `Provider\OpenAI\ChatCompletions`, `Provider\Anthropic\Messages`, `Tools\Files`,
  `Tools\Shell`, `Agents\AgentTool`, `Testing\FakeModel` and `FakeTransport`.
- Examples: `chat.php`, `files.php`, `shell.php`, `subagent.php`.
- Tests: the unit suite runs under the phasync and the Revolt adapter; live tests against a Chat
  Completions endpoint.
