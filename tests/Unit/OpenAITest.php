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
use Harness\Part\Media;
use Harness\Part\Reasoning;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Role;
use Harness\Testing\FakeTransport;
use Harness\ToolChoice;
use Harness\ToolSpec;
use Harness\Usage;

function sse(array ...$chunks): Response
{
    $body = '';
    foreach ($chunks as $c) {
        $body .= 'data: ' . \json_encode($c) . "\n\n";
    }

    return new Response(200, ['content-type' => 'text/event-stream'], \str_split($body . "data: [DONE]\n\n", 17));
}

function delta(array $delta, ?string $finish = null): array
{
    return ['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]];
}

it('maps the stream: reasoning, text, tool calls by index, usage after finish_reason', function () {
    $t     = new FakeTransport(sse(
        delta(['role' => 'assistant', 'content' => '']),
        delta(['reasoning_content' => 'hmm']),
        delta(['content' => 'Hi']),
        delta(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"pa']]]]),
        delta(['tool_calls' => [['index' => 1, 'id' => 'call_b', 'function' => ['name' => 'list', 'arguments' => '{}']]]]),
        delta(['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'th":"x"}']]]]),
        delta([], 'tool_calls'),
        ['choices' => [], 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 100]]],
    ));
    $model  = new ChatCompletions($t, 'http://h/v1/', 'qwen');
    $deltas = [...$model->stream(new Prompt(null, [user('hi')], [], new Options(), live()))];
    expect($deltas)->toEqual([
        new ReasoningDelta('hmm', ['model' => 'qwen']),
        new TextDelta('Hi'),
        new ToolCallStart('call_a', 'read'),
        new ToolInputDelta('call_a', '{"pa'),
        new ToolCallStart('call_b', 'list'),
        new ToolInputDelta('call_b', '{}'),
        new ToolInputDelta('call_a', 'th":"x"}'),
        new ToolCallEnd('call_a'),
        new ToolCallEnd('call_b'),
        new Usage(300, 20, 100),
        new Finish(FinishReason::ToolCalls),
    ])->and($t->requests[0]['url'])->toBe('http://h/v1/chat/completions');
});

it('builds the request: system, tool messages, replayed reasoning, tools and options', function () {
    $t        = new FakeTransport(sse(delta(['content' => 'ok'], 'stop')));
    $messages = [
        user('read a'),
        new Message(Role::Assistant, [new Reasoning('mine', ['model' => 'qwen']), new Reasoning('theirs', ['model' => 'other']), new Text('Reading.'), new ToolCall('c1', 'read', ['path' => 'a']), new ToolCall('c2', 'now', [])]),
        new Message(Role::User, [new ToolResult('c1', [new Text('content')]), new ToolResult('c2', [new Text('boom')], true), new Text('and?')]),
        new Message(Role::User, [new Media('image/png', 'PNG'), new Text('see')]),
    ];
    $tools   = [new ToolSpec('read', 'Read', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]])];
    $options = new Options(maxOutputTokens: 100, temperature: 0.2, toolChoice: ToolChoice::Tool, tool: 'read', provider: ['openai' => ['top_p' => 0.9], 'anthropic' => ['ignored' => true]]);
    [...(new ChatCompletions($t, 'http://h/v1', 'qwen', 'sk-1', ['X-Extra' => 'y']))->stream(new Prompt('Be brief', $messages, $tools, $options, live()))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($t->requests[0]['headers'])->toMatchArray(['Authorization' => 'Bearer sk-1', 'X-Extra' => 'y', 'Content-Type' => 'application/json'])
        ->and($body['messages'])->toBe([
            ['role' => 'system', 'content' => 'Be brief'],
            ['role' => 'user', 'content' => 'read a'],
            ['role' => 'assistant', 'content' => 'Reading.', 'reasoning_content' => 'mine', 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"path":"a"}']],
                ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'now', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'content'],
            ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => 'Error: boom'],
            ['role' => 'user', 'content' => 'and?'],
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . \base64_encode('PNG')]], ['type' => 'text', 'text' => 'see']]],
        ])
        ->and($body['tools'][0]['function']['name'])->toBe('read')
        ->and($body['tool_choice'])->toBe(['type' => 'function', 'function' => ['name' => 'read']])
        ->and($body)->toMatchArray(['model' => 'qwen', 'stream' => true, 'stream_options' => ['include_usage' => true], 'max_tokens' => 100, 'temperature' => 0.2, 'top_p' => 0.9])
        ->and($body)->not->toHaveKey('ignored');
});

it('maps HTTP errors to error kinds', function (int $status, string $body, ErrorKind $kind) {
    $model = new ChatCompletions(new FakeTransport(new Response($status, ['retry-after' => '3'], [$body])), 'http://h/v1', 'm');
    try {
        [...$model->stream(new Prompt(null, [user('x')], [], new Options(), live()))];
        $this->fail('no exception');
    } catch (ModelException $e) {
        expect($e->kind)->toBe($kind)->and($e->getMessage())->toContain((string) $status);
        if (ErrorKind::RateLimited === $kind) {
            expect($e->retryAfter)->toBe(3.0);
        }
    }
})->with([
    [429, '{"error":"busy"}', ErrorKind::RateLimited],
    [400, '{"error":{"code":"context_length_exceeded"}}', ErrorKind::ContextTooLong],
    [500, 'oops', ErrorKind::ModelError],
]);

it('fails on an error inside the stream and on a missing finish_reason', function () {
    $model = new ChatCompletions(new FakeTransport(sse(['error' => ['message' => 'oom']]), sse(delta(['content' => 'x']))), 'http://h/v1', 'm');
    $p     = new Prompt(null, [user('x')], [], new Options(), live());
    expect(fn () => [...$model->stream($p)])->toThrow(ModelException::class, 'oom')
        ->and(fn () => [...$model->stream($p)])->toThrow(ModelException::class, 'without a finish_reason');
});

it('refuses what it cannot send', function () {
    $model = new ChatCompletions(new FakeTransport(sse(delta([], 'stop'))), 'http://h/v1', 'm');
    expect(fn () => [...$model->stream(new Prompt(null, [user('x')], [], new Options(toolChoice: ToolChoice::Required), live()))])->toThrow(Harness\UnsupportedOptionException::class)
        ->and(fn () => [...$model->stream(new Prompt(null, [new Message(Role::User, [new Media('video/mp4', 'x')])], [], new Options(), live()))])->toThrow(Harness\UnsupportedContentException::class);
});

it('continues an assistant message by sending it last, its reasoning with it for the same model', function () {
    $t    = new FakeTransport(sse(delta(['content' => ' Three.']), delta([], 'stop')));
    $edit = new Message(Role::Assistant, [new Reasoning('count', ['model' => 'qwen']), new Text('One. Two.')]);
    [...(new ChatCompletions($t, 'http://h/v1', 'qwen'))->stream(Harness\Testing\TestPrompt::make(null, [user('count'), $edit], continue: true))];
    $body = \json_decode($t->requests[0]['body'], true);
    expect($body['messages'])->toBe([
        ['role' => 'user', 'content' => 'count'],
        ['role' => 'assistant', 'content' => 'One. Two.', 'reasoning_content' => 'count'],
    ]);
});
