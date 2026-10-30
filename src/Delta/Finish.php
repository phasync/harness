<?php

declare(strict_types=1);

namespace Harness\Delta;

use Harness\Delta;
use Harness\FinishReason;

/** The last delta of every reply. */
final readonly class Finish implements Delta
{
    public function __construct(public FinishReason $reason)
    {
    }
}
