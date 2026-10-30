<?php

declare(strict_types=1);

use Harness\ClosureTool;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\ToolSpec;

it('runs a tool loop', function () {
    $tool   = new ClosureTool(new ToolSpec('echo', 'Echo', ['type' => 'object']), fn (array $in) => Result::text('echoed ' . $in['x']));
    $model  = new FakeModel(calls(['c1', 'echo', ['x' => 'hi']]), says('done'));
    $run    = new Run($model, new Tools($tool));
    $events = turn($run, user('go'));
    expect(types($events))->toBe(['turn_started', 'step_started', 'message_started', 'tool_call_started', 'tool_input_delta', 'tool_call_ready', 'message_ended', 'tool_ended', 'step_ended', 'step_started', 'message_started', 'text_delta', 'message_ended', 'step_ended', 'turn_ended']);
    expect($run->ended()->reason->value)->toBe('done');
});
