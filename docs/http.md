# Harness\Http (core)

Providers speak HTTP through `Transport`, not through an HTTP client:

```php
interface Transport {
    public function post(string $url, array $headers, string $body, Cancel $cancel): Response;
    public function get(string $url, array $headers, Cancel $cancel): Response;
}
```

`post()` and `get()` return once the status line and headers are in. `Response::$status` and
`$headers` (names lower-case, repeats joined with `", "`) are public; `body()` yields the body
chunks as they arrive and can be called once. A non-2xx status is a normal response; a failed
connection, a timeout or a truncated body throws `TransportException`, which ends a turn `Failed`
with `ErrorKind::TransportError`. `get()` is what `Harness\Tools\Web` fetches with; a hand-written
`Transport` must add it.

`$cancel` is the turn's signal (`Prompt::$cancel`, passed on by the provider). A transport checks it
while it waits and throws `Harness\Cancelled` once it is set; the run that set it ends the turn
`Cancelled`.

## StreamTransport

HTTP/1.1 over `stream_socket_client()` with non-blocking sockets. Every wait is `fiber\readable()` or
`fiber\writable()`, so requests run concurrently on any adapter, and must be made inside
`fiber\run()`. One connection per request (`Connection: close`); chunked and content-length bodies;
`https://` with peer verification.

```php
new StreamTransport(connectTimeout: 10.0, idleTimeout: 300.0, ssl: ['cafile' => '/etc/ssl/my-ca.pem']);
```

`idleTimeout` bounds each wait for more of the response, not the whole request: a long stream that
keeps sending is never cut off. Each wait is cut into slices of at most 250 ms with a check of the
`Cancel` signal between them, so a cancelled turn ends within 250 ms even while the server sends
nothing. DNS resolution is blocking (PHP has no non-blocking resolver). No proxies or compression: a
model endpoint needs none. A redirect comes back as the response it is: `Response::redirectTarget($url)`
gives the absolute target of a 301/302/303/307/308 with a `Location`, and the caller decides whether to
follow it, draining the body first (`web_fetch` follows up to 5 hops and runs its SSRF guard on each).

## Sse

`Sse::parse(iterable $chunks)` yields server-sent events as `event name => data` (keys repeat; the
name is `message` when the stream gives none). Multi-line data is joined with `"\n"`; comments, `id`
and `retry` are ignored; an event cut off by the end of the stream is dropped.

```php
foreach (Sse::parse($response->body()) as $event => $data) { ... }
```

`Sse` is for `text/event-stream` only. A backend that streams NDJSON (Ollama's `/api/chat`) splits
`body()` on newlines itself:

```php
$buffer = '';
foreach ($response->body() as $chunk) {
    $buffer .= $chunk;
    while (($eol = strpos($buffer, "\n")) !== false) {
        $line   = substr($buffer, 0, $eol);
        $buffer = substr($buffer, $eol + 1);
        if ('' !== trim($line)) { $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR); ... }
    }
}
```

A PSR-18 transport would be a module implementing `Transport`; there is none yet.
