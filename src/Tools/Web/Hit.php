<?php

declare(strict_types=1);

namespace Harness\Tools\Web;

/** One search result: `publishedAt` is the engine's own date string, unparsed, when it gave one. */
final readonly class Hit
{
    public function __construct(
        public string $url,
        public string $title,
        public string $snippet,
        public ?string $publishedAt,
        public string $engine,
    ) {
    }

    /** @return array{url: string, title: string, snippet: string, publishedAt: ?string, engine: string} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'title' => $this->title, 'snippet' => $this->snippet, 'publishedAt' => $this->publishedAt, 'engine' => $this->engine];
    }
}
