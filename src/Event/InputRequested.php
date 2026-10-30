<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** The turn waits for the host: the pending calls, to be answered with `Answer`s in the next turn. */
final readonly class InputRequested extends Event
{
    /** @param list<\Harness\Pending> $pending */
    public function __construct(int $turn, ?string $parent, public array $pending)
    {
        parent::__construct($turn, $parent);
    }
}
