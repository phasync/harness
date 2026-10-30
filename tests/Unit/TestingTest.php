<?php

declare(strict_types=1);

use Harness\Approval;
use Harness\Call;
use Harness\Decision;
use Harness\Hints;
use Harness\Part\ToolCall;
use Harness\Result;
use Harness\Testing\TestCall;
use Harness\ToolMiddleware;
use Harness\ToolSpec;

it('makes a Call for testing a tool directly, collecting its events', function () {
    $events = [];
    $call   = TestCall::make(new ToolSpec('t', 'T', ['type' => 'object']), context: 'host', id: 'c9', emit: function ($e) use (&$events) {
        $events[] = $e;
    });
    $result = tool('t', function (array $in, Call $call) {
        $call->progress('half');

        return Result::text("{$call->context} {$call->id}");
    })->call([], $call);
    expect($result->content[0]->text)->toBe('host c9')
        ->and($call->isCancelled())->toBeFalse()
        ->and($call->spec->name)->toBe('t')
        ->and(types($events))->toBe(['tool_progress']);
});

it('tests a policy middleware without a run', function () {
    $policy = new class implements ToolMiddleware {
        public function call(ToolCall $call, Call $context, Closure $next): Result
        {
            if (null === $context->decision() && !$context->spec->hints->readOnly) {
                return $context->needsInput(new Approval('sure?'));
            }

            return $next($call, $context);
        }
    };
    $ran  = 0;
    $next = function () use (&$ran) {
        ++$ran;

        return Result::text('ran');
    };
    $call = TestCall::make(new ToolSpec('w', 'W', ['type' => 'object'], new Hints(destructive: true)));
    $policy->call(new ToolCall('test', 'w', []), $call, $next);
    expect($call->pending()?->needs)->toEqual(new Approval('sure?'))
        ->and($ran)->toBe(0);
    $call->answer(Decision::allow());
    expect($policy->call(new ToolCall('test', 'w', []), $call, $next)->content[0]->text)->toBe('ran');

    $read = TestCall::make(new ToolSpec('r', 'R', ['type' => 'object'], new Hints(readOnly: true)));
    expect($policy->call(new ToolCall('test', 'r', []), $read, $next)->content[0]->text)->toBe('ran')
        ->and($read->pending())->toBeNull();
});
