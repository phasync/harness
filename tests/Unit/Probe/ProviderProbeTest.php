<?php

declare(strict_types=1);

use Harness\Delta\Finish;
use Harness\FinishReason;
use Harness\Http\Response;
use Harness\Message;
use Harness\Options;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Prompt;
use Harness\Provider\Anthropic\Messages as AnthropicMessages;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Role;
use Harness\Testing\FakeTransport;
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
