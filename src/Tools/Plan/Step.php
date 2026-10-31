<?php

declare(strict_types=1);

namespace Harness\Tools\Plan;

/** One step of a `Plan`, numbered from 1 in the order `Plan::set()` gave it. */
final readonly class Step
{
    public function __construct(
        public int $id,
        public string $text,
        public Status $status,
        public ?string $note,
    ) {
    }

    /** @return array{id: int, text: string, status: string, note: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'text' => $this->text, 'status' => $this->status->value, 'note' => $this->note];
    }

    /** @param array{id: int, text: string, status: string, note: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['text'], Status::from($data['status']), $data['note']);
    }
}
