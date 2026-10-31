<?php

declare(strict_types=1);

use Harness\Http\Response;
use Harness\Part\Text;
use Harness\Reason;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Testing\FakeTransport;
use Harness\Tools;
use Harness\Tools\Web\Hit;
use Harness\Tools\Web\Search;
use Harness\Tools\Web\WebFetch;
use Harness\Tools\Web\WebSearch;

it('runs web_search then web_fetch as a model would', function () {
    $search = new class implements Search {
        public function search(string $query, int $limit): array
        {
            return [new Hit('https://example.com/wal', 'WAL mode', 'How WAL works.', null, 'brave')];
        }
    };
    $transport = new FakeTransport(new Response(200, ['content-type' => 'text/html'], ['<title>WAL</title><p>Write-ahead logging.</p>']));
    $tools     = new Tools(new WebSearch($search), new WebFetch($transport, 'test-agent'));

    $model = new FakeModel(
        calls(['s1', 'web_search', ['query' => 'WAL mode']]),
        calls(['f1', 'web_fetch', ['url' => 'https://example.com/wal']]),
        says('WAL keeps a log of changes before they are applied.'),
    );
    $run    = new Run($model, $tools);
    $events = turn($run, user('What is WAL mode?'));

    $toolResults = \array_map(
        static fn ($r) => \implode('', \array_map(static fn ($p) => $p instanceof Text ? $p->text : '', $r->content)),
        \array_filter(
            \array_merge(...\array_map(static fn ($m) => $m->parts, $run->ended()->appended)),
            static fn ($p) => $p instanceof Harness\Part\ToolResult,
        ),
    );

    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and(\array_values($toolResults))->toBe([
            "1. WAL mode — https://example.com/wal\n   How WAL works.",
            "WAL\n\nWrite-ahead logging.",
        ])
        ->and(types($events))->toContain('tool_ended');
});
