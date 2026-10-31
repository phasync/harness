# Harness\Acp

`Agent` makes a `Run` an [Agent Client Protocol](https://agentclientprotocol.com) (v1) agent: an
editor starts the agent's executable and speaks newline-delimited JSON-RPC to it over stdio.
[`examples/acp.php`](../examples/acp.php) is one.

```php
$agent = new Agent(fn (string $cwd): Run => new Run($model, $toolsFor($cwd), middleware: [$policy]),
                   name: 'my-agent', version: '1.0.0', sessionDir: '/var/lib/my-agent/sessions');
fiber\run(fn () => $agent->serve());          // STDIN and STDOUT until the editor closes them
```

The closure builds the `Run` of each new session from its working directory (an absolute path).
Nothing else may write to STDOUT: log to STDERR or a file.

## Methods

| Method | |
| --- | --- |
| `initialize` | protocol version 1; `loadSession` when there is a `sessionDir`; prompts take text, resource links and embedded text resources |
| `authenticate` | accepted; there are no auth methods |
| `session/new` | a session in `cwd`; its `mcpServers` are not connected, the run's own tools run |
| `session/load` | with a `sessionDir`: the run is restored from the session's transcript (`<sessionDir>/<id>.jsonl`), and the conversation is replayed as `user_message_chunk`, `agent_message_chunk`, `agent_thought_chunk`, `tool_call` and `tool_call_update` |
| `session/prompt` | runs turns until the run ends other than `AwaitingInput`, then answers `stopReason` |
| `session/cancel` | cancels the running prompt: `stopReason` `cancelled` |

A second prompt while one runs, an unknown session, a relative `cwd`, an image or audio block, and
an unknown method are JSON-RPC errors. A failed turn is a JSON-RPC error (`-32603`) naming the
`ErrorKind` and the message.

## Events

| Event | `session/update` |
| --- | --- |
| `TextDelta` | `agent_message_chunk` |
| `ReasoningDelta` | `agent_thought_chunk` |
| `ToolCallReady` | `tool_call`, `status: pending`: a title (the name and the `command` or `path`), `kind` from the tool's `Hints` (read-only: `read`, or `fetch` with `openWorld`; otherwise `execute` with `openWorld`, else `edit`), `rawInput`, the file in `locations`, and for `edit_file` and `write_file` a `diff` (the edit's `old` and `new`; the file's content and the new one) |
| `MessageEnded` | `tool_call_update` `in_progress` for each of its calls |
| `ToolProgress` with a string (`shell` output) | `tool_call_update` with the output so far as text content |
| `ToolEnded` | `tool_call_update` `completed` or `failed`, with the diff and the result's text |

A sub-agent's tool calls appear as their own `tool_call`s under the id `<parent call id>/<id>`; its
text stays inside its result, which ends the parent's call. Paths in `locations` and diffs are the
input's path under the session's `cwd`.

`stopReason` follows the turn: `Done` is `end_turn`, or `max_tokens` with finish `Length` and
`refusal` with finish `Refusal`; `Stopped` is `max_turn_requests`; `Cancelled` and `Interrupted`
are `cancelled`.

## Permissions

A turn that ends `AwaitingInput` does not end the prompt. Each `Approval` of each pending call (a
`Policy` `Ask`, and a sub-agent's, whose needs come up flattened under the parent's call) becomes a
`session/request_permission` for that call, titled with the call and the approval's reason, with the options
`allow_once`, `allow_always`, `reject_once` and `reject_always`. The "always" options are kept for
the session by tool name: the next approval for that tool is answered without asking. A
rejection reaches the model as `Denied: <reason>`. The answers resume the run, and the prompt goes
on. An outcome `cancelled` denies the rest and cancels the prompt. A `Question` is declined with
`Decision::deny()`, since ACP has no way to ask one.

## A client-side exchange

A minimal client, one request at a time (newline-delimited JSON-RPC over the agent's stdio):

```
--> {"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":1,"clientCapabilities":{}}}
<-- {"jsonrpc":"2.0","id":1,"result":{"protocolVersion":1,"agentCapabilities":{"loadSession":true},"authMethods":[]}}

--> {"jsonrpc":"2.0","id":2,"method":"session/new","params":{"cwd":"/abs/project","mcpServers":[]}}
<-- {"jsonrpc":"2.0","id":2,"result":{"sessionId":"sess_..."}}

--> {"jsonrpc":"2.0","id":3,"method":"session/prompt","params":{"sessionId":"sess_...","prompt":[{"type":"text","text":"delete the build directory"}]}}
<-- {"jsonrpc":"2.0","method":"session/update","params":{"sessionId":"sess_...","update":{"sessionUpdate":"tool_call","toolCallId":"c1","kind":"execute",...}}}
<-- {"jsonrpc":"2.0","id":4,"method":"session/request_permission","params":{"sessionId":"sess_...","toolCall":{"toolCallId":"c1"},"options":[{"optionId":"allow_once","kind":"allow_once"},...]}}
--> {"jsonrpc":"2.0","id":4,"result":{"outcome":{"outcome":"selected","optionId":"allow_once"}}}
<-- {"jsonrpc":"2.0","id":3,"result":{"stopReason":"end_turn"}}
```

`session/new`'s `mcpServers` is required by the protocol even when empty: this agent accepts the
field but never connects to the servers it names (see Not used), so `[]` and a populated list
behave the same.

## Not used

The client's `fs/*` and `terminal/*` methods: the harness's own tools read, write and run commands
in the agent's process, so `shell` output is text content, not a `terminal` (which would need the
client's terminal). Session modes, plans and slash commands are not sent. A session's `mcpServers`
stay unconnected in 0.1: the agent's own tools run instead.

## Testing

`tests/Support/AcpClient.php` is a JSON-RPC client over pipes that starts an agent executable,
collects its updates and answers its permission requests; `tests/Unit/AcpTest.php` drives
`tests/Support/acp-agent.php`, an agent over `Testing\FakeModel` scripts.
