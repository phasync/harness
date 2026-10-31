<?php

declare(strict_types=1);

use Harness\Approval;
use Harness\Call;
use Harness\ClosureTool;
use Harness\Compact\Compact;
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
use Harness\ModelException;
use Harness\ModelMiddleware;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Prompt;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Stop;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\ToolSpec;
use Harness\UnsupportedOptionException;
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

/*
 * Round 7: attacking the #36 fix itself. `running` is now false before `TurnEnded` is emitted, so
 * `ended()` works and `interject()` throws in its handler -- but `turn()`'s own guard
 * (`if ($this->running) throw ...`) is the ONLY thing that ever stopped a second `turn()` call
 * while a previous one's generator had not finished. Since `running` is false for the entire
 * `TurnEnded` handling window, nothing stops the host from calling `turn()` again from inside
 * that handler, starting a brand-new turn while the OLD turn()'s generator is still suspended
 * mid-`yield`, with its own cleanup (and, for a failed turn, `turn()`'s documented "A
 * \LogicException ... is rethrown from the iteration after TurnEnded") not yet delivered.
 *
 * For a turn that failed with a misuse `\LogicException`, that rethrow does not happen until the
 * consumer asks the OLD generator for its next event -- which, if a nested turn was started and
 * run to completion inside the TurnEnded handler, is only after that whole second turn finished.
 * The result: the first turn's exception surfaces later, out of its own context, after a
 * completely different (successful) turn has already run and been observed -- not "from the
 * iteration after TurnEnded" of the turn it actually belongs to, as documented, but from whatever
 * code happens to resume the outer generator next, long after the host reasonably believed that
 * turn was already handled.
 *
 * Verified directly against the real code with both adapters: a turn that fails with
 * `UnsupportedOptionException` (a `\LogicException`), handled by starting a second, successful
 * nested turn inside the `TurnEnded` branch, lets the nested turn finish and report
 * `Reason::Done` -- and only then, when the outer foreach resumes, throws the FIRST turn's
 * `UnsupportedOptionException`, landing in a catch the host may reasonably have assumed, by then,
 * covered a turn that had already succeeded.
 */
it('does not let a nested turn() defer the previous turn\'s exception past it', function () {
    $model = new FakeModel(
        static function () {
            throw new UnsupportedOptionException('no temperature here');
        },
        [new TextDelta('second turn text'), new Usage(1, 1), new Finish(FinishReason::Stop)],
    );
    $run    = new Harness\Run($model, new Tools());
    $nested = null;
    $threw  = null;

    \fiber\run(function () use ($run, &$nested, &$threw) {
        try {
            foreach ($run->turn(new Message(Role::User, [new Text('go')])) as $e) {
                if ($e instanceof Harness\Event\TurnEnded) {
                    // The old generator has not finished its last yield (isRunning() is already
                    // false since the #36 fix); a second turn must not start here.
                    try {
                        foreach ($run->turn(new Message(Role::User, [new Text('second')])) as $e2) {
                        }
                    } catch (LogicException $nested) {
                    }
                }
            }
        } catch (Throwable $ex) {
            $threw = $ex;
        }
    });

    // The nested turn() is refused; iterating on delivers the first turn's own exception, as documented.
    expect($nested)->toBeInstanceOf(LogicException::class)
        ->and($nested->getMessage())->toContain('still open')
        ->and($threw)->toBeInstanceOf(UnsupportedOptionException::class);
}); // #41: the nested turn ran to completion and the first turn's exception surfaced after it

/*
 * Round 8: attacking the #41 fix from the angle it did not cover -- a host that simply `break`s
 * out of the foreach right at `TurnEnded` of a turn that failed with a misuse `\LogicException`,
 * and never resumes or releases the generator (no nested turn(), just normal code going on).
 *
 * `turn()`'s docblock promises: "A \LogicException (misuse, such as an option the provider
 * cannot honour) is rethrown from the iteration after TurnEnded." But that rethrow only exists in
 * the generator's own code as `await($task);`, reached only once the `while (($event =
 * $queue->next()) !== null)` loop exits normally -- which requires the consumer to keep asking
 * for events past `TurnEnded`. A `break` right at `TurnEnded` is exactly the pattern the same
 * docblock endorses one line earlier ("Breaking out of the loop cancels the turn"), and it is a
 * completely ordinary way to consume the stream (check the reason at `TurnEnded`, then stop).
 * When the generator is later destroyed (going out of scope, or GC), PHP only runs its pending
 * `finally` block (`$this->open = false; ...; $queue->detach();`); it does not resume execution
 * to reach `await($task)`, so the exception is never "rethrown from the iteration" at all -- that
 * promise is structurally unreachable once the host breaks.
 *
 * Instead, the still-running background task (the `go()`-spawned coroutine that failed with the
 * LogicException) is simply abandoned, unawaited. Nothing in `turn()`'s docblock, the README's
 * "rethrown from the iteration after TurnEnded" line, or `Ended`'s docblock documents what happens
 * to that exception then. Verified directly against the real code with both adapters: it escapes
 * all the way past `\fiber\run()` itself -- not caught by a `try`/`catch` the host wrapped around
 * the very `foreach` that iterates the turn, inside the same `fiber\run()` closure, but only by a
 * `try`/`catch` around the `\fiber\run()` call itself. A host whose error handling (reasonably,
 * following the documented contract) wraps only the turn-consuming code never sees the exception
 * there at all; it surfaces from a scope the docs never mention.
 */
it('does not let a misuse exception escape past fiber\run() when the host breaks at TurnEnded', function () {
    $model = new FakeModel(static function () {
        throw new UnsupportedOptionException('no temperature here');
    });
    $run         = new Harness\Run($model, new Tools());
    $caughtInner = null;
    $caughtOuter = null;

    try {
        \fiber\run(function () use ($run, &$caughtInner) {
            try {
                foreach ($run->turn(new Message(Role::User, [new Text('go')])) as $e) {
                    if ($e instanceof Harness\Event\TurnEnded) {
                        break;  // an ordinary way to consume the stream; never resumed again
                    }
                }
            } catch (Throwable $ex) {
                $caughtInner = $ex;
            }
        });
    } catch (Throwable $ex) {
        $caughtOuter = $ex;
    }

    // Today: $caughtInner is null and $caughtOuter is the UnsupportedOptionException -- the
    // exception the docs promise "is rethrown from the iteration after TurnEnded" instead escapes
    // past fiber\run() entirely, bypassing a try/catch the host placed around the very foreach
    // that consumes the turn, exactly as the docblock's own example shows.
    expect($caughtInner)->toBeInstanceOf(UnsupportedOptionException::class)
        ->and($caughtOuter)->toBeNull();
}); // #47: the failed task's exception surfaced from fiber\run(), past the host's handler

/*
 * Round 9: attacking the #47 fix's own escape hatch. `turn()`'s docblock, and the #41 error
 * message for a reentrant call, both treat "release it" (let the generator go out of scope, or
 * `unset()` it, without exhausting it) as a legitimate alternative to exhausting the generator.
 * But #47's fix made the `finally` block call `await($task)` whenever the LAST event delivered
 * was `TurnEnded` -- and that `finally` only runs once the generator is actually destroyed. If the
 * host instead HOLDS the generator open (an ordinary pattern: break at TurnEnded, keep the
 * generator in a variable for later, go on with other work inside the same `fiber\run()` call),
 * the generator is not destroyed yet, so the `finally` -- and the `await($task)` inside it --
 * never runs while still inside that `fiber\run()` call. Structured concurrency then has an
 * outstanding, unreconciled child (`$task`, and the turn's own scope) that nothing is waiting on
 * in the normal way, and the whole `fiber\run()` call deadlocks instead of returning.
 *
 * This reproduces even for a turn that ends completely successfully (`Reason::Done`, no
 * exception anywhere) -- it has nothing to do with the misuse-exception machinery #47 just fixed;
 * it is the plain act of not draining the generator before the enclosing `fiber\run()` call
 * returns. Nothing in `turn()`'s docblock says the generator must be exhausted or released before
 * the `fiber\run()` call it was created in returns, and the #41 message's "release it" wording
 * implies the opposite -- that holding it a while longer, then releasing it, is fine.
 *
 * Verified directly against the real code with both adapters: holding the generator open across
 * `break` and letting the closure return makes `fiber\run()` itself fail -- a
 * `phasync\DeadlockException` on the phasync adapter, an event-loop stall error on the revolt one
 * -- for a turn that produced no error of its own.
 */
it('does not deadlock fiber\run() when the generator is held open past TurnEnded', function () {
    // Run as a subprocess: this reproduces as fiber\run() itself crashing (verified standalone,
    // both adapters), but inside a long-lived shared process (such as this very test run) the
    // same wedge can hang the event loop indefinitely instead of failing fast -- a subprocess with
    // a hard timeout is what keeps a genuine hang from taking the test runner down with it.
    $script = \tempnam(\sys_get_temp_dir(), 'round9_');
    \file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1] . '/vendor/autoload.php';
        use Harness\Delta\Finish;
        use Harness\Delta\TextDelta;
        use Harness\FinishReason;
        use Harness\Message;
        use Harness\Part\Text;
        use Harness\Role;
        use Harness\Run;
        use Harness\Testing\FakeModel;
        use Harness\Tools;
        use Harness\Usage;
        \fiber\setup('revolt' === ($argv[2] ?? 'phasync') ? new FiberInterop\Revolt\Adapter() : new FiberInterop\Phasync\Adapter());
        $model = new FakeModel([new TextDelta('ok'), new Usage(1, 1), new Finish(FinishReason::Stop)]);
        $run = new Run($model, new Tools());
        $gen = null;
        \fiber\run(function () use ($run, &$gen) {
            $gen = $run->turn(new Message(Role::User, [new Text('go')]));
            foreach ($gen as $e) {
                if ($e instanceof Harness\Event\TurnEnded) {
                    break; // hold $gen open; an ordinary pattern, not an exhaust-or-release misuse
                }
            }
            // ... the host goes on with other work inside the same fiber\run() call ...
        });
        echo "DONE\n";
        PHP);

    $adapter  = \getenv('HARNESS_ADAPTER') ?: 'phasync';
    $proc     = \proc_open(['php', $script, \dirname(__DIR__, 3), $adapter], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $deadline = \microtime(true) + 5.0;
    do {
        $status = \proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        \usleep(20_000);
    } while (\microtime(true) < $deadline);
    $timedOut = $status['running'];
    if ($timedOut) {
        \proc_terminate($proc, 9);
    }
    $out = \stream_get_contents($pipes[1]);
    foreach ($pipes as $p) {
        \fclose($p);
    }
    \proc_close($proc);
    \unlink($script);

    // Today: the subprocess either hangs past the 5 s deadline (killed here, $timedOut true) or
    // exits non-zero with an uncaught DeadlockException/event-loop-stall error -- for a turn that
    // ended Done with no exception anywhere. Merely holding the generator open, which the docs
    // never forbid (the #41 message's "release it" wording implies holding it a while is fine),
    // wedges fiber\run()'s own bookkeeping instead of letting it return normally.
    expect($timedOut)->toBeFalse()
        ->and(\trim($out))->toBe('DONE');
}); // #53: TurnEnded was pushed synchronously; the task waited for a next() that never came

/*
 * Round 10: attacking #53 from the angle it explicitly opened up. `turn()`'s docblock now reads:
 * "Once TurnEnded is out the turn's work is done: the generator may be kept and finished later."
 * -- holding the generator across TurnEnded, then finishing it later (not necessarily inside the
 * same fiber\run() call), is the documented, supported pattern #53 made safe from deadlocking.
 *
 * But #53 also made `TurnEnded` pushed with `wait: false`, so the turn's background task runs to
 * completion (and, for a failed turn, fails) immediately, without the host ever having asked for
 * a next event -- before the host's own code even resumes from the `yield`. Nobody calls
 * `await($task)` for that already-finished, already-failed task unless the generator is actually
 * destroyed (the `finally`'s `if ($event instanceof TurnEnded && null !== current()) { await($task);
 * }`, round 9's fix). If the host does exactly what the docblock now invites -- breaks at
 * TurnEnded, holds the generator in a variable to finish "later" -- and that "later" is after the
 * CURRENT `fiber\run()` call's closure has already returned (the generator still alive, just not
 * touched again yet), the task is left unawaited for the rest of that `fiber\run()` call. Nothing
 * in `turn()`'s docblock or the README's "rethrown from the iteration after TurnEnded, or from
 * the break that leaves the loop there" says what happens then -- and what happens is that
 * `fiber\run()` itself throws the turn's `\LogicException`, as an unawaited task failure, past a
 * try/catch the host wrapped around the very code that iterates and holds the generator.
 *
 * This is the same externally-visible symptom as #47 (fixed in round 8: the exception escaping
 * past fiber\run() instead of surfacing from the turn's own iteration/break), reached through a
 * different door: not by abandoning the generator, but by doing precisely what the docblock now
 * recommends -- keeping it to finish later.
 *
 * Verified directly against the real code with both adapters: breaking at TurnEnded of a turn
 * that failed with `UnsupportedOptionException`, holding the generator in a variable and letting
 * the enclosing `fiber\run()` closure return without touching it again, makes `fiber\run()` throw
 * that exception -- not the try/catch inside the closure that did the holding.
 */
it('does not let fiber\run() throw a held turn\'s exception before the host finishes it', function () {
    $model = new FakeModel(static function () {
        throw new UnsupportedOptionException('no temperature here');
    });
    $run         = new Harness\Run($model, new Tools());
    $gen         = null;
    $caughtOuter = null;

    try {
        \fiber\run(function () use ($run, &$gen) {
            $gen = $run->turn(new Message(Role::User, [new Text('go')]));
            foreach ($gen as $e) {
                if ($e instanceof Harness\Event\TurnEnded) {
                    break;  // hold $gen to finish later, as the docblock allows
                }
            }
        });
    } catch (Throwable $ex) {
        $caughtOuter = $ex;
    }

    // fiber\run() returns normally; the exception is the host's when it lets the generator go.
    expect($caughtOuter)->toBeNull()
        ->and(function () use (&$gen) {
            $gen = null;
        })->toThrow(UnsupportedOptionException::class);
}); // #58: fiber\run() threw it as an unawaited task error

/*
 * Round 11: attacking the #58 ruling "a turn left before TurnEnded is cancelled and keeps no
 * exception". The generator's `finally` only takes the rethrow path when `$event instanceof
 * TurnEnded` (Run.php: `if ($event instanceof TurnEnded && !$finished) { ...; $this->rethrow(); }`)
 * -- for any earlier event it just treats the break as an ordinary cancel (`$this->cancel();
 * $queue->detach();`), nothing more. That is exactly right when the host breaks at `TurnStarted`:
 * `loop()`'s own `if ($this->isCancelled()) { $reason = Reason::Cancelled; break; }` check, at the
 * top of the step loop, runs before the model is ever called, so a model that would have thrown
 * never gets the chance to -- `Ended::$reason` is genuinely `Cancelled`, nothing lost.
 *
 * But `loop()` has no such check between emitting `StepStarted`/`MessageStarted` and actually
 * driving the model (`modelStep()`'s `foreach ($this->modelChain(...) as $delta)`). If the host
 * breaks right after `MessageStarted` instead of `TurnStarted`, the generator's `finally` sees an
 * event that is not `TurnEnded`, so it still only cancels -- no `rethrow()`. But once `detach()`
 * wakes the producer back up, `loop()` resumes straight into driving the model, which throws its
 * `\LogicException` regardless of the cancellation flag (there is nothing checking it between
 * `MessageStarted` and the model call). `loop()`'s catch block sets `Reason::Failed` with the real
 * error, unconditionally -- cancellation never overrides an exception that already happened. So
 * `Ended::$reason` ends up `Failed`, with the real `UnsupportedOptionException` in `Ended::$error`
 * -- contradicting "a turn left before TurnEnded is cancelled" for a break that happens only one
 * event later than the case the ruling was verified against, with no way left to retrieve that
 * exception from the generator (the one `finally` that could have called `rethrow()` already
 * ran and chose not to, since the last event wasn't `TurnEnded`).
 *
 * Verified directly against the real code with both adapters: breaking right after
 * `MessageStarted` of a turn whose model throws `UnsupportedOptionException` on its first pull
 * delivers nothing to either the inner or outer try/catch, yet `$run->ended()->reason` is
 * `Reason::Failed`, not `Reason::Cancelled` as the ruling for "left before TurnEnded" promises.
 */
it('keeps the "left before TurnEnded is cancelled" ruling consistent across events', function () {
    $model = new FakeModel(static function () {
        throw new UnsupportedOptionException('no temperature here');
    });
    $run    = new Harness\Run($model, new Tools());
    $caught = null;

    try {
        \fiber\run(function () use ($run, &$caught) {
            try {
                foreach ($run->turn(new Message(Role::User, [new Text('go')])) as $e) {
                    if ($e instanceof Harness\Event\MessageStarted) {
                        break;  // one event later than TurnStarted, still well before TurnEnded
                    }
                }
            } catch (Throwable $ex) {
                $caught = $ex;
            }
        });
    } catch (Throwable $ex) {
        $caught = $caught ?? $ex;
    }

    // The abandoned turn finished the step it was in, which failed: ended() says so, nothing is rethrown.
    expect($caught)->toBeNull()
        ->and($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->error)->toBeInstanceOf(UnsupportedOptionException::class);
}); // #63: the docblock promised Cancelled; the step failed first, and that is what ended() tells

/*
 * Round 12: `Agents\AgentTool::call()` promises "A failed child is an error result" (docs/agents.md),
 * and its own code backs that up: `$ended = $run->ended(); if (Reason::Failed === $ended->reason) {
 * throw new \RuntimeException("The agent '{name}' failed (...): ...", 0, $ended->error); }` --
 * converting ANY failed child into a plain `\RuntimeException`, which `Run::runCall()` then turns
 * into a normal error `Result` for the parent's model (a `\RuntimeException` is not a
 * `\LogicException`, so it does not hit the `if ($e instanceof \LogicException) { throw $e; }`
 * misuse rethrow in `runCall()`'s catch).
 *
 * But that conversion only happens if execution reaches it. `AgentTool::call()`'s own
 * `foreach ($run->turn($turn, $call) as $event) { $call->emit($event); }` fully drains the
 * child's turn with no `break` -- exactly the "exhausted normally" path that, since round 11/#58,
 * calls `$this->rethrow()` on the CHILD Run once its generator is spent, throwing the child's own
 * misuse `\LogicException` (if its turn failed with one) straight out of that `foreach`. That
 * throw happens one line *before* `$ended = $run->ended();` is ever reached -- so the "failed
 * child is an error result" conversion is skipped entirely. The child's raw `\LogicException`
 * (not a `\RuntimeException`) then propagates out of `AgentTool::call()` into the parent's
 * `toolChain()`/`runCall()`, which mark it 'tool' and then hit their OWN
 * `if ($e instanceof \LogicException) { throw $e; }` -- failing the WHOLE PARENT TURN
 * (`Reason::Failed`, `ErrorKind::ToolError`) and re-throwing the child's exception from the
 * parent's own `turn()`, instead of giving the parent's model a contained error result it could
 * see and work around.
 *
 * Verified directly against the real code with both adapters: a child `Run` whose model throws
 * `UnsupportedOptionException` (a misuse `\LogicException`) makes the PARENT's `turn()` itself
 * throw that same exception, with `$parent->ended()->reason` `Failed` and
 * `$parent->ended()->error` the raw, unwrapped child exception -- not the
 * `"The agent '...' failed (...): ..."` `\RuntimeException` the docs and `AgentTool`'s own code
 * promise, and not the parent turn continuing with an error result as it does for every other
 * kind of child failure (verified elsewhere with a plain `RuntimeException`).
 */
it('ends the parent turn Failed with a child\'s misuse exception, which is the host\'s bug', function () {
    $child  = new Harness\Run(new FakeModel(static function () {
        throw new UnsupportedOptionException('child cannot do that');
    }), new Tools());
    $agent  = new Harness\Agents\AgentTool($child, 'sub', 'Sub');
    $model  = new FakeModel(
        [
            new ToolCallStart('p', 'sub'),
            new ToolInputDelta('p', \json_encode(['prompt' => 'x'])),
            new ToolCallEnd('p'),
            new Usage(10, 5),
            new Finish(FinishReason::ToolCalls),
        ],
        [new TextDelta('ok'), new Usage(1, 1), new Finish(FinishReason::Stop)],
    );
    $parent = new Harness\Run($model, new Tools($agent));
    $threw  = null;

    try {
        \fiber\run(function () use ($parent) {
            foreach ($parent->turn(new Message(Role::User, [new Text('go')])) as $e) {
            }
        });
    } catch (Throwable $ex) {
        $threw = $ex;
    }

    // The child's misuse is the host's bug: it ends the parent turn Failed with that exception, as
    // the parent's own would; only the child's runtime failures are error results.
    expect($threw)->toBeInstanceOf(UnsupportedOptionException::class)
        ->and($parent->ended()->reason)->toBe(Reason::Failed)
        ->and($parent->ended()->error)->toBe($threw);
}); // #68: docs/agents.md promised an error result for every child failure

/*
 * Round 13: Compact's side call (the summarizer) rejecting its OWN prompt as too long is
 * indistinguishable, from the host's side, from the MAIN conversation model rejecting the
 * conversation itself.
 *
 * `Compact::compact()` -- which calls `summarize()`, which drives `$this->summarizer->stream($prompt)`
 * -- runs entirely OUTSIDE the `try { ...; foreach ($next($compacted) as $delta) {...}; } catch
 * (ModelException $e) { if (ErrorKind::ContextTooLong !== $e->kind || $started) { throw $e; } ...
 * }` block in `Compact::stream()` (Compact.php): that catch, and the whole 85/70/50/35 % retry
 * ladder docs/compact.md describes under "A prompt the provider rejects", wraps only the call to
 * `$next($compacted)` -- the MAIN model, further down `modelChain()`. `[$compacted, ...] =
 * $this->compact($prompt, $share);` sits one line above the try, unprotected. So when the
 * summarizer itself throws a `ModelException` with `ErrorKind::ContextTooLong` -- its own context
 * window too small for the chunk `Compact` asked it to fold -- that exception propagates straight
 * out of `Compact::stream()`, skipping the retry ladder entirely, and reaches `Run::modelStep()`'s
 * catch, which (Run.php) does not mark a `ModelException` at all: `if (!isset($this->origin[$e])
 * && !$e instanceof ModelException && ...) { $this->mark($e, 'middleware'); }` -- explicitly
 * skipped because it already IS a `ModelException`. `Run::loop()`'s `$kind` match then takes
 * `$e instanceof ModelException => $e->kind` first, giving `ErrorKind::ContextTooLong`.
 *
 * The result: a host that branches on `errorKind === ErrorKind::ContextTooLong` to mean "the
 * conversation itself needs trimming, raise Options::$contextWindow or budgetTokens" gets exactly
 * that signal for a problem with a completely different cause and fix -- the summarizer's own
 * window, not the main model's or the conversation's -- with no way to tell the two apart from
 * `Ended::$errorKind` alone.
 *
 * Verified directly against the real code with both adapters: a conversation whose prompt is
 * genuinely over `Compact`'s budget (so it tries to fold a large tool result), paired with a
 * summarizer that rejects the side-call prompt with `ModelException(ErrorKind::ContextTooLong)`,
 * ends the turn `Reason::Failed` with `ErrorKind::ContextTooLong` -- identical to what the MAIN
 * model rejecting the real conversation would report.
 */
it('does not report the summarizer\'s own ContextTooLong as the conversation\'s', function () {
    $bigResult = \str_repeat('x', 20000);
    $messages  = [
        new Message(Role::User, [new Text('look something up')]),
        new Message(Role::Assistant, [new ToolCall('c1', 'lookup', [])]),
        new Message(Role::User, [new ToolResult('c1', [new Text($bigResult)])]),
        new Message(Role::Assistant, [new Text('found it')]),
        new Message(Role::User, [new Text('continue, please, this is the latest message')]),
    ];
    $summarizer = new FakeModel(static function () {
        throw new ModelException(ErrorKind::ContextTooLong, "the summarizer's own context window is too small for this chunk");
    });
    $compact   = new Compact($summarizer, budgetTokens: 200, keepFirst: 0, keepRecentTokens: 5);
    $mainModel = new FakeModel([new TextDelta('ok'), new Usage(1, 1), new Finish(FinishReason::Stop)]);
    $run       = new Harness\Run($mainModel, new Tools(), messages: $messages, middleware: [$compact]);

    turn($run, user('next'));

    // Today: errorKind is ContextTooLong, exactly as if the MAIN model had rejected the real
    // conversation -- even though the actual conversation prompt was never sent anywhere; only
    // the summarizer's own, much smaller side-call prompt was rejected, by the summarizer.
    expect($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->errorKind)->toBe(ErrorKind::MiddlewareError);
}); // #75: the summarizer's ContextTooLong was the turn's ErrorKind

/*
 * Round 14, fresh ground on Compact (the generator-lifecycle angle having had five rounds):
 * a summarizer that collapses twice in a row -- here, by returning no text at all, both times --
 * makes Compact::summarize() throw a plain \RuntimeException ("the summarizer returned no text
 * ..., twice; ..."), per its own docblock and CompactTest.php's
 * 'fails the step on a second collapsed checkpoint, naming both sizes', which calls Compact's
 * step() directly and asserts the message, but never through a real Run -- so Run::loop()'s
 * ErrorKind classification for this specific exception (an unmarked \RuntimeException, caught by
 * modelStep() and marked 'middleware' because it did not come from the model or a tool) is
 * verified here, not merely assumed. This is the fallback this round's charter allows when no new
 * defect survives genuine attack: a regression pinning a verified-but-uncovered behaviour.
 */
it('reports a second collapsed checkpoint as the turn\'s MiddlewareError, not ModelError', function () {
    $bigResult = \str_repeat('x', 20000);
    $messages  = [
        new Message(Role::User, [new Text('look something up')]),
        new Message(Role::Assistant, [new ToolCall('c1', 'lookup', [])]),
        new Message(Role::User, [new ToolResult('c1', [new Text($bigResult)])]),
        new Message(Role::Assistant, [new Text('found it')]),
        new Message(Role::User, [new Text('continue, please, this is the latest message')]),
    ];
    // Two replies, both with no Text part at all: summarize() sees '' twice and fails loudly
    // on the second attempt, never silently folding an empty checkpoint.
    $summarizer = new FakeModel([new Finish(FinishReason::Stop)], [new Finish(FinishReason::Stop)]);
    $compact    = new Compact($summarizer, budgetTokens: 200, keepFirst: 0, keepRecentTokens: 5);
    $mainModel  = new FakeModel([new TextDelta('ok'), new Usage(1, 1), new Finish(FinishReason::Stop)]);
    $run        = new Harness\Run($mainModel, new Tools(), messages: $messages, middleware: [$compact]);

    turn($run, user('next'));

    expect($run->ended()->reason)->toBe(Reason::Failed)
        ->and($run->ended()->errorKind)->toBe(ErrorKind::MiddlewareError)
        ->and($run->ended()->error)->toBeInstanceOf(RuntimeException::class)
        ->and($run->ended()->error->getMessage())->toContain('returned no text')
        ->and($run->ended()->error->getMessage())->toContain('twice');
});
