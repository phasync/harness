<?php

declare(strict_types=1);

namespace Harness;

/**
 * A request for the host to allow or deny a tool call, answered with a `Decision` under its `id`
 * (default `approval`, which `Call::decision()` reads).
 *
 * Who handles a denial: the tool's `state` decides. State means "call me back with the answer,
 * whatever it is". A call that asked with a non-null state is called again on a denial, with the
 * denied `Decision` in `answers()`, and handles it itself (`Agents\AgentTool` tells its sub-agent).
 * A call that asked without state is not called again when an approval is denied: the `Run` gives
 * the model the error result `Denied: <reason>`.
 */
final readonly class Approval extends Need
{
    public function __construct(string $id = 'approval', public ?string $reason = null)
    {
        parent::__construct($id);
    }

    /** @return array{type: 'approval', id: string, reason: ?string} */
    public function toArray(): array
    {
        return ['type' => 'approval', 'id' => $this->id, 'reason' => $this->reason];
    }
}
