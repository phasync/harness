<?php

declare(strict_types=1);

namespace Harness\Http;

/**
 * An HTTP response whose body is read once, as chunks arrive. Header names are lower-case;
 * repeated headers are joined with ", ". Read a whole body with `implode('', [...$response->body()])`.
 */
final class Response
{
    private bool $consumed = false;

    /**
     * @param array<string, string> $headers
     * @param iterable<string>      $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        private iterable $body,
    ) {
    }

    /** @return iterable<string> the body chunks; a second call throws */
    public function body(): iterable
    {
        if ($this->consumed) {
            throw new \LogicException('The response body is a stream and was already read');
        }
        $this->consumed = true;
        $body           = $this->body;
        $this->body     = [];

        return $body;
    }

    /**
     * The absolute URL this response redirects to (a 301, 302, 303, 307 or 308 with a `Location`,
     * which may be absolute or path-absolute, resolved against `$from`), or null when it is not a
     * redirect. A caller that follows it drains this body first and checks the target as it would
     * any URL: a transport never follows redirects on its own.
     */
    public function redirectTarget(string $from): ?string
    {
        $location = $this->headers['location'] ?? null;
        if (null === $location || !\in_array($this->status, [301, 302, 303, 307, 308], true)) {
            return null;
        }
        // a space or a non-ASCII byte sent raw would break the next request line (RFC 7230 §3.1.1)
        $location = \preg_replace_callback('/[^\x21-\x7e]/', static fn (array $m): string => \sprintf('%%%02X', \ord($m[0])), $location) ?? $location;
        if (1 === \preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts  = \parse_url($from);
        $scheme = $parts['scheme'] ?? 'http';
        if (\str_starts_with($location, '//')) { // a network-path reference: the scheme stays, the authority is the new one
            return $scheme . ':' . $location;
        }
        $base = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = \str_starts_with($location, '/') ? $location : \rtrim(\dirname($parts['path'] ?? '/'), '/') . '/' . $location;

        return $base . self::removeDotSegments($path);
    }

    /** RFC 3986 §5.2.4: `/a/b/../c` is `/a/c`; the query and fragment are left as they are. */
    private static function removeDotSegments(string $path): string
    {
        $rest = \strpbrk($path, '?#');
        $path = false === $rest ? $path : \substr($path, 0, -\strlen($rest));
        $out  = [];
        foreach (\explode('/', $path) as $i => $segment) {
            if ('.' === $segment && $i > 0) {
                continue;
            }
            if ('..' === $segment && $i > 0) {
                \array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        $path = \implode('/', $out);

        return ('' === $path || '/' !== $path[0] ? '/' : '') . $path . ($rest ?: '');
    }
}
