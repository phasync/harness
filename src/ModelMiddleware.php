<?php

declare(strict_types=1);

namespace Harness;

/**
 * Intercepts model calls. `$next(Prompt): iterable<Delta>` calls the next middleware or the model.
 * Compaction rewrites the prompt; retries wrap `$next`; tracing wraps the iteration.
 */
interface ModelMiddleware
{
    /**
     * @param \Closure(Prompt): iterable<Delta> $next
     *
     * @return iterable<Delta>
     */
    public function stream(Prompt $prompt, \Closure $next): iterable;
}
