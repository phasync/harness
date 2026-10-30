<?php

declare(strict_types=1);

namespace Harness\Part;

use Harness\Part;

/** Plain text, from the user, the model or a tool. */
final readonly class Text implements Part
{
    public function __construct(public string $text)
    {
    }

    public function toArray(): array
    {
        return ['type' => 'text', 'text' => $this->text];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['text']);
    }
}
