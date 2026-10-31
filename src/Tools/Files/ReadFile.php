<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Call;
use Harness\Hints;
use Harness\Internal\Utf8;
use Harness\Part\Media;
use Harness\Part\Text;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `read_file`: a text file as numbered lines from `offset` (1-based) for `limit` lines, with a tail
 * saying how to read on; PNG, JPEG, GIF and WebP files come back as Media. Lines longer than
 * `maxLineLength` are cut with a marker.
 */
final readonly class ReadFile implements Tool
{
    private const IMAGES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /** The image type the first bytes announce, or null. */
    private static function magic(string $bytes): ?string
    {
        return match (true) {
            \str_starts_with($bytes, "\x89PNG")                                            => 'image/png',
            \str_starts_with($bytes, "\xFF\xD8\xFF")                                       => 'image/jpeg',
            \str_starts_with($bytes, 'GIF87a') || \str_starts_with($bytes, 'GIF89a')       => 'image/gif',
            \str_starts_with($bytes, 'RIFF') && 'WEBP' === \substr($bytes, 8, 4)           => 'image/webp',
            default                                                                        => null,
        };
    }

    public function __construct(private Root $root, private int $limit = 2000, private int $maxLineLength = 2000)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('read_file', "Read a file. Text comes back as numbered lines, {$this->limit} at most per call; images come back as images.", [
            'type'       => 'object',
            'properties' => [
                'path'   => ['type' => 'string', 'description' => 'Path relative to the root'],
                'offset' => ['type' => 'integer', 'description' => 'First line to read, 1-based (default 1)'],
                'limit'  => ['type' => 'integer', 'description' => "Lines to read (default and maximum {$this->limit})"],
            ],
            'required' => ['path'],
        ], new Hints(readOnly: true, idempotent: true));
    }

    public function call(array $input, Call $call): Result
    {
        $path   = Input::string($input, 'path');
        $offset = Input::int($input, 'offset', 1, 1);
        $limit  = \min(Input::int($input, 'limit', $this->limit, 1), $this->limit);
        $abs    = $this->root->resolve($path);
        if (\is_dir($abs)) {
            throw new \RuntimeException("'$path' is a directory; use list_dir");
        }
        if (!\is_file($abs)) {
            throw new \RuntimeException("'$path' does not exist");
        }
        $bytes = \file_get_contents($abs);
        if (false === $bytes) {
            throw new \RuntimeException("'$path' could not be read");
        }
        $mime = self::IMAGES[\strtolower(\pathinfo($abs, \PATHINFO_EXTENSION))] ?? null;
        if (null !== $mime && self::magic($bytes) === $mime) { // the bytes decide: a .png holding text is read as text
            return new Result([new Media($mime, $bytes)], meta: ['bytes' => \strlen($bytes)]);
        }
        if (\str_contains(\substr($bytes, 0, 8192), "\0")) {
            throw new \RuntimeException("'$path' is a binary file (" . \strlen($bytes) . ' bytes)');
        }
        if ('' === $bytes) {
            return Result::text("'$path' is empty");
        }
        $lines = \explode("\n", \str_ends_with($bytes, "\n") ? \substr($bytes, 0, -1) : $bytes);
        $total = \count($lines);
        if ($offset > $total) {
            throw new \RuntimeException("'$path' has $total lines; offset $offset is past the end");
        }
        $out = [];
        foreach (\array_slice($lines, $offset - 1, $limit) as $i => $line) {
            $line = Utf8::scrub($line);
            if (Utf8::length($line) > $this->maxLineLength) {
                $line = Utf8::slice($line, 0, $this->maxLineLength) . ' [line cut at ' . $this->maxLineLength . ' characters]';
            }
            $out[] = \str_pad((string) ($offset + $i), 6, ' ', \STR_PAD_LEFT) . "\t" . $line;
        }
        $last = $offset + \count($out) - 1;
        $text = \implode("\n", $out);
        if ($last < $total) {
            $text .= "\n[" . ($total - $last) . ' more lines; pass offset=' . ($last + 1) . ' to read on]';
        }

        return new Result([new Text($text)], meta: ['lines' => $total]);
    }
}
