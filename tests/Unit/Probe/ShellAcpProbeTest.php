<?php

declare(strict_types=1);

use Harness\Testing\FakeModel;
use Harness\Testing\TestPrompt;
use Harness\Tools\Shell\Shell;

it('throws when FakeModel runs out of replies', function () {
    $model = new FakeModel();
    expect(fn () => \fiber\run(fn () => \iterator_to_array($model->stream(TestPrompt::make()))))->toThrow(
        LogicException::class,
        'FakeModel has no reply left for call 1'
    );
});

it('captures only-stderr output with non-zero exit code', function () {
    $r = \fiber\run(fn () => (new Shell(\sys_get_temp_dir()))->call(['command' => 'echo error >&2; exit 2'], callFor()));
    expect($r->content[0]->text)->toBe("Exit code 2\nerror\n")
        ->and($r->error)->toBeTrue()
        ->and($r->value)->toBe(2);
});

it('does not leave background children alive', function () {
    $marker = \sys_get_temp_dir() . '/harness-bg-child-' . \bin2hex(\random_bytes(4));
    $cmd    = "(sleep 2 && touch '$marker') &";

    try {
        $r = \fiber\run(fn () => (new Shell(\sys_get_temp_dir()))->call(['command' => $cmd], callFor()));
        expect($r->content[0]->text)->toContain('Exit code 0');

        \usleep(2500000);

        expect(\file_exists($marker))->toBeFalse();
    } finally {
        if (\is_file($marker)) {
            \unlink($marker);
        }
    }
});
