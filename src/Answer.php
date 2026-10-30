<?php

declare(strict_types=1);

namespace Harness;

/**
 * The host's answer to one `Pending` call, keyed by need id: a `Decision` for each `Approval`, and
 * for each `Question` its answer or `Decision::deny($reason)` to decline it. Passed to the next
 * `Run::turn()`. In the array, decisions and values are kept apart:
 * `{"callId", "decisions": {id: {"allowed", "reason"}}, "values": {id: answer}}`.
 */
final readonly class Answer
{
    /** @param array<string, Decision|string|int|float|bool|array<mixed>> $answers */
    public function __construct(public string $callId, public array $answers)
    {
        if ([] === $answers) {
            throw new \InvalidArgumentException("The Answer to tool call $callId is empty; answer each of its needs by id");
        }
        foreach ($answers as $id => $a) {
            if (!$a instanceof Decision && !\is_scalar($a) && !\is_array($a)) {
                throw new \InvalidArgumentException("The answer to need '$id' of tool call $callId is " . \get_debug_type($a) . '; answers are a Decision, a scalar or an array');
            }
        }
    }

    /** @return array{callId: string, decisions: array<string, array{allowed: bool, reason: ?string}>, values: array<string, mixed>} */
    public function toArray(): array
    {
        $decisions = [];
        $values    = [];
        foreach ($this->answers as $id => $a) {
            if ($a instanceof Decision) {
                $decisions[$id] = $a->toArray();
            } else {
                $values[$id] = $a;
            }
        }

        return ['callId' => $this->callId, 'decisions' => $decisions, 'values' => $values];
    }

    /** @param array{callId: string, decisions: array<string, array{allowed: bool, reason: ?string}>, values: array<string, mixed>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['callId'], \array_map(Decision::fromArray(...), $data['decisions']) + $data['values']);
    }
}
