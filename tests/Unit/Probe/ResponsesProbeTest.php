<?php

declare(strict_types=1);

use Harness\Http\Response;
use Harness\Message;
use Harness\Part\Reference;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Provider\OpenAI\Responses;
use Harness\Role;
use Harness\Testing\FakeTransport;
use Harness\Testing\TestPrompt;
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
