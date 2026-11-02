<?php

declare(strict_types=1);

namespace Harness\Compact;

use Harness\Message;

/**
 * A checkpoint `Compact` made: it stands for the conversation's first `covered` messages. `hash` is
 * a content hash of those messages (of their arrays, not their identity), so a conversation rebuilt
 * from arrays finds it again. A summary checkpoint has the `summary` and the `recent` text, the
 * newest messages it covers as the serialized record shown beside the summary (empty when none
 * were kept); a native one has the `window` the provider made in their place, its compacted item
 * last. A JSON value, part of `State`.
 */
final readonly class Checkpoint implements \JsonSerializable
{
    /** @var list<Message>|null */
    public ?array $window;

    /** @param list<Message>|null $window */
    public function __construct(public string $hash, public int $covered, public string $summary, public string $recent = '', ?array $window = null)
    {
        if ($covered < 1) {
            throw new \InvalidArgumentException("A checkpoint covers at least one message, got $covered");
        }
        if ((null === $window) === ('' === $summary) || (null !== $window && '' !== $recent)) {
            throw new \InvalidArgumentException('A checkpoint has a summary (and maybe recent text) or a native window, exactly one of them');
        }
        foreach ($window ?? [] as $m) {
            if (!$m instanceof Message) {
                throw new \InvalidArgumentException('A checkpoint window is Message objects, got ' . \get_debug_type($m));
            }
        }
        $this->window = null === $window ? null : \array_values($window);
    }

    /** @return array{hash: string, covered: int, summary: string, recent: string, window: list<array<string, mixed>>|null} */
    public function toArray(): array
    {
        return [
            'hash'    => $this->hash,
            'covered' => $this->covered,
            'summary' => $this->summary,
            'recent'  => $this->recent,
            'window'  => null === $this->window ? null : \array_map(static fn (Message $m) => $m->toArray(), $this->window),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['hash' => 'is_string', 'covered' => 'is_int', 'summary' => 'is_string', 'recent' => 'is_string'] as $key => $is) {
            if (!$is($data[$key] ?? null)) {
                throw new \InvalidArgumentException("A checkpoint array needs \"$key\" (" . \substr($is, 3) . ')');
            }
        }

        return new self($data['hash'], $data['covered'], $data['summary'], $data['recent'], null === ($data['window'] ?? null) ? null : \array_map(Message::fromArray(...), $data['window']));
    }
}
