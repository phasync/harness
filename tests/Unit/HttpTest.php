<?php

declare(strict_types=1);

use function fiber\go;
use function fiber\readable;
use function fiber\sleep;
use function fiber\writable;
use Harness\Http\Response;
use Harness\Http\Sse;
use Harness\Http\StreamTransport;
use Harness\Http\TransportException;

it('parses server-sent events across arbitrary chunk boundaries', function () {
    $stream = "event: ping\ndata: {}\n\n: comment\ndata: line1\ndata: line2\n\nid: 7\r\nevent: x\r\ndata:no space\r\n\r\ndata: cut off";
    $chunks = \str_split($stream, 3);
    $events = [];
    foreach (Sse::parse($chunks) as $event => $data) {
        $events[] = [$event, $data];
    }
    expect($events)->toBe([['ping', '{}'], ['message', "line1\nline2"], ['x', 'no space']]);
});

it('reads a response body once', function () {
    $r = new Response(200, [], ['a', 'b']);
    expect(\implode('', [...$r->body()]))->toBe('ab')
        ->and(fn () => $r->body())->toThrow(LogicException::class, 'already read');
});

/**
 * A one-shot HTTP server in the current fiber-interop run: it reads one request and writes
 * `$respond($request)`, a list of raw chunks with pauses between them.
 *
 * @return array{0: int, 1: object} the port and the server task
 */
function serve(Closure $respond): array
{
    $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port   = (int) \substr(\strrchr(\stream_socket_get_name($server, false), ':'), 1);
    $task   = go(function () use ($server, $respond) {
        $conn = \stream_socket_accept(readable($server), 0);
        \stream_set_blocking($conn, false);
        $request = '';
        while (!\str_contains($request, "\r\n\r\n") || \strlen(\substr($request, \strpos($request, "\r\n\r\n") + 4)) < (int) (\preg_match('/content-length: (\d+)/i', $request, $m) ? $m[1] : 0)) {
            $request .= \fread(readable($conn), 65536);
        }
        foreach ($respond($request) as $chunk) {
            \fwrite(writable($conn), $chunk);
            sleep(0.01);
        }
        \fclose($conn);
        \fclose($server);

        return $request;
    });

    return [$port, $task];
}

it('streams a chunked response as it arrives', function () {
    $result = \fiber\run(function () {
        [$port, $task] = serve(fn () => [
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\nX-A: 1\r\nX-A: 2\r\n\r\n",
            "5\r\nhello\r\n",
            "6;ext=1\r\n wor",
            "ld\r\n",
            "0\r\n\r\n",
        ]);
        $response = (new StreamTransport())->post("http://127.0.0.1:$port/v1/x?y=1", ['Content-Type' => 'application/json'], '{"a":1}', live());
        $chunks   = [...$response->body()];

        return [$response->status, $response->headers, $chunks, \fiber\await($task)];
    });
    [$status, $headers, $chunks, $request] = $result;
    expect($status)->toBe(200)
        ->and($headers['x-a'])->toBe('1, 2')
        ->and(\implode('', $chunks))->toBe('hello world')
        ->and(\count($chunks))->toBeGreaterThan(1)
        ->and($request)->toStartWith("POST /v1/x?y=1 HTTP/1.1\r\n")
        ->and($request)->toContain("Content-Length: 7\r\n")
        ->and($request)->toEndWith('{"a":1}');
});

it('reads content-length and close-delimited bodies, and non-2xx statuses', function (array $response, int $status, string $body) {
    $out = \fiber\run(function () use ($response) {
        [$port] = serve(fn () => $response);
        $r      = (new StreamTransport())->post("http://127.0.0.1:$port/", [], '', live());

        return [$r->status, \implode('', [...$r->body()])];
    });
    expect($out)->toBe([$status, $body]);
})->with([
    'content-length' => [["HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nab", 'cde'], 200, 'abcde'],
    'close'          => [["HTTP/1.0 500 Oops\r\n\r\nerr", 'or'], 500, 'error'],
    'continue first' => [["HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 429 Slow\r\nContent-Length: 2\r\n\r\nno"], 429, 'no'],
]);

it('fails with TransportException when the connection breaks or is refused', function () {
    expect(fn () => \fiber\run(function () {
        [$port] = serve(fn () => ["HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nabc"]);
        $r      = (new StreamTransport())->post("http://127.0.0.1:$port/", [], '', live());
        foreach ($r->body() as $_) {
        }
    }))->toThrow(TransportException::class, 'closed before the response body ended');

    $server = \stream_socket_server('tcp://127.0.0.1:0');
    $port   = (int) \substr(\strrchr(\stream_socket_get_name($server, false), ':'), 1);
    \fclose($server);
    expect(fn () => \fiber\run(fn () => (new StreamTransport())->post("http://127.0.0.1:$port/", [], '', live())))->toThrow(TransportException::class);
});

it('times out an idle response', function () {
    expect(fn () => \fiber\run(function () {
        [$port] = serve(function () {
            sleep(0.3);

            return [];
        });
        (new StreamTransport(idleTimeout: 0.05))->post("http://127.0.0.1:$port/", [], '', live());
    }))->toThrow(TransportException::class, 'Timed out');
});

it('rejects URLs it cannot serve', function () {
    expect(fn () => (new StreamTransport())->post('ftp://example.com/', [], '', live()))->toThrow(InvalidArgumentException::class);
});

it('returns a redirect as the response it is, with redirectTarget() naming the absolute target', function () {
    [$status, $target, $relative] = \fiber\run(function () {
        [$port, $task] = serve(fn () => ["HTTP/1.1 302 Found\r\nLocation: /dest?x=1\r\nContent-Length: 0\r\n\r\n"]);
        $response      = (new StreamTransport())->get("http://127.0.0.1:$port/start/here", [], live());
        \implode('', [...$response->body()]);
        \fiber\await($task);
        $relative = new Response(301, ['location' => 'next.html'], []);

        return [$response->status, $response->redirectTarget("http://127.0.0.1:$port/start/here"), $relative->redirectTarget('https://h/a/b/c.html')];
    });
    expect($status)->toBe(302)
        ->and($target)->toBe('http://127.0.0.1:' . \parse_url($target, \PHP_URL_PORT) . '/dest?x=1')
        ->and($relative)->toBe('https://h/a/b/next.html')
        ->and((new Response(200, ['location' => '/x'], []))->redirectTarget('https://h/'))->toBeNull();
});
