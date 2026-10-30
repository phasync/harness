<?php

declare(strict_types=1);

namespace Harness\Testing;

use Harness\Cancel;
use Harness\Http\Response;
use Harness\Http\Transport;

/**
 * A scripted transport for provider tests: each `post()` takes the next response, a `Response`
 * or a closure `fn (string $url, array $headers, string $body, Cancel $cancel): Response`.
 * `requests` records every request. Running out of responses throws. A scripted body that waits
 * observes the `Cancel` the closure is given, as a real transport does.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var list<Response|\Closure(string, array<string, string>, string, Cancel): Response> */
    private array $responses;

    public function __construct(Response|\Closure ...$responses)
    {
        $this->responses = \array_values($responses);
    }

    public function post(string $url, array $headers, string $body, Cancel $cancel): Response
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        if ([] === $this->responses) {
            throw new \LogicException('FakeTransport has no response left for request ' . \count($this->requests));
        }
        $response = \array_shift($this->responses);

        return $response instanceof \Closure ? $response($url, $headers, $body, $cancel) : $response;
    }
}
