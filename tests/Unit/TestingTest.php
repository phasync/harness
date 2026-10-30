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
            if ([] === $context->answers() && !$context->spec->hints->readOnly) {
                return $context->needsInput([new Approval(reason: 'sure?')]);
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
    expect($call->pending()?->needs)->toEqual([new Approval(reason: 'sure?')])
        ->and($ran)->toBe(0);
    $call->answer(['approval' => Decision::allow()]);
    expect($policy->call(new ToolCall('test', 'w', []), $call, $next)->content[0]->text)->toBe('ran');

    $read = TestCall::make(new ToolSpec('r', 'R', ['type' => 'object'], new Hints(readOnly: true)));
    expect($policy->call(new ToolCall('test', 'r', []), $read, $next)->content[0]->text)->toBe('ran')
        ->and($read->pending())->toBeNull();
});

it('makes a Prompt for testing a model middleware without a run', function () {
    $upper = new class implements Harness\ModelMiddleware {
        public function stream(Harness\Prompt $prompt, Closure $next): iterable
        {
            yield from $next(new Harness\Prompt(\strtoupper((string) $prompt->system), $prompt->messages, $prompt->tools, $prompt->options, $prompt->cancel));
        }
    };
    $model  = new Harness\Testing\FakeModel(says('ok'));
    $prompt = Harness\Testing\TestPrompt::make('be brief', [user('hi')]);
    $deltas = [...$upper->stream($prompt, fn (Harness\Prompt $p) => $model->stream($p))];
    expect($model->prompts[0]->system)->toBe('BE BRIEF')
        ->and($model->prompts[0]->messages)->toEqual([user('hi')])
        ->and($prompt->cancel->isCancelled())->toBeFalse()
        ->and($deltas)->toHaveCount(3);
});
