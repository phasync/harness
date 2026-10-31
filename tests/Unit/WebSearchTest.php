<?php

declare(strict_types=1);

use Harness\Tools\Web\Hit;
use Harness\Tools\Web\Search;
use Harness\Tools\Web\WebSearch;

final class FakeSearch implements Search
{
    /** @param list<Hit> $hits */
    public function __construct(private array $hits = [])
    {
    }

    public array $queries = [];

    public function search(string $query, int $limit): array
    {
        $this->queries[] = [$query, $limit];

        return \array_slice($this->hits, 0, $limit);
    }
}

it('numbers results with their title, url and a snippet', function () {
    $search = new FakeSearch([
        new Hit('https://a.example/', 'Page A', 'A short snippet about A.', null, 'brave'),
        new Hit('https://b.example/', 'Page B', 'A short snippet about B.', '2026-01-01', 'google'),
    ]);
    $tool = new WebSearch($search);
    $r    = $tool->call(['query' => 'things'], callFor());
    expect($r->content[0]->text)->toBe(
        "1. Page A — https://a.example/\n   A short snippet about A.\n\n2. Page B — https://b.example/\n   A short snippet about B."
    )->and($search->queries)->toBe([['things', 5]]);
});

it('says plainly when there are no results', function () {
    $tool = new WebSearch(new FakeSearch([]));
    $r    = $tool->call(['query' => 'nothing matches this'], callFor());
    expect($r->content[0]->text)->toBe("No results for 'nothing matches this'");
});

it('clamps a limit above the maximum down to 10', function () {
    $search = new FakeSearch();
    $tool   = new WebSearch($search);
    $tool->call(['query' => 'x', 'limit' => 50], callFor());
    expect($search->queries)->toBe([['x', 10]]);
});

it('asks the model to correct a limit below 1', function () {
    $tool = new WebSearch(new FakeSearch());
    expect(fn () => $tool->call(['query' => 'x', 'limit' => 0], callFor()))->toThrow(Harness\Retry::class);
});

it('cuts a long snippet at about 300 characters', function () {
    $long   = \str_repeat('a', 400);
    $search = new FakeSearch([new Hit('https://a.example/', 'A', $long, null, 'brave')]);
    $tool   = new WebSearch($search);
    $r      = $tool->call(['query' => 'x'], callFor());
    expect($r->content[0]->text)->toBe("1. A — https://a.example/\n   " . \str_repeat('a', 300) . '...');
});
