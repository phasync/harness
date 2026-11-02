<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * How `Compact` last condensed a conversation: the results of reads before `until` (after the
 * kept first messages and any checkpoint) replaced by `$condense`, and read-only calls repeated
 * before `end` dropped but for the latest. `hash` is a content hash of the first `end` messages,
 * so the same condensing serves every later step of the conversation, and the prompt keeps its
 * prefix, until it is over the budget again. A JSON value, part of `State`.
 */
final readonly class Demotion implements \JsonSerializable
{
    public function __construct(public string $hash, public int $until, public int $end)
    {
        if ($until < 1 || $end < $until) {
            throw new \InvalidArgumentException("A demotion ends where the conversation did then, at or after what it condenses, got until $until, end $end");
        }
    }

    /** @return array{hash: string, until: int, end: int} */
    public function toArray(): array
    {
        return ['hash' => $this->hash, 'until' => $this->until, 'end' => $this->end];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['hash' => 'is_string', 'until' => 'is_int', 'end' => 'is_int'] as $key => $is) {
            if (!$is($data[$key] ?? null)) {
                throw new \InvalidArgumentException("A demotion array needs \"$key\" (" . \substr($is, 3) . ')');
            }
        }

        return new self($data['hash'], $data['until'], $data['end']);
    }
}
