<?php

declare(strict_types=1);

use Harness\Approval;
use Harness\Call;
use Harness\ClosureTool;
use Harness\Delta\Finish;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Reason;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\ToolSpec;
use Harness\Usage;

/*
 * Run::cancel()'s docblock promises, unconditionally: "The turn ends Reason::Cancelled, without
 * the partial assistant message." No exception is carved out for a tool call that keeps running
 * after cancellation and ends up asking the host for input.
 *
 * A tool is cooperative: it checks isCancelled() between waits, but nothing stops it from
 * deciding to call Call::needsInput() anyway (e.g. it needs an approval regardless of whether the
 * host is trying to stop the turn, or it simply doesn't check in time). Run::loop() checks the
 * step's $pending list BEFORE it checks isCancelled() (see the `if ([] !== $pending) { $reason =
 * Reason::AwaitingInput; break; }` ahead of the `if ($this->isCancelled())` check that follows
 * it), so a single call asking for input silently overrides a cancellation that was requested in
 * the very same step, even though cancel() was already in effect while the tool ran.
 *
 * Verified directly against the real code with both adapters (HARNESS_ADAPTER=phasync and
 * =revolt): a tool that calls $run->cancel() and then $call->needsInput() makes the turn end
 * 'awaiting_input', never 'cancelled' -- contradicting cancel()'s docblock and Reason::Cancelled's
 * own docblock ("Run::cancel() was called").
 */
it('ends the turn Cancelled, not AwaitingInput, when a tool asks for input after cancel() was called', function () {
    $run  = null;
    $ask  = new ClosureTool(
        new ToolSpec('ask', 'ask', ['type' => 'object'], new Hints()),
        function (array $in, Call $call) use (&$run) {
            $run->cancel();  // the host cancels while this call is still running

            return $call->needsInput([new Approval()]);  // but the tool asks for input anyway
        }
    );
    $model = new FakeModel([
        new ToolCallStart('c1', 'ask'),
        new ToolInputDelta('c1', '{}'),
        new ToolCallEnd('c1'),
        new Usage(10, 5),
        new Finish(FinishReason::ToolCalls),
    ]);
    $run = new Harness\Run($model, new Tools($ask));
    turn($run, user('go'));

    // #11: the turn ended AwaitingInput with the stale call in ended()->pending; a question asked
    // after cancel() is moot, the call gets a cancelled error result and the turn ends Cancelled
    expect($run->ended()->reason)->toBe(Reason::Cancelled)
        ->and($run->ended()->pending)->toBe([]);
});
