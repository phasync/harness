<?php

declare(strict_types=1);

namespace Harness\Compact;

/**
 * How `Compact` turns its counter's figure into the provider's tokens: `ratio * count + offset`,
 * from two reported prompts, the smallest (`lowCounted` by the counter, `lowReported` by the
 * provider) and the largest. Once the largest counts at least twice the smallest (`fitted`), the
 * ratio and offset are the line through both; until then the ratio is 1 and the offset the largest
 * one's difference. Ratio, offset and `fitted` follow from the points. A JSON value, part of
 * `State`.
 */
final readonly class Calibration implements \JsonSerializable
{
    public float $ratio;
    public float $offset;
    public bool $fitted;

    public function __construct(
        public int $lowCounted,
        public int $lowReported,
        public int $highCounted,
        public int $highReported,
    ) {
        if ($lowCounted < 0 || $highCounted < $lowCounted || $lowReported < 1 || $highReported < 1) {
            throw new \InvalidArgumentException("A calibration needs reported counts of at least 1 and the low point's count at most the high one's, got low ($lowCounted, $lowReported), high ($highCounted, $highReported)");
        }
        // Two close prompts give a slope that the next large tool result would multiply.
        $this->fitted = $highCounted > $lowCounted && $highCounted >= 2 * $lowCounted;
        $this->ratio  = $this->fitted ? ($highReported - $lowReported) / ($highCounted - $lowCounted) : 1.0;
        $this->offset = $highReported - $this->ratio * $highCounted;
    }

    /** This calibration with one more report: the prompt counted `$counted`, reported `$reported`. */
    public function with(int $counted, int $reported): self
    {
        [$lc, $lr] = $counted <= $this->lowCounted ? [$counted, $reported] : [$this->lowCounted, $this->lowReported];
        [$hc, $hr] = $counted >= $this->highCounted ? [$counted, $reported] : [$this->highCounted, $this->highReported];

        return new self($lc, $lr, $hc, $hr);
    }

    /** The provider's tokens for a prompt the counter counts `$counted`. */
    public function tokens(int $counted): int
    {
        return (int) \ceil($this->ratio * $counted + $this->offset);
    }

    /** @return array{low: array{counted: int, reported: int}, high: array{counted: int, reported: int}, ratio: float, offset: float} */
    public function toArray(): array
    {
        return [
            'low'    => ['counted' => $this->lowCounted, 'reported' => $this->lowReported],
            'high'   => ['counted' => $this->highCounted, 'reported' => $this->highReported],
            'ratio'  => $this->ratio,
            'offset' => $this->offset,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Rebuilds a calibration from `toArray()` (or its JSON decoded as an array). The ratio and
     * offset are computed again from the points.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['low', 'high'] as $point) {
            if (!\is_int($data[$point]['counted'] ?? null) || !\is_int($data[$point]['reported'] ?? null)) {
                throw new \InvalidArgumentException("A calibration array needs \"$point\": {\"counted\": int, \"reported\": int}");
            }
        }

        return new self($data['low']['counted'], $data['low']['reported'], $data['high']['counted'], $data['high']['reported']);
    }
}
