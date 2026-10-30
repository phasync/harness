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
use Harness\Provider\OpenAI\Responses;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\Testing\FakeTransport;
use Harness\Testing\TestPrompt;
use Harness\ToolChoice;
use Harness\Tools;
use Harness\ToolSpec;
use Harness\Usage;

/** A Responses event stream: `event:` and `data:` per event, cut into small chunks. */
function events(array ...$events): Response
{
    $body = '';
    foreach ($events as $e) {
        $body .= "event: {$e['type']}\ndata: " . \json_encode($e) . "\n\n";
    }

    return new Response(200, ['content-type' => 'text/event-stream'], \str_split($body, 23));
}

function completed(int $in = 50, int $out = 7, int $cached = 0): array
{
    return ['type' => 'response.completed', 'response' => ['status' => 'completed', 'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'input_tokens_details' => ['cached_tokens' => $cached]]]];
}

/** The deltas of one call, and the request body it sent. */
function respond(Response $response, ?Harness\Prompt $prompt = null, bool $strict = true, bool $store = false): array
{
    $t      = new FakeTransport($response);
    $deltas = [...(new Responses($t, 'https://api.example/v1/', 'gpt-x', 'sk-1', strict: $strict, store: $store))->stream($prompt ?? TestPrompt::make(null, [user('hi')]))];

    return [$deltas, \json_decode($t->requests[0]['body'], true), $t->requests[0]];
}

it('maps the event stream: reasoning with its encrypted content, text, a function call, usage', function () {
    $reasoning            = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Plan. Then.']], 'encrypted_content' => 'ENC'];
    [$deltas, , $request] = respond(events(
        ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]],
        ['type' => 'response.reasoning_summary_part.added', 'item_id' => 'rs_1', 'summary_index' => 0],
        ['type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs_1', 'summary_index' => 0, 'delta' => 'Plan.'],
        ['type' => 'response.reasoning_summary_part.added', 'item_id' => 'rs_1', 'summary_index' => 1],
        ['type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs_1', 'summary_index' => 1, 'delta' => 'Then.'],
        ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $reasoning],
        ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => []]],
        ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Hel'],
        ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'lo'],
        ['type' => 'response.output_item.added', 'output_index' => 2, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read', 'arguments' => '']],
        ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{"pa'],
        ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => 'th":"a"}'],
        ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{"path":"a"}'],
        completed(50, 7, 20),
    ));
    expect($deltas)->toEqual([
        new ReasoningDelta('', ['model' => 'gpt-x']),
        new ReasoningDelta('Plan.'),
        new ReasoningDelta("\n\n"),
        new ReasoningDelta('Then.'),
        new ReasoningDelta('', ['items' => [$reasoning]]),
        new TextDelta('Hel'),
        new TextDelta('lo'),
        new ToolCallStart('call_1', 'read'),
        new ToolInputDelta('call_1', '{"pa'),
        new ToolInputDelta('call_1', 'th":"a"}'),
        new ToolCallEnd('call_1'),
        new Usage(50, 7, 20),
        new Finish(FinishReason::ToolCalls),
    ])->and($request['url'])->toBe('https://api.example/v1/responses')
        ->and($request['headers']['Authorization'])->toBe('Bearer sk-1');
});

it('builds the request: instructions, input items paired by call_id, reasoning replayed by encrypted content', function () {
    $mine     = new Reasoning('Plan.', ['model' => 'gpt-x', 'items' => [['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Plan.']], 'encrypted_content' => 'ENC']]]);
    $messages = [
        user('read a'),
        new Message(Role::Assistant, [$mine, new Reasoning('theirs', ['model' => 'other', 'items' => [['type' => 'reasoning', 'id' => 'rs_9']]]), new Text('Reading.'), new ToolCall('call_1', 'read', ['path' => 'a']), new ToolCall('call_2', 'now', [])]),
        new Message(Role::User, [new ToolResult('call_1', [new Text('content'), new Media('image/png', 'PNG')]), new ToolResult('call_2', [new Text('boom')], true), new Text('and?')]),
    ];
    $spec     = new ToolSpec('read', 'Reads', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]);
    [, $body] = respond(events(completed()), TestPrompt::make('Be brief.', $messages, [$spec], new Options(maxOutputTokens: 100, temperature: 0.5, toolChoice: ToolChoice::Required, provider: ['openai' => ['reasoning' => ['effort' => 'low']]])));
    expect($body)->toBe([
        'model'        => 'gpt-x',
        'input'        => [
            ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'read a']]],
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Plan.']], 'encrypted_content' => 'ENC'],
            ['type' => 'message', 'role' => 'assistant', 'content' => 'Reading.'],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'read', 'arguments' => '{"path":"a"}'],
            ['type' => 'function_call', 'call_id' => 'call_2', 'name' => 'now', 'arguments' => '{}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => 'content'],
            ['type' => 'function_call_output', 'call_id' => 'call_2', 'output' => 'Error: boom'],
            ['type' => 'message', 'role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => '(attached by tool call call_1)'],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . \base64_encode('PNG')],
                ['type' => 'input_text', 'text' => 'and?'],
            ]],
        ],
        'stream'            => true,
        'store'             => false,
        'instructions'      => 'Be brief.',
        'include'           => ['reasoning.encrypted_content'],
        'tools'             => [['type' => 'function', 'name' => 'read', 'description' => 'Reads', 'parameters' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'], 'additionalProperties' => false], 'strict' => true]],
        'tool_choice'       => 'required',
        'max_output_tokens' => 100,
        'temperature'       => 0.5,
        'reasoning'         => ['effort' => 'low'],
    ]);
});

it('replays reasoning by id when the responses are stored', function () {
    $mine     = new Reasoning('', ['model' => 'gpt-x', 'items' => [['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'ENC']]]);
    [, $body] = respond(events(completed()), TestPrompt::make(null, [user('a'), new Message(Role::Assistant, [$mine, new Text('b')]), user('c')]), store: true);
    expect($body['store'])->toBeTrue()
        ->and($body)->not->toHaveKey('include')
        ->and($body['input'][1])->toBe(['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]);
});

it('sends a schema strict when it can be: closed objects, all required, optional ones nullable', function () {
    $schema = ['type' => 'object', 'properties' => [
        'path'  => ['type' => 'string'],
        'mode'  => ['type' => 'string', 'enum' => ['r', 'w']],
        'range' => ['type' => 'object', 'properties' => ['from' => ['type' => 'integer'], 'to' => ['type' => 'integer']], 'required' => ['from']],
        'tags'  => ['type' => 'array', 'items' => ['type' => 'string']],
        'id'    => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
    ], 'required' => ['path']];
    $tools = [
        new ToolSpec('read', 'R', $schema),
        new ToolSpec('none', 'N', ['type' => 'object']),
        new ToolSpec('free', 'F', ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]),
        new ToolSpec('one', 'O', ['type' => 'object', 'properties' => ['x' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]]]),
        new ToolSpec('any', 'A', ['type' => 'object', 'properties' => ['v' => []]]),
    ];
    [, $body] = respond(events(completed()), TestPrompt::make(null, [user('x')], $tools));
    $sent     = \array_column($body['tools'], null, 'name');
    expect($sent['read']['strict'])->toBeTrue()
        ->and($sent['read']['parameters'])->toBe(['type' => 'object', 'properties' => [
            'path'  => ['type' => 'string'],
            'mode'  => ['type' => ['string', 'null'], 'enum' => ['r', 'w', null]],
            'range' => ['type' => ['object', 'null'], 'properties' => ['from' => ['type' => 'integer'], 'to' => ['type' => ['integer', 'null']]], 'required' => ['from', 'to'], 'additionalProperties' => false],
            'tags'  => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
            'id'    => ['anyOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'null']]],
        ], 'required' => ['path', 'mode', 'range', 'tags', 'id'], 'additionalProperties' => false])
        ->and($sent['none'])->toMatchArray(['strict' => true, 'parameters' => ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false]])
        ->and([$sent['free']['strict'], $sent['one']['strict'], $sent['any']['strict']])->toBe([false, false, false])
        ->and($sent['free']['parameters'])->toBe(['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]);

    [, $body] = respond(events(completed()), TestPrompt::make(null, [user('x')], [$tools[0]]), strict: false);
    expect($body['tools'][0]['strict'])->toBeFalse()
        ->and($body['tools'][0]['parameters'])->toBe($schema);
});

it('hands a strict tool its arguments whole, without the nulls strict mode made the model send', function () {
    $spec = new ToolSpec('read', 'R', ['type' => 'object', 'properties' => [
        'path'  => ['type' => 'string'],
        'limit' => ['type' => 'integer'],
        'range' => ['type' => 'object', 'properties' => ['from' => ['type' => 'integer'], 'to' => ['type' => 'integer']], 'required' => ['from']],
    ], 'required' => ['path']]);
    [$deltas] = respond(events(
        ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read', 'arguments' => '']],
        ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{"path":"a","limit":null,'],
        ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '"range":{"from":1,"to":null}}'],
        ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{"path":"a","limit":null,"range":{"from":1,"to":null}}'],
        completed(),
    ), TestPrompt::make(null, [user('x')], [$spec]));
    expect($deltas)->toEqual([
        new ToolCallStart('call_1', 'read'),
        new ToolInputDelta('call_1', '{"path":"a","range":{"from":1}}'),
        new ToolCallEnd('call_1'),
        new Usage(50, 7),
        new Finish(FinishReason::ToolCalls),
    ]);
});

it('ends Length, Refusal or Stop', function (array $events, FinishReason $finish) {
    [$deltas] = respond(events(...$events));
    expect(\array_slice($deltas, -1)[0])->toEqual(new Finish($finish));
})->with([
    'completed'  => [[['type' => 'response.output_text.delta', 'item_id' => 'm', 'delta' => 'ok'], completed()], FinishReason::Stop],
    'max tokens' => [[['type' => 'response.incomplete', 'response' => ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]]], FinishReason::Length],
    'filtered'   => [[['type' => 'response.incomplete', 'response' => ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter']]]], FinishReason::Refusal],
    'refusal'    => [[['type' => 'response.refusal.delta', 'item_id' => 'm', 'delta' => 'I cannot'], completed()], FinishReason::Refusal],
]);

it('fails with the error kind the API names', function (Response $response, ErrorKind $kind, string $message, ?float $retryAfter) {
    try {
        respond($response);
        $this->fail('no exception');
    } catch (ModelException $e) {
        expect($e->kind)->toBe($kind)
            ->and($e->getMessage())->toContain($message)
            ->and($e->retryAfter)->toBe($retryAfter);
    }
})->with([
    'rate limit'   => [new Response(429, ['retry-after' => '3'], ['{"error":{"message":"slow down","type":"requests","code":"rate_limit_exceeded"}}']), ErrorKind::RateLimited, 'HTTP 429', 3.0],
    'context'      => [new Response(400, [], ['{"error":{"message":"too long","type":"invalid_request_error","code":"context_length_exceeded"}}']), ErrorKind::ContextTooLong, 'too long', null],
    'bad request'  => [new Response(400, [], ['{"error":{"message":"bad","type":"invalid_request_error","code":"invalid_value"}}']), ErrorKind::ModelError, 'bad', null],
    'error event'  => [events(['type' => 'error', 'code' => 'server_error', 'message' => 'oops']), ErrorKind::ModelError, 'oops', null],
    'failed'       => [events(['type' => 'response.failed', 'response' => ['error' => ['code' => 'context_length_exceeded', 'message' => 'long']]]), ErrorKind::ContextTooLong, 'long', null],
    'cut off'      => [events(['type' => 'response.output_text.delta', 'item_id' => 'm', 'delta' => 'par']), ErrorKind::ModelError, 'without response.completed', null],
]);

it('runs a tool loop over the Responses API', function () {
    $t = new FakeTransport(
        events(
            ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'clock', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
            completed(),
        ),
        events(['type' => 'response.output_text.delta', 'item_id' => 'm', 'delta' => 'It is noon.'], completed()),
    );
    $run = new Run(new Responses($t, 'https://api.example/v1', 'gpt-x'), new Tools(tool('clock', fn () => Result::text('12:00'), new Harness\Hints(readOnly: true))));
    turn($run, user('time?'));
    $second = \json_decode($t->requests[1]['body'], true)['input'];
    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and($run->messages()[3]->parts)->toEqual([new Text('It is noon.')])
        ->and(\array_slice($second, 1))->toBe([
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'clock', 'arguments' => '{}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '12:00'],
        ]);
});

it('throws on a continuation, naming the fallback', function () {
    $prompt = TestPrompt::make(null, [user('x'), new Message(Role::Assistant, [new Text('One.')])], continue: true);
    expect(fn () => respond(new Response(200, [], []), $prompt))->toThrow(Harness\UnsupportedOptionException::class, 'append the edit as a finished assistant message');
});
