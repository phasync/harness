<?php

declare(strict_types=1);

namespace Harness;

use Harness\Part\ToolCall;

/**
 * A tool call that waits for the host: the list of what it needs (`Approval`s and `Question`s,
 * each with an id unique within the call) and the tool's own `state`, which is JSON-safe so it
 * survives a process boundary. Persist it with `toArray()` next to the messages and pass it back
 * to `Run`'s constructor to resume elsewhere. In the array, `needs` is a list of
 * `{"type": "approval", "id", "reason"}` and `{"type": "question", "id", "text", "choices"}`.
 */
final readonly class Pending
{
    /** @param non-empty-list<Need> $needs */
    public function __construct(public ToolCall $call, public array $needs, public mixed $state = null)
    {
        if ([] === $needs || !\array_is_list($needs)) {
            throw new \InvalidArgumentException('needsInput() takes a non-empty list of Need (Approval, Question)');
        }
        $ids = [];
        foreach ($needs as $need) {
            if (!$need instanceof Need) {
                throw new \InvalidArgumentException('needsInput() takes a list of Need (Approval, Question), got ' . \get_debug_type($need));
            }
            if (isset($ids[$need->id])) {
                throw new \InvalidArgumentException("Tool call {$call->id} lists two needs with id '{$need->id}'; each need is answered by its id");
            }
            $ids[$need->id] = true;
        }
        self::assertJsonSafe($state, 'state');
    }

    /** @return array{call: array<string, mixed>, needs: list<array<string, mixed>>, state: mixed} */
    public function toArray(): array
    {
        return [
            'call'  => $this->call->toArray(),
            'needs' => \array_map(static fn (Need $n) => $n->toArray(), $this->needs),
            'state' => $this->state,
        ];
    }

    /** @param array{call: array<string, mixed>, needs: list<array<string, mixed>>, state: mixed} $data */
    public static function fromArray(array $data): self
    {
        if (!\is_array($data['needs']) || !\array_is_list($data['needs'])) {
            throw new \InvalidArgumentException('Pending needs must be a list of needs ({"type": "approval"|"question", "id", ...}); a single {"type": ...} object is the 0.0.1-beta2 shape');
        }

        return new self(ToolCall::fromArray($data['call']), \array_map(Need::fromArray(...), $data['needs']), $data['state']);
    }

    private static function assertJsonSafe(mixed $value, string $path): void
    {
        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                self::assertJsonSafe($v, "{$path}[$k]");
            }

            return;
        }
        if (null !== $value && !\is_scalar($value)) {
            throw new \InvalidArgumentException("Pending $path is " . \get_debug_type($value) . '; the state must be null, scalars and arrays so it survives a process boundary');
        }
    }
}
