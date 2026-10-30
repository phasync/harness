<?php

declare(strict_types=1);

namespace Harness\Tools\Shell;

use function fiber\readable;
use function fiber\sleep;
use Harness\Call;
use Harness\Hints;
use Harness\Part\Text;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `shell`: runs a command with `bash -c` in `cwd`, stdout and stderr merged, stdin empty. Output is
 * read with `fiber\readable()` and reported as `ToolProgress`; the model gets the exit code and the
 * output, the middle cut out past `maxOutput` bytes. On timeout or cancellation the command's whole
 * process group is killed (on Linux it runs under `setsid`). A non-zero exit is an error result.
 * Which commands may run is a policy for middleware.
 */
final readonly class Shell implements Tool
{
    public function __construct(
        private string $cwd,
        private float $timeout = 120.0,
        private float $maxTimeout = 600.0,
        private int $maxOutput = 30000,
    ) {
        if (!\is_dir($cwd)) {
            throw new \InvalidArgumentException("Shell cwd '$cwd' is not a directory");
        }
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('shell', "Run a bash command in {$this->cwd}. stdout and stderr are merged; there is no stdin. Default timeout {$this->timeout} s.", [
            'type'       => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'The command line, run with bash -c'],
                'timeout' => ['type' => 'number', 'description' => "Seconds before it is killed (default {$this->timeout}, at most {$this->maxTimeout})"],
            ],
            'required' => ['command'],
        ], new Hints(destructive: true, openWorld: true));
    }

    public function call(array $input, Call $call): Result
    {
        $command = Input::string($input, 'command');
        $timeout = $input['timeout'] ?? $this->timeout;
        if (!\is_int($timeout) && !\is_float($timeout) || $timeout <= 0) {
            throw new \Harness\Retry("'timeout' must be a positive number of seconds");
        }
        $timeout = \min((float) $timeout, $this->maxTimeout);
        $group   = \PHP_OS_FAMILY === 'Linux' && \function_exists('posix_kill');
        $argv    = $group ? ['setsid', 'bash', '-c', $command] : ['bash', '-c', $command];
        $process = \proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->cwd);
        if (false === $process) {
            throw new \RuntimeException('Could not start bash');
        }
        $out = $pipes[1];
        \stream_set_blocking($out, false);
        $started  = \microtime(true);
        $deadline = $started + $timeout;
        $head     = '';
        $tail     = '';
        $total    = 0;
        $killed   = null;
        while (true) {
            $left = $deadline - \microtime(true);
            if ($left <= 0 || $call->isCancelled()) {
                $killed = $left <= 0 ? 'timed out after ' . $timeout . ' s' : 'cancelled';
                break;
            }
            try {
                readable($out, \min($left, 0.25));
            } catch (\fiber\TimeoutException) {
                continue;
            }
            $chunk = \fread($out, 65536);
            if (false === $chunk || ('' === $chunk && \feof($out))) {
                break;
            }
            if ('' === $chunk) {
                continue;
            }
            $total += \strlen($chunk);
            $call->progress($chunk);
            $half = \intdiv($this->maxOutput, 2);
            if (\strlen($head) < $half) {
                $take = \substr($chunk, 0, $half - \strlen($head));
                $head .= $take;
                $chunk = \substr($chunk, \strlen($take));
            }
            $tail = \substr($tail . $chunk, -$half);
        }
        \fclose($out);
        $status = \proc_get_status($process);
        if (null !== $killed && $status['running']) {
            $group ? \posix_kill(-$status['pid'], 9) : \proc_terminate($process, 9);
        }
        while ($status['running']) {
            sleep(0.01);
            $status = \proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (\microtime(true) > $deadline + 1.0) {
                $group ? \posix_kill(-$status['pid'], 9) : \proc_terminate($process, 9);
                $killed ??= 'timed out after ' . $timeout . ' s';
            }
        }
        \proc_close($process);
        $exit   = $status['exitcode'];
        $output = $head . ($total > \strlen($head) + \strlen($tail) ? "\n[" . ($total - \strlen($head) - \strlen($tail)) . " bytes cut]\n" : '') . $tail;
        $header = null !== $killed ? "Killed: $killed" : "Exit code $exit";

        return new Result(
            [new Text($header . ('' === $output ? ' (no output)' : "\n" . $output))],
            value: null !== $killed ? null : $exit,
            meta: ['seconds' => \round(\microtime(true) - $started, 3), 'bytes' => $total],
            error: null !== $killed || 0 !== $exit,
        );
    }
}
