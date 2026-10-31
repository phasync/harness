<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Internal\Fs;

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
     * not exist is deleted, one that did gets its original content back. A path that cannot be
     * restored (made read-only since) does not stop the others; it stays recorded for another
     * `undo()`, and a `\RuntimeException` naming every such path follows the rest.
     *
     * @return list<string> the paths restored
     */
    public function undo(): array
    {
        $restored = [];
        $failed   = [];
        foreach (\array_reverse($this->order) as $abs) {
            $original = $this->before[$abs];
            try {
                if (null === $original) {
                    if (\is_file($abs)) {
                        Fs::unlink($abs, "'$abs'");
                    }
                } else {
                    Fs::write($abs, $original, "'$abs'");
                }
                $restored[] = $abs;
            } catch (\RuntimeException $e) {
                $failed[$abs] = $e->getMessage();
            }
        }
        $this->before = \array_intersect_key($this->before, $failed);
        $this->order  = \array_values(\array_filter($this->order, static fn (string $abs): bool => isset($failed[$abs])));
        if ([] !== $failed) {
            throw new \RuntimeException('Journal::undo() could not restore every path: ' . \implode('; ', $failed) . '. These stay recorded for another undo()');
        }

        return $restored;
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
