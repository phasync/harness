<?php

declare(strict_types=1);

namespace Harness;

/**
 * Stop predicates: closures `fn (Step $step): bool`, checked after every step the turn continues
 * past (one that called tools, or one that a steered or queued message continues), OR'd. A true one
 * ends the turn with `Reason::Stopped`; an interjection it cut off is in `Ended::$queued`.
 */
final class Stop
{
    /** Stops after step `n`. */
    public static function maxSteps(int $n): \Closure
    {
        if ($n < 1) {
            throw new \InvalidArgumentException('Stop::maxSteps() needs n >= 1');
        }

        return static fn (Step $step): bool => $step->n >= $n;
    }

    /** Stops after a step that called the named tool. */
    public static function toolCalled(string $name): \Closure
    {
        return static function (Step $step) use ($name): bool {
            foreach ($step->calls as $call) {
                if ($call->name === $name) {
                    return true;
                }
            }

            return false;
        };
    }

    /** Stops once the turn's input plus output tokens reach `n`. */
    public static function tokens(int $n): \Closure
    {
        return static fn (Step $step): bool => $step->turnUsage->input + $step->turnUsage->output >= $n;
    }
}
