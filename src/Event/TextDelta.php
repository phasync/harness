<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** Visible text of message `id`. */
final readonly class TextDelta extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id, public string $text)
    {
        parent::__construct($turn, $parent);
    }
}
