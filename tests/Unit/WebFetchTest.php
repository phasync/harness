<?php

declare(strict_types=1);

use Harness\Http\Response;
use Harness\Testing\FakeTransport;
use Harness\Tools\Web\WebFetch;

function html(string $status, string $contentType, string $body): Response
{
    return new Response((int) $status, ['content-type' => $contentType], [$body]);
}

function webText(Harness\Result $r): string
{
    return $r->content[0]->text;
}

it('fetches a page, converts HTML and sends the User-Agent and Accept headers', function () {
    $transport = new FakeTransport(html('200', 'text/html; charset=utf-8', '<title>T</title><p>Hello</p>'));
    $fetch     = new WebFetch($transport, 'my-agent/1.0');
    $result    = $fetch->call(['url' => 'https://example.com/page'], callFor());
    expect(webText($result))->toBe("T\n\nHello")
        ->and($transport->requests[0]['url'])->toBe('https://example.com/page')
        ->and($transport->requests[0]['headers']['User-Agent'])->toBe('my-agent/1.0')
        ->and($transport->requests[0]['headers']['Accept'])->toBe('text/html,application/xhtml+xml,text/*,application/json');
});

it('passes text/plain and application/json through untouched', function () {
    $transport = new FakeTransport(html('200', 'application/json', '{"a":1}'));
    $fetch     = new WebFetch($transport, 'agent');
    expect(webText($fetch->call(['url' => 'https://example.com/a.json'], callFor())))->toBe('{"a":1}');
});

it('refuses content types it does not read', function () {
    $transport = new FakeTransport(html('200', 'application/pdf', '%PDF-1.4'));
    $fetch     = new WebFetch($transport, 'agent');
    expect(fn () => $fetch->call(['url' => 'https://example.com/a.pdf'], callFor()))
        ->toThrow(RuntimeException::class, 'web_fetch does not read application/pdf; only text');
});

it('cuts long text with the tail idiom and continues from offset', function () {
    $body      = \str_repeat('x', 30);
    $transport = new FakeTransport(html('200', 'text/plain', $body));
    $fetch     = new WebFetch($transport, 'agent', maxChars: 10);
    $first     = webText($fetch->call(['url' => 'https://example.com/big.txt'], callFor()));
    expect($first)->toBe(\str_repeat('x', 10) . "\n[20 more characters; pass offset=10 to read on]");

    $transport2 = new FakeTransport(html('200', 'text/plain', $body));
    $fetch2     = new WebFetch($transport2, 'agent', maxChars: 10);
    $second     = webText($fetch2->call(['url' => 'https://example.com/big.txt', 'offset' => 10], callFor()));
    expect($second)->toBe(\str_repeat('x', 10) . "\n[10 more characters; pass offset=20 to read on]");
});

it('refuses a private-network URL unless allowPrivate is set', function () {
    $fetch = new WebFetch(new FakeTransport(), 'agent');
    expect(fn () => $fetch->call(['url' => 'http://127.0.0.1/secret'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard')
        ->and(fn () => $fetch->call(['url' => 'http://192.168.1.1/'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard')
        ->and(fn () => $fetch->call(['url' => 'http://localhost/'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard')
        ->and(fn () => $fetch->call(['url' => 'file:///etc/passwd'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard');
});

it('fetches a private-network URL when allowPrivate is true', function () {
    $transport = new FakeTransport(html('200', 'text/plain', 'internal'));
    $fetch     = new WebFetch($transport, 'agent', allowPrivate: true);
    expect(webText($fetch->call(['url' => 'http://127.0.0.1/internal'], callFor())))->toBe('internal');
});

it('caches a fetched body and reuses it within cacheTtl without a new request', function () {
    $dir       = \sys_get_temp_dir() . '/harness-web-' . \bin2hex(\random_bytes(4));
    $transport = new FakeTransport(html('200', 'text/plain', 'cached body'));
    $fetch     = new WebFetch($transport, 'agent', cacheDir: $dir);
    try {
        $first  = webText($fetch->call(['url' => 'https://example.com/x'], callFor()));
        $second = webText($fetch->call(['url' => 'https://example.com/x'], callFor()));
        expect($first)->toBe('cached body')
            ->and($second)->toBe('cached body')
            ->and($transport->requests)->toHaveCount(1);
    } finally {
        \exec('rm -rf ' . \escapeshellarg($dir));
    }
});
