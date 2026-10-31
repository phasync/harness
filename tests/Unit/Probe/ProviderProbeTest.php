<?php

declare(strict_types=1);

use Harness\Http\Response;
use Harness\Message;
use Harness\Options;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Prompt;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Role;
use Harness\Testing\FakeTransport;

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
