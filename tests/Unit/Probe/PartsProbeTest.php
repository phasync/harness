<?php

declare(strict_types=1);

use Harness\Accumulator;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\FinishReason;
use Harness\Message;
use Harness\Part\Media;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Role;

it('seeds Accumulator with assistant message and rejects non-assistant', function () {
    $seed = new Message(Role::Assistant, [new Text('previous')]);
    $acc  = new Accumulator($seed);
    expect($acc->message()->parts)->toEqual([new Text('previous')]);

    $userMsg = new Message(Role::User, [new Text('user')]);
    expect(fn () => new Accumulator($userMsg))->toThrow(InvalidArgumentException::class, 'assistant message');
});

it('preserves seeded Media and Reference parts through delta accumulation with tool calls', function () {
    $seed = new Message(Role::Assistant, [
        new Text('Caption:'),
        new Media('image/png', uri: 'https://example.com/image.png'),
        new Reference('https://example.com', 'Example'),
        new Text('Continued'),
    ]);
    $acc = new Accumulator($seed);

    // Extend seeded text with a new text delta (merges because last part is text)
    $acc->add(new TextDelta(' from deltas'));
    // Add a tool call with input
    $acc->add(new ToolCallStart('c1', 'read'));
    $acc->add(new ToolInputDelta('c1', '{"path":"file.txt"}'));
    $acc->add(new ToolCallEnd('c1'));
    $acc->add(new Finish(FinishReason::ToolCalls));

    $msg = $acc->message();
    expect($msg->parts)->toHaveCount(5)
        ->and($msg->parts[0])->toEqual(new Text('Caption:'))
        ->and($msg->parts[1])->toEqual(new Media('image/png', uri: 'https://example.com/image.png'))
        ->and($msg->parts[2])->toEqual(new Reference('https://example.com', 'Example'))
        ->and($msg->parts[3])->toEqual(new Text('Continued from deltas'))
        ->and($msg->parts[4])->toEqual(new ToolCall('c1', 'read', ['path' => 'file.txt']))
        ->and($acc->finish())->toBe(FinishReason::ToolCalls);
});
