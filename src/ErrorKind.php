<?php

declare(strict_types=1);

namespace Harness;

/** What failed in a turn that ended `Reason::Failed`. */
enum ErrorKind: string
{
    case ModelError          = 'model_error';
    case ContextTooLong      = 'context_too_long';
    case RateLimited         = 'rate_limited';
    case TransportError      = 'transport_error';
    case ToolRetriesExceeded = 'tool_retries_exceeded';
    case MiddlewareError     = 'middleware_error';
}
