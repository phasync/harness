<?php

declare(strict_types=1);

namespace Harness\Internal;

/**
 * @internal a one-shot then-able that fulfils with null, which `fiber\await()` accepts on every
 * adapter
 */
final class Signal
{
    private bool $fulfilled = false;
    /** @var list<callable> */
    private array $listeners = [];

    public function then(callable $ok, ?callable $err = null): void
    {
        if ($this->fulfilled) {
            $ok(null);

            return;
        }
        $this->listeners[] = $ok;
    }

    public function fulfil(): void
    {
        if ($this->fulfilled) {
            throw new \LogicException('A Signal fulfils once');
        }
        $this->fulfilled = true;
        $listeners       = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $ok) {
            $ok(null);
        }
    }
}
