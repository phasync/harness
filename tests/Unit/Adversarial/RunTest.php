<?php

declare(strict_types=1);

use Harness\Approval;
use Harness\Call;
use Harness\ClosureTool;
use Harness\Delivery;
use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\ErrorKind;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Message;
use Harness\ModelMiddleware;
use Harness\Part\Text;
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

/*
 * Round 4: a `ModelMiddleware` that corrupts the delta stream it wraps (here, duplicating a
 * `ToolCallStart`) rather than the underlying `Model` misbehaving.
 *
 * `Run::modelStep()` folds every delta into the `Accumulator` inside a nested try/catch:
 * `try { $acc->add($delta); } catch (\LogicException $e) { throw $this->mark($e, 'model'); }`
 * (Run.php). That unconditionally tags the exception 'model' -- before the outer catch even gets
 * a chance to apply the generic "unmarked => middleware" rule -- regardless of whether the
 * malformed delta sequence came from the actual `Model` or from a `ModelMiddleware` sitting in
 * `modelChain()` in front of it. `Accumulator`'s own docblock calls this "a provider bug", but a
 * buggy `ModelMiddleware` (duplicating, dropping or reordering deltas) triggers the exact same
 * `\LogicException` ("Tool call X started twice", etc.) without the underlying model doing
 * anything wrong.
 *
 * This is the same shape of bug the round-2/#16 fix just addressed for `Stop` predicates, with the
 * fix's own reasoning ("host code, like a middleware: not the model's fault") applying just as
 * much here -- a `ModelMiddleware` is host code too -- but this call site was untouched by that
 * fix.
 *
 * Verified directly against the real code with both adapters: a `ModelMiddleware` that re-yields
 * one extra `ToolCallStart` makes `Accumulator::add()` throw "Tool call c1 started twice", and the
 * turn ends Failed with errorKind 'model_error', blaming the `FakeModel`, which only ever yielded
 * each delta once.
 */
it('does not blame the model for a ModelMiddleware that corrupts the delta stream', function () {
    $buggy = new class implements ModelMiddleware {
        public function stream(Prompt $prompt, Closure $next): iterable
        {
            foreach ($next($prompt) as $delta) {
                yield $delta;
                if ($delta instanceof ToolCallStart) {
                    yield $delta;  // duplicate: the Accumulator sees "started twice"
                }
            }
        }
    };
    $model = new FakeModel([
        new ToolCallStart('c1', 'a'),
        new ToolInputDelta('c1', '{}'),
        new ToolCallEnd('c1'),
        new Usage(10, 5),
        new Finish(FinishReason::ToolCalls),
    ]);
    $run = new Harness\Run($model, new Tools(), middleware: [$buggy]);
    try {
        turn($run, user('go'));
    } catch (LogicException) {
        // turn()'s own docblock: a LogicException (misuse) is rethrown after TurnEnded, once
        // ended() already reflects it.
    }

    // Today: errorKind is ModelError, blaming the FakeModel, which yielded every delta exactly
    // once; the corruption is entirely the ModelMiddleware's, which should be marked the same way
    // the #16 fix now marks a throwing Stop predicate.
    expect($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->errorKind)->toBe(ErrorKind::MiddlewareError);
}); // #28: this was ModelError; the raw stream is validated before the middleware chain sees it

/*
 * Round 5: attacking the #28 shadow fix from the other side -- a `ModelMiddleware` that *fixes* a
 * malformed provider stream (drops a duplicate `ToolCallStart`) instead of corrupting a clean one.
 *
 * `callModel()` (Run.php) now feeds every raw delta into `$this->shadow` -- a second Accumulator --
 * before it is ever yielded to the middleware chain: `$this->shadow->add($delta); // a malformed
 * sequence is the provider's fault; the middleware chain has not seen it yet`. That ordering is
 * structural, not incidental: the shadow validates the provider's raw output at the bottom of
 * `modelChain()`, one level inside where any `ModelMiddleware::stream()` wraps `$next()`. So a
 * middleware sitting in front of `callModel()` never gets a chance to filter a malformed delta
 * before validation sees it -- the shadow throws, marked 'model', while the delta is still inside
 * `callModel()`'s own generator, before the `yield $delta;` that would hand it to the middleware.
 *
 * Is a repair opportunity part of the contract? No: `Accumulator`'s docblock calls a malformed
 * sequence "a provider bug" with no carve-out for a middleware that cleans it up, `ModelMiddleware`'s
 * own docblock and every documented use (README's retry/read-first/compact examples, docs/compact.md,
 * docs/plan.md) rewrite the `Prompt` or wrap the whole iteration for retry/tracing -- none claims to
 * sanitize individual deltas before the run sees them -- and the fix's own comment states the
 * ordering is deliberate ("the middleware chain has not seen it yet"). So a middleware that repairs
 * a malformed provider stream still fails the turn as ModelError: this is the documented contract,
 * not a break of it.
 *
 * Verified directly against the real code with both adapters: a `FakeModel` that malfunctions
 * (duplicate `ToolCallStart`) wrapped by a middleware that drops the duplicate before yielding it on
 * still ends the turn Failed with errorKind 'model_error', not Done.
 */
it('still blames the model when a middleware would have repaired its malformed stream', function () {
    $model = new FakeModel([
        new ToolCallStart('c1', 'a'),
        new ToolCallStart('c1', 'a'),  // the provider's own bug: a duplicate start
        new ToolInputDelta('c1', '{}'),
        new ToolCallEnd('c1'),
        new Usage(10, 5),
        new Finish(FinishReason::ToolCalls),
    ]);
    $repair = new class implements ModelMiddleware {
        public function stream(Prompt $prompt, Closure $next): iterable
        {
            $seen = [];
            foreach ($next($prompt) as $delta) {
                if ($delta instanceof ToolCallStart) {
                    if (isset($seen[$delta->id])) {
                        continue;  // drop the duplicate: the stream this middleware hands on is clean
                    }
                    $seen[$delta->id] = true;
                }
                yield $delta;
            }
        }
    };
    $run = new Harness\Run($model, new Tools(), middleware: [$repair]);
    try {
        turn($run, user('go'));
    } catch (LogicException) {
        // turn()'s own docblock: a LogicException (misuse) is rethrown after TurnEnded.
    }

    // The shadow validates callModel()'s raw output one level below $repair, so $repair never sees
    // the duplicate before validation fails on it: the turn is Failed/ModelError, not Done, because
    // the provider's bug is real even though downstream middleware would have hidden it.
    expect($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->errorKind)->toBe(ErrorKind::ModelError);
});

/*
 * Round 6: `interject()` called while the host is handling the turn's own `TurnEnded` event.
 *
 * `isRunning()`'s docblock: "True from turn() until the turn's last event, TurnEnded, was
 * handled: while it is, cancel(), interrupt() and interject() have an effect". `Run::loop()`
 * only sets `$this->running = false` AFTER emitting `TurnEnded` (`$emit(new TurnEnded(...));
 * $this->running = false;`), so during the window the consumer's `foreach` body spends handling
 * that event, `isRunning()` is still true, exactly as documented -- `interject()` does not throw.
 *
 * But by that point `$this->ended` was already built, a few lines earlier, from the *old*
 * `$this->steer`/`$this->queue` ("`$this->ended = new Ended(..., [...$this->steer,
 * ...$this->queue]); $this->steer = []; $this->queue = [];`") -- both already reset to `[]`
 * before `TurnEnded` is even emitted. So a message handed to `interject()` during `TurnEnded`'s
 * handling is appended to the now-empty `$this->steer`/`$this->queue`, which nothing will ever
 * read again: not this turn (it is done looping), not `$ended->queued` (already frozen), and not
 * the next turn (`turn()` resets `$this->steer`/`$this->queue` to `[]` again at the top). The
 * call succeeds silently -- no exception -- and the message is gone for good.
 *
 * This breaks `interject()`'s own documented guarantee: "Those that cannot land before the turn
 * ends ... are in Ended::$queued, steered ones first." A message that cannot land and is absent
 * from `Ended::$queued` is exactly the case the docblock says cannot happen.
 *
 * Verified directly against the real code with both adapters: interjecting a Queue message from
 * inside the `if ($e instanceof TurnEnded)` branch of the consuming foreach does not throw, and
 * `$run->ended()->queued` is empty afterwards -- the message is silently lost.
 */
it('does not silently lose a message interjected while handling TurnEnded', function () {
    $model = new FakeModel([new TextDelta('ok'), new Usage(1, 1), new Finish(FinishReason::Stop)]);
    $run   = new Harness\Run($model, new Tools());
    $lost  = new Message(Role::User, [new Text('lost message')]);
    $threw = null;

    \fiber\run(function () use ($run, $lost, &$threw) {
        foreach ($run->turn(new Message(Role::User, [new Text('go')])) as $e) {
            if ($e instanceof Harness\Event\TurnEnded) {
                try {
                    $run->interject($lost, Delivery::Queue);
                } catch (Throwable $ex) {
                    $threw = $ex;
                }
            }
        }
    });

    // Today: interject() does not throw (isRunning() is still true, as its own docblock promises
    // during TurnEnded's handling), yet the message lands neither in a new turn nor in
    // ended()->queued: it is simply gone, contradicting interject()'s "cannot land ... in
    // Ended::$queued" guarantee. Either outcome keeps that guarantee: interject() throws once
    // TurnEnded has been built (the turn has nothing left to hand the message to), or the message
    // is still accounted for in queued.
    expect($threw instanceof LogicException || [] !== $run->ended()->queued)->toBeTrue();
}); // #36: interject() succeeded into orphaned arrays; the turn is over before TurnEnded is emitted now
