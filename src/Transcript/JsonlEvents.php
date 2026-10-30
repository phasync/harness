<?php

declare(strict_types=1);

namespace Harness\Transcript;

use function fiber\await;
use Harness\Event;
use Harness\Events;
use Harness\Internal\Signal;

/**
 * An event sink: `write()` appends one JSON line per event (`Event::toArray()`) to `$path`, opened
 * in append mode, so each line is one write and writers in other processes interleave whole lines.
 * A short write (a non-blocking stream) waits with `fiber\writable()` for the rest, and fibers
 * writing at once wait their turn, so lines never mix. `replay()` reads the file back as typed
 * events through `Events::fromArray()`; a corrupt line throws with its number.
 */
final class JsonlEvents
{
    /** @var resource */
    private mixed $handle;
    private bool $writing = false;
    /** @var list<Signal> */
    private array $waiting = [];

    public function __construct(private readonly string $path)
    {
        $this->handle = JsonlFile::open($path, 'a');
    }

    public function __destruct()
    {
        \fclose($this->handle);
    }

    public function write(Event $event): void
    {
        $line = \json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
        while ($this->writing) {
            $this->waiting[] = $turn = new Signal();
            await($turn);
        }
        $this->writing = true;
        try {
            JsonlFile::write($this->handle, $line, $this->path);
        } finally {
            $this->writing = false;
            $waiting       = $this->waiting;
            $this->waiting = [];
            foreach ($waiting as $turn) {
                $turn->fulfil();
            }
        }
    }

    /** @return \Generator<int, Event> the events in the file, by line number */
    public function replay(): \Generator
    {
        foreach (JsonlFile::lines($this->path) as $n => $data) {
            yield $n => Events::fromArray($data);
        }
    }
}
