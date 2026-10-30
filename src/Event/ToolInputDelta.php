<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** A fragment of tool call `id`'s JSON input. */
final readonly class ToolInputDelta extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id, public string $json)
    {
        parent::__construct($turn, $parent);
    }
}
