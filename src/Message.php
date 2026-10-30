<?php

declare(strict_types=1);

namespace Harness;

use Harness\Part\Reasoning;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;

/**
 * One message of a conversation: a role and any mix of parts. ToolCall and Reasoning appear only
 * in assistant messages, ToolResult only in user messages (Anthropic's shape; providers map it).
 */
final readonly class Message
{
    /** @param list<Part> $parts */
    public function __construct(public Role $role, public array $parts)
    {
        foreach ($parts as $i => $part) {
            if (!$part instanceof Part) {
                throw new \InvalidArgumentException("Message part $i is " . \get_debug_type($part) . ', not a Harness\Part');
            }
            if (Role::User === $role && ($part instanceof ToolCall || $part instanceof Reasoning)) {
                throw new \InvalidArgumentException(\get_debug_type($part) . ' parts belong in assistant messages');
            }
            if (Role::Assistant === $role && $part instanceof ToolResult) {
                throw new \InvalidArgumentException('ToolResult parts belong in user messages');
            }
        }
        if (!\array_is_list($parts)) {
            throw new \InvalidArgumentException('Message parts must be a list');
        }
    }

    /** @return array{role: string, parts: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['role' => $this->role->value, 'parts' => \array_map(static fn (Part $p) => $p->toArray(), $this->parts)];
    }

    /** @param array{role: string, parts: list<array<string, mixed>>} $data */
    public static function fromArray(array $data): self
    {
        return new self(Role::from($data['role']), \array_map(Parts::fromArray(...), $data['parts']));
    }
}
