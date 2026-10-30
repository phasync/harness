<?php

declare(strict_types=1);

/*
 * A chat with one MCP server's tools, over stdio. Without HARNESS_MCP_COMMAND it starts the small
 * PHP test server in tests/Support (echo, a form that asks for a colour, progress, ...). When a tool
 * asks for input (input_required), the turn ends awaiting input; the questions are asked on the
 * terminal and the next turn resumes the call with the answers.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8-27b-exl3-sivert php examples/mcp.php ["task"]
 *   HARNESS_MCP_COMMAND='my-mcp-server --flag' ...   # any server speaking revision 2026-07-28
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Answer;
use Harness\Event;
use Harness\Http\StreamTransport;
use Harness\Mcp\McpServer;
use Harness\Mcp\StdioConnection;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Run;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url     = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$command = \getenv('HARNESS_MCP_COMMAND');
$server  = new McpServer(new StdioConnection(false === $command ? [\PHP_BINARY, __DIR__ . '/../tests/Support/mcp-server.php'] : ['/bin/sh', '-c', $command]));
$task    = \implode(' ', \array_slice($argv, 1));

function render(Event $e): void
{
    match (true) {
        $e instanceof Event\TextDelta     => print ($e->text),
        $e instanceof Event\ToolCallReady => print ("\n\e[36m→ {$e->name} " . \json_encode($e->input, \JSON_UNESCAPED_SLASHES) . "\e[0m\n"),
        $e instanceof Event\ToolProgress  => print ("\e[2m  " . \json_encode($e->value) . "\e[0m\n"),
        $e instanceof Event\ToolEnded     => print ($e->outcome instanceof Result
            ? "\e[2m← " . ($e->outcome->error ? 'error: ' : '') . \strtok($e->outcome->content[0]->text ?? '(media)', "\n") . "\e[0m\n"
            : "\e[31m← {$e->outcome->getMessage()}\e[0m\n"),
        $e instanceof Event\TurnEnded     => print ("\n\e[2m[{$e->reason->value}, {$e->usage->input} in, {$e->usage->output} out]\e[0m\n"),
        default                           => null,
    };
}

function ask(string $prompt): string|false
{
    echo $prompt;
    $line = \fgets(fiber\readable(\STDIN));

    return false === $line ? false : \trim($line);
}

fiber\run(function () use ($server, $url, $task) {
    $run = new Run(
        new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default'),
        $server,
        system: 'You have the tools of an MCP server. Use them when they help. Be brief. ' . $server->instructions(),
        options: new Options(maxOutputTokens: 2000),
    );
    $once = '' !== $task;
    while (true) {
        if (!$once && false === ($task = ask("\n\e[1myou>\e[0m "))) {
            return;
        }
        $input = new Message(Role::User, [new Text($task)]);
        do {
            foreach ($run->turn($input) as $event) {
                render($event);
            }
            // A tool asked the host: ask each question here and resume with the answers.
            $input = [];
            foreach ($run->ended()->pending as $p) {
                $answers = [];
                foreach ($p->needs as $q) {
                    $choices = [] === $q->choices ? '' : ' [' . \implode('/', $q->choices) . ']';
                    do {
                        $a = ask("\e[33m{$q->text}{$choices}\e[0m ");
                        if (false === $a) {
                            return;
                        }
                    } while ([] !== $q->choices && !\in_array($a, $q->choices, true));
                    $answers[$q->id] = $a;
                }
                $input[] = new Answer($p->call->id, $answers);
            }
        } while (Reason::AwaitingInput === $run->ended()->reason);
        if ($once) {
            return;
        }
    }
});
