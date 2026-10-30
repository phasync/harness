<?php

declare(strict_types=1);

namespace Harness;

/** The answer to an `Approval`: allow, or deny with a reason the model is told. */
final readonly class Decision
{
    private function __construct(public bool $allowed, public ?string $reason)
    {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }

    /** @return array{allowed: bool, reason: ?string} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason];
    }

    /** @param array{allowed: bool, reason: ?string} $data */
    public static function fromArray(array $data): self
    {
        return $data['allowed'] ? self::allow() : self::deny($data['reason']);
    }
}
