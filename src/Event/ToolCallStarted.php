<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** The model began tool call `id`. */
final readonly class ToolCallStarted extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id, public string $name)
    {
        parent::__construct($turn, $parent);
    }
}
