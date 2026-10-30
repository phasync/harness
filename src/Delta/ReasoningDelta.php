<?php

declare(strict_types=1);

namespace Harness\Delta;

use Harness\Delta;

/**
 * Reasoning text. Consecutive reasoning deltas extend one Reasoning part; `raw` is merged into
 * that part's `raw` (later keys win), which is how a provider attaches a signature or its model id.
 */
final readonly class ReasoningDelta implements Delta
{
    /** @param array<string, mixed> $raw */
    public function __construct(public string $text, public array $raw = [])
    {
    }
}
