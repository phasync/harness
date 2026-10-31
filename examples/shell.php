<?php

declare(strict_types=1);

/*
 * An agent with a shell in a directory. Every command needs your approval; output streams as it runs.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8 php examples/shell.php <dir> [task]
 *
 * With a task it runs one turn; without, it asks for tasks until EOF. Ctrl-C cancels a turn (and kills the command).
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Call;
use Harness\Event;
use Harness\Http\StreamTransport;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\ToolMiddleware;
use Harness\Tools;
use Harness\Tools\Shell\Shell;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url  = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$dir  = \realpath($argv[1] ?? '.') ?: exit("Usage: php examples/shell.php <dir> [task]\n");
$task = \implode(' ', \array_slice($argv, 2));

// The policy is a middleware: here, ask on the terminal before every command.
$approve = new class implements ToolMiddleware {
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        echo "\n\e[33mRun `" . ($call->input['command'] ?? '?') . "`? [y/N]\e[0m ";
        $answer = \fgets(fiber\readable(\STDIN));
        if ('y' !== \strtolower(\trim((string) $answer))) {
            return new Result([new Text('The user did not allow this command')], error: true);
        }

        return $next($call, $context);
    }
};

$run = new Run(
    new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default'),
    new Tools(new Shell($dir, timeout: 60)),
    system: "You have a bash shell in $dir. Prefer one command per call. Be brief.",
    middleware: [$approve],
    options: new Options(maxOutputTokens: 2000),
);

$busy = false;
\pcntl_async_signals(true);
\pcntl_signal(\SIGINT, function () use ($run, &$busy) {
    $busy ? $run->cancel() : exit(0);
});

fiber\run(function () use ($run, $task, &$busy) {
    $once = '' !== $task;
    do {
        if (!$once) {
            echo "\n\e[1mtask>\e[0m ";
            $line = \fgets(fiber\readable(\STDIN));
            if (false === $line) {
                return;
            }
            $task = \trim($line);
        }
        $busy = true;
        foreach ($run->turn(new Message(Role::User, [new Text($task)])) as $e) {
            match (true) {
                $e instanceof Event\ReasoningDelta => print ("\e[2m{$e->text}\e[0m"),
                $e instanceof Event\TextDelta      => print ($e->text),
                $e instanceof Event\ToolProgress   => print ("\e[2m" . (\is_string($e->value) ? $e->value : \json_encode($e->value)) . "\e[0m"),
                $e instanceof Event\ToolEnded      => print ($e->outcome instanceof Result ? "\e[36m← " . \strtok($e->outcome->content[0]->text, "\n") . "\e[0m\n" : "\e[31m← {$e->outcome->getMessage()}\e[0m\n"),
                $e instanceof Event\TurnEnded      => print ("\n\e[2m[{$e->reason->value}" . ($e->error ? ": {$e->error->value}" : '') . ", {$e->usage->input} in, {$e->usage->output} out]\e[0m\n"),
                default                            => null,
            };
        }
        $busy = false;
    } while (!$once);
});
