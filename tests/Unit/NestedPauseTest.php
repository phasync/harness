<?php

declare(strict_types=1);

use Harness\Agents\AgentTool;
use Harness\Answer;
use Harness\Approval;
use Harness\Call;
use Harness\Decision;
use Harness\Event\ToolDecided;
use Harness\Message;
use Harness\Part\ToolCall;
use Harness\Pending;
use Harness\Question;
use Harness\Reason;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\ToolMiddleware;
use Harness\Tools;

/** The policy of the README: anything not read-only needs approval, unless the host answered. */
final class GateWrites implements ToolMiddleware
{
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        if (null === $context->decision() && null === $context->answers() && null !== $context->spec && !$context->spec->hints->readOnly) {
            return $context->needsInput(new Approval("{$call->name} writes"));
        }

        return $next($call, $context);
    }
}

/**
 * A parent with one sub-agent whose `write` tool is gated, built fresh as a new process would.
 *
 * @param list<mixed>  $parentReplies
 * @param list<mixed>  $childReplies
 * @param list<string> $written
 */
function nested(array $parentReplies, array $childReplies, array &$written, array $state = ['messages' => [], 'pending' => []], array $parentMiddleware = []): Run
{
    $write = tool('write', function (array $in, Call $call) use (&$written) {
        $written[] = $in['x'];

        return Result::text("wrote {$in['x']}");
    });
    $ask = tool('ask', function (array $in, Call $call) {
        return null === $call->answers()
            ? $call->needsInput([new Question('colour', 'Which colour?', ['red', 'blue'])], ['asked' => true])
            : Result::text("colour {$call->answers()['colour']}, state " . \json_encode($call->state()));
    }, new Harness\Hints(readOnly: true));
    $child = new Run(new FakeModel(...$childReplies), new Tools($write, $ask), middleware: [new GateWrites()]);

    return new Run(
        new FakeModel(...$parentReplies),
        new Tools(new AgentTool($child, 'worker', 'Does the work')),
        middleware: $parentMiddleware,
        messages: \array_map(Message::fromArray(...), $state['messages']),
        pending: \array_map(Pending::fromArray(...), $state['pending']),
    );
}

/** What a web host stores between requests: JSON. */
function persist(Run $run): array
{
    return \json_decode(\json_encode([
        'messages' => \array_map(fn (Message $m) => $m->toArray(), $run->messages()),
        'pending'  => \array_map(fn (Pending $p) => $p->toArray(), $run->ended()->pending),
    ], \JSON_THROW_ON_ERROR), true);
}

it('passes a child\'s approval up, and a restored parent resumes the child with the answer', function () {
    $written = [];
    $parent  = nested([calls(['p1', 'worker', ['prompt' => 'write 1']])], [calls(['w1', 'write', ['x' => 1]])], $written);
    turn($parent, user('go'));
    expect($parent->ended()->reason)->toBe(Reason::AwaitingInput)
        ->and($written)->toBe([]);
    $state = persist($parent);
    expect($state['pending'])->toHaveCount(1)
        ->and($state['pending'][0]['call']['id'])->toBe('p1')
        ->and($state['pending'][0]['needs'])->toBe(['type' => 'approval', 'reason' => 'write writes']);

    // Another request: everything is rebuilt from the JSON.
    $parent = nested([says('parent done')], [says('child done')], $written, $state);
    $events = turn($parent, [new Answer('p1', Decision::allow())]);
    expect($parent->ended()->reason)->toBe(Reason::Done)
        ->and($written)->toBe([1])
        ->and($parent->messages()[2]->parts[0]->content[0]->text)->toBe('child done');
    $decided = \array_values(\array_filter($events, fn ($e) => $e instanceof ToolDecided));
    expect(\array_map(fn ($e) => [$e->parent, $e->id], $decided))->toBe([[null, 'p1'], ['p1', 'w1']]);
});

it('routes a denial to the child, whose model hears it', function () {
    $written = [];
    $parent  = nested([calls(['p1', 'worker', ['prompt' => 'write 1']])], [calls(['w1', 'write', ['x' => 1]])], $written);
    turn($parent, user('go'));
    $childModel = new FakeModel(says('skipped it'));
    $parent     = nested([says('parent done')], [fn ($prompt) => $childModel->stream($prompt)], $written, persist($parent));
    turn($parent, [new Answer('p1', Decision::deny('not today'))]);
    expect($written)->toBe([])
        ->and($parent->messages()[2]->parts[0]->error)->toBeFalse()
        ->and($parent->messages()[2]->parts[0]->content[0]->text)->toBe('skipped it')
        ->and(\array_slice($childModel->prompts[0]->messages, -1)[0]->parts[0]->content[0]->text)->toBe('Denied: not today');
});

it('passes a child\'s questions up and routes the answers with the child tool\'s state', function () {
    $written = [];
    $parent  = nested([calls(['p1', 'worker', ['prompt' => 'ask']])], [calls(['q1', 'ask', []])], $written);
    turn($parent, user('go'));
    $state = persist($parent);
    expect($state['pending'][0]['needs'])->toBe(['type' => 'questions', 'questions' => [['id' => 'colour', 'text' => 'Which colour?', 'choices' => ['red', 'blue']]]]);

    $childModel = new FakeModel(says('ok'));
    $parent     = nested([says('parent done')], [fn ($prompt) => $childModel->stream($prompt)], $written, $state);
    turn($parent, [new Answer('p1', ['colour' => 'blue'])]);
    expect($parent->ended()->reason)->toBe(Reason::Done)
        ->and(\array_slice($childModel->prompts[0]->messages, -1)[0]->parts[0]->content[0]->text)->toBe('colour blue, state {"asked":true}');
});

it('asks several pending child calls as one list of questions', function () {
    $written = [];
    $parent  = nested([calls(['p1', 'worker', ['prompt' => 'two']])], [calls(['w1', 'write', ['x' => 1]], ['w2', 'write', ['x' => 2]], ['q1', 'ask', []])], $written);
    turn($parent, user('go'));
    $state = persist($parent);
    expect(\array_column($state['pending'][0]['needs']['questions'], 'id'))->toBe(['w1', 'w2', 'q1/colour'])
        ->and($state['pending'][0]['needs']['questions'][0]['choices'])->toBe(['allow', 'deny']);

    $parent = nested([says('parent done')], [says('child done')], $written, $state);
    turn($parent, [new Answer('p1', ['w1' => 'deny', 'w2' => 'allow', 'q1/colour' => 'red'])]);
    expect($written)->toBe([2])
        ->and($parent->messages()[2]->parts[0]->content[0]->text)->toBe('child done');
});

it('lets the parent\'s own policy gate the sub-agent and still route the child\'s answers', function () {
    $written = [];
    // The worker is not read-only (its write tool is not), so the parent's policy asks first.
    $parent = nested([calls(['p1', 'worker', ['prompt' => 'write 1']])], [], $written, parentMiddleware: [new GateWrites()]);
    turn($parent, user('go'));
    expect($parent->ended()->pending[0]->needs)->toEqual(new Approval('worker writes'));

    $parent = nested([], [calls(['w1', 'write', ['x' => 1]])], $written, persist($parent), [new GateWrites()]);
    turn($parent, [new Answer('p1', Decision::allow())]);
    expect($parent->ended()->reason)->toBe(Reason::AwaitingInput)
        ->and($parent->ended()->pending[0]->needs)->toEqual(new Approval('write writes'));

    $parent = nested([says('parent done')], [says('child done')], $written, persist($parent), [new GateWrites()]);
    turn($parent, [new Answer('p1', Decision::allow())]);
    expect($parent->ended()->reason)->toBe(Reason::Done)
        ->and($written)->toBe([1]);
});
