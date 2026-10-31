<?php

declare(strict_types=1);

/*
 * A parent agent that delegates reading to sub-agents. Their events arrive in the same stream with
 * `parent` set to the tool call that started them; several may stream at once. Text is buffered per
 * message and printed a whole line at a time (and the rest at MessageEnded), indented by nesting,
 * so two open messages never mix their characters.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8 php examples/subagent.php <dir> <question>
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
    system: 'You answer questions about a project. You cannot read files yourself: delegate reading to the explorer tool. For independent questions, call it several times in one step; the calls run at once. Be brief.',
    options: $options,
);

/** Renders a stream with concurrent sub-agents: one whole line at a time, per message. */
final class Lines
{
    /** @var array<string, int> nesting depth by tool call id */
    private array $depth = [];
    /** @var array<string, string> unprinted text by message id */
    private array $open = [];

    public function render(Event $e): void
    {
        $depth = null === $e->parent ? 0 : $this->depth[$e->parent] + 1;
        $pad   = \str_repeat('    ', $depth);
        $tag   = null === $e->parent ? '' : "\e[35m{$e->parent}\e[0m ";
        if ($e instanceof Event\ToolCallReady) {
            $this->depth[$e->id] = $depth;
        }
        match (true) {
            $e instanceof Event\TextDelta     => $this->text($e->id, $e->text, $pad . $tag),
            $e instanceof Event\MessageEnded  => $this->flush($e->id, $pad . $tag),
            $e instanceof Event\ToolCallReady => $this->line($pad . $tag . "\e[36m→ {$e->name} " . \json_encode($e->input, \JSON_UNESCAPED_SLASHES) . "\e[0m"),
            $e instanceof Event\ToolEnded     => $this->line($pad . $tag . "\e[2m← " . ($e->outcome instanceof Result ? \strtok($e->outcome->content[0]->text ?? '', "\n") : $e->outcome->getMessage()) . "\e[0m"),
            $e instanceof Event\TurnEnded     => $this->line($pad . $tag . "\e[2m[" . (null === $e->parent ? '' : 'sub-agent ') . "{$e->reason->value}, {$e->usage->input} in, {$e->usage->output} out]\e[0m"),
            default                           => null,
        };
    }

    private function text(string $id, string $text, string $prefix): void
    {
        $this->open[$id] = ($this->open[$id] ?? '') . $text;
        while (($eol = \strpos($this->open[$id], "\n")) !== false) {
            $this->line($prefix . \substr($this->open[$id], 0, $eol));
            $this->open[$id] = \substr($this->open[$id], $eol + 1);
        }
    }

    private function flush(string $id, string $prefix): void
    {
        if ('' !== ($this->open[$id] ?? '')) {
            $this->line($prefix . $this->open[$id]);
        }
        unset($this->open[$id]);
    }

    private function line(string $line): void
    {
        echo $line, "\n";
    }
}

fiber\run(function () use ($run, $question) {
    $lines = new Lines();
    foreach ($run->turn(new Message(Role::User, [new Text($question)])) as $e) {
        $lines->render($e);
    }
});
