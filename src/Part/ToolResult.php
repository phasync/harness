<?php

declare(strict_types=1);

namespace Harness\Part;

use Harness\Part;
use Harness\Parts;

/**
 * What the model sees of a tool call's outcome. Travels in a user message. `content` holds Text,
 * Media and Reference parts only; `error` tells the model the call failed.
 */
final readonly class ToolResult implements Part
{
    /** @param list<Text|Media|Reference> $content */
    public function __construct(public string $callId, public array $content, public bool $error = false)
    {
        foreach ($content as $part) {
            if (!$part instanceof Text && !$part instanceof Media && !$part instanceof Reference) {
                throw new \InvalidArgumentException('ToolResult content holds Text, Media and Reference parts only, got ' . \get_debug_type($part));
            }
        }
    }

    public function toArray(): array
    {
        return [
            'type'    => 'tool_result',
            'callId'  => $this->callId,
            'content' => \array_map(static fn (Part $p) => $p->toArray(), $this->content),
            'error'   => $this->error,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['callId'], \array_map(Parts::fromArray(...), $data['content']), $data['error']);
    }
}
