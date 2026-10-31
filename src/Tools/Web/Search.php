<?php

declare(strict_types=1);

namespace Harness\Tools\Web;

/** A web search engine; `WebSearch` is the tool over it. */
interface Search
{
    /** @return list<Hit> at most `$limit` results, best first */
    public function search(string $query, int $limit): array;
}
