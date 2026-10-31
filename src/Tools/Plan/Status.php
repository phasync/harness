<?php

declare(strict_types=1);

namespace Harness\Tools\Plan;

/** A Step's progress, in the model's own words to `plan_update`. */
enum Status: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Skipped    = 'skipped';
}
