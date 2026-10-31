<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Delivery;
use Harness\Event;
use Harness\Message;

/**
 * A user message given to `Run::interject()` was appended to the conversation here, before the
 * next model call: after the last step's tool results (`Delivery::Steer`), or after a reply that
 * would have ended the turn (`Delivery::Queue`).
 */
final readonly class Interjected extends Event
{
    public function __construct(int $turn, ?string $parent, public Message $message, public Delivery $delivery)
    {
        parent::__construct($turn, $parent);
    }
}
