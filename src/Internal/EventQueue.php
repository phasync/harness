<?php

declare(strict_types=1);

namespace Harness\Internal;

use Harness\Event;

/**
 * @internal The queue between a turn's coroutine and its consumer. `wait()` returns a then-able,
 * which every fiber-interop adapter can await.
 */
final class EventQueue
{
    /** @var \SplQueue<Event> */
    private \SplQueue $events;
    private bool $closed = false;
    /** @var array{0: callable, 1: callable}|null */
    private ?array $waiter = null;

    public function __construct()
    {
        $this->events = new \SplQueue();
    }

    public function push(Event $event): void
    {
        if ($this->closed) {
            throw new \LogicException('Event ' . $event::class . ' pushed after the turn ended');
        }
        if (null !== $this->waiter) {
            [$ok]         = $this->waiter;
            $this->waiter = null;
            $ok($event);

            return;
        }
        $this->events->enqueue($event);
    }

    public function close(): void
    {
        $this->closed = true;
        if (null !== $this->waiter) {
            [$ok]         = $this->waiter;
            $this->waiter = null;
            $ok(null);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** The next event if one is buffered, else null. */
    public function shift(): ?Event
    {
        return $this->events->isEmpty() ? null : $this->events->dequeue();
    }

    /** A then-able that fulfils with the next event, or with null once the queue is closed and drained. */
    public function wait(): object
    {
        $queue = $this;

        return new class($queue) {
            public function __construct(private EventQueue $queue)
            {
            }

            public function then(callable $ok, callable $err): void
            {
                $this->queue->listen($ok, $err);
            }
        };
    }

    public function listen(callable $ok, callable $err): void
    {
        if (null !== $this->waiter) {
            throw new \LogicException('A turn has one consumer');
        }
        if (!$this->events->isEmpty()) {
            $ok($this->events->dequeue());

            return;
        }
        if ($this->closed) {
            $ok(null);

            return;
        }
        $this->waiter = [$ok, $err];
    }
}
