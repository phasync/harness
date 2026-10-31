<?php

declare(strict_types=1);

namespace Harness\Tests\Support;

use function fiber\readable;
use function fiber\writable;

/**
 * A minimal ACP client for the tests: starts an agent executable, speaks newline-delimited JSON-RPC
 * over its pipes, collects the `session/update` notifications, answers the agent's
 * `session/request_permission` with `$permission($params)` and calls `$onUpdate($update, $this)` for
 * each update, from which a test may send a notification (`session/cancel`). Use inside `fiber\run()`.
 */
final class AcpClient
{
    /** @var list<array<string, mixed>> every update, in order */
    public array $updates = [];
    /** @var list<array<string, mixed>> every permission request's params, in order */
    public array $permissions = [];
    /** @var resource */
    private mixed $process;
    /** @var resource */
    private mixed $stdin;
    /** @var resource */
    private mixed $stdout;
    private string $buffer = '';
    private int $nextId    = 1;
    /** @var array<int, array<string, mixed>> */
    private array $responses = [];

    /**
     * @param list<string>                                                $command
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $permission
     * @param (\Closure(array<string, mixed>, self): void)|null           $onUpdate
     */
    public function __construct(array $command, private ?\Closure $permission = null, private ?\Closure $onUpdate = null)
    {
        $process = \proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (false === $process) {
            throw new \RuntimeException('Could not start ' . \implode(' ', $command));
        }
        $this->process                = $process;
        [$this->stdin, $this->stdout] = $pipes;
        \stream_set_blocking($this->stdin, false);
        \stream_set_blocking($this->stdout, false);
    }

    /**
     * Sends a request and reads until its response: the result, or `['error' => ...]`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function request(string $method, array $params): ?array
    {
        $id = $this->nextId++;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
        while (!isset($this->responses[$id])) {
            $this->read();
        }
        $message = $this->responses[$id];
        unset($this->responses[$id]);

        return isset($message['error']) ? ['error' => $message['error']] : $message['result'];
    }

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params]);
    }

    /** Sends a raw line, for malformed input. */
    public function raw(string $line): void
    {
        $this->write($line . "\n");
    }

    /** Closes the agent's input, reads its output to the end and returns its exit code. */
    public function close(): int
    {
        \fclose($this->stdin);
        \fiber\run(function () {
            while (!\feof($this->stdout)) {
                readable($this->stdout);
                \fread($this->stdout, 65536);
            }
        });
        \fclose($this->stdout);

        return \proc_close($this->process);
    }

    /** @return list<string> the kinds of the updates, in order (`sessionUpdate`) */
    public function kinds(): array
    {
        return \array_map(static fn (array $u) => $u['sessionUpdate'], $this->updates);
    }

    private function read(): void
    {
        readable($this->stdout);
        $data = \fread($this->stdout, 65536);
        if ('' === $data && \feof($this->stdout)) {
            throw new \RuntimeException('The agent exited');
        }
        $this->buffer .= $data;
        while (false !== ($eol = \strpos($this->buffer, "\n"))) {
            $message      = \json_decode(\substr($this->buffer, 0, $eol), true, 512, \JSON_THROW_ON_ERROR);
            $this->buffer = \substr($this->buffer, $eol + 1);
            if (!isset($message['method'])) {
                $this->responses[$message['id']] = $message;
            } elseif ('session/update' === $message['method']) {
                $this->updates[] = $message['params']['update'];
                if (null !== $this->onUpdate) {
                    ($this->onUpdate)($message['params']['update'], $this);
                }
            } elseif ('session/request_permission' === $message['method']) {
                $this->permissions[] = $message['params'];
                $this->send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ($this->permission ?? throw new \LogicException('The agent asked for permission, but this client has no answer'))($message['params'])]);
            }
        }
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $this->write(\json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function write(string $line): void
    {
        while ('' !== $line) {
            writable($this->stdin);
            $line = \substr($line, (int) \fwrite($this->stdin, $line));
        }
    }
}
