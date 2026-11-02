<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * What a `Compact` learned: the counter's `Calibration` (null before the provider's first report)
 * and its latest `Checkpoint`s and `Demotion`s, newest first, for the conversations it serves (at
 * most 8 of each). A JSON
 * value: a host that builds a `Compact` per request persists `toArray()` next to the conversation
 * and passes `State::fromArray()` to the next one, which then starts calibrated and serves the
 * checkpoint it has without a side call. That saves a side call outright only while the restored
 * checkpoint's view still fits the request's budget; further over budget it still saves work, by
 * costing one UPDATE call rather than a from-scratch summary.
 */
final readonly class State implements \JsonSerializable
{
    /** @var list<Checkpoint> */
    public array $checkpoints;
    /** @var list<Demotion> */
    public array $demotions;

    /**
     * @param list<Checkpoint> $checkpoints newest first
     * @param list<Demotion>   $demotions   newest first
     */
    public function __construct(public ?Calibration $calibration = null, array $checkpoints = [], array $demotions = [])
    {
        foreach ($checkpoints as $c) {
            if (!$c instanceof Checkpoint) {
                throw new \InvalidArgumentException('State checkpoints are Checkpoint objects, got ' . \get_debug_type($c));
            }
        }
        foreach ($demotions as $d) {
            if (!$d instanceof Demotion) {
                throw new \InvalidArgumentException('State demotions are Demotion objects, got ' . \get_debug_type($d));
            }
        }
        $this->checkpoints = \array_values($checkpoints);
        $this->demotions   = \array_values($demotions);
    }

    /** @return array{calibration: ?array<string, mixed>, checkpoints: list<array<string, mixed>>, demotions: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'calibration' => $this->calibration?->toArray(),
            'checkpoints' => \array_map(static fn (Checkpoint $c) => $c->toArray(), $this->checkpoints),
            'demotions'   => \array_map(static fn (Demotion $d) => $d->toArray(), $this->demotions),
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
        if (!\array_key_exists('calibration', $data) || !\is_array($data['checkpoints'] ?? null) || !\is_array($data['demotions'] ?? null)) {
            throw new \InvalidArgumentException('A Compact state array needs "calibration" (an array or null), "checkpoints" and "demotions" (lists)');
        }

        return new self(
            null === $data['calibration'] ? null : Calibration::fromArray($data['calibration']),
            \array_map(Checkpoint::fromArray(...), \array_values($data['checkpoints'])),
            \array_map(Demotion::fromArray(...), \array_values($data['demotions'])),
        );
    }
}
