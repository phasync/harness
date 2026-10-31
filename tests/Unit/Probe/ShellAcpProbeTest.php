<?php

declare(strict_types=1);

use Harness\Testing\FakeModel;
use Harness\Testing\TestPrompt;

it('throws when FakeModel runs out of replies', function () {
    $model = new FakeModel();
    expect(fn () => \fiber\run(fn () => \iterator_to_array($model->stream(TestPrompt::make()))))->toThrow(
        LogicException::class,
        'FakeModel has no reply left for call 1'
    );
});
