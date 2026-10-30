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
  destructive. `readOnlyHint: true` forces `destructive` false, whatever `destructiveHint` says.
- Content: `text` → `Text`; `image`, `audio` → `Media`; `resource` → `Text` (text) or `Media` (blob);
  `resource_link` → `Reference`. `structuredContent` is the result's `value` (and its text when there
  is no content). `isError` → an error result. A JSON-RPC error throws `McpException`, which the model
  sees as an error result. Images go to the model as the provider allows; Chat Completions moves
  images in a tool result to a following user message ([openai.md](openai.md)).
- `notifications/progress` for the call become `Call::progress()` (`ToolProgress` events).
- A tool whose `x-mcp-header` annotations are invalid is left out; the valid ones are mirrored into
  `Mcp-Param-*` headers over HTTP.

## Input required

A result with `resultType: "input_required"` ends the call with `Call::needsInput()`, so the turn
ends `AwaitingInput` and the host asks the user, now or in a later request:

- each form elicitation (`elicitation/create`) with fields becomes one `Question` per field, id
  `<request key>/<field>`, with the field's `enum` (or `true`/`false` for a boolean) as choices;
- a form without fields (a confirmation) becomes an `Approval` with id `<request key>` and the
  message as its reason;
- the state holds the input requests and the opaque `requestState`.

On resume the call is made again with `inputResponses` built from the answers (numbers and booleans
converted by the field's type) and the same `requestState`. A request is `accept`ed, or `decline`d
when the host answered its approval or any of its questions with `Decision::deny()`. An
`input_required` with only a `requestState` is retried at once. Sampling and roots requests are not
supported (the client does not declare them; both are deprecated), and a result without
`resultType` is `complete`.

## Writing a server

A tool that needs the user answers `tools/call` with `input_required`; the client asks and calls the
tool again with the answers. One round, one question (`_meta` left out of the requests):

```json
{"jsonrpc": "2.0", "id": 7, "result": {
  "resultType": "input_required",
  "inputRequests": {"prefs": {"method": "elicitation/create", "params": {
    "mode": "form", "message": "Your preferences",
    "requestedSchema": {"type": "object", "properties": {"colour": {"type": "string", "title": "Colour", "enum": ["red", "blue"]}}, "required": ["colour"]}}}},
  "requestState": "eyJhc2tlZCI6InByZWZzIn0="}}
```

The harness asks `Question('prefs/colour', 'Your preferences (Colour)', ['red', 'blue'])`, and retries:

```json
{"jsonrpc": "2.0", "id": 8, "method": "tools/call", "params": {
  "name": "ask", "arguments": {},
  "inputResponses": {"prefs": {"action": "accept", "content": {"colour": "blue"}}},
  "requestState": "eyJhc2tlZCI6InByZWZzIn0="}}
```

Two rounds: a name, then a confirmation. Each retry carries `inputResponses` for the latest round's
`inputRequests` only, so the server keeps earlier answers in `requestState` (opaque to the client;
encode, and sign or encrypt it if the client must not alter it):

```json
{"resultType": "input_required",
 "inputRequests": {"user": {"method": "elicitation/create", "params": {"mode": "form", "message": "New user",
   "requestedSchema": {"type": "object", "properties": {"name": {"type": "string", "title": "Name"}}, "required": ["name"]}}}},
 "requestState": "eyJyb3VuZCI6MX0="}

{"name": "create_user", "arguments": {},
 "inputResponses": {"user": {"action": "accept", "content": {"name": "Ada"}}},
 "requestState": "eyJyb3VuZCI6MX0="}

{"resultType": "input_required",
 "inputRequests": {"confirm": {"method": "elicitation/create", "params": {"mode": "form", "message": "Create the user Ada?",
   "requestedSchema": {"type": "object", "properties": {}}}}},
 "requestState": "eyJyb3VuZCI6MiwibmFtZSI6IkFkYSJ9"}

{"name": "create_user", "arguments": {},
 "inputResponses": {"confirm": {"action": "accept"}},
 "requestState": "eyJyb3VuZCI6MiwibmFtZSI6IkFkYSJ9"}

{"resultType": "complete", "content": [{"type": "text", "text": "Created Ada"}]}
```

The second round is an `Approval` with id `confirm` on the host's side. A host that answers it with
`Decision::deny('not now')` (or a question of the form with a denial) sends
`{"confirm": {"action": "decline"}}`; the server then answers as it sees fit, usually a `complete`
result saying nothing was done. `tests/Support/mcp-server.php` is a whole server in plain PHP.

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
