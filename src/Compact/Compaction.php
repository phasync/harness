<?php

declare(strict_types=1);

namespace Harness\Compact;

use Harness\FinishReason;

/**
 * What `Compact` did to the prompt of a step: `removed` messages of the conversation were replaced
 * by `summary`, `kept` messages after it went on verbatim, and the prompt counted `before` and
 * `after` tokens (`ratio` times the counter's figure plus `offset`, the calibration from the
 * provider's reported input tokens; 1.0 and 0.0 before the first report). `reused` is true when
 * the summary was an earlier step's. `previousSummaryBytes` is the size of the earlier summary this
 * one replaced, null when it replaced none; `finish` is how the side call that wrote the summary
 * ended. `truncatedUpstream` is true when the provider reported under 70 % of the calibrated count:
 * the server cut the prompt. On such a step that compacted nothing, `removed` is 0, `summary` empty
 * and `before` and `after` the calibrated count.
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
        public float $offset = 0.0,
        public ?FinishReason $finish = null,
        public bool $truncatedUpstream = false,
    ) {
    }
}
