<?php

declare(strict_types=1);

namespace Harness;

use Harness\Part\ToolCall;

/**
 * A tool call that waits for the host: what it needs (an `Approval` or a list of `Question`s)
 * and the tool's own `state`, which is JSON-safe so it survives a process boundary. Persist it
 * with `toArray()` next to the messages and pass it back to `Run`'s constructor to resume elsewhere.
 */
final readonly class Pending
{
    /** @param Approval|list<Question> $needs */
    public function __construct(public ToolCall $call, public Approval|array $needs, public mixed $state = null)
    {
        if (\is_array($needs)) {
            if ([] === $needs || !\array_is_list($needs)) {
                throw new \InvalidArgumentException('needsInput() takes an Approval or a non-empty list of Question');
            }
            foreach ($needs as $q) {
                if (!$q instanceof Question) {
                    throw new \InvalidArgumentException('needsInput() takes an Approval or a list of Question, got ' . \get_debug_type($q));
                }
            }
        }
        self::assertJsonSafe($state, 'state');
    }

    /** @return array{call: array<string, mixed>, needs: array<mixed>, state: mixed} */
    public function toArray(): array
    {
        return [
            'call'  => $this->call->toArray(),
            'needs' => $this->needs instanceof Approval ? $this->needs->toArray() : \array_map(static fn (Question $q) => $q->toArray(), $this->needs),
            'state' => $this->state,
        ];
    }

    /** @param array{call: array<string, mixed>, needs: array<mixed>, state: mixed} $data */
    public static function fromArray(array $data): self
    {
        $needs = ($data['needs']['type'] ?? null) === 'approval'
            ? Approval::fromArray($data['needs'])
            : \array_map(Question::fromArray(...), $data['needs']);

        return new self(ToolCall::fromArray($data['call']), $needs, $data['state']);
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
