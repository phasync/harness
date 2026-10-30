<?php

declare(strict_types=1);

namespace Harness;

/** Who wrote a message. The system text is not a message; it is `Run`'s `system`. */
enum Role: string
{
    case User      = 'user';
    case Assistant = 'assistant';
}
