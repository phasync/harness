<?php

declare(strict_types=1);

namespace Harness\Policy;

/** What a `Policy` rule does with a call: run it, refuse it, or ask the host. */
enum Verdict: string
{
    case Allow = 'allow';
    case Deny  = 'deny';
    case Ask   = 'ask';
}
