<?php

declare(strict_types=1);

namespace Harness\Tools\Web;

use Harness\Call;
use Harness\Hints;
use Harness\Part\Text;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/** `web_search`: the top results of a `Search` engine, numbered with their URL and a snippet. */
final readonly class WebSearch implements Tool
{
    /** The highest `limit` a caller may ask for. */
    private const MAX_LIMIT = 10;

    public function __construct(private Search $search)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('web_search', 'Search the web. Returns a numbered list of results, each with its URL and a short snippet.', [
            'type'       => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The search query'],
                'limit' => ['type' => 'integer', 'description' => 'Results to return, 1-' . self::MAX_LIMIT . ' (default 5)'],
            ],
            'required' => ['query'],
        ], new Hints(readOnly: true, idempotent: true, openWorld: true));
    }

    public function call(array $input, Call $call): Result
    {
        $query = Input::string($input, 'query');
        $limit = \min(Input::int($input, 'limit', 5, 1), self::MAX_LIMIT);
        $hits  = $this->search->search($query, $limit);
        if ([] === $hits) {
            return Result::text("No results for '$query'");
        }
        $lines = [];
        foreach ($hits as $i => $hit) {
            $snippet = \strlen($hit->snippet) > 300 ? \substr($hit->snippet, 0, 300) . '...' : $hit->snippet;
            $lines[] = ($i + 1) . ". {$hit->title} — {$hit->url}\n   $snippet";
        }

        return new Result([new Text(\implode("\n\n", $lines))], meta: ['count' => \count($hits)]);
    }
}
