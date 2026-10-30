<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** A turn began. */
final readonly class TurnStarted extends Event
{
    public function __construct(int $turn, ?string $parent)
    {
        parent::__construct($turn, $parent);
    }
}
