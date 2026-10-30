<?php

declare(strict_types=1);

namespace Harness;

/**
 * MCP's tool annotations. Policy middleware reads them; the scheduler runs `readOnly` calls of a
 * step concurrently and every other call one at a time.
 */
final readonly class Hints
{
    public function __construct(
        public bool $readOnly = false,
        public bool $destructive = false,
        public bool $idempotent = false,
        public bool $openWorld = false,
    ) {
    }

    /** @return array{readOnly: bool, destructive: bool, idempotent: bool, openWorld: bool} */
    public function toArray(): array
    {
        return ['readOnly' => $this->readOnly, 'destructive' => $this->destructive, 'idempotent' => $this->idempotent, 'openWorld' => $this->openWorld];
    }
}
