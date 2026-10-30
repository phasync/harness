<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** A progress report from running tool call `id` (`Call::progress()`). */
final readonly class ToolProgress extends Event
{
    /** @param string|array<mixed> $value */
    public function __construct(int $turn, ?string $parent, public string $id, public string|array $value)
    {
        parent::__construct($turn, $parent);
    }
}
