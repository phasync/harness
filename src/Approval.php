<?php

declare(strict_types=1);

namespace Harness;

/** A request for the host to allow or deny a tool call, answered with a `Decision`. */
final readonly class Approval
{
    public function __construct(public string $reason = '')
    {
    }

    /** @return array{type: 'approval', reason: string} */
    public function toArray(): array
    {
        return ['type' => 'approval', 'reason' => $this->reason];
    }

    /** @param array{reason: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['reason']);
    }
}
