<?php

declare(strict_types=1);

use Harness\Event\TextDelta;
use Harness\Hints;
use Harness\Http\StreamTransport;
use Harness\Options;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Reason;
use Harness\Result;
use Harness\Run;
use Harness\Tools;
use Harness\Tools\Files\ReadFile;
use Harness\Tools\Files\Root;

// Live: HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=... vendor/bin/pest --group live
function liveModel(): ChatCompletions
{
    return new ChatCompletions(new StreamTransport(), (string) \getenv('HARNESS_OPENAI_URL'), (string) (\getenv('HARNESS_MODEL') ?: 'default'));
}

function liveFinalText(Run $run): string
{
    $appended = $run->ended()->appended;
    $last     = $appended[\array_key_last($appended)];

    return \implode('', \array_map(fn ($p) => $p instanceof Harness\Part\Text ? $p->text : '', $last->parts));
}

$skip = false === \getenv('HARNESS_OPENAI_URL');

it('streams a reply', function () {
    $run    = new Run(liveModel(), new Tools(), options: new Options(maxOutputTokens: 1500));
    $events = turn($run, user('Reply with the single word: pong'));
    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and(\array_filter($events, fn ($e) => $e instanceof TextDelta))->not->toBeEmpty()
        ->and(\strtolower(liveFinalText($run)))->toContain('pong')
        ->and($run->ended()->usage->output)->toBeGreaterThan(0);
})->group('live')->skip($skip, 'HARNESS_OPENAI_URL is not set');

it('calls a tool and uses its result', function () {
    $weather = new Harness\ClosureTool(
        new Harness\ToolSpec('weather', 'The current weather in a city', ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']], new Hints(readOnly: true)),
        fn (array $in) => Result::text("It is 17 degrees and raining in {$in['city']}."),
    );
    $run     = new Run(liveModel(), new Tools($weather), options: new Options(maxOutputTokens: 1500));
    turn($run, user('Use the weather tool for Bergen, then tell me the temperature in one short sentence.'));
    $calls = \array_filter($run->messages()[1]->parts, fn ($p) => $p instanceof Harness\Part\ToolCall);
    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and($calls)->not->toBeEmpty()
        ->and(liveFinalText($run))->toContain('17');
})->group('live')->skip($skip, 'HARNESS_OPENAI_URL is not set');

it('reads a file with the file tools', function () {
    $dir = \sys_get_temp_dir() . '/harness-live-' . \bin2hex(\random_bytes(4));
    \mkdir($dir);
    \file_put_contents($dir . '/ticket.txt', "The ticket number is TANGERINE-42.\n");
    try {
        $run = new Run(liveModel(), new Tools(new ReadFile(new Root($dir))), options: new Options(maxOutputTokens: 1500));
        turn($run, user('Read ticket.txt and tell me the ticket number, nothing else.'));
        expect($run->ended()->reason)->toBe(Reason::Done)->and(liveFinalText($run))->toContain('TANGERINE-42');
    } finally {
        \unlink($dir . '/ticket.txt');
        \rmdir($dir);
    }
})->group('live')->skip($skip, 'HARNESS_OPENAI_URL is not set');
