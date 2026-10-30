<?php

declare(strict_types=1);

namespace Harness;

/** A question a tool asks the host; answered under its `id`. `choices` lists allowed answers when non-empty. */
final readonly class Question
{
    /** @param list<string> $choices */
    public function __construct(public string $id, public string $text, public array $choices = [])
    {
        if ('' === $id) {
            throw new \InvalidArgumentException('A Question needs a non-empty id');
        }
    }

    /** @return array{id: string, text: string, choices: list<string>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'text' => $this->text, 'choices' => $this->choices];
    }

    /** @param array{id: string, text: string, choices: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['text'], $data['choices']);
    }
}
