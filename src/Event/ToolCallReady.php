<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** Tool call `id`'s input is complete and decoded. */
final readonly class ToolCallReady extends Event
{
    /** @param array<string, mixed> $input */
    public function __construct(int $turn, ?string $parent, public string $id, public string $name, public array $input)
    {
        parent::__construct($turn, $parent);
    }
}
