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
use Harness\Tools\Files\Matcher;

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

it('reindents text from tab to space indentation with blank lines', function () {
    // Test reindent converting tabs to spaces, including blank lines which should be skipped
    $text   = "if (x) {\n\tcode();\n\n\tanother();\n}";
    $result = Matcher::reindent($text, "\t", '    ');

    // Tabs should be converted to 4 spaces; blank line should be preserved as-is
    expect($result)->toBe("if (x) {\n    code();\n\n    another();\n}");
});

it('rejects non-Message objects in messages parameter', function () {
    // Run.php lines 105-108: validate that all messages are Message objects
    // When a non-Message is passed in messages array, throw InvalidArgumentException naming the type

    // Checked against tests/ - no existing test for this specific validation
    try {
        new Run(new FakeModel(), new Tools(), messages: [new stdClass()]);
        // Should not reach here
        expect(true)->toBe(false);
    } catch (InvalidArgumentException $e) {
        // Expected: exception thrown with type name in message
        expect($e->getMessage())->toContain('messages must be Message objects, got stdClass');
    }
});
