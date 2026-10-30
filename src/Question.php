<?php

declare(strict_types=1);

namespace Harness;

/**
 * A question a tool asks the host, answered under its `id` with a value (one of `choices` when
 * they are given), or declined with `Decision::deny($reason)`: the tool is called again either way
 * and sees the `Decision` in `answers()`. The id `approval` belongs to `Approval`.
 */
final readonly class Question extends Need
{
    /** @param non-empty-list<string>|null $choices null: any answer */
    public function __construct(string $id, public string $text, public ?array $choices = null)
    {
        parent::__construct($id);
        if ('approval' === $id) {
            throw new \InvalidArgumentException('The need id "approval" is the Approval\'s, which Call::decision() reads; give the Question another id');
        }
        if (null !== $choices && ([] === $choices || !\array_is_list($choices) || [] !== \array_filter($choices, static fn ($c) => !\is_string($c)))) {
            throw new \InvalidArgumentException("Question '$id': choices are null (any answer) or a non-empty list of strings");
        }
    }

    /** @return array{type: 'question', id: string, text: string, choices: ?list<string>} */
    public function toArray(): array
    {
        return ['type' => 'question', 'id' => $this->id, 'text' => $this->text, 'choices' => $this->choices];
    }
}
