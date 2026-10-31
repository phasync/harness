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
