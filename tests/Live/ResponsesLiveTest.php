<?php

declare(strict_types=1);

use Harness\Hints;
use Harness\Http\StreamTransport;
use Harness\Options;
use Harness\Provider\OpenAI\Responses;
use Harness\Reason;
use Harness\Result;
use Harness\Run;
use Harness\Tools;

// Live: OPENAI_API_KEY=... [OPENAI_MODEL=...] [OPENAI_BASE_URL=...] vendor/bin/pest --group live
it('calls a tool over the Responses API and uses its result', function () {
    $model = new Responses(new StreamTransport(), \getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', \getenv('OPENAI_MODEL') ?: 'gpt-5-mini', (string) \getenv('OPENAI_API_KEY'));
    $clock = tool('clock', fn () => Result::text('The time is 12:34'), new Hints(readOnly: true));
    $run   = new Run($model, new Tools($clock), system: 'Use the clock tool to tell the time.', options: new Options(maxOutputTokens: 2000));
    turn($run, user('What time is it?'));
    expect($run->ended()->reason)->toBe(Reason::Done)
        ->and(\json_encode(\array_map(fn ($m) => $m->toArray(), $run->messages())))->toContain('12:34');
})->group('live')->skip(false === \getenv('OPENAI_API_KEY'), 'OPENAI_API_KEY is not set');
