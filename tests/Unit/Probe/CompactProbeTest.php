<?php

declare(strict_types=1);

use Harness\Compact\Calibration;
use Harness\Compact\Checkpoint;
use Harness\FinishReason;

it('rejects invalid Calibration initialization: lowReported must be at least 1', function () {
    expect(fn () => new Calibration(0, 0, 100, 200))->toThrow(
        InvalidArgumentException::class,
        'A calibration needs reported counts of at least 1 and the low point\'s count at most the high one\'s, got low (0, 0), high (100, 200)'
    );
});

it('serializes and deserializes Checkpoint with lifted message index', function () {
    // Test Checkpoint::jsonSerialize and fromArray preserves lifted message index
    $checkpoint = new Checkpoint(
        hash: 'abc123def456',
        covered: 10,
        kept: 2,
        lifted: 8,  // The lifted message (latest user message in the covered range)
        text: '## Goal' . "\n" . 'Task here',
        previousBytes: 1500,
        finish: FinishReason::Stop
    );

    // Serialize to JSON
    $json = \json_encode($checkpoint, \JSON_THROW_ON_ERROR);
    $data = \json_decode($json, true);

    // Deserialize back
    $restored = Checkpoint::fromArray($data);

    expect($restored->hash)->toBe('abc123def456')
        ->and($restored->covered)->toBe(10)
        ->and($restored->kept)->toBe(2)
        ->and($restored->lifted)->toBe(8)
        ->and($restored->text)->toBe('## Goal' . "\n" . 'Task here')
        ->and($restored->previousBytes)->toBe(1500)
        ->and($restored->finish)->toBe(FinishReason::Stop)
        ->and($restored->removed())->toBe(7);  // 10 covered - 2 kept - 1 lifted = 7
});
