<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** Step `n` ended. */
final readonly class StepEnded extends Event
{
    public function __construct(int $turn, ?string $parent, public int $n)
    {
        parent::__construct($turn, $parent);
    }
}
