<?php

declare(strict_types=1);

/*
 * A parent agent that delegates reading to a sub-agent. The sub-agent's events arrive in the same
 * stream with `parent` set to the tool call that started it; they are printed indented.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8-27b-exl3-sivert php examples/subagent.php <dir> <question>
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Agents\AgentTool;
use Harness\Event;
use Harness\Http\StreamTransport;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\Tools;
use Harness\Tools\Files;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url      = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$dir      = $argv[1] ?? exit("Usage: php examples/subagent.php <dir> <question>\n");
$question = \implode(' ', \array_slice($argv, 2)) ?: exit("Usage: php examples/subagent.php <dir> <question>\n");
$root     = new Files\Root($dir);
$model    = new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default');
$options  = new Options(maxOutputTokens: 2000);

$explorer = new Run(
    $model,
    new Tools(new Files\ListDir($root), new Files\Glob($root), new Files\Grep($root), new Files\ReadFile($root)),
    system: 'You explore a directory with read-only tools and answer the task precisely and briefly, citing file paths.',
    options: $options,
);
$run = new Run(
    $model,
    new Tools(new AgentTool($explorer, 'explorer', 'A sub-agent that reads the project files and answers a question about them. It sees only the prompt you give it.')),
    system: 'You answer questions about a project. You cannot read files yourself: delegate reading to the explorer tool. Be brief.',
    options: $options,
);

fiber\run(function () use ($run, $question) {
    foreach ($run->turn(new Message(Role::User, [new Text($question)])) as $e) {
        $pad = null === $e->parent ? '' : '    ';
        match (true) {
            $e instanceof Event\TurnStarted && null !== $e->parent => print ("\n\e[35m{$pad}┌ sub-agent for {$e->parent}\e[0m\n"),
            $e instanceof Event\TextDelta                          => print ($pad ? \str_replace("\n", "\n$pad", $e->text) : $e->text),
            $e instanceof Event\ToolCallReady                      => print ("\n\e[36m{$pad}→ {$e->name} " . \json_encode($e->input, \JSON_UNESCAPED_SLASHES) . "\e[0m\n"),
            $e instanceof Event\ToolEnded                          => print ("\e[2m{$pad}← " . ($e->outcome instanceof Result ? \strtok($e->outcome->content[0]->text ?? '', "\n") : $e->outcome->getMessage()) . "\e[0m\n"),
            $e instanceof Event\MessageStarted                     => print ($pad),
            $e instanceof Event\TurnEnded                          => print ("\n\e[2m{$pad}[" . (null === $e->parent ? '' : 'sub-agent ') . "{$e->reason->value}, {$e->usage->input} in, {$e->usage->output} out]\e[0m\n"),
            default                                                => null,
        };
    }
});
