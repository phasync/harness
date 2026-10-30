<?php

declare(strict_types=1);

namespace Harness;

/**
 * Token counts. `input` counts every prompt token, cached or not; `cacheRead` and `cacheWrite`
 * are the parts of it read from and written to the provider's prompt cache. As a delta it is
 * cumulative within one reply: the accumulator keeps the largest value seen per field.
 */
final readonly class Usage implements Delta
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
    ) {
    }

    /** The field-wise sum, for totals across replies. */
    public function plus(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheRead + $other->cacheRead,
            $this->cacheWrite + $other->cacheWrite,
        );
    }

    /** @return array{input: int, output: int, cacheRead: int, cacheWrite: int} */
    public function toArray(): array
    {
        return ['input' => $this->input, 'output' => $this->output, 'cacheRead' => $this->cacheRead, 'cacheWrite' => $this->cacheWrite];
    }

    /** @param array{input: int, output: int, cacheRead: int, cacheWrite: int} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['input'], $data['output'], $data['cacheRead'], $data['cacheWrite']);
    }
}
