<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Event;

/**
 * The model goes on with the assistant message of a `Resume`, in place of `MessageStarted`: the
 * deltas that follow under `id` extend that message, and `MessageEnded` carries the whole of it.
 */
final readonly class MessageResumed extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id)
    {
        parent::__construct($turn, $parent);
    }
}
