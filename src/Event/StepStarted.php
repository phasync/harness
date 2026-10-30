<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** Step `n` began: one model call and its tool calls. Step 0 is the resumed tool calls of an answered turn. */
final readonly class StepStarted extends Event
{
    public function __construct(int $turn, ?string $parent, public int $n)
    {
        parent::__construct($turn, $parent);
    }
}
