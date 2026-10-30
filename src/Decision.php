<?php

declare(strict_types=1);

namespace Harness;

/**
 * The answer to an `Approval` (allow, or deny with a reason), or the host declining a `Question`
 * (`deny($reason)`, MCP's `decline`). See `Approval` for who handles a denial.
 */
final readonly class Decision
{
    private function __construct(public bool $allowed, public ?string $reason)
    {
    }

    /** Run the call. */
    public static function allow(): self
    {
        return new self(true, null);
    }

    /** Do not run the call, or decline the question; the reason reaches the model or the asking tool. */
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
