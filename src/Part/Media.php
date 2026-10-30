<?php

declare(strict_types=1);

namespace Harness\Part;

use Harness\Part;

/**
 * An image, audio clip, PDF or other binary, either inline (`bytes`) or by `uri`. Exactly one of
 * the two is set. `toArray()` base64-encodes the bytes.
 */
final readonly class Media implements Part
{
    public function __construct(public string $mime, public ?string $bytes = null, public ?string $uri = null)
    {
        if ((null === $bytes) === (null === $uri)) {
            throw new \InvalidArgumentException('Media takes exactly one of bytes or uri');
        }
    }

    public function toArray(): array
    {
        return [
            'type'  => 'media',
            'mime'  => $this->mime,
            'bytes' => null === $this->bytes ? null : \base64_encode($this->bytes),
            'uri'   => $this->uri,
        ];
    }

    public static function fromArray(array $data): static
    {
        $bytes = null;
        if (null !== $data['bytes']) {
            $bytes = \base64_decode($data['bytes'], true);
            if (false === $bytes) {
                throw new \InvalidArgumentException('Media bytes are not valid base64');
            }
        }

        return new self($data['mime'], $bytes, $data['uri']);
    }
}
