<?php

declare(strict_types=1);

namespace Harness\Tools\Web;

/**
 * @internal The decoder behind `web_fetch`'s `Content-Encoding` support; not a stable API
 *
 * Decodes a gzip or deflate body piece by piece. A gzip body may be several members end to end
 * (RFC 1952): when one ends, bytes that start with the gzip magic begin the next; anything else
 * after a member is ignored, as gzip does. A body that does not decode is a `\RuntimeException`.
 */
final class Inflater
{
    private \InflateContext $ctx;
    private int $fed      = 0;  // bytes given to the current context
    private bool $between = false; // a member ended; waiting to see whether another begins
    private bool $done    = false;
    private string $carry = ''; // a lone first magic byte at the end of a piece, judged with the next

    public function __construct(private readonly int $encoding, private readonly string $url)
    {
        $this->ctx = \inflate_init($encoding);
    }

    /** The decoded bytes this piece contributes. */
    public function add(string $piece): string
    {
        if ($this->done) {
            return '';
        }
        if ($this->between) {
            $piece       = $this->carry . $piece;
            $this->carry = '';
            if ("\x1f" === $piece) {
                $this->carry = $piece;

                return '';
            }
            if (!\str_starts_with($piece, "\x1f\x8b")) {
                $this->done = true;

                return '';
            }
            $this->ctx     = \inflate_init($this->encoding);
            $this->fed     = 0;
            $this->between = false;
        }
        $this->fed += \strlen($piece);
        $out = $this->inflate($piece);
        if (\ZLIB_STREAM_END === \inflate_get_status($this->ctx)) {
            $rest = \substr($piece, \strlen($piece) - ($this->fed - \inflate_get_read_len($this->ctx)));
            if (\ZLIB_ENCODING_GZIP !== $this->encoding) {
                $this->done = true;

                return $out;
            }
            $this->between = true;

            return $out . ('' === $rest ? '' : $this->add($rest));
        }

        return $out;
    }

    /** True once nothing more of the body will decode: the last member ended. */
    public function done(): bool
    {
        return $this->done;
    }

    private function inflate(string $piece): string
    {
        \set_error_handler(fn (int $no, string $message): bool => throw new \RuntimeException("'{$this->url}' sends a compressed body that does not decode: $message"));
        try {
            return \inflate_add($this->ctx, $piece);
        } finally {
            \restore_error_handler();
        }
    }
}
