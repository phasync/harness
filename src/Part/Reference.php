<?php

declare(strict_types=1);

namespace Harness\Part;

use Harness\Part;

/** A source or a resource link: a URI with an optional title. */
final readonly class Reference implements Part
{
    public function __construct(public string $uri, public ?string $title = null)
    {
    }

    public function toArray(): array
    {
        return ['type' => 'reference', 'uri' => $this->uri, 'title' => $this->title];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['uri'], $data['title']);
    }
}
