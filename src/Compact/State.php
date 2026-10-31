<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * What a `Compact` learned: the counter's `Calibration` (null before the provider's first report)
 * and its latest `Checkpoint`s, newest first, one per conversation it serves (at most 8). A JSON
 * value: a host that builds a `Compact` per request persists `toArray()` next to the conversation
 * and passes `State::fromArray()` to the next one, which then starts calibrated and serves the
 * checkpoint it has without a side call.
 */
final readonly class State implements \JsonSerializable
{
    /** @var list<Checkpoint> */
    public array $checkpoints;

    /** @param list<Checkpoint> $checkpoints newest first */
    public function __construct(public ?Calibration $calibration = null, array $checkpoints = [])
    {
        foreach ($checkpoints as $c) {
            if (!$c instanceof Checkpoint) {
                throw new \InvalidArgumentException('State checkpoints are Checkpoint objects, got ' . \get_debug_type($c));
            }
        }
        $this->checkpoints = \array_values($checkpoints);
    }

    /** @return array{calibration: ?array<string, mixed>, checkpoints: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'calibration' => $this->calibration?->toArray(),
            'checkpoints' => \array_map(static fn (Checkpoint $c) => $c->toArray(), $this->checkpoints),
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
        if (!\array_key_exists('calibration', $data) || !\is_array($data['checkpoints'] ?? null)) {
            throw new \InvalidArgumentException('A Compact state array needs "calibration" (an array or null) and "checkpoints" (a list)');
        }

        return new self(
            null === $data['calibration'] ? null : Calibration::fromArray($data['calibration']),
            \array_map(Checkpoint::fromArray(...), \array_values($data['checkpoints'])),
        );
    }
}
