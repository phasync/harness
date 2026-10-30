<?php

declare(strict_types=1);

use Harness\Answer;
use Harness\Approval;
use Harness\Decision;
use Harness\Message;
use Harness\Part\Media;
use Harness\Part\Reasoning;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Parts;
use Harness\Pending;
use Harness\Question;
use Harness\Role;

it('round-trips every part through JSON', function (Harness\Part $part) {
    $json = \json_decode(\json_encode($part->toArray(), \JSON_THROW_ON_ERROR), true);
    expect(Parts::fromArray($json))->toEqual($part);
})->with([
    'text'        => [new Text('hi')],
    'reasoning'   => [new Reasoning('hmm', ['model' => 'm', 'blocks' => [['type' => 'thinking']]])],
    'tool call'   => [new ToolCall('c1', 'read', ['path' => 'a', 'n' => 2])],
    'tool result' => [new ToolResult('c1', [new Text('ok'), new Reference('file:///a', 'A')], true)],
    'media bytes' => [new Media('image/png', "\x89PNG\0binary")],
    'media uri'   => [new Media('image/png', uri: 'https://example.com/a.png')],
    'reference'   => [new Reference('https://example.com', null)],
]);

it('round-trips messages, pending calls and answers', function () {
    $m = new Message(Role::Assistant, [new Text('a'), new ToolCall('c', 'x', ['k' => 'v'])]);
    expect(Message::fromArray(\json_decode(\json_encode($m->toArray()), true)))->toEqual($m);

    $p = new Pending(new ToolCall('c', 'x', []), [new Question('q', 'Which?', ['a', 'b'])], ['step' => 2]);
    expect(Pending::fromArray(\json_decode(\json_encode($p->toArray()), true)))->toEqual($p);
    $p = new Pending(new ToolCall('c', 'x', []), new Approval('writes'));
    expect(Pending::fromArray(\json_decode(\json_encode($p->toArray()), true)))->toEqual($p);

    foreach ([new Answer('c', Decision::deny('no')), new Answer('c', Decision::allow()), new Answer('c', ['q' => 'a'])] as $a) {
        expect(Answer::fromArray(\json_decode(\json_encode($a->toArray()), true)))->toEqual($a);
    }
});

it('rejects parts in the wrong place', function (Closure $make) {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'tool call in user message'        => [fn () => new Message(Role::User, [new ToolCall('c', 'x', [])])],
    'reasoning in user message'        => [fn () => new Message(Role::User, [new Reasoning('r')])],
    'tool result in assistant message' => [fn () => new Message(Role::Assistant, [new ToolResult('c', [])])],
    'tool call inside a tool result'   => [fn () => new ToolResult('c', [new ToolCall('c', 'x', [])])],
    'media with bytes and uri'         => [fn () => new Media('image/png', 'x', 'y')],
    'media with neither'               => [fn () => new Media('image/png')],
    'unknown type'                     => [fn () => Parts::fromArray(['type' => 'video'])],
    'pending state with an object'     => [fn () => new Pending(new ToolCall('c', 'x', []), new Approval(), ['o' => new stdClass()])],
    'pending with no questions'        => [fn () => new Pending(new ToolCall('c', 'x', []), [])],
]);

it('rebuilds every event of a turn from its array', function () {
    $model = new Harness\Testing\FakeModel(
        [new Harness\Delta\ReasoningDelta('hm', ['model' => 'm']), ...calls(['c1', 'slow', []], ['c2', 'boom', []], ['c3', 'gated', []])],
    );
    $tools = new Harness\Tools(
        tool('slow', function (array $in, Harness\Call $call) {
            $call->progress(['done' => 1]);

            return new Harness\Result([new Text('ok')], value: ['v' => 1], meta: ['m' => 2]);
        }),
        tool('boom', fn () => throw new RuntimeException('bang')),
        tool('gated', fn (array $in, Harness\Call $call) => null === $call->decision() ? $call->needsInput(new Approval('sure?')) : Result::text('gated ran')),
    );
    $run     = new Harness\Run($model, $tools);
    $events  = turn($run, user('go'));
    $resumed = new Harness\Run(new Harness\Testing\FakeModel(says('fine')), $tools, messages: $run->messages(), pending: $run->ended()->pending);
    $events  = [...$events, ...turn($resumed, [new Answer('c3', Decision::allow())])];
    $types   = [];
    foreach ($events as $event) {
        $json  = \json_decode(\json_encode($event, \JSON_THROW_ON_ERROR), true);
        $again = Harness\Events::fromArray($json);
        expect($again)->toBeInstanceOf($event::class)
            ->and(\json_encode($again))->toBe(\json_encode($event));
        $types[$json['type']] = true;
    }
    expect(\array_keys($types))->toContain('reasoning_delta', 'tool_progress', 'tool_ended', 'input_requested', 'tool_decided', 'text_delta', 'turn_ended');
    $thrown = \array_values(\array_filter($events, fn ($e) => $e instanceof Harness\Event\ToolEnded && $e->outcome instanceof Throwable))[0];
    $again  = Harness\Events::fromArray($thrown->toArray());
    expect($again->outcome)->toBeInstanceOf(Harness\ReplayedError::class)
        ->and($again->outcome->class)->toBe(RuntimeException::class)
        ->and($again->outcome->getMessage())->toBe('bang');
    expect(fn () => Harness\Events::fromArray(['type' => 'nope', 'turn' => 1]))->toThrow(InvalidArgumentException::class, "Unknown event type 'nope'");
});

it('rebuilds a Result from its array', function () {
    $r = new Harness\Result([new Text('a'), new Reference('file:///x', 'X')], ['v' => 1], ['m' => true], true);
    expect(Harness\Result::fromArray(\json_decode(\json_encode($r->toArray()), true)))->toEqual($r);
});

it('normalizes schema nodes PHP holds as empty arrays to JSON objects', function () {
    $spec = new Harness\ToolSpec('t', 'T', [
        'type'       => 'object',
        'properties' => [
            'any'    => [],
            'nested' => ['type' => 'object', 'properties' => [], 'additionalProperties' => []],
            'list'   => ['type' => 'array', 'items' => []],
            'either' => ['anyOf' => [[], ['type' => 'string']]],
        ],
        'required' => [],
    ]);
    expect(\json_encode($spec->schema))->toBe('{"type":"object","properties":{"any":{},"nested":{"type":"object","properties":{},"additionalProperties":{}},"list":{"type":"array","items":{}},"either":{"anyOf":[{},{"type":"string"}]}},"required":[]}')
        ->and(\json_encode((new Harness\ToolSpec('e', 'E', ['type' => 'object', 'properties' => []]))->schema))->toBe('{"type":"object","properties":{}}')
        ->and(fn () => new Harness\ToolSpec('s', 'S', ['type' => 'string']))->toThrow(InvalidArgumentException::class, 'must have "type": "object"');
});

it('serializes the needs of a Pending with an explicit type', function () {
    $call = new ToolCall('c1', 'write', ['path' => 'a']);
    $a    = new Pending($call, new Approval('write a?'));
    $q    = new Pending($call, [new Question('name', 'Name?'), new Question('size', 'Size?', ['s', 'm'])], ['step' => 2]);
    expect(\json_encode($a->toArray()))->toBe('{"call":{"type":"tool_call","id":"c1","name":"write","input":{"path":"a"}},"needs":{"type":"approval","reason":"write a?"},"state":null}')
        ->and(\json_encode($q->toArray()))->toBe('{"call":{"type":"tool_call","id":"c1","name":"write","input":{"path":"a"}},"needs":{"type":"questions","questions":[{"id":"name","text":"Name?","choices":[]},{"id":"size","text":"Size?","choices":["s","m"]}]},"state":{"step":2}}')
        ->and(Pending::fromArray(\json_decode(\json_encode($q->toArray()), true)))->toEqual($q)
        ->and(Pending::fromArray(\json_decode(\json_encode($a->toArray()), true)))->toEqual($a)
        ->and(fn () => Pending::fromArray(['call' => $call->toArray(), 'needs' => [['id' => 'x', 'text' => 'X', 'choices' => []]], 'state' => null]))
        ->toThrow(InvalidArgumentException::class, 'needs.type must be "approval" or "questions"');
});
