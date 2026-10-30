<?php

declare(strict_types=1);

use Harness\Http\StreamTransport;
use Harness\Options;
use Harness\Provider\Anthropic\Messages;
use Harness\Reason;
use Harness\Run;
use Harness\Tools;

// Live: ANTHROPIC_API_KEY=... [ANTHROPIC_MODEL=...] vendor/bin/pest --group live
it('streams a reply from the Messages API', function () {
    $model = new Messages(new StreamTransport(), (string) \getenv('ANTHROPIC_API_KEY'), \getenv('ANTHROPIC_MODEL') ?: 'claude-opus-5');
    $run   = new Run($model, new Tools(), options: new Options(maxOutputTokens: 1000));
    turn($run, user('Reply with the single word: pong'));
    expect($run->ended()->reason)->toBe(Reason::Done);
})->group('live')->skip(false === \getenv('ANTHROPIC_API_KEY'), 'ANTHROPIC_API_KEY is not set');
