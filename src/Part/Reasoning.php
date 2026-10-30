<?php

declare(strict_types=1);

namespace Harness\Part;

use Harness\Part;

/**
 * The model's reasoning. Opaque and model-bound: `raw` holds what the provider needs to replay it
 * (a signature, the model id that produced it); a provider replays it verbatim to the same model
 * and drops it for another. It is never re-derived from `text`.
 */
final readonly class Reasoning implements Part
{
    /** @param array<string, mixed> $raw provider data, JSON-safe */
    public function __construct(public string $text, public array $raw = [])
    {
    }

    public function toArray(): array
    {
        return ['type' => 'reasoning', 'text' => $this->text, 'raw' => $this->raw];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['text'], $data['raw']);
    }
}
