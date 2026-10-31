<?php

declare(strict_types=1);

namespace Harness\Compact;

use Harness\FinishReason;

/**
 * A checkpoint `Compact` wrote: `text` stands for the conversation's first `covered` messages after
 * the first `kept`, which stay verbatim before it. `hash` is a content hash of those `covered`
 * messages (of their arrays, not their identity), so a conversation rebuilt from arrays finds it again. `lifted` is the
 * index of the latest user message when the cut fell after it: that message is folded into the
 * checkpoint and also kept verbatim right after it. `previousBytes` is the size of the checkpoint
 * this one updated (null for the first), `finish` how its side call ended. A JSON value, part of
 * `State`.
 */
final readonly class Checkpoint implements \JsonSerializable
{
    public function __construct(
        public string $hash,
        public int $covered,
        public int $kept,
        public ?int $lifted,
        public string $text,
        public ?int $previousBytes,
        public FinishReason $finish,
    ) {
        if ($kept < 0 || $covered <= $kept) {
            throw new \InvalidArgumentException("A checkpoint covers more messages than it keeps before it, got covered $covered, kept $kept");
        }
        if (null !== $lifted && ($lifted < $kept || $lifted >= $covered)) {
            throw new \InvalidArgumentException("A checkpoint's lifted message is one it covers, got $lifted of $kept..$covered");
        }
        if ('' === $text) {
            throw new \InvalidArgumentException('A checkpoint has text');
        }
    }

    /** How many messages the checkpoint replaces: those it covers, less the kept and the lifted one. */
    public function removed(): int
    {
        return $this->covered - $this->kept - (null === $this->lifted ? 0 : 1);
    }

    /** @return array{hash: string, covered: int, kept: int, lifted: ?int, text: string, previousBytes: ?int, finish: string} */
    public function toArray(): array
    {
        return [
            'hash'          => $this->hash,
            'covered'       => $this->covered,
            'kept'          => $this->kept,
            'lifted'        => $this->lifted,
            'text'          => $this->text,
            'previousBytes' => $this->previousBytes,
            'finish'        => $this->finish->value,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['hash' => 'is_string', 'covered' => 'is_int', 'kept' => 'is_int', 'text' => 'is_string', 'finish' => 'is_string'] as $key => $is) {
            if (!$is($data[$key] ?? null)) {
                throw new \InvalidArgumentException("A checkpoint array needs \"$key\" (" . \substr($is, 3) . ')');
            }
        }

        return new self($data['hash'], $data['covered'], $data['kept'], $data['lifted'] ?? null, $data['text'], $data['previousBytes'] ?? null, FinishReason::from($data['finish']));
    }
}
