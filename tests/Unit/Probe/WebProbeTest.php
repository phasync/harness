<?php

declare(strict_types=1);

use Harness\Testing\FakeTransport;
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
