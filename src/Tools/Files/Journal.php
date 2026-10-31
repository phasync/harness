<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

/**
 * A record of the first content (or absence) of every absolute path `WriteFile`, `EditFile` and
 * `ApplyPatch` change, for undoing a turn's, or a session's, writes. The host decides the scope: a
 * fresh `Journal` per turn, or one kept for the whole session.
 */
final class Journal
{
    /** @var array<string, ?string> absolute path => its content before the first recorded change, or null if it did not exist */
    private array $before = [];

    /** @var list<string> absolute paths, in the order they were first recorded */
    private array $order = [];

    /** @internal called by a Files tool before it writes or deletes `$abs`; a path already recorded keeps its first state. */
    public function record(string $abs): void
    {
        if (\array_key_exists($abs, $this->before)) {
            return;
        }
        $this->before[$abs] = \is_file($abs) ? \file_get_contents($abs) : null;
        $this->order[]      = $abs;
    }

    /**
     * Restores every recorded path to its first recorded state, in reverse order: a path that did
     * not exist is deleted, one that did gets its original content back.
     *
     * @return list<string> the paths restored
     */
    public function undo(): array
    {
        $paths = \array_reverse($this->order);
        foreach ($paths as $abs) {
            $original = $this->before[$abs];
            if (null === $original) {
                if (\is_file($abs)) {
                    \unlink($abs);
                }
            } else {
                \file_put_contents($abs, $original);
            }
        }
        $this->clear();

        return $paths;
    }

    /** @return list<string> the absolute paths recorded so far */
    public function changed(): array
    {
        return $this->order;
    }

    public function clear(): void
    {
        $this->before = [];
        $this->order  = [];
    }
}
