<?php

declare(strict_types=1);

namespace Harness;

/** Why the model stopped writing. */
enum FinishReason: string
{
    case Stop      = 'stop';
    case ToolCalls = 'tool_calls';
    case Length    = 'length';
    case Refusal   = 'refusal';
}
