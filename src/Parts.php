<?php

declare(strict_types=1);

namespace Harness;

use Harness\Part\Media;
use Harness\Part\Reasoning;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;

/**
 * Decodes a serialized part whose class is known only by its `type` key.
 */
final class Parts
{
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): Part
    {
        return match ($data['type'] ?? null) {
            'text'        => Text::fromArray($data),
            'reasoning'   => Reasoning::fromArray($data),
            'tool_call'   => ToolCall::fromArray($data),
            'tool_result' => ToolResult::fromArray($data),
            'media'       => Media::fromArray($data),
            'reference'   => Reference::fromArray($data),
            default       => throw new \InvalidArgumentException('Unknown part type ' . \var_export($data['type'] ?? null, true) . '; expected text, reasoning, tool_call, tool_result, media or reference'),
        };
    }
}
