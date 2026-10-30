<?php

declare(strict_types=1);

namespace Harness;

/**
 * One thing a paused tool call needs from the host, under an `id` unique within the call: an
 * `Approval` or a `Question`. The host answers each by its id in an `Answer`. `toArray()` gives
 * `type` plus the fields; `Need::fromArray()` rebuilds the right class from it.
 */
abstract readonly class Need
{
    public function __construct(public string $id)
    {
        if ('' === $id) {
            throw new \InvalidArgumentException(static::class . ' needs a non-empty id');
        }
    }

    /** @return array<string, mixed> with `type` ("approval" or "question") and `id` */
    abstract public function toArray(): array;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return match ($data['type'] ?? null) {
            'approval' => new Approval($data['id'], $data['reason']),
            'question' => new Question($data['id'], $data['text'], $data['choices']),
            default    => throw new \InvalidArgumentException('A need\'s type must be "approval" or "question", got ' . \var_export($data['type'] ?? null, true)),
        };
    }
}
