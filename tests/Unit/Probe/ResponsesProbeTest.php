<?php

declare(strict_types=1);

use Harness\Http\Response;
use Harness\Message;
use Harness\Options;
use Harness\Part\Media;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Provider\OpenAI\Responses;
use Harness\Role;
use Harness\Testing\FakeTransport;
use Harness\Testing\TestPrompt;
use Harness\ToolChoice;
use Harness\ToolSpec;

/** A Responses event stream: `event:` and `data:` per event. */
function sseEvents(array ...$events): Response
{
    $body = '';
    foreach ($events as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }

    return new Response(200, ['content-type' => 'text/event-stream'], \str_split($body, 23));
}

it('formats a Reference in a tool result as a markdown link in the request body', function () {
    $t = new FakeTransport(sseEvents(
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => []]],
        ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Done'],
        ['type' => 'response.completed', 'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0]]]],
    ));

    $messages = [
        user('help'),
        new Message(Role::Assistant, [new ToolCall('call_1', 'find', [])]),
        new Message(Role::User, [new ToolResult('call_1', [new Reference('https://example.com', 'Link Title')], false)]),
    ];

    [...(new Responses($t, 'https://api.example/v1/', 'gpt-x', 'sk-1'))->stream(
        TestPrompt::make(null, $messages, [new ToolSpec('find', 'Find', ['type' => 'object'])])
    )];

    $body   = \json_decode($t->requests[0]['body'], true);
    $output = \array_values(\array_filter($body['input'], fn ($item) => 'function_call_output' === $item['type']));

    expect($output)->toHaveCount(1)
        ->and($output[0]['output'])->toBe('[Link Title](https://example.com)');
});

it('sends tool_choice with specific tool name when ToolChoice::Tool is set', function () {
    // Test tool_choice mapping for ToolChoice::Tool (line 199: {type: 'function', name: $tool})
    // Checked against ResponsesTest line 96: "builds the request: instructions, input items paired by call_id..."
    $t = new FakeTransport(sseEvents(
        ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => []]],
        ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Using read tool'],
        ['type' => 'response.completed', 'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0]]]],
    ));

    $options  = new Options(toolChoice: ToolChoice::Tool, tool: 'read');
    $messages = [user('read the file')];

    [...(new Responses($t, 'https://api.example/v1/', 'gpt-x', 'sk-1'))->stream(
        new Prompt(null, $messages, [
            new ToolSpec('read', 'Read file', ['type' => 'object']),
            new ToolSpec('write', 'Write file', ['type' => 'object']),
        ], $options, live())
    )];

    // Verify request body contains tool_choice with specific tool name
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body['tool_choice'])->toBe(['type' => 'function', 'name' => 'read']);
});

it('sends a user message with Media as input_image with data URL in request body', function () {
    // Test Media in user message: image/png bytes → input_image with data: URL (line 245: $this->media($part))
    // Checked against ResponsesTest line 96: "builds the request..." which tests Media in tool result
    $t = new FakeTransport(sseEvents(
        ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => []]],
        ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Image received'],
        ['type' => 'response.completed', 'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 15, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0]]]],
    ));

    $imageBytesText = 'PNG\x89\x50\x4E\x47';  // PNG image bytes
    $messages       = [
        new Message(Role::User, [
            new Text('Check this image:'),
            new Media('image/png', $imageBytesText),
        ]),
    ];

    [...(new Responses($t, 'https://api.example/v1/', 'gpt-x', 'sk-1'))->stream(
        TestPrompt::make(null, $messages)
    )];

    // Verify request body contains input_image with data: URL
    $body  = \json_decode($t->requests[0]['body'], true);
    $input = $body['input'];

    // Find the user message with content array
    $userMessage = \array_values(\array_filter($input, fn ($item) => isset($item['type'], $item['role']) && 'message' === $item['type'] && 'user' === $item['role']))[0] ?? null;

    expect($userMessage)->not->toBeNull()
        ->and($userMessage['content'])->toHaveCount(2)
        ->and($userMessage['content'][0])->toBe(['type' => 'input_text', 'text' => 'Check this image:'])
        ->and($userMessage['content'][1]['type'])->toBe('input_image')
        ->and($userMessage['content'][1]['image_url'])->toStartWith('data:image/png;base64,');
});

it('sends a user message with PDF Media as input_file with data URL in request body', function () {
    // Test PDF Media with bytes in user message: application/pdf → input_file with filename + file_data (line 299-300: $m->uri null case)
    // Checked against ResponsesTest line 96: "builds the request..." (no PDF tests exist there)
    $t = new FakeTransport(sseEvents(
        ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => []]],
        ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'PDF processed'],
        ['type' => 'response.completed', 'response' => ['status' => 'completed', 'usage' => ['input_tokens' => 20, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0]]]],
    ));

    $pdfBytesText = '%PDF-1.4' . "\x0A" . '1 0 obj';  // PDF file bytes
    $messages     = [
        new Message(Role::User, [
            new Text('Please analyze this PDF:'),
            new Media('application/pdf', $pdfBytesText),
        ]),
    ];

    [...(new Responses($t, 'https://api.example/v1/', 'gpt-x', 'sk-1'))->stream(
        TestPrompt::make(null, $messages)
    )];

    // Verify request body contains input_file with filename and file_data (base64 data URL)
    $body  = \json_decode($t->requests[0]['body'], true);
    $input = $body['input'];

    // Find the user message with content array
    $userMessage = \array_values(\array_filter($input, fn ($item) => isset($item['type'], $item['role']) && 'message' === $item['type'] && 'user' === $item['role']))[0] ?? null;

    expect($userMessage)->not->toBeNull()
        ->and($userMessage['content'])->toHaveCount(2)
        ->and($userMessage['content'][0])->toBe(['type' => 'input_text', 'text' => 'Please analyze this PDF:'])
        ->and($userMessage['content'][1]['type'])->toBe('input_file')
        ->and($userMessage['content'][1]['filename'])->toBe('document.pdf')
        ->and($userMessage['content'][1]['file_data'])->toStartWith('data:application/pdf;base64,');
});
