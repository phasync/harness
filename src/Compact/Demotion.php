<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * Where `Compact` last demoted a conversation's tool results: those before `until` (after the kept
 * first messages and any checkpoint), condensed from `condensed` on and stubbed before it. `hash`
 * is a content hash of the first `until` messages, so the same boundaries serve every later step
 * of the conversation, and the prompt keeps its prefix, until it is over the budget again. A JSON
 * value, part of `State`.
 */
final readonly class Demotion implements \JsonSerializable
{
    public function __construct(public string $hash, public int $until, public int $condensed)
    {
        if ($until < 1 || $condensed < 0 || $condensed > $until) {
            throw new \InvalidArgumentException("A demotion condenses from an index at or before the one it ends at, got condensed $condensed, until $until");
        }
    }

    /** @return array{hash: string, until: int, condensed: int} */
    public function toArray(): array
    {
        return ['hash' => $this->hash, 'until' => $this->until, 'condensed' => $this->condensed];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['hash' => 'is_string', 'until' => 'is_int', 'condensed' => 'is_int'] as $key => $is) {
            if (!$is($data[$key] ?? null)) {
                throw new \InvalidArgumentException("A demotion array needs \"$key\" (" . \substr($is, 3) . ')');
            }
        }

        return new self($data['hash'], $data['until'], $data['condensed']);
    }
}
