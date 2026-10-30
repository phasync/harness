<?php

declare(strict_types=1);

use Harness\Delta\Finish;
use Harness\Delta\ReasoningDelta;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\ErrorKind;
use Harness\FinishReason;
use Harness\Http\Response;
use Harness\Message;
use Harness\ModelException;
use Harness\Options;
use Harness\Part\Reasoning;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Provider\Anthropic\Messages;
use Harness\Role;
use Harness\Run;
use Harness\Testing\FakeTransport;
use Harness\Tools;
use Harness\ToolSpec;
use Harness\Usage;

function anthropicStream(array ...$events): Response
{
    $body = '';
    foreach ($events as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }

    return new Response(200, [], \str_split($body, 23));
}

function thinkingThenTool(): Response
{
    return anthropicStream(
        ['type' => 'message_start', 'message' => ['id' => 'm', 'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 90, 'cache_creation_input_tokens' => 5, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'SIG']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'ENC']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'ping'],
        ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'text_delta', 'text' => 'Reading']],
        ['type' => 'content_block_stop', 'index' => 2],
        ['type' => 'content_block_start', 'index' => 3, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'read', 'input' => []]],
        ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"path":']],
        ['type' => 'content_block_delta', 'index' => 3, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"a"}']],
        ['type' => 'content_block_stop', 'index' => 3],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 42]],
        ['type' => 'message_stop'],
    );
}

it('maps the stream, keeping thinking blocks verbatim', function () {
    $model  = new Messages(new FakeTransport(thinkingThenTool()), 'key', 'claude-x');
    $deltas = [...$model->stream(new Prompt(null, [user('hi')], [], new Options(), live()))];
    $blocks = [['type' => 'thinking', 'thinking' => 'Let me', 'signature' => 'SIG']];
    expect($deltas)->toEqual([
        new Usage(105, 1, 90, 5),
        new ReasoningDelta('Let me'),
        new ReasoningDelta('', ['model' => 'claude-x', 'blocks' => $blocks]),
        new ReasoningDelta('', ['model' => 'claude-x', 'blocks' => [...$blocks, ['type' => 'redacted_thinking', 'data' => 'ENC']]]),
        new TextDelta('Reading'),
        new ToolCallStart('tu_1', 'read'),
        new ToolInputDelta('tu_1', '{"path":'),
        new ToolInputDelta('tu_1', '"a"}'),
        new ToolCallEnd('tu_1'),
        new Usage(105, 42, 90, 5),
        new Finish(FinishReason::ToolCalls),
    ]);
});

it('replays reasoning to the same model, merges user turns and puts tool results first', function () {
    // Run the stream through a Run so the message comes from the real accumulator.
    $t   = new FakeTransport(thinkingThenTool(), anthropicStream(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 1, 'output_tokens' => 1]]], ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]], ['type' => 'message_stop']));
    $run = new Run(new Messages($t, 'key', 'claude-x'), new Tools(tool('read', fn () => Harness\Result::text('A'))), system: 'sys', options: new Options(provider: ['anthropic' => ['thinking' => ['type' => 'adaptive']]]));
    turn($run, user('hi'));
    $body = \json_decode($t->requests[1]['body'], true);
    expect($t->requests[1]['headers'])->toMatchArray(['x-api-key' => 'key', 'anthropic-version' => '2023-06-01'])
        ->and($body)->toMatchArray(['model' => 'claude-x', 'system' => 'sys', 'max_tokens' => 16000, 'stream' => true, 'thinking' => ['type' => 'adaptive']])
        ->and($body['tools'][0])->toBe(['name' => 'read', 'description' => 'The read tool', 'input_schema' => ['type' => 'object']])
        ->and($body['messages'][1]['content'])->toBe([
            ['type' => 'thinking', 'thinking' => 'Let me', 'signature' => 'SIG'],
            ['type' => 'redacted_thinking', 'data' => 'ENC'],
            ['type' => 'text', 'text' => 'Reading'],
            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'read', 'input' => ['path' => 'a']],
        ])
        ->and($body['messages'][2])->toBe(['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => [['type' => 'text', 'text' => 'A']], 'is_error' => false]]]);

    $other = new FakeTransport(anthropicStream(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']], ['type' => 'message_stop']));
    $msgs  = [
        user('a'),
        new Message(Role::Assistant, [new Reasoning('x', ['model' => 'claude-x', 'blocks' => [['type' => 'thinking']]]), new ToolCall('t', 'n', [])]),
        new Message(Role::User, [new Text('also')]),
        new Message(Role::User, [new ToolResult('t', [new Text('r')], true)]),
    ];
    [...(new Messages($other, 'k', 'claude-y'))->stream(new Prompt(null, $msgs, [new ToolSpec('n', 'N', ['type' => 'object'])], new Options(), live()))];
    $body = \json_decode($other->requests[0]['body'], true);
    expect($body['messages'][1]['content'])->toBe([['type' => 'tool_use', 'id' => 't', 'name' => 'n', 'input' => []]])
        ->and($body['messages'])->toHaveCount(3)
        ->and($body['messages'][2]['content'][0]['type'])->toBe('tool_result')
        ->and($body['messages'][2]['content'][1])->toBe(['type' => 'text', 'text' => 'also']);
});

it('maps errors, in the status and in the stream', function (Response $response, ErrorKind $kind) {
    $model = new Messages(new FakeTransport($response), 'k', 'm');
    try {
        [...$model->stream(new Prompt(null, [user('x')], [], new Options(), live()))];
        $this->fail('no exception');
    } catch (ModelException $e) {
        expect($e->kind)->toBe($kind);
    }
})->with([
    'rate limit' => [new Response(429, [], ['{"type":"error","error":{"type":"rate_limit_error","message":"slow"}}']), ErrorKind::RateLimited],
    'too long'   => [new Response(400, [], ['{"type":"error","error":{"type":"invalid_request_error","message":"prompt is too long: 300000 tokens"}}']), ErrorKind::ContextTooLong],
    'overloaded' => [anthropicStream(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'busy']]), ErrorKind::RateLimited],
    'cut off'    => [anthropicStream(['type' => 'message_start', 'message' => ['usage' => []]]), ErrorKind::TransportError],
]);

it('continues an assistant message as a prefill, without reasoning or trailing whitespace', function () {
    $t    = new FakeTransport(anthropicStream(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']], ['type' => 'message_stop']));
    $edit = new Message(Role::Assistant, [new Reasoning('x', ['model' => 'claude-x', 'blocks' => [['type' => 'thinking', 'thinking' => 'x', 'signature' => 'S']]]), new Text("One. Two. \n")]);
    [...(new Messages($t, 'k', 'claude-x'))->stream(Harness\Testing\TestPrompt::make(null, [user('count'), $edit], continue: true))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body['messages'])->toBe([
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'count']]],
        ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'One. Two.']]],
    ]);
});

it('throws on a continuation with thinking on, or of a message that ends with a tool call', function () {
    $model    = new Messages(new FakeTransport(), 'k', 'claude-x');
    $thinking = new Options(provider: ['anthropic' => ['thinking' => ['type' => 'enabled', 'budget_tokens' => 2000]]]);
    $edit     = new Message(Role::Assistant, [new Text('One.')]);
    expect(fn () => [...$model->stream(Harness\Testing\TestPrompt::make(null, [user('x'), $edit], options: $thinking, continue: true))])
        ->toThrow(Harness\UnsupportedOptionException::class, 'append the edit as a finished assistant message')
        ->and(fn () => [...$model->stream(Harness\Testing\TestPrompt::make(null, [user('x'), new Message(Role::Assistant, [new Text('a'), new ToolCall('t', 'n', [])])], continue: true))])
        ->toThrow(Harness\UnsupportedContentException::class, 'tool_use');
});
