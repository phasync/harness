<?php

declare(strict_types=1);

namespace Harness\Part;

use Harness\Part;

/**
 * A provider's own compaction of the conversation before it: an opaque, encrypted item that only
 * the model that made it can read (`Provider\OpenAI\Responses` replays it as a `compaction` input
 * item to the same `model`; every other provider rejects it). `Compact\Compact` with a `native`
 * compactor puts one in an assistant message in place of the history it stands for.
 */
final readonly class Compacted implements Part
{
    public function __construct(public string $provider, public string $model, public string $id, public string $encrypted)
    {
    }

    public function toArray(): array
    {
        return ['type' => 'compacted', 'provider' => $this->provider, 'model' => $this->model, 'id' => $this->id, 'encrypted' => $this->encrypted];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['provider'], $data['model'], $data['id'], $data['encrypted']);
    }
}
