<?php

declare(strict_types=1);

use Harness\Stop;

it('validates Stop::maxSteps requires n >= 1', function () {
    expect(fn () => Stop::maxSteps(0))->toThrow(InvalidArgumentException::class, 'n >= 1');
});
