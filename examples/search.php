<?php

declare(strict_types=1);

/*
 * A question answered with web_search and web_fetch, over a local SearXng instance
 * (docs/web.md: "Running SearXNG"). Tool calls are printed as they happen, then the model's reply.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8 php examples/search.php "question"
 *   HARNESS_SEARXNG_URL=http://127.0.0.1:8888 ...   # default
 */

require __DIR__ . '/../vendor/autoload.php';

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
use Harness\Tools\Web\SearXng;
use Harness\Tools\Web\WebFetch;
use Harness\Tools\Web\WebSearch;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url        = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$searxngUrl = \getenv('HARNESS_SEARXNG_URL') ?: 'http://127.0.0.1:8888';
$question   = \implode(' ', \array_slice($argv, 1)) ?: exit("Usage: php examples/search.php \"question\"\n");

$transport = new StreamTransport();
$tools     = new Tools(
    new WebSearch(new SearXng($transport, $searxngUrl)),
    new WebFetch($transport, userAgent: 'phasync-harness-example/0.2 (+https://github.com/phasync/harness)'),
);

function render(Event $e): void
{
    match (true) {
        $e instanceof Event\TextDelta     => print ($e->text),
        $e instanceof Event\ToolCallReady => print ("\n\e[36m→ {$e->name} " . \json_encode($e->input, \JSON_UNESCAPED_SLASHES) . "\e[0m\n"),
        $e instanceof Event\ToolEnded     => print ($e->outcome instanceof Result
            ? "\e[2m← " . ($e->outcome->error ? 'error: ' : '') . \strtok($e->outcome->content[0]->text ?? '(media)', "\n") . "\e[0m\n"
            : "\e[31m← {$e->outcome->getMessage()}\e[0m\n"),
        default                           => null,
    };
}

fiber\run(function () use ($tools, $transport, $url, $question) {
    $model = new ChatCompletions($transport, $url, \getenv('HARNESS_MODEL') ?: 'default');
    $run   = new Run($model, $tools, system: 'Search and fetch pages to answer. Be brief.', options: new Options(maxOutputTokens: 2000));
    foreach ($run->turn(new Message(Role::User, [new Text($question)])) as $event) {
        render($event);
    }
    echo "\n";
});
