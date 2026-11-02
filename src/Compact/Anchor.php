<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * The provider's count of a step `Compact` passed on: the prompt of the conversation's first
 * `messages` messages (`hash` is their content hash), sent with the checkpoint whose hash is
 * `checkpoint` (empty for none), and the reply to it took `tokens` in all, input and output. The
 * next step's prompt is sized as this count plus an estimate of the messages added since. A JSON
 * value, part of `State`.
 */
final readonly class Anchor implements \JsonSerializable
{
    public function __construct(public string $hash, public int $messages, public string $checkpoint, public int $tokens)
    {
        if ($messages < 1 || $tokens < 1) {
            throw new \InvalidArgumentException("An anchor counts at least one message and one token, got $messages and $tokens");
        }
    }

    /** @return array{hash: string, messages: int, checkpoint: string, tokens: int} */
    public function toArray(): array
    {
        return ['hash' => $this->hash, 'messages' => $this->messages, 'checkpoint' => $this->checkpoint, 'tokens' => $this->tokens];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['hash' => 'is_string', 'messages' => 'is_int', 'checkpoint' => 'is_string', 'tokens' => 'is_int'] as $key => $is) {
            if (!$is($data[$key] ?? null)) {
                throw new \InvalidArgumentException("An anchor array needs \"$key\" (" . \substr($is, 3) . ')');
            }
        }

        return new self($data['hash'], $data['messages'], $data['checkpoint'], $data['tokens']);
    }
}
