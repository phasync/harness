<?php

declare(strict_types=1);

namespace Harness\Transcript;

/**
 * @internal the file operations of the JSONL classes: whole writes and line reads, with PHP
 * warnings turned into `\RuntimeException`s
 */
final class JsonlFile
{
    /** @return resource */
    public static function open(string $path, string $mode): mixed
    {
        return self::guard("open '$path'", static fn () => \fopen($path, $mode));
    }

    /**
     * Writes all of `$data`. A short write (a non-blocking stream whose buffer is full) waits with
     * `fiber\writable()` and writes the rest.
     *
     * @param resource $handle
     */
    public static function write(mixed $handle, string $data, string $path): void
    {
        while ('' !== $data) {
            $n = self::guard("write to '$path'", static fn () => \fwrite($handle, $data));
            if (false === $n) {
                throw new \RuntimeException("Could not write to '$path'");
            }
            $data = \substr($data, $n);
            if ('' !== $data) {
                \fiber\writable($handle);
            }
        }
    }

    /**
     * The decoded lines of a file, by line number; a line that is not a JSON object throws.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function lines(string $path): \Generator
    {
        $handle = self::open($path, 'r');
        try {
            for ($n = 1; ($line = \fgets($handle)) !== false; ++$n) {
                if ("\n" !== \substr($line, -1)) {
                    throw new \RuntimeException("Line $n of '$path' is incomplete (no newline): a write was cut off");
                }
                try {
                    $data = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException("Line $n of '$path' is not valid JSON: {$e->getMessage()}", 0, $e);
                }
                if (!\is_array($data)) {
                    throw new \RuntimeException("Line $n of '$path' is not a JSON object");
                }
                yield $n => $data;
            }
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $f
     *
     * @return T
     */
    private static function guard(string $what, \Closure $f): mixed
    {
        \set_error_handler(static function (int $no, string $message) use ($what): never {
            throw new \RuntimeException("Could not $what: $message");
        });
        try {
            $result = $f();
        } finally {
            \restore_error_handler();
        }
        if (false === $result) {
            throw new \RuntimeException("Could not $what");
        }

        return $result;
    }
}
