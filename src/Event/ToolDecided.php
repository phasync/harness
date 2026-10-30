<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Decision;
use Harness\Event;

/** The host's decision on tool call `id`, from an `Answer` on resume. */
final readonly class ToolDecided extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id, public Decision $decision)
    {
        parent::__construct($turn, $parent);
    }
}
