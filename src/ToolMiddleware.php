<?php

declare(strict_types=1);

namespace Harness;

use Harness\Part\ToolCall;

/**
 * Intercepts tool calls, inside the call's own fiber. `$next(ToolCall, Call): Result` calls the next
 * middleware or the tool. A policy returns an error `Result` to deny or `$context->needsInput([new
 * Approval(reason: ...)])` to ask the host; truncation rewrites the `Result`.
 */
interface ToolMiddleware
{
    /** @param \Closure(ToolCall, Call): Result $next */
    public function call(ToolCall $call, Call $context, \Closure $next): Result;
}
