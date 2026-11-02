<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * What `Compact` did to the prompt of a step: why it compacted (`auto`: the prompt reached the
 * ceiling; `overflow`: the provider rejected it as too long; `manual`: `compactNext()` asked), how
 * many messages of the conversation the new checkpoint covers, and the prompt's size before and
 * after in tokens (`before` by the provider's last count plus an estimate of what came after it,
 * `after` estimated). A summary compaction has the `summary` and the `recent` text kept beside it;
 * a native one has `native` true and neither.
 */
final readonly class Compaction
{
    public function __construct(
        public string $reason,
        public int $covered,
        public int $before,
        public int $after,
        public string $summary,
        public string $recent,
        public bool $native,
    ) {
    }
}
