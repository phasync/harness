<?php

declare(strict_types=1);

namespace Harness;

/** Why a turn ended. */
enum Reason: string
{
    /** The model replied without tool calls. */
    case Done = 'done';
    /** A stop predicate returned true. */
    case Stopped = 'stopped';
    /** A tool asked for input; answer the pending calls in the next turn. */
    case AwaitingInput = 'awaiting_input';
    /** `Run::cancel()` was called, or the consumer stopped iterating. */
    case Cancelled = 'cancelled';
    /**
     * `Run::interrupt()` was called: as `Cancelled`, but `Ended::$partial` holds the assistant
     * message as delivered so far, which the host may edit and continue with a `Resume`.
     */
    case Interrupted = 'interrupted';
    /** See `Ended::$errorKind` and `Ended::$error`. */
    case Failed = 'failed';
}
