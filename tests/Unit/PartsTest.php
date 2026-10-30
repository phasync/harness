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
