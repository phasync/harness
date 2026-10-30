<?php

declare(strict_types=1);

namespace Harness\Internal;

use function fiber\await;
use Harness\Event;

/**
 * @internal The hand-over between a turn's fibers and its one consumer. Delivery is synchronous:
 * `push()` returns once the consumer has handled the event, that is when it asks for the next one
 * (or stops iterating), so whatever a producer does after an event happens after the host handled
 * it. Producers that push at the same time wait their turn in order of arrival.
 */
final class EventQueue
{
    /** @var \SplQueue<array{0: Event, 1: Signal}> */
    private \SplQueue $events;
    /** Fulfilled when an event arrives or the queue closes, while the consumer waits. */
    private ?Signal $arrival = null;
    /** Fulfilled when the consumer is done with the event it holds. */
    private ?Signal $held   = null;
    private bool $closed    = false;
    private bool $detached  = false;

    public function __construct()
    {
        $this->events = new \SplQueue();
    }

    /** Hands `$event` to the consumer and returns once the consumer handled it; at once when it stopped. */
    public function push(Event $event): void
    {
        if ($this->closed) {
            throw new \LogicException('Event ' . $event::class . ' pushed after the turn ended');
        }
        if ($this->detached) {
            return;
        }
        $handled = new Signal();
        $this->events->enqueue([$event, $handled]);
        $this->wake();
        await($handled);
    }

    /** No more events; the consumer's `next()` returns null once the queue is drained. */
    public function close(): void
    {
        $this->closed = true;
        $this->wake();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * For the consumer: releases the producer of the event it held, then waits for the next event.
     * Null once the queue is closed and drained.
     */
    public function next(): ?Event
    {
        $this->release();
        while ($this->events->isEmpty()) {
            if ($this->closed) {
                return null;
            }
            $this->arrival = new Signal();
            await($this->arrival);
        }
        [$event, $this->held] = $this->events->dequeue();

        return $event;
    }

    /** The consumer stopped: every waiting producer is released and later events are dropped. */
    public function detach(): void
    {
        $this->detached = true;
        $this->release();
        while (!$this->events->isEmpty()) {
            $this->events->dequeue()[1]->fulfil();
        }
    }

    private function release(): void
    {
        $held       = $this->held;
        $this->held = null;
        $held?->fulfil();
    }

    private function wake(): void
    {
        $arrival       = $this->arrival;
        $this->arrival = null;
        $arrival?->fulfil();
    }
}
