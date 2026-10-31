<?php

declare(strict_types=1);

namespace Harness\Acp;

use function fiber\await;
use fiber\Deferred;
use function fiber\go;
use function fiber\readable;
use function fiber\writable;

/**
 * Newline-delimited JSON-RPC 2.0 in both directions over a pair of streams: the agent's side of ACP
 * over stdio. `serve()` reads until the input ends, running each incoming request in its own
 * coroutine (its handler's return value is the result, an `AcpError` the error response) and each
 * notification in order; `request()` sends a request to the other side and waits for its response,
 * which `serve()` hands over. Writes are whole lines, one at a time.
 */
final class Channel
{
    /** @var resource */
    private mixed $in;
    /** @var resource */
    private mixed $out;
    private int $nextId   = 1;
    private bool $writing = false;
    /** @var list<Deferred> writers waiting for their turn */
    private array $queue = [];
    /** @var array<int, Deferred> requests waiting for their response, by id */
    private array $open = [];

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct(mixed $in, mixed $out)
    {
        if (!\is_resource($in) || !\is_resource($out)) {
            throw new \InvalidArgumentException('Channel takes two open streams: the input and the output');
        }
        \stream_set_blocking($in, false);
        \stream_set_blocking($out, false);
        $this->in  = $in;
        $this->out = $out;
    }

    /**
     * Reads messages until the input ends. Requests go to `$onRequest(method, params)` in a
     * coroutine each; notifications to `$onNotification(method, params)` in order.
     *
     * @param \Closure(string, array<string, mixed>): mixed $onRequest
     * @param \Closure(string, array<string, mixed>): void  $onNotification
     */
    public function serve(\Closure $onRequest, \Closure $onNotification): void
    {
        $buffer = '';
        while (true) {
            readable($this->in);
            $data = \fread($this->in, 65536);
            if (false === $data || ('' === $data && \feof($this->in))) {
                break;
            }
            $buffer .= $data;
            while (false !== ($eol = \strpos($buffer, "\n"))) {
                $line   = \trim(\substr($buffer, 0, $eol));
                $buffer = \substr($buffer, $eol + 1);
                if ('' !== $line) {
                    $this->dispatch($line, $onRequest, $onNotification);
                }
            }
        }
        foreach ($this->open as $waiting) {
            $waiting->reject(new AcpError(-32000, 'The client closed the connection'));
        }
        $this->open = [];
    }

    /**
     * Sends a request and waits for the result.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function request(string $method, array $params): array
    {
        $id              = $this->nextId++;
        $this->open[$id] = $waiting = new Deferred();
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $message = await($waiting);
        if (isset($message['error'])) {
            throw new AcpError((int) ($message['error']['code'] ?? -32603), "The client answered $method with an error: " . ($message['error']['message'] ?? '(no message)'));
        }

        return \is_array($message['result'] ?? null) ? $message['result'] : throw new AcpError(-32603, "The client answered $method without a result object");
    }

    /**
     * Sends a notification, which has no response.
     *
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    }

    /**
     * @param \Closure(string, array<string, mixed>): mixed $onRequest
     * @param \Closure(string, array<string, mixed>): void  $onNotification
     */
    private function dispatch(string $line, \Closure $onRequest, \Closure $onNotification): void
    {
        try {
            $message = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: a line that is not JSON']]);

            return;
        }
        if (!\is_array($message)) {
            $this->send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request: not a JSON-RPC object']]);

            return;
        }
        $id = $message['id'] ?? null;
        if (!isset($message['method'])) {
            // A response to one of our requests.
            if (null !== $id && isset($this->open[$id])) {
                $this->open[$id]->resolve($message);
                unset($this->open[$id]);
            }

            return;
        }
        $method = (string) $message['method'];
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];
        if (!\array_key_exists('id', $message)) {
            $onNotification($method, $params);

            return;
        }
        go(function () use ($id, $method, $params, $onRequest) {
            try {
                $result = $onRequest($method, $params);
                $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result ?? null]);
            } catch (AcpError $e) {
                $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]]);
            }
        });
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $line = \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) . "\n";
        while ($this->writing) {
            $this->queue[] = $turn = new Deferred();
            await($turn);
        }
        $this->writing = true;
        try {
            while ('' !== $line) {
                writable($this->out);
                $n = \fwrite($this->out, $line);
                if (false === $n) {
                    throw new \RuntimeException('Could not write to the ACP client');
                }
                $line = \substr($line, $n);
            }
        } finally {
            $this->writing = false;
            if ([] !== $this->queue) {
                \array_shift($this->queue)->resolve();
            }
        }
    }
}
