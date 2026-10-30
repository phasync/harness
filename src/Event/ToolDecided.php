<?php

declare(strict_types=1);

namespace Harness\Event;

use Harness\Decision;
use Harness\Event;

/**
 * The host's `Decision` on the need `needId` of tool call `id`, from an `Answer` on resume: one
 * event per decision answered, every `Approval` (whatever its id) and every `Question` declined
 * with `Decision::deny()`, in the order of the call's needs. A sub-agent's call emits its own,
 * with `parent` set, beside the event of the parent's call that forwarded it (`w1/approval`).
 */
final readonly class ToolDecided extends Event
{
    public function __construct(int $turn, ?string $parent, public string $id, public string $needId, public Decision $decision)
    {
        parent::__construct($turn, $parent);
    }
}
