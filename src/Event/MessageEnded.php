<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;
use Harness\FinishReason;
use Harness\Message;
use Harness\Usage;

/** Message `id` is complete: the assistant message, the usage and the finish reason of this reply. */
final readonly class MessageEnded extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id, public Message $message, public Usage $usage, public FinishReason $finish)
    {
        parent::__construct($turn, $parent);
    }
}
