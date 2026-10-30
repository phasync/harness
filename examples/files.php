<?php

declare(strict_types=1);

/*
 * An agent with the file tools over a directory. Writes and edits need your approval on the terminal.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8-27b-exl3-sivert php examples/files.php <dir> [task]
 *
 * With a task it runs one turn; without, it asks for tasks until EOF. Ctrl-C cancels a turn.
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Approval;
use Harness\Call;
use Harness\Decision;
use Harness\Event;
use Harness\Http\StreamTransport;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\ToolMiddleware;
use Harness\Tools;
use Harness\Tools\Files;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url  = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$dir  = $argv[1] ?? exit("Usage: php examples/files.php <dir> [task]\n");
$task = \implode(' ', \array_slice($argv, 2));
$root = new Files\Root($dir);

// A CLI that only ever answers on the terminal gates in one middleware (see examples/shell.php): it
// asks, then calls $next() or returns an error Result. This example uses two on purpose, to show the
// form for a policy shared with a web host. The policy asks with needsInput(), which a web host
// answers in its next request; the terminal middleware outside it answers at once instead, in the
// tool's fiber, so here the turn never ends awaiting input.
$policy = new class implements ToolMiddleware {
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        // decision() or answers() set: the host answered this call already.
        if (null === $context->decision() && null === $context->answers() && null !== $context->spec && !$context->spec->hints->readOnly) {
            return $context->needsInput(new Approval("{$call->name} changes files"));
        }

        return $next($call, $context);
    }
};

$terminal = new class implements ToolMiddleware {
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        $result = $next($call, $context);
        if (null === $context->pending()) {
            return $result;
        }
        $input = $call->input;
        $shown = isset($input['content']) ? \strlen((string) $input['content']) . ' bytes' : \json_encode(\array_diff_key($input, ['path' => 1]), \JSON_UNESCAPED_SLASHES);
        echo "\n\e[33mAllow {$call->name} on " . ($input['path'] ?? '?') . " ($shown)? [y/N]\e[0m ";
        $answer = \fgets(fiber\readable(\STDIN));
        echo false === $answer ? "\n" : '';
        $context->answer('y' === \strtolower(\trim((string) $answer)) ? Decision::allow() : Decision::deny('the user said no'));

        return $context->decision()->allowed ? $next($call, $context) : new Result([new Text('Denied by the user')], error: true);
    }
};

$model = new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default');
$run   = new Run(
    $model,
    new Tools(new Files\ReadFile($root), new Files\ListDir($root), new Files\Glob($root), new Files\Grep($root), new Files\WriteFile($root), new Files\EditFile($root)),
    system: "You work on the files in {$root->path} with your tools. Paths are relative to it. Be brief.",
    middleware: [$terminal, $policy],
    options: new Options(maxOutputTokens: 2000),
);

$busy = false;
\pcntl_async_signals(true);
\pcntl_signal(\SIGINT, function () use ($run, &$busy) {
    $busy ? $run->cancel() : exit(0);
});

function render(Event $e): void
{
    match (true) {
        $e instanceof Event\ReasoningDelta => print ("\e[2m{$e->text}\e[0m"),
        $e instanceof Event\TextDelta      => print ($e->text),
        $e instanceof Event\ToolCallReady  => print ("\n\e[36m→ {$e->name} " . \json_encode($e->input, \JSON_UNESCAPED_SLASHES) . "\e[0m\n"),
        $e instanceof Event\ToolEnded      => print ($e->outcome instanceof Result
            ? "\e[2m← " . ($e->outcome->error ? 'error: ' : '') . \strtok($e->outcome->content[0]->text ?? '(media)', "\n") . "\e[0m\n"
            : "\e[31m← {$e->outcome->getMessage()}\e[0m\n"),
        $e instanceof Event\TurnEnded      => print ("\n\e[2m[{$e->reason->value}" . ($e->error ? ": {$e->error->value}" : '') . ", {$e->usage->input} in, {$e->usage->output} out]\e[0m\n"),
        default                            => null,
    };
}

fiber\run(function () use ($run, $task, &$busy) {
    do {
        if ('' === $task) {
            echo "\n\e[1mtask>\e[0m ";
            $line = \fgets(fiber\readable(\STDIN));
            if (false === $line) {
                return;
            }
            $task = \trim($line);
            if ('' === $task) {
                continue;
            }
            $once = false;
        } else {
            $once = true;
        }
        $busy = true;
        foreach ($run->turn(new Message(Role::User, [new Text($task)])) as $event) {
            render($event);
        }
        $busy = false;
        if (Reason::Failed === $run->ended()->reason) {
            echo "\e[31m{$run->ended()->error->getMessage()}\e[0m\n";
        }
        $task = '';
    } while (!$once);
});
