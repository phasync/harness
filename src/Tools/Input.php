<?php

declare(strict_types=1);

namespace Harness\Tools;

use Harness\Retry;

/**
 * @internal reads typed fields from a tool's input; a missing or mistyped field is a `Retry`, so the
 * model is told and may correct it
 */
final class Input
{
    /** @param array<string, mixed> $input */
    public static function string(array $input, string $key, ?string $default = null): string
    {
        $value = $input[$key] ?? $default;
        if (!\is_string($value)) {
            throw new Retry(null === $value ? "'$key' is required" : "'$key' must be a string");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    public static function int(array $input, string $key, int $default, int $min = \PHP_INT_MIN): int
    {
        $value = $input[$key] ?? $default;
        if (\is_string($value) && 1 === \preg_match('/^-?\d+$/', $value)) {
            $value = (int) $value;
        }
        if (!\is_int($value) || $value < $min) {
            throw new Retry("'$key' must be an integer" . (\PHP_INT_MIN !== $min ? " >= $min" : ''));
        }

        return $value;
    }

    /**
     * A number, which a model may send as a numeric string; `$min` is exclusive, so a positive
     * number is `$min = 0`.
     *
     * @param array<string, mixed> $input
     */
    public static function number(array $input, string $key, float $default, float $min = -\INF): float
    {
        $value = $input[$key] ?? $default;
        if (\is_string($value) && \is_numeric($value)) {
            $value = (float) $value;
        }
        if (!\is_int($value) && !\is_float($value) || \is_nan($value) || $value <= $min) {
            throw new Retry("'$key' must be a number" . (-\INF !== $min ? " > $min" : ''));
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $input */
    public static function bool(array $input, string $key, bool $default): bool
    {
        $value = $input[$key] ?? $default;
        if (!\is_bool($value)) {
            throw new Retry("'$key' must be true or false");
        }

        return $value;
    }
}
