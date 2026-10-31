<?php

declare(strict_types=1);

use Harness\Delta\TextDelta;
use Harness\ErrorKind;
use Harness\Message;
use Harness\Part\Text;
use Harness\Reason;
use Harness\Role;
use Harness\Run;
use Harness\Stop;
use Harness\Testing\FakeModel;
use Harness\Tools;

it('validates Stop::maxSteps requires n >= 1', function () {
    expect(fn () => Stop::maxSteps(0))->toThrow(InvalidArgumentException::class, 'n >= 1');
});

it('fails when a model yields a non-Delta object', function () {
    $model = new FakeModel(function () {
        yield new TextDelta('valid');
        yield new stdClass(); // Invalid: not a Delta
    });
    $run = new Run($model, new Tools());

    try {
        \fiber\run(function () use ($run) {
            foreach ($run->turn(new Message(Role::User, [new Text('go')])) as $_) {
            }
        });
        // Should not reach here - LogicException should be rethrown
        expect(false)->toBeTrue();
    } catch (LogicException $e) {
        // Expected: LogicException is rethrown after TurnEnded
        expect($e->getMessage())->toContain('not a Harness\Delta');
    }

    $ended = $run->ended();
    expect($ended->reason)->toBe(Reason::Failed)
        ->and($ended->errorKind)->toBe(ErrorKind::ModelError)
        ->and($ended->error)->toBeInstanceOf(LogicException::class);
});
