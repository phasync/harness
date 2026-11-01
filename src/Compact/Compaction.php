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
 * and `before` and `after` the calibrated count. `demoted` is how many `ToolResult` parts
 * `Compact`'s cheap first tier (`demote()`, before any summarizer call, as Hermes Agent's
 * `_prune_old_tool_results` does) shrank to a bounded excerpt or a duplicate marker this step; 0
 * when none qualified. It is set whether or not a checkpoint was also made: demotion and folding
 * are independent, and either can happen alone.
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
        public int $demoted = 0,
    ) {
    }
}
