<?php

declare(strict_types=1);

use Harness\Testing\FakeTransport;
use Harness\Tools\Web\WebFetch;

it('refuses IPv6 localhost address ::1 as SSRF guard', function () {
    $fetch = new WebFetch(new FakeTransport(), 'agent');
    expect(fn () => $fetch->call(['url' => 'http://[::1]/secret'], callFor()))
        ->toThrow(RuntimeException::class, 'SSRF guard');
});
