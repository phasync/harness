<?php

declare(strict_types=1);

namespace Harness\Tools\Web;

use Harness\Cancel;
use Harness\Http\Transport;

/**
 * `Search` over a self-hosted SearXng instance's JSON API: GET `{baseUrl}/search?q=...&format=json`,
 * with `language`, `time_range` and `categories` (joined by a comma) added when given. SearXng
 * serves JSON only when its settings.yml lists it under `search.formats`, which is off by default.
 * Maps each of `results[]`'s `url`, `title`, `content`, `publishedDate` and `engine`. A non-200
 * reply, or one that is not the expected JSON, throws `\RuntimeException` naming the URL and the
 * status.
 */
final readonly class SearXng implements Search
{
    /** @param list<string> $categories */
    public function __construct(
        private Transport $transport,
        private string $baseUrl,
        private ?string $language = null,
        private ?string $timeRange = null,
        private array $categories = [],
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $params = ['q' => $query, 'format' => 'json'];
        if (null !== $this->language) {
            $params['language'] = $this->language;
        }
        if (null !== $this->timeRange) {
            $params['time_range'] = $this->timeRange;
        }
        if ([] !== $this->categories) {
            $params['categories'] = \implode(',', $this->categories);
        }
        $url      = \rtrim($this->baseUrl, '/') . '/search?' . \http_build_query($params);
        $response = $this->transport->get($url, [], new Cancel(static fn () => false));
        $body     = \implode('', \iterator_to_array($response->body(), false));
        if (200 !== $response->status) {
            throw new \RuntimeException("SearXng at '$url' returned status {$response->status}");
        }
        $data = \json_decode($body, true);
        if (!\is_array($data) || !\is_array($data['results'] ?? null)) {
            throw new \RuntimeException("SearXng at '$url' did not reply with JSON search results");
        }

        $hits = [];
        foreach (\array_slice($data['results'], 0, $limit) as $result) {
            $hits[] = new Hit(
                (string) ($result['url'] ?? ''),
                (string) ($result['title'] ?? ''),
                (string) ($result['content'] ?? ''),
                isset($result['publishedDate']) ? (string) $result['publishedDate'] : null,
                (string) ($result['engine'] ?? ''),
            );
        }

        return $hits;
    }
}
