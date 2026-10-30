<?php

declare(strict_types=1);

namespace Harness\Delta;

use Harness\Delta;

/** Visible text; consecutive text deltas extend one Text part. */
final readonly class TextDelta implements Delta
{
    public function __construct(public string $text)
    {
    }
}
