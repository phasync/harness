<?php

declare(strict_types=1);

use Harness\Delta\Finish;
use Harness\FinishReason;
use Harness\Http\Response;
use Harness\Message;
use Harness\Options;
use Harness\Part\Media;
use Harness\Part\Reasoning;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Provider\Anthropic\Messages as AnthropicMessages;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Role;
use Harness\Testing\FakeTransport;
use Harness\ToolChoice;
use Harness\ToolSpec;
use Harness\Usage;

it('maps a user message with a reference part that has a title', function () {
    $chunk    = \json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);
    $t        = new FakeTransport(new Response(200, ['content-type' => 'text/event-stream'], ["data: $chunk\n\ndata: [DONE]\n\n"]));
    $messages = [user('hello'), new Message(Role::User, [new Reference('https://example.com/doc', 'Example Doc'), new Text('see this')])];
    [...(new ChatCompletions($t, 'http://h/v1', 'm'))->stream(new Prompt(null, $messages, [], new Options(), live()))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body['messages'])->toBe([
        ['role' => 'user', 'content' => 'hello'],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => '[Example Doc](https://example.com/doc)'], ['type' => 'text', 'text' => 'see this']]],
    ]);
});

it('maps Anthropic message_delta with stop_reason max_tokens to FinishReason::Length', function () {
    $body = "event: message_start\ndata: " . \json_encode(['message' => ['id' => 'm', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]]) . "\n\n"
        . "event: content_block_start\ndata: " . \json_encode(['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n"
        . "event: content_block_delta\ndata: " . \json_encode(['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Token limit']]) . "\n\n"
        . "event: message_delta\ndata: " . \json_encode(['delta' => ['stop_reason' => 'max_tokens'], 'usage' => ['output_tokens' => 20]]) . "\n\n"
        . "event: message_stop\ndata: " . \json_encode(['type' => 'message_stop']) . "\n\n";
    $t      = new FakeTransport(new Response(200, [], \str_split($body, 23)));
    $deltas = [...(new AnthropicMessages($t, 'key', 'claude-x'))->stream(new Prompt(null, [user('test')], [], new Options(), live()))];
    $last   = \array_slice($deltas, -2);
    expect($last[0])->toBeInstanceOf(Usage::class)
        ->and($last[0]->input)->toBe(10)
        ->and($last[0]->output)->toBe(20)
        ->and($last[1])->toBeInstanceOf(Finish::class)
        ->and($last[1]->reason)->toBe(FinishReason::Length);
});

it('sends Anthropic tool result with Media (image) in request body', function () {
    $respBody = "event: message_start\ndata: " . \json_encode(['message' => ['id' => 'm', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]]) . "\n\nevent: message_delta\ndata: " . \json_encode(['delta' => ['stop_reason' => 'end_turn']]) . "\n\nevent: message_stop\ndata: " . \json_encode(['type' => 'message_stop']) . "\n\n";
    $t        = new FakeTransport(new Response(200, [], \str_split($respBody, 23)));
    $msgs     = [
        user('help'),
        new Message(Role::Assistant, [new Text('I need an image'), new ToolCall('tu1', 'get_image', [])]),
        new Message(Role::User, [new ToolResult('tu1', [new Media('image/png', 'PNG')], false)]),
    ];
    [...(new AnthropicMessages($t, 'key', 'claude-x'))->stream(new Prompt(null, $msgs, [new ToolSpec('get_image', 'Get', ['type' => 'object'])], new Options(), live()))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body['messages'][2])->toBe([
        'role'    => 'user',
        'content' => [[
            'type'        => 'tool_result',
            'tool_use_id' => 'tu1',
            'content'     => [[
                'type'   => 'image',
                'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => \base64_encode('PNG')],
            ]],
            'is_error' => false,
        ]],
    ]);
});

it('builds Anthropic request with system cache_control, tool_choice, temperature, and thinking replay', function () {
    $respBody = "event: message_start\ndata: " . \json_encode(['message' => ['id' => 'm', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]]) . "\n\nevent: message_delta\ndata: " . \json_encode(['delta' => ['stop_reason' => 'end_turn']]) . "\n\nevent: message_stop\ndata: " . \json_encode(['type' => 'message_stop']) . "\n\n";
    $t        = new FakeTransport(new Response(200, [], \str_split($respBody, 23)));
    $tools    = [new ToolSpec('search', 'Search', ['type' => 'object'])];
    $options  = new Options(
        temperature: 0.7,
        toolChoice: ToolChoice::None,
        provider: [
            'anthropic' => [
                'system' => [['type' => 'text', 'text' => 'You are helpful', 'cache_control' => ['type' => 'ephemeral']]],
            ],
        ]
    );
    $msgs = [
        user('question'),
        new Message(Role::Assistant, [
            new Reasoning('thinking', ['model' => 'claude-x', 'blocks' => [['type' => 'thinking', 'thinking' => 'consider', 'signature' => 'SIG']]]),
            new Text('answer'),
        ]),
    ];
    [...(new AnthropicMessages($t, 'key', 'claude-x'))->stream(new Prompt('Sys', $msgs, $tools, $options, live()))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body)->toMatchArray([
        'system'      => [['type' => 'text', 'text' => 'You are helpful', 'cache_control' => ['type' => 'ephemeral']]],
        'temperature' => 0.7,
        'tool_choice' => ['type' => 'none'],
        'tools'       => [['name' => 'search', 'description' => 'Search', 'input_schema' => ['type' => 'object']]],
    ])
        ->and($body['messages'][1]['content'])->toBe([
            ['type' => 'thinking', 'thinking' => 'consider', 'signature' => 'SIG'],
            ['type' => 'text', 'text' => 'answer'],
        ]);
});

it('formats a Reference in an Anthropic user message as a markdown link', function () {
    $streamResp = "event: message_start\ndata: " . \json_encode(['message' => ['id' => 'm1', 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]]]) . "\n\n"
        . "event: message_delta\ndata: " . \json_encode(['delta' => ['stop_reason' => 'end_turn']]) . "\n\n"
        . "event: message_stop\ndata: " . \json_encode(['type' => 'message_stop']) . "\n\n";
    $t    = new FakeTransport(new Response(200, [], \str_split($streamResp, 23)));
    $msgs = [new Message(Role::User, [new Reference('https://docs.example.com', 'Docs Link'), new Text('thanks')])];
    [...(new AnthropicMessages($t, 'key', 'claude-x'))->stream(new Prompt(null, $msgs, [], new Options(), live()))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body['messages'][0]['content'])->toBe([
        ['type' => 'text', 'text' => '[Docs Link](https://docs.example.com)'],
        ['type' => 'text', 'text' => 'thanks'],
    ]);
});

it('sends Anthropic PDF document in user message with base64 encoding in request body', function () {
    $streamResp = "event: message_start\ndata: " . \json_encode(['message' => ['id' => 'm', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]]) . "\n\n"
        . "event: message_delta\ndata: " . \json_encode(['delta' => ['stop_reason' => 'end_turn']]) . "\n\n"
        . "event: message_stop\ndata: " . \json_encode(['type' => 'message_stop']) . "\n\n";
    $t          = new FakeTransport(new Response(200, [], \str_split($streamResp, 23)));
    $pdfContent = 'PDF_BINARY_DATA_HERE';
    $msgs       = [new Message(Role::User, [new Media('application/pdf', $pdfContent), new Text('please analyze')])];
    [...(new AnthropicMessages($t, 'key', 'claude-x'))->stream(new Prompt(null, $msgs, [], new Options(), live()))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body['messages'][0]['content'])->toBe([
        ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => \base64_encode($pdfContent)]],
        ['type' => 'text', 'text' => 'please analyze'],
    ]);
});
