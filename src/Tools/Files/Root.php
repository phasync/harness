<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/**
 * The directory the file tools may touch. Paths are relative to it (or absolute inside it); a path
 * that leaves it, lexically or through a symlink, throws `\RuntimeException`, which the run turns
 * into an error result for the model.
 */
final readonly class Root
{
    public string $path;

    public function __construct(string $path)
    {
        $real = \realpath($path);
        if (false === $real || !\is_dir($real)) {
            throw new \InvalidArgumentException("Root '$path' is not a directory");
        }
        $this->path = $real;
    }

    /** `$text` decoded when it is a JSON string literal naming what `Page::name()` would quote; else as is. */
    private static function unquote(string $text): string
    {
        if (\strlen($text) >= 2 && '"' === $text[0] && '"' === $text[-1]) {
            $decoded = \json_decode($text);
            if (\is_string($decoded) && Page::quoted($decoded)) {
                return $decoded;
            }
        }

        return $text;
    }

    /**
     * The absolute path for `$path`, which need not exist yet. A path written as a JSON string
     * literal that decodes to a name `Page::name()` would show that way (one holding a control
     * character, or wrapped in double quotes) is taken as that name, whole or as one segment of a
     * longer path: the exact inverse of how `list_dir`, `glob` and `grep` show it.
     */
    public function resolve(string $path): string
    {
        $unquoted = self::unquote($path);
        if ($unquoted === $path && \str_contains($path, '"')) { // not a literal as a whole: maybe one segment is, as composed from a list_dir entry
            $unquoted = \implode('/', \array_map(self::unquote(...), \explode('/', $path)));
        }
        $path = $unquoted;
        if (\str_contains($path, "\0")) {
            throw new \RuntimeException('The path contains a NUL byte');
        }
        $abs   = \str_starts_with($path, '/') ? $path : $this->path . '/' . $path;
        $parts = [];
        foreach (\explode('/', $abs) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                \array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        $abs = '/' . \implode('/', $parts);
        if (!$this->contains($abs)) {
            throw new \RuntimeException("'$path' is outside the root; paths are relative to " . $this->path);
        }
        $probe = $abs;
        while (!\file_exists($probe) && !\is_link($probe)) {
            $probe = \dirname($probe);
        }
        $real = \realpath($probe);
        if (false === $real || !$this->contains($real)) {
            throw new \RuntimeException("'$path' leads outside the root through a symlink");
        }

        return $abs;
    }

    /** `$abs` relative to the root, '.' for the root itself. */
    public function relative(string $abs): string
    {
        return $abs === $this->path ? '.' : \substr($abs, \strlen($this->path) + 1);
    }

    private function contains(string $abs): bool
    {
        return $abs === $this->path || \str_starts_with($abs, '/' === $this->path ? '/' : $this->path . '/');
    }
}
