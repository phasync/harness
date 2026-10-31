<?php

declare(strict_types=1);

namespace Harness\Mcp;

use function fiber\await;
use fiber\Deferred;
use function fiber\readable;
use function fiber\writable;
use Harness\Cancel;
use Harness\Cancelled;

/**
 * An MCP server as a child process (`proc_open($command)`, no shell), speaking newline-delimited
 * JSON-RPC on its stdin and stdout; its stderr is this process's. The process starts at the first
 * request and is terminated when the connection is destroyed. Concurrent requests share the
 * process: one waiting request reads for all and hands each response to its caller. Waits are
 * `fiber\readable()`/`fiber\writable()` in 250 ms slices; a cancelled request sends
 * `notifications/cancelled` and throws `Harness\Cancelled`. Progress notifications reach the
 * request that set their `progressToken`.
 */
final class StdioConnection implements Connection
{
    private const POLL = 0.25;

    /** @var resource|null */
    private mixed $process = null;
    /** @var resource */
    private mixed $stdin;
    /** @var resource */
    private mixed $stdout;
    private int $nextId   = 1;
    private string $input = '';
    private bool $reading = false;
    private bool $writing = false;
    private ?string $dead = null;
    /** @var array<int, array<string, mixed>> responses by request id */
    private array $responses = [];
    /** @var array<int, true> requests awaiting their response */
    private array $open = [];
    /** @var array<string, \Closure(array<string, mixed>): void> */
    private array $progress = [];
    /** @var list<Deferred> */
    private array $waiting = [];

    /**
     * @param list<string>               $command the program and its arguments
     * @param array<string, string>|null $env     null: this process's environment
     */
    public function __construct(private readonly array $command, private readonly ?string $cwd = null, private readonly ?array $env = null)
    {
        if ([] === $command || !\array_is_list($command)) {
            throw new \InvalidArgumentException('StdioConnection takes the command as a non-empty list: [program, ...arguments]');
        }
    }

    public function __destruct()
    {
        if (null !== $this->process) {
            \fclose($this->stdin);
            \fclose($this->stdout);
            \proc_terminate($this->process);
            \proc_close($this->process);
        }
    }

    public function request(string $method, array $params, Cancel $cancel, ?\Closure $notify = null, array $headers = []): array
    {
        $this->start();
        $id = $this->nextId++;
        if (null !== $notify) {
            $params['_meta']['progressToken'] = "p$id";
            $this->progress["p$id"]           = $notify;
        }
        $this->open[$id] = true;
        try {
            $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => self::object($params)]);
            $message = $this->receive($id, $method, $cancel);
        } finally {
            unset($this->open[$id], $this->progress["p$id"]);
        }
        if (isset($message['error'])) {
            throw McpException::fromError($method, $message['error']);
        }

        return $message['result'] ?? throw new McpException("MCP $method: the response has neither result nor error");
    }

    private function start(): void
    {
        if (null !== $this->process) {
            return;
        }
        \set_error_handler(function (int $no, string $message): never {
            throw new McpException('Could not start the MCP server: ' . \implode(' ', $this->command) . ": $message");
        });
        try {
            $process = \proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, $this->cwd, $this->env);
        } finally {
            \restore_error_handler();
        }
        if (false === $process) {
            throw new McpException('Could not start the MCP server: ' . \implode(' ', $this->command));
        }
        $this->process                = $process;
        [$this->stdin, $this->stdout] = $pipes;
        \stream_set_blocking($this->stdin, false);
        \stream_set_blocking($this->stdout, false);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $line = \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
        while ($this->writing) {
            $this->waiting[] = $turn = new Deferred();
            await($turn);
        }
        $this->writing = true;
        try {
            while ('' !== $line) {
                $this->alive();
                writable($this->stdin);
                $n = \fwrite($this->stdin, $line);
                if (false === $n) {
                    throw new McpException('Could not write to the MCP server: ' . \implode(' ', $this->command));
                }
                $line = \substr($line, $n);
            }
        } finally {
            $this->writing = false;
            $this->wake();
        }
    }

    /** @return array<string, mixed> the response message */
    private function receive(int $id, string $method, Cancel $cancel): array
    {
        while (!isset($this->responses[$id])) {
            if ($cancel->isCancelled()) {
                $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => $id, 'reason' => 'The turn was cancelled']]);
                throw new Cancelled("Cancelled while waiting for MCP $method");
            }
            $this->alive();
            if ($this->reading) {
                $this->waiting[] = $turn = new Deferred();
                try {
                    await($turn, self::POLL);
                } catch (\fiber\TimeoutException) {
                }
                continue;
            }
            $this->reading = true;
            try {
                $this->read();
            } finally {
                $this->reading = false;
                $this->wake();
            }
        }
        $message = $this->responses[$id];
        unset($this->responses[$id]);

        return $message;
    }

    /** Reads what the server wrote within one poll interval and dispatches every whole line. */
    private function read(): void
    {
        try {
            readable($this->stdout, self::POLL);
        } catch (\fiber\TimeoutException) {
            return;
        }
        $data = \fread($this->stdout, 65536);
        if ('' === $data || false === $data) {
            if (\feof($this->stdout)) {
                $status     = \proc_get_status($this->process);
                $this->dead = 'The MCP server exited' . ($status['running'] ? '' : " (status {$status['exitcode']})") . ': ' . \implode(' ', $this->command);
            }

            return;
        }
        $this->input .= $data;
        while (($eol = \strpos($this->input, "\n")) !== false) {
            $line        = \trim(\substr($this->input, 0, $eol));
            $this->input = \substr($this->input, $eol + 1);
            if ('' !== $line) {
                $this->dispatch($line);
            }
        }
    }

    private function dispatch(string $line): void
    {
        try {
            $message = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpException("The MCP server wrote a line that is not JSON-RPC: $line", previous: $e);
        }
        if (isset($message['id']) && (\array_key_exists('result', $message) || isset($message['error']))) {
            if (isset($this->open[$message['id']])) {
                $this->responses[$message['id']] = $message;
            }

            return;
        }
        if (\array_key_exists('id', $message) && null === $message['id'] && isset($message['error'])) {
            // JSON-RPC: the server could not tell which request this answers (a parse error, say); every open request hears it
            foreach (\array_keys($this->open) as $id) {
                $this->responses[$id] = ['id' => $id] + $message;
            }

            return;
        }
        if (isset($message['method']) && !isset($message['id'])) {
            $token = $message['params']['progressToken'] ?? null;
            if (\is_string($token) && isset($this->progress[$token])) {
                ($this->progress[$token])($message);
            }

            return;
        }
        if (isset($message['method'], $message['id'])) {
            // A server-to-client request: not part of 2026-07-28 (input goes through input_required).
            $this->send(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32601, 'message' => "This client takes no requests ({$message['method']}); use input_required"]]);
        }
    }

    private function alive(): void
    {
        if (null !== $this->dead) {
            throw new McpException($this->dead);
        }
    }

    private function wake(): void
    {
        $waiting       = $this->waiting;
        $this->waiting = [];
        foreach ($waiting as $turn) {
            $turn->resolve();
        }
    }

    /** Empty JSON-RPC params and `_meta` maps are objects. */
    private static function object(array $params): object|array
    {
        return [] === $params ? new \stdClass() : $params;
    }
}
