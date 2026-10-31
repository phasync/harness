<?php

declare(strict_types=1);

use Harness\Http\Response;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Provider\Anthropic\Messages;
use Harness\Role;
use Harness\Testing\FakeTransport;
use Harness\ToolSpec;

/*
 * Mcp\McpTool::result() builds a `Result` straight from `content ?? []` and `structuredContent ??
 * null` (src/Mcp/McpTool.php): a `tools/call` reply with `isError: true` and neither `content` nor
 * `structuredContent` (one of the adversarial threats this module's charter names explicitly: "a
 * tool result with isError and no content") legitimately yields `Result([], null, error: true)`.
 * `Run::toolResult()` (src/Run.php:771) passes `$result->content` straight into `ToolResult`
 * unchanged, so an MCP tool failing this way produces `ToolResult($id, [], true)`.
 *
 * Chat Completions and the Responses API both guard this case: `($part->error ? 'Error: ' : '') .
 * implode("\n", $text)` always yields a non-empty string, even when `$text` is empty, because of
 * the 'Error: ' prefix (src/Provider/OpenAI/ChatCompletions.php and .../Responses.php). Anthropic's
 * `Messages::user()` has no such guard: `'content' => array_map($this->block(...), $part->content)`
 * maps an empty `$part->content` straight to an empty array, so the request sent to the Messages
 * API carries `{"type":"tool_result","tool_use_id":"...","content":[],"is_error":true}`. The
 * Messages API rejects a tool_result block whose content is an empty list (it requires at least one
 * block), so this turns a tool failure the host already observed as an *error result* into a 400
 * from the provider instead -- the one path through this module that is documented to carry an
 * error to the model (docs/anthropic.md: "ToolResult maps to tool_result with is_error") cannot
 * actually reach the model when the tool contributed no content, which happens whenever an MCP
 * server reports isError without content (no made-up server needed: a server that fails before
 * producing any output, e.g. a precondition check, has nothing else to put in `content`).
 *
 * Verified directly against the real code (both adapters): the Anthropic request body's tool_result
 * block has "content": [] when ToolResult content is empty, while Chat Completions' tool message
 * for the identical ToolResult always has non-empty content.
 */
it('sends a non-empty tool_result content block for a ToolResult with no content', function () {
    $body = '';
    foreach ([
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
        ['type' => 'message_stop'],
    ] as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }
    $transport = new FakeTransport(new Response(200, [], \str_split($body, 17)));
    $messages  = [
        new Message(Role::User, [new Text('run it')]),
        new Message(Role::Assistant, [new ToolCall('t1', 'broken', [])]),
        // What Mcp\McpTool::result() builds for a `tools/call` reply {"isError": true} with
        // neither "content" nor "structuredContent": Result([], null, error: true), carried into
        // ToolResult unchanged by Run::toolResult().
        new Message(Role::User, [new ToolResult('t1', [], true)]),
    ];
    $model = new Messages($transport, 'key', 'claude-x');
    [...$model->stream(new Prompt(null, $messages, [new ToolSpec('broken', 'd', ['type' => 'object'])], new Options(), live()))];

    $sent  = \json_decode($transport->requests[0]['body'], true);
    $block = $sent['messages'][2]['content'][0];
    // #14: this sent "content": [], which the Messages API rejects
    expect($block['type'])->toBe('tool_result')
        ->and($block['content'])->toBe([['type' => 'text', 'text' => 'Error']]);
});
