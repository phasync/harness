<?php

declare(strict_types=1);

namespace Harness\Http;

/**
 * An HTTP response whose body is read once, as chunks arrive. Header names are lower-case;
 * repeated headers are joined with ", ". Read a whole body with `implode('', [...$response->body()])`.
 */
final class Response
{
    private bool $consumed = false;

    /**
     * @param array<string, string> $headers
     * @param iterable<string>      $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        private iterable $body,
    ) {
    }

    /** @return iterable<string> the body chunks; a second call throws */
    public function body(): iterable
    {
        if ($this->consumed) {
            throw new \LogicException('The response body is a stream and was already read');
        }
        $this->consumed = true;
        $body           = $this->body;
        $this->body     = [];

        return $body;
    }
}
