<?php

declare(strict_types=1);

namespace Harness;

/**
 * Something that happened in a turn. Items follow `started → delta* → ended`; every event carries
 * the `turn` number of its run and the `parent` tool call id when the run is nested in another
 * (null at the top). `toArray()` gives `type` (the class name in snake_case) and the fields, with
 * enums as their values, value objects as their arrays and a Throwable as `{class, message}`; it is
 * also what `json_encode()` writes.
 */
abstract readonly class Event implements \JsonSerializable
{
    public function __construct(public int $turn, public ?string $parent)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $short = \substr(static::class, \strrpos(static::class, '\\') + 1);
        $out   = ['type' => \strtolower((string) \preg_replace('/(?<!^)[A-Z]/', '_$0', $short))];
        foreach (\get_object_vars($this) as $name => $value) {
            $out[$name] = self::export($value);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function export(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum                           => $value->value,
            $value instanceof \Throwable                            => ['class' => $value::class, 'message' => $value->getMessage()],
            \is_object($value) && \method_exists($value, 'toArray') => $value->toArray(),
            \is_array($value)                                       => \array_map(self::export(...), $value),
            default                                                 => $value,
        };
    }
}
