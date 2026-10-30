<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;
use Harness\Message;

/**
 * A user message given to `Run::interject()` was appended to the conversation here: after the
 * last step's tool results, before the next model call.
 */
final readonly class Interjected extends Event
{
    public function __construct(int $turn, ?string $parent, public Message $message)
    {
        parent::__construct($turn, $parent);
    }
}
