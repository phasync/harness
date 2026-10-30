<?php

declare(strict_types=1);

namespace Harness;

use Harness\Part\Media;
use Harness\Part\Reference;
use Harness\Part\Text;

/**
 * A tool's outcome, kept in three parts: `content` is what the model sees, `value` what the
 * application gets (in the `ToolEnded` event), `meta` what is only logged. `error` marks a failure
 * for the model.
 */
final readonly class Result
{
    /**
     * @param list<Text|Media|Reference> $content
     * @param array<string, mixed>       $meta
     */
    public function __construct(
        public array $content,
        public mixed $value = null,
        public array $meta = [],
        public bool $error = false,
    ) {
        foreach ($content as $part) {
            if (!$part instanceof Text && !$part instanceof Media && !$part instanceof Reference) {
                throw new \InvalidArgumentException('Result content holds Text, Media and Reference parts only, got ' . \get_debug_type($part));
            }
        }
    }

    /** A successful result with one Text part. */
    public static function text(string $text): self
    {
        return new self([new Text($text)]);
    }

    /** @param array{content: list<array<string, mixed>>, value: mixed, meta: array<string, mixed>, error: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(\array_map(Parts::fromArray(...), $data['content']), $data['value'], $data['meta'], $data['error']);
    }

    /** @return array{content: list<array<string, mixed>>, value: mixed, meta: array<string, mixed>, error: bool} */
    public function toArray(): array
    {
        return [
            'content' => \array_map(static fn (Part $p) => $p->toArray(), $this->content),
            'value'   => $this->value,
            'meta'    => $this->meta,
            'error'   => $this->error,
        ];
    }
}
