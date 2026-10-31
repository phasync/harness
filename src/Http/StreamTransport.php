<?php

declare(strict_types=1);

namespace Harness\Http;

use function fiber\readable;
use function fiber\writable;
use Harness\Cancel;
use Harness\Cancelled;

/**
 * HTTP/1.1 over `stream_socket_client()`, for `http://` and `https://` URLs. Non-blocking sockets;
 * every wait is `fiber\readable()` or `fiber\writable()`, so it runs concurrently on any
 * fiber-interop adapter and must be used inside `fiber\run()`. One connection per request
 * (`Connection: close`), chunked and content-length bodies; `post()` and `get()` share this
 * connection and request/response code; a redirect comes back as the response it is (see
 * `Response::redirectTarget()`); no proxies or
 * compression. `connectTimeout` bounds connecting and the TLS handshake, `idleTimeout` each wait
 * for more of the response. Every wait is cut into slices of at most 250 ms; between them it
 * checks the `Cancel` signal and throws `Harness\Cancelled` once it is set, so a cancelled turn
 * ends within that time even while the server is silent. `ssl` is merged into the TLS stream
 * context (e.g. `['cafile' => ...]`).
 */
final class StreamTransport implements Transport
{
    /** The longest wait between two checks of the Cancel signal, in seconds. */
    private const POLL = 0.25;

    /** @param array<string, mixed> $ssl */
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly float $idleTimeout = 300.0,
        private readonly array $ssl = [],
    ) {
    }

    public function post(string $url, array $headers, string $body, Cancel $cancel): Response
    {
        return $this->send('POST', $url, $headers, $body, $cancel);
    }

    public function get(string $url, array $headers, Cancel $cancel): Response
    {
        return $this->send('GET', $url, $headers, null, $cancel);
    }

    private function send(string $method, string $url, array $headers, ?string $body, Cancel $cancel): Response
    {
        $parts = \parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || !\in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException("StreamTransport needs an absolute http:// or https:// URL, got '$url'");
        }
        $tls  = 'https' === $parts['scheme'];
        $host = $parts['host'];
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $socket = $this->connect($host, $port, $tls, $cancel);
        try {
            $request = "$method $path HTTP/1.1\r\n";
            $lower   = \array_change_key_case($headers);
            if (!isset($lower['host'])) {
                $request .= 'Host: ' . $host . (isset($parts['port']) ? ':' . $port : '') . "\r\n";
            }
            foreach ($headers as $name => $value) {
                if (1 === \preg_match('/[\r\n]/', $name . $value)) {
                    throw new \InvalidArgumentException("Header '$name' contains a line break");
                }
                $request .= "$name: $value\r\n";
            }
            if (null !== $body) {
                $request .= 'Content-Length: ' . \strlen($body) . "\r\n";
            }
            $request .= "Connection: close\r\n\r\n" . ($body ?? '');
            $this->write($socket, $request, $cancel);

            $buffer = '';
            do {
                while (($end = \strpos($buffer, "\r\n\r\n")) === false) {
                    $chunk = $this->read($socket, $cancel);
                    if (null === $chunk) {
                        throw new TransportException("Connection to $host:$port closed before the response headers");
                    }
                    $buffer .= $chunk;
                }
                $head   = \substr($buffer, 0, $end);
                $buffer = \substr($buffer, $end + 4);
                $lines  = \explode("\r\n", $head);
                if (1 !== \preg_match('#^HTTP/1\.[01] (\d{3})#', $lines[0], $m)) {
                    throw new TransportException("Malformed status line from $host:$port: " . $lines[0]);
                }
                $status = (int) $m[1];
            } while ($status >= 100 && $status < 200);

            $responseHeaders = [];
            foreach (\array_slice($lines, 1) as $line) {
                $colon = \strpos($line, ':');
                if (false === $colon) {
                    throw new TransportException("Malformed header line from $host:$port: $line");
                }
                $name                   = \strtolower(\trim(\substr($line, 0, $colon)));
                $value                  = \trim(\substr($line, $colon + 1));
                $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
            }
        } catch (\Throwable $e) {
            \fclose($socket);
            throw $e;
        }

        return new Response($status, $responseHeaders, $this->body($socket, $buffer, $status, $responseHeaders, $cancel));
    }

    /** @return resource */
    private function connect(string $host, int $port, bool $tls, Cancel $cancel): mixed
    {
        $context = \stream_context_create(['ssl' => $this->ssl + ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $socket  = $this->guard("connect to $host:$port", static fn () => \stream_socket_client(
            "tcp://$host:$port",
            $errno,
            $errstr,
            null,
            \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        ));
        if (false === $socket) {
            throw new TransportException("Could not connect to $host:$port");
        }
        \stream_set_blocking($socket, false);
        try {
            $this->wait(writable(...), $socket, $this->connectTimeout, "connecting to $host:$port", $cancel);
            if (false === \stream_socket_get_name($socket, true)) {
                throw new TransportException("Could not connect to $host:$port (refused or unreachable)");
            }
            if ($tls) {
                $deadline = \microtime(true) + $this->connectTimeout;
                while (true) {
                    $done = $this->guard("TLS handshake with $host", static fn () => \stream_socket_enable_crypto($socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT));
                    if (true === $done) {
                        break;
                    }
                    if (false === $done) {
                        throw new TransportException("TLS handshake with $host failed");
                    }
                    $this->wait(readable(...), $socket, \max(0.0, $deadline - \microtime(true)), "in the TLS handshake with $host", $cancel);
                }
            }
        } catch (\Throwable $e) {
            \fclose($socket);
            throw $e;
        }

        return $socket;
    }

    /** @param resource $socket */
    private function write(mixed $socket, string $data, Cancel $cancel): void
    {
        while ('' !== $data) {
            $this->wait(writable(...), $socket, $this->idleTimeout, 'sending the request', $cancel);
            $n = $this->guard('send the request', static fn () => \fwrite($socket, $data));
            if (false === $n) {
                throw new TransportException('Could not send the request');
            }
            $data = \substr($data, $n);
        }
    }

    /**
     * The next bytes, or null at end of stream.
     *
     * @param resource $socket
     */
    private function read(mixed $socket, Cancel $cancel): ?string
    {
        while (true) {
            $this->wait(readable(...), $socket, $this->idleTimeout, 'reading the response', $cancel);
            $data = $this->guard('read the response', static fn () => \fread($socket, 65536));
            if (false === $data) {
                throw new TransportException('Could not read the response');
            }
            if ('' !== $data) {
                return $data;
            }
            if (\feof($socket)) {
                return null;
            }
        }
    }

    /**
     * @param resource              $socket
     * @param array<string, string> $headers
     *
     * @return \Generator<int, string>
     */
    private function body(mixed $socket, string $buffer, int $status, array $headers, Cancel $cancel): \Generator
    {
        try {
            if (204 === $status || 304 === $status) {
                return;
            }
            if (\str_contains(\strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
                while (true) {
                    while (($eol = \strpos($buffer, "\r\n")) === false) {
                        $buffer .= $this->more($socket, $cancel);
                    }
                    $hex = \trim(\explode(';', \substr($buffer, 0, $eol))[0]);
                    if (1 !== \preg_match('/^[0-9a-fA-F]{1,15}$/', $hex)) {
                        throw new TransportException("Malformed chunk size '$hex' in the response body");
                    }
                    $size   = (int) \hexdec($hex);
                    $buffer = \substr($buffer, $eol + 2);
                    if (0 === $size) {
                        return;
                    }
                    while ($size > 0) {
                        if ('' === $buffer) {
                            $buffer = $this->more($socket, $cancel);
                        }
                        $piece  = \substr($buffer, 0, $size);
                        $buffer = \substr($buffer, \strlen($piece));
                        $size -= \strlen($piece);
                        yield $piece;
                    }
                    while (\strlen($buffer) < 2) {
                        $buffer .= $this->more($socket, $cancel);
                    }
                    $buffer = \substr($buffer, 2);
                }
            }
            if (isset($headers['content-length'])) {
                $remaining = (int) $headers['content-length'];
                while ($remaining > 0) {
                    if ('' === $buffer) {
                        $buffer = $this->more($socket, $cancel);
                    }
                    $piece  = \substr($buffer, 0, $remaining);
                    $buffer = \substr($buffer, \strlen($piece));
                    $remaining -= \strlen($piece);
                    yield $piece;
                }

                return;
            }
            if ('' !== $buffer) {
                yield $buffer;
            }
            while (($chunk = $this->read($socket, $cancel)) !== null) {
                yield $chunk;
            }
        } finally {
            \fclose($socket);
        }
    }

    /** @param resource $socket */
    private function more(mixed $socket, Cancel $cancel): string
    {
        return $this->read($socket, $cancel) ?? throw new TransportException('Connection closed before the response body ended');
    }

    /**
     * Waits until the socket is ready, in slices of at most POLL seconds with a check of the
     * Cancel signal before each.
     *
     * @param resource $socket
     */
    private function wait(\Closure $wait, mixed $socket, float $timeout, string $what, Cancel $cancel): void
    {
        $deadline = \microtime(true) + $timeout;
        while (true) {
            if ($cancel->isCancelled()) {
                throw new Cancelled("Cancelled while $what");
            }
            $left = $deadline - \microtime(true);
            try {
                $wait($socket, \max(0.0, \min(self::POLL, $left)));

                return;
            } catch (\fiber\TimeoutException $e) {
                if ($left <= self::POLL) {
                    throw new TransportException(\sprintf('Timed out after %.1f s %s', $timeout, $what), 0, $e);
                }
            }
        }
    }

    /**
     * Runs a stream function with PHP warnings turned into a TransportException.
     *
     * @template T
     *
     * @param \Closure(): T $f
     *
     * @return T
     */
    private function guard(string $what, \Closure $f): mixed
    {
        \set_error_handler(static function (int $no, string $message) use ($what): never {
            throw new TransportException("Could not $what: $message");
        });
        try {
            return $f();
        } finally {
            \restore_error_handler();
        }
    }
}
