<?php

declare(strict_types=1);

use Harness\Accumulator;
use Harness\Message;
use Harness\Part\Text;
use Harness\Role;

it('seeds Accumulator with assistant message and rejects non-assistant', function () {
    $seed = new Message(Role::Assistant, [new Text('previous')]);
    $acc  = new Accumulator($seed);
    expect($acc->message()->parts)->toEqual([new Text('previous')]);

    $userMsg = new Message(Role::User, [new Text('user')]);
    expect(fn () => new Accumulator($userMsg))->toThrow(InvalidArgumentException::class, 'assistant message');
});
