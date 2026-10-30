<?php

declare(strict_types=1);

namespace Harness\Transcript;

use Harness\Message;

/**
 * A `Transcript` in a file, one message per line as `Message::toArray()` JSON. `append()` writes
 * its messages in one append-mode write, so concurrent appenders (fibers or processes) never cut
 * each other's lines. A missing file is an empty transcript; a corrupt line throws on `load()`.
 */
final readonly class JsonlTranscript implements Transcript
{
    public function __construct(private string $path)
    {
    }

    public function load(): array
    {
        if (!\file_exists($this->path)) {
            return [];
        }
        $messages = [];
        foreach (JsonlFile::lines($this->path) as $data) {
            $messages[] = Message::fromArray($data);
        }

        return $messages;
    }

    public function append(array $messages): void
    {
        $lines = '';
        foreach ($messages as $m) {
            if (!$m instanceof Message) {
                throw new \InvalidArgumentException('append() takes Message objects, got ' . \get_debug_type($m));
            }
            $lines .= \json_encode($m->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
        }
        if ('' === $lines) {
            return;
        }
        $handle = JsonlFile::open($this->path, 'a');
        try {
            JsonlFile::write($handle, $lines, $this->path);
        } finally {
            \fclose($handle);
        }
    }

    public function clear(): void
    {
        \fclose(JsonlFile::open($this->path, 'w'));
    }
}
