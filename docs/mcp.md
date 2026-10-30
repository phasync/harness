# Harness\Mcp

An MCP server's tools as a `ToolSource`, under protocol revision 2026-07-28 (stateless, no
`initialize`).

```php
$fs   = new McpServer(new StdioConnection(['npx', '-y', 'some-mcp-server', '/srv/data']), prefix: 'fs_');
$web  = new McpServer(new HttpConnection(new StreamTransport(), 'https://mcp.example.com/mcp', ['Authorization' => "Bearer $token"]));
$run  = new Run($model, $fs, system: 'Be brief. ' . $fs->instructions());
```

To use several servers, or a server's tools next to your own, write a `ToolSource` that yields from
each.

## Requests

Every request carries `_meta` with `io.modelcontextprotocol/protocolVersion` (`2026-07-28`),
`clientInfo` and `clientCapabilities` (form elicitation only). The first use calls `server/discover`
and throws `McpException` unless the server supports this revision. `tools/list` (every page) is
cached for its `ttlMs`; the run reads the tools each step, so a list with `ttlMs: 0` is fetched each
step and a server whose tools change shows the change once its TTL lapses.

## Tools

- Names: `$prefix` plus the server's name, with characters a `ToolSpec` cannot hold as `_` (`files.read`
  becomes `files_read`); the call uses the server's own name.
- `annotations` → `Hints`, with MCP's defaults: `destructiveHint` and `openWorldHint` are true unless
  the server says otherwise, so a policy keyed on `destructive` treats an unannotated tool as
  destructive.
- Content: `text` → `Text`; `image`, `audio` → `Media`; `resource` → `Text` (text) or `Media` (blob);
  `resource_link` → `Reference`. `structuredContent` is the result's `value` (and its text when there
  is no content). `isError` → an error result. A JSON-RPC error throws `McpException`, which the model
  sees as an error result.
- `notifications/progress` for the call become `Call::progress()` (`ToolProgress` events).
- A tool whose `x-mcp-header` annotations are invalid is left out; the valid ones are mirrored into
  `Mcp-Param-*` headers over HTTP.

## Input required

A result with `resultType: "input_required"` ends the call with `Call::needsInput()`, so the turn
ends `AwaitingInput` and the host asks the user, now or in a later request:

- each form elicitation (`elicitation/create`) becomes one `Question` per field of its schema, id
  `<request key>/<field>`, with the field's `enum` (or `true`/`false` for a boolean) as choices; a
  schema without fields becomes one question `<request key>` with choices `accept`/`decline`;
- the state holds the input requests and the opaque `requestState`.

On resume the call is made again with `inputResponses` built from the answers (numbers and booleans
converted by the field's type) and the same `requestState`. An `input_required` with only a
`requestState` is retried at once. Sampling and roots requests are not supported (the client does not
declare them; both are deprecated), and a result without `resultType` is `complete`.

## Connections

`StdioConnection(list<string> $command, ?string $cwd = null, ?array $env = null)` starts the server
at the first request with `proc_open` (no shell; its stderr is yours) and terminates it when the
connection is destroyed. Messages are newline-delimited JSON-RPC. Concurrent calls share the process:
one waiting call reads for all and hands each response to its caller. A cancelled turn sends
`notifications/cancelled` and stops waiting within 250 ms. A server that exits fails every request
with its exit status.

`HttpConnection(Transport $transport, string $url, array $headers = [])` posts each request with
`MCP-Protocol-Version`, `Mcp-Method` and, for `tools/call`, `Mcp-Name` (base64-wrapped when it is not
plain ASCII), and reads an `application/json` or `text/event-stream` response. Cancellation is the
transport's.

`subscriptions/listen` (change notifications) is not implemented; the TTL cache stands in for it.
