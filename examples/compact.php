<?php

declare(strict_types=1);

/*
 * A long session within a small context budget. A Compact middleware replaces the oldest half of
 * the conversation with a summary whenever a step's prompt would exceed the budget; the run itself
 * keeps every message. Compactions are printed as they happen (the middleware logs them) and after
 * each turn (Compact::last()). The last question can only be answered from the summary.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8-27b-exl3-sivert php examples/compact.php [budget]
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Call;
use Harness\ClosureTool;
use Harness\Compact\Compact;
use Harness\Event;
use Harness\Hints;
use Harness\Http\StreamTransport;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\Tools;
use Harness\ToolSpec;
use Psr\Log\AbstractLogger;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url    = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$budget = (int) ($argv[1] ?? 700);
$model  = new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default');

// A catalogue with long entries, so a few lookups fill the budget.
$colours = ['amber', 'teal', 'crimson', 'olive', 'indigo', 'saffron'];
$lookup  = new ClosureTool(
    new ToolSpec('catalogue', 'Looks up one item of the catalogue by number (1-6)', ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']], new Hints(readOnly: true)),
    function (array $in, Call $call) use ($colours): Result {
        $n = (int) $in['n'];
        if (!isset($colours[$n - 1])) {
            return new Result([new Text("There is no item $n")], error: true);
        }

        return Result::text("Item $n. Colour: {$colours[$n - 1]}. Weight: " . (100 * $n + 7) . " g.\n" . \str_repeat("Item $n is a handmade ceramic piece; the glaze is fired twice and every piece differs slightly. ", 8));
    },
);

$log = new class extends AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $text = \preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($context[$m[1]] ?? $m[0]), (string) $message);
        echo "\n\e[35m[$level] $text\e[0m\n";
    }
};
$compact = new Compact($model, $budget, log: $log);
$run     = new Run(
    $model,
    new Tools($lookup),
    system: 'You answer questions about a catalogue. Look items up with the catalogue tool, one call per item. Be brief.',
    middleware: [$compact],
    options: new Options(maxOutputTokens: 1500),
);

$questions = [
    'Look up items 1, 2 and 3 and tell me their colours.',
    'Now items 4, 5 and 6: their weights?',
    'Without looking anything up again: what colour was item 1, and what did I ask you first?',
];
fiber\run(function () use ($run, $compact, $questions) {
    foreach ($questions as $q) {
        echo "\n\e[1m> $q\e[0m\n";
        foreach ($run->turn(new Message(Role::User, [new Text($q)])) as $e) {
            match (true) {
                $e instanceof Event\TextDelta     => print ($e->text),
                $e instanceof Event\ToolCallReady => print ("\e[36m→ {$e->name} " . \json_encode($e->input) . "\e[0m\n"),
                $e instanceof Event\TurnEnded     => print ("\n\e[2m[{$e->reason->value}, {$e->usage->input} in, {$e->usage->output} out]\e[0m\n"),
                default                           => null,
            };
        }
        $last = $compact->last();
        echo "\e[2m" . (null === $last
            ? 'last step: no compaction'
            : "last step: {$last->removed} messages summarized" . ($last->reused ? ' (summary reused)' : '') . ", {$last->kept} kept, {$last->before} -> {$last->after} tokens")
            . ', ' . \count($run->messages()) . " messages in the run\e[0m\n";
    }
});
