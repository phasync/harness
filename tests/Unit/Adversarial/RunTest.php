<?php

declare(strict_types=1);

use Harness\Approval;
use Harness\Call;
use Harness\ClosureTool;
use Harness\Delta\Finish;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\ErrorKind;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Message;
use Harness\ModelMiddleware;
use Harness\Prompt;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Stop;
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

/*
 * Round 2: a Stop predicate that throws. `Run::loop()` runs the host's predicates as plain
 * closures, outside `modelStep()`/`toolChain()`, and never marks their exceptions in `$this->origin`.
 * The `catch (\Throwable $e)` block's `$kind` match has cases for ModelException, TransportException,
 * ToolRetriesExceeded, and `$this->origin[$e]` tagged 'middleware' or 'tool' -- anything else,
 * including an unmarked exception, falls to `default => ErrorKind::ModelError`.
 *
 * Compare that to `modelStep()`'s own catch block (Run.php): an exception that is not a
 * ModelException/TransportException and not already marked is explicitly tagged 'middleware'
 * there ("if (!isset($this->origin[$e]) ...) { $this->mark($e, 'middleware'); }"), precisely
 * because it came from somewhere other than the model (typically a `ModelMiddleware`) and must
 * not be blamed on the model. A `Stop` predicate is exactly the same kind of host-supplied
 * closure as a `ModelMiddleware` -- but because it runs outside modelStep(), the identical
 * "unmarked, not-from-the-model" case is never tagged and is mislabeled ErrorKind::ModelError
 * instead, even though nothing about the model or the provider failed. A host that branches on
 * `errorKind` (retrying on a transport error, reporting a provider outage on ModelError, etc.)
 * is misled into blaming the model/provider for a bug in its own Stop predicate.
 *
 * Verified directly against the real code with both adapters: a Stop predicate that throws a
 * plain RuntimeException ends the turn Failed with errorKind 'model_error'.
 */
it('does not blame the model for a Stop predicate that throws', function () {
    $tool  = new ClosureTool(new ToolSpec('a', 'a', ['type' => 'object'], new Hints()), fn () => Result::text('A'));
    $model = new FakeModel([
        new ToolCallStart('c1', 'a'),
        new ToolInputDelta('c1', '{}'),
        new ToolCallEnd('c1'),
        new Usage(10, 5),
        new Finish(FinishReason::ToolCalls),
    ]);
    $run = new Harness\Run($model, new Tools($tool), stop: [
        function (Harness\Step $step): bool {
            throw new RuntimeException('predicate blew up');
        },
    ]);
    turn($run, user('go'));

    // Today: errorKind is ModelError, the same label a genuine provider failure gets, even though
    // the exception came from the host's own Stop predicate, not the model or its transport.
    // #16: this was ModelError; a Stop predicate is host code and is marked like a middleware
    expect($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->errorKind)->toBe(ErrorKind::MiddlewareError);
});
