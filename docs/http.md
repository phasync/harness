# Harness\Http (core)

Providers speak HTTP through `Transport`, not through an HTTP client:

```php
interface Transport {
    public function post(string $url, array $headers, string $body): Response;
}
```

`post()` returns once the status line and headers are in. `Response::$status` and `$headers` (names
lower-case, repeats joined with `", "`) are public; `body()` yields the body chunks as they arrive and
can be called once. A non-2xx status is a normal response; a failed connection, a timeout or a
truncated body throws `TransportException`, which ends a turn `Failed` with `ErrorKind::TransportError`.

## StreamTransport

HTTP/1.1 over `stream_socket_client()` with non-blocking sockets. Every wait is `fiber\readable()` or
`fiber\writable()`, so requests run concurrently on any adapter, and must be made inside
`fiber\run()`. One connection per request (`Connection: close`); chunked and content-length bodies;
`https://` with peer verification. No redirects, proxies or compression: a model endpoint needs none.

```php
new StreamTransport(connectTimeout: 10.0, idleTimeout: 300.0, ssl: ['cafile' => '/etc/ssl/my-ca.pem']);
```

`idleTimeout` bounds each wait for more of the response, not the whole request: a long stream that
keeps sending is never cut off. DNS resolution is blocking (PHP has no non-blocking resolver).

## Sse

`Sse::parse(iterable $chunks)` yields server-sent events as `event name => data` (keys repeat; the
name is `message` when the stream gives none). Multi-line data is joined with `"\n"`; comments, `id`
and `retry` are ignored; an event cut off by the end of the stream is dropped.

```php
foreach (Sse::parse($response->body()) as $event => $data) { ... }
```

A PSR-18 transport would be a module implementing `Transport`; there is none yet.
