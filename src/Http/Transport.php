<?php

declare(strict_types=1);

namespace Harness\Http;

use Harness\Cancel;

/**
 * Streaming HTTP for providers and tools. `post()` and `get()` return once the status and headers
 * are in; the body arrives through `Response::body()` as it is received. They throw
 * `TransportException` when the request cannot be made or the connection fails; a non-2xx status is
 * a normal `Response`. `get()` follows up to 5 redirects (301, 302, 303, 307, 308), as a browser
 * would for a GET request, and otherwise returns the response as is. Both observe `$cancel` while
 * they wait (for the connection, the headers and each body chunk) and throw `Harness\Cancelled`
 * once it is set, within a bounded time.
 */
interface Transport
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body, Cancel $cancel): Response;

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers, Cancel $cancel): Response;
}
