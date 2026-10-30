<?php

declare(strict_types=1);

namespace Harness;

/** How a tool is presented to the model: its name, description, JSON Schema for the input, and hints. */
final readonly class ToolSpec
{
    /** @param array<string, mixed> $schema a JSON Schema of type "object" */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
        public Hints $hints = new Hints(),
    ) {
        if (1 !== \preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
            throw new \InvalidArgumentException("Tool name '$name' must be 1-64 characters of a-z, A-Z, 0-9, _ and -");
        }
        if (($schema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException("The input schema of tool '$name' must have \"type\": \"object\"");
        }
    }

    /** @return array{name: string, description: string, schema: array<string, mixed>, hints: array<string, bool>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'schema' => $this->schema, 'hints' => $this->hints->toArray()];
    }
}
