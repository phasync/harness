<?php

declare(strict_types=1);

use Harness\Compact\Calibration;

it('rejects invalid Calibration initialization: lowReported must be at least 1', function () {
    expect(fn () => new Calibration(0, 0, 100, 200))->toThrow(
        InvalidArgumentException::class,
        'A calibration needs reported counts of at least 1 and the low point\'s count at most the high one\'s, got low (0, 0), high (100, 200)'
    );
});
