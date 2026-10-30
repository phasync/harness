<?php

declare(strict_types=1);

namespace Harness;

/**
 * How a tool is presented to the model: its name, description, JSON Schema for the input, and
 * hints. The schema must have `"type": "object"`. Schema nodes that PHP holds as empty arrays but
 * JSON Schema needs as objects (`properties: []`, a property's schema `[]`, `additionalProperties:
 * []`, ...) are normalized to `stdClass`, so every backend receives `{}`; `required: []` stays a list.
 */
final readonly class ToolSpec
{
    /** Keywords whose value is a map of name => schema. */
    private const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];
    /** Keywords whose value is one schema. */
    private const SCHEMAS = ['additionalProperties', 'unevaluatedProperties', 'propertyNames', 'contains', 'not', 'if', 'then', 'else', 'additionalItems', 'unevaluatedItems'];
    /** Keywords whose value is a list of schemas. */
    private const SCHEMA_LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** @var array<string, mixed> */
    public array $schema;

    /** @param array<string, mixed> $schema a JSON Schema of type "object" */
    public function __construct(
        public string $name,
        public string $description,
        array $schema,
        public Hints $hints = new Hints(),
    ) {
        if (1 !== \preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
            throw new \InvalidArgumentException("Tool name '$name' must be 1-64 characters of a-z, A-Z, 0-9, _ and -");
        }
        if (($schema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException("The input schema of tool '$name' must have \"type\": \"object\", got " . \json_encode($schema['type'] ?? null));
        }
        $this->schema = self::normalize($schema);
    }

    /** @return array{name: string, description: string, schema: array<string, mixed>, hints: array<string, bool>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'schema' => $this->schema, 'hints' => $this->hints->toArray()];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $node): array
    {
        foreach ($node as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }
            if (\in_array($key, self::SCHEMA_MAPS, true)) {
                $node[$key] = [] === $value ? new \stdClass() : \array_map(self::schema(...), $value);
            } elseif (\in_array($key, self::SCHEMAS, true)) {
                $node[$key] = self::schema($value);
            } elseif (\in_array($key, self::SCHEMA_LISTS, true)) {
                $node[$key] = \array_map(self::schema(...), $value);
            } elseif ('items' === $key) {
                $node[$key] = [] !== $value && \array_is_list($value) ? \array_map(self::schema(...), $value) : self::schema($value);
            }
        }

        return $node;
    }

    /** One schema node: an empty array is the empty schema `{}`. */
    private static function schema(mixed $node): mixed
    {
        if (!\is_array($node)) {
            return $node;
        }

        return [] === $node ? new \stdClass() : self::normalize($node);
    }
}
