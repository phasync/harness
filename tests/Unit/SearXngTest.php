<?php

declare(strict_types=1);

use Harness\Http\Response;
use Harness\Testing\FakeTransport;
use Harness\Tools\Web\Hit;
use Harness\Tools\Web\SearXng;

it('maps a captured SearXng reply to Hits', function () {
    $body      = \file_get_contents(__DIR__ . '/../Support/searxng-reply.json');
    $transport = new FakeTransport(new Response(200, ['content-type' => 'application/json'], [$body]));
    $searxng   = new SearXng($transport, 'http://127.0.0.1:8888');

    $hits = $searxng->search('SQLite write-ahead log', 3);

    expect($transport->requests[0]['url'])->toBe('http://127.0.0.1:8888/search?q=SQLite+write-ahead+log&format=json')
        ->and($hits)->toHaveCount(3)
        ->and($hits[0])->toEqual(new Hit('https://www.sqlite.org/wal.html', 'Write-Ahead Logging - SQLite', $hits[0]->snippet, null, 'google cse'))
        ->and($hits[1]->publishedAt)->toBe('2026-10-07T12:37:10')
        ->and($hits[1]->engine)->toBe('brave')
        ->and($hits[1]->url)->toBe('https://sqldocs.org/sqlite-write-ahead-logging/');
});

it('adds language, time_range and categories to the query', function () {
    $transport = new FakeTransport(new Response(200, ['content-type' => 'application/json'], ['{"results": []}']));
    $searxng   = new SearXng($transport, 'http://127.0.0.1:8888/', 'en', 'month', ['general', 'it']);

    $searxng->search('x', 5);

    expect($transport->requests[0]['url'])->toBe('http://127.0.0.1:8888/search?q=x&format=json&language=en&time_range=month&categories=general%2Cit');
});

it('throws naming the URL and status on a non-200 reply', function () {
    $transport = new FakeTransport(new Response(500, [], ['boom']));
    $searxng   = new SearXng($transport, 'http://127.0.0.1:8888');
    expect(fn () => $searxng->search('x', 5))->toThrow(RuntimeException::class, 'returned status 500');
});

it('throws when the reply is not the expected JSON', function () {
    $transport = new FakeTransport(new Response(200, [], ['not json']));
    $searxng   = new SearXng($transport, 'http://127.0.0.1:8888');
    expect(fn () => $searxng->search('x', 5))->toThrow(RuntimeException::class, 'did not reply with JSON search results');
});
