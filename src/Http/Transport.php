<?php

declare(strict_types=1);

namespace Harness\Http;

/**
 * Streaming HTTP for providers. `post()` returns once the status and headers are in; the body
 * arrives through `Response::body()` as it is received. It throws `TransportException` when the
 * request cannot be made or the connection fails; a non-2xx status is a normal `Response`.
 */
interface Transport
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body): Response;
}
