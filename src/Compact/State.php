<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * What a `Compact` learned: its latest `Checkpoint`s and `Anchor`s (the provider's counts), newest
 * first, for the conversations it serves (at most 8 of each). A JSON value: a host that builds a
 * `Compact` per request persists `toArray()` next to the conversation and passes
 * `State::fromArray()` to the next one, which then sends the checkpoint it has without compacting
 * again and sizes the prompt by the provider's last count.
 */
final readonly class State implements \JsonSerializable
{
    /** @var list<Checkpoint> */
    public array $checkpoints;
    /** @var list<Anchor> */
    public array $anchors;

    /**
     * @param list<Checkpoint> $checkpoints newest first
     * @param list<Anchor>     $anchors     newest first
     */
    public function __construct(array $checkpoints = [], array $anchors = [])
    {
        foreach ($checkpoints as $c) {
            if (!$c instanceof Checkpoint) {
                throw new \InvalidArgumentException('State checkpoints are Checkpoint objects, got ' . \get_debug_type($c));
            }
        }
        foreach ($anchors as $a) {
            if (!$a instanceof Anchor) {
                throw new \InvalidArgumentException('State anchors are Anchor objects, got ' . \get_debug_type($a));
            }
        }
        $this->checkpoints = \array_values($checkpoints);
        $this->anchors     = \array_values($anchors);
    }

    /** @return array{checkpoints: list<array<string, mixed>>, anchors: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'checkpoints' => \array_map(static fn (Checkpoint $c) => $c->toArray(), $this->checkpoints),
            'anchors'     => \array_map(static fn (Anchor $a) => $a->toArray(), $this->anchors),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Rebuilds a state from `toArray()` (or its JSON decoded as an array).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!\is_array($data['checkpoints'] ?? null) || !\is_array($data['anchors'] ?? null)) {
            throw new \InvalidArgumentException('A Compact state array needs "checkpoints" and "anchors" (lists)');
        }

        return new self(
            \array_map(Checkpoint::fromArray(...), \array_values($data['checkpoints'])),
            \array_map(Anchor::fromArray(...), \array_values($data['anchors'])),
        );
    }
}
