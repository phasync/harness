<?php

declare(strict_types=1);

namespace Harness\Part;

use Harness\Part;

/** A tool call made by the model: the provider's call id, the tool name and the decoded input. */
final readonly class ToolCall implements Part
{
    /** @param array<string, mixed> $input */
    public function __construct(public string $id, public string $name, public array $input)
    {
        if ('' === $id || '' === $name) {
            throw new \InvalidArgumentException('A ToolCall needs a non-empty id and name');
        }
    }

    public function toArray(): array
    {
        return ['type' => 'tool_call', 'id' => $this->id, 'name' => $this->name, 'input' => $this->input];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['id'], $data['name'], $data['input']);
    }
}
