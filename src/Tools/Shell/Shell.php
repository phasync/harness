<?php

declare(strict_types=1);

namespace Harness\Tools\Shell;

use function fiber\readable;
use function fiber\sleep;
use Harness\Call;
use Harness\Hints;
use Harness\Internal\Utf8;
use Harness\Part\Text;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `shell`: runs a command with `bash -c` in `cwd`, stdout and stderr merged, stdin empty. Output is
 * read with `fiber\readable()` and reported as `ToolProgress`; the model gets the exit code and the
 * output, the middle cut out past `maxOutput` bytes, invalid UTF-8 replaced by U+FFFD. The call ends when the shell exits, times out or
 * is cancelled, and the command's whole process group is then killed (on Linux it runs under `setsid`):
 * a background child does not outlive the call. A non-zero exit is an error result.
 * Which commands may run is a policy for middleware.
 */
final readonly class Shell implements Tool
{
    private string $cwd;

    public function __construct(
        string $cwd,
        private float $timeout = 120.0,
        private float $maxTimeout = 600.0,
        private int $maxOutput = 30000,
    ) {
        if (!\is_dir($cwd)) {
            throw new \InvalidArgumentException("Shell cwd '$cwd' is not a directory");
        }
        if (!\is_executable($cwd)) {
            throw new \InvalidArgumentException("Shell cwd '$cwd' cannot be entered by this process (no execute permission)");
        }
        if (!($timeout > 0.0) || \is_infinite($timeout)) {
            throw new \InvalidArgumentException('Shell timeout is a positive number of seconds, got ' . \var_export($timeout, true));
        }
        if (!($maxTimeout >= $timeout) || \is_infinite($maxTimeout)) {
            throw new \InvalidArgumentException("Shell maxTimeout is at least the default timeout ($timeout s), got " . \var_export($maxTimeout, true));
        }
        if ($maxOutput < 2) {
            throw new \InvalidArgumentException("Shell maxOutput is at least 2 bytes (one for the head, one for the tail), got $maxOutput");
        }
        $this->cwd = \realpath($cwd); // absolute: a later chdir() by the process must not move the tool
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
        $timeout = \min(Input::number($input, 'timeout', $this->timeout, 0.0), $this->maxTimeout);
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
        $exited   = null;
        $carry    = ''; // bytes of a character the next read completes
        $take     = function (string $chunk) use (&$head, &$tail, &$total, &$carry, $call): void {
            $total += \strlen($chunk);
            $text  = $carry . $chunk;
            $carry = Utf8::incompleteTail($text);
            $call->progress(Utf8::scrub(\substr($text, 0, \strlen($text) - \strlen($carry)))); // valid UTF-8, as the final text
            $half = \intdiv($this->maxOutput, 2);
            if (\strlen($head) < $half) {
                $piece = \substr($chunk, 0, $half - \strlen($head));
                $head .= $piece;
                $chunk = \substr($chunk, \strlen($piece));
            }
            $tail = \substr($tail . $chunk, -$half);
        };
        while (true) {
            $left = $deadline - \microtime(true);
            if ($left <= 0 || $call->isCancelled()) {
                $killed = $left <= 0 ? 'timed out after ' . $timeout . ' s' : 'cancelled';
                break;
            }
            try {
                readable($out, \min($left, 0.25));
            } catch (\fiber\TimeoutException) {
                // The pipe stays open while a background child holds it: the shell's own exit ends the call.
                $status = \proc_get_status($process);
                if (!$status['running']) {
                    $exited = $status;
                    while ('' !== ($rest = (string) \fread($out, 65536))) {
                        $take($rest);
                    }
                    break;
                }
                continue;
            }
            $chunk = \fread($out, 65536);
            if (false === $chunk || ('' === $chunk && \feof($out))) {
                break;
            }
            if ('' !== $chunk) {
                $take($chunk);
            }
        }
        \fclose($out);
        $status = $exited ?? \proc_get_status($process);
        if ($group) {
            \posix_kill(-$status['pid'], 9); // the whole group, however the command ended: a background child does not outlive the call
        } elseif ($status['running']) {
            \proc_terminate($process, 9);
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
        if ('' !== $carry) {
            $call->progress(Utf8::scrub($carry));
        }
        $exit   = $status['exitcode'];
        $output = $head . ($total > \strlen($head) + \strlen($tail) ? "\n[" . ($total - \strlen($head) - \strlen($tail)) . " bytes cut]\n" : '') . $tail;
        $output = Utf8::scrub($output); // binary output and a character split by the cut become U+FFFD
        $header = null !== $killed ? "Killed: $killed" : "Exit code $exit";

        return new Result(
            [new Text($header . ('' === $output ? ' (no output)' : "\n" . $output))],
            value: null !== $killed ? null : $exit,
            meta: ['seconds' => \round(\microtime(true) - $started, 3), 'bytes' => $total],
            error: null !== $killed || 0 !== $exit,
        );
    }
}
