<?php

declare(strict_types=1);

namespace Harness;

/**
 * The cancellation signal of a turn, created by the `Run` and carried by the `Prompt` to the
 * model and on to the transport. Whatever waits long checks `isCancelled()` between waits and
 * stops by throwing `Cancelled` (`Http\StreamTransport` checks every 250 ms while the response is
 * silent). A test or a hand-built `Prompt` makes one from any closure: `new Cancel(fn () => false)`.
 */
final readonly class Cancel
{
    /** @param \Closure(): bool $signal */
    public function __construct(private \Closure $signal)
    {
    }

    public function isCancelled(): bool
    {
        return ($this->signal)();
    }
}
