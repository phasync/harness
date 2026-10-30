<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * What `Compact` did to the prompt of a step: `removed` messages of the conversation were replaced
 * by `summary`, `kept` messages after it went on verbatim, and the prompt counted `before` and
 * `after` tokens (the counter's figure times `ratio`, the calibration from the provider's reported
 * input tokens; 1.0 before the first report). `reused` is true when the summary was an earlier
 * step's. `previousSummaryBytes` is the size of the earlier summary this one replaced, null when it
 * replaced none.
 */
final readonly class Compaction
{
    public function __construct(
        public int $removed,
        public int $kept,
        public string $summary,
        public int $before,
        public int $after,
        public bool $reused,
        public ?int $previousSummaryBytes = null,
        public float $ratio = 1.0,
    ) {
    }
}
