<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\ErrorKind;
use Harness\Event;
use Harness\FinishReason;
use Harness\Reason;
use Harness\Usage;

/**
 * A turn ended; `error` is set when `reason` is Failed, `finish` is the finish reason of the turn's
 * last assistant message (see `Ended::$finish`). The last event of every turn.
 */
final readonly class TurnEnded extends Event
{
    public function __construct(int $turn, ?string $parent, public Reason $reason, public Usage $usage, public ?ErrorKind $error, public ?FinishReason $finish = null)
    {
        parent::__construct($turn, $parent);
    }
}
