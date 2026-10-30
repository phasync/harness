<?php

declare(strict_types=1);

namespace Harness;

/**
 * The host's answer to one `Pending` call: a `Decision` for an approval, or the answers to its
 * questions keyed by question id. Passed to the next `Run::turn()`.
 */
final readonly class Answer
{
    /** @param Decision|array<string, mixed> $answer */
    public function __construct(public string $callId, public Decision|array $answer)
    {
    }

    /** @return array{callId: string, decision: ?array{allowed: bool, reason: ?string}, answers: ?array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'callId'   => $this->callId,
            'decision' => $this->answer instanceof Decision ? $this->answer->toArray() : null,
            'answers'  => \is_array($this->answer) ? $this->answer : null,
        ];
    }

    /** @param array{callId: string, decision: ?array{allowed: bool, reason: ?string}, answers: ?array<string, mixed>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['callId'], null !== $data['decision'] ? Decision::fromArray($data['decision']) : $data['answers']);
    }
}
