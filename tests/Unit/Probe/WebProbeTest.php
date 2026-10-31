<?php

declare(strict_types=1);

use Harness\Testing\FakeTransport;
use Harness\Tools\Web\Html;
use Harness\Tools\Web\WebFetch;

it('refuses IPv6 localhost address ::1 as SSRF guard', function () {
    $fetch = new WebFetch(new FakeTransport(), 'agent');
    expect(fn () => $fetch->call(['url' => 'http://[::1]/secret'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard');
});

it('refuses decimal IP notation for 127.0.0.1 as an SSRF guard', function () {
    // 2130706433 is the decimal notation for 127.0.0.1. filter_var(FILTER_VALIDATE_IP) rejects it,
    // so isPrivateHost() falls through to false and the guard lets it through; the resolver that
    // actually connects (gethostbynamel, curl, ...) accepts decimal notation and resolves it to
    // 127.0.0.1, bypassing the guard. This is a real defect: the test documents the desired
    // (currently failing) behaviour rather than the bypass.
    $transport = new FakeTransport(new Harness\Http\Response(200, ['content-type' => 'text/plain'], ['bypassed']));
    $fetch     = new WebFetch($transport, 'agent');
    expect(fn () => $fetch->call(['url' => 'http://2130706433/secret'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard');
});

it('refuses host localhost. (trailing dot) as SSRF guard', function () {
    // guard() is the same call for the initial URL and every redirect hop (WebFetchTest's "follows
    // a redirect, guarding every hop" already proves the per-hop part); the only thing left to
    // probe here is this one host value, so skip the redirect indirection and call it directly.
    $fetch = new WebFetch(new FakeTransport(), 'agent');
    expect(fn () => $fetch->call(['url' => 'http://localhost./internal'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard');
});

it('renders a table with nested inline tags in cells', function () {
    expect(Html::extract('<table><tr><td>Text <b>bold</b> here</td><td><a href="http://x">link</a></td></tr></table>'))
        ->toBe('Text bold here | link (http://x)');
});
