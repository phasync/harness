<?php

declare(strict_types=1);

namespace Harness;

/**
 * When a message given to `Run::interject()` lands. `Steer`: at the next step boundary, before the
 * next model call; the step's tool calls that have not started yet are skipped. `Queue`: when the
 * turn would otherwise end `Done`, starting another step with it.
 */
enum Delivery: string
{
    case Steer = 'steer';
    case Queue = 'queue';
}
