<?php

declare(strict_types=1);

namespace Harness;

/**
 * One piece of message content: Text, Reasoning, ToolCall, ToolResult, Media or Reference.
 * The set is closed; the same serialization (`toArray()` with a `type` key) is used by the
 * transcript, the event stream and resume. Decode a part of unknown type with `Parts::fromArray()`.
 */
interface Part
{
    /** @return array<string, mixed> a JSON-safe array with a `type` key */
    public function toArray(): array;

    /** @param array<string, mixed> $data the output of `toArray()` */
    public static function fromArray(array $data): static;
}
