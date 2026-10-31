<?php

declare(strict_types=1);

namespace Harness\Http;

/**
 * Server-sent events: turns a chunk iterable into events, yielded as `event name => data`
 * (keys repeat; the name is "message" when the stream gives none). Multi-line data is joined with
 * "\n"; comments, `id` and `retry` are ignored, and an event cut off by the end of the stream
 * (no closing blank line) is dropped, as the SSE spec says.
 */
final class Sse
{
    /** False when the response says it is something other than an event stream (a JSON body on a 200, say). */
    public static function isEventStream(Response $response): bool
    {
        $type = \strtolower(\trim(\explode(';', $response->headers['content-type'] ?? 'text/event-stream')[0]));

        return 'text/event-stream' === $type;
    }

    /**
     * @param iterable<string> $chunks
     *
     * @return \Generator<string, string>
     */
    public static function parse(iterable $chunks): \Generator
    {
        $buffer = '';
        $event  = '';
        $data   = null;
        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            while (1 === \preg_match('/\r\n|\r(?!$)|\n/', $buffer, $m, \PREG_OFFSET_CAPTURE)) {
                $line   = \substr($buffer, 0, $m[0][1]);
                $buffer = \substr($buffer, $m[0][1] + \strlen($m[0][0]));
                if ('' === $line) {
                    if (null !== $data) {
                        yield ('' === $event ? 'message' : $event) => $data;
                    }
                    $event = '';
                    $data  = null;
                    continue;
                }
                if (':' === $line[0]) {
                    continue;
                }
                $colon = \strpos($line, ':');
                $field = false === $colon ? $line : \substr($line, 0, $colon);
                $value = false === $colon ? '' : \substr($line, $colon + 1);
                if ('' !== $value && ' ' === $value[0]) {
                    $value = \substr($value, 1);
                }
                if ('event' === $field) {
                    $event = $value;
                } elseif ('data' === $field) {
                    $data = null === $data ? $value : $data . "\n" . $value;
                }
            }
        }
    }
}
