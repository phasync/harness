<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/** The model began a reply; `id` names the message item. */
final readonly class MessageStarted extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id)
    {
        parent::__construct($turn, $parent);
    }
}
