<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;
use Harness\Result;

/** Tool call `id` finished: its `Result`, or the Throwable it threw (the model got the message as an error result). */
final readonly class ToolEnded extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id, public Result|\Throwable $outcome)
    {
        parent::__construct($turn, $parent);
    }
}
