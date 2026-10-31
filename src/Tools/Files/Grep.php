<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Call;
use Harness\Hints;
use Harness\Internal\Utf8;
use Harness\Part\Text;
use Harness\Result;
use Harness\Retry;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `grep`: lines matching a PCRE pattern in the files under `path` (optionally filtered by a glob),
 * as `file:line: text`, paged by `offset`. Binary files and `.git` are skipped and counted.
 */
final readonly class Grep implements Tool
{
    public function __construct(private Root $root, private int $limit = 200, private int $maxLineLength = 500)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('grep', 'Search file contents with a regular expression (PCRE, no delimiters). Prints file:line: text. Skips binary files and .git.', [
            'type'       => 'object',
            'properties' => [
                'pattern'     => ['type' => 'string', 'description' => 'Regular expression, e.g. "function \\w+Handler"'],
                'path'        => ['type' => 'string', 'description' => 'File or directory to search, relative to the root (default .)'],
                'glob'        => ['type' => 'string', 'description' => 'Only files whose path matches this glob, e.g. "**/*.php"'],
                'ignore_case' => ['type' => 'boolean', 'description' => 'Case-insensitive (default false)'],
                'offset'      => ['type' => 'integer', 'description' => 'Matches to skip (default 0)'],
            ],
            'required' => ['pattern'],
        ], new Hints(readOnly: true, idempotent: true));
    }

    public function call(array $input, Call $call): Result
    {
        $pattern = Input::string($input, 'pattern');
        $path    = Input::string($input, 'path', '.');
        $glob    = isset($input['glob']) ? Glob::regex(Input::string($input, 'glob')) : null;
        $offset  = Input::int($input, 'offset', 0, 0);
        $regex   = '#' . \str_replace('#', '\#', $pattern) . '#' . (Input::bool($input, 'ignore_case', false) ? 'i' : '');
        \set_error_handler(static fn () => true);
        try {
            $valid = false !== \preg_match($regex, '');
        } finally {
            \restore_error_handler();
        }
        if (!$valid) {
            throw new Retry("'$pattern' is not a valid regular expression: " . \preg_last_error_msg());
        }
        $abs     = $this->root->resolve($path);
        $files   = \is_file($abs) ? [\basename($abs)] : (\is_dir($abs) ? Glob::files($abs) : throw new \RuntimeException("'$path' does not exist"));
        $base    = \is_file($abs) ? \dirname($abs) : $abs;
        $lines   = [];
        $binary  = 0;
        foreach ($files as $rel) {
            if (null !== $glob && 1 !== \preg_match($glob, $rel)) {
                continue;
            }
            $content = \file_get_contents($base . '/' . $rel);
            if (false === $content) {
                continue;
            }
            if (\str_contains(\substr($content, 0, 8192), "\0")) {
                ++$binary;
                continue;
            }
            $name = $this->root->relative($base . '/' . $rel);
            foreach (\explode("\n", \str_ends_with($content, "\n") ? \substr($content, 0, -1) : $content) as $no => $line) { // the trailing newline is not one more line, as read_file counts
                if (1 === \preg_match($regex, $line)) {
                    $line    = Utf8::scrub($line);
                    $lines[] = $name . ':' . ($no + 1) . ': ' . (Utf8::length($line) > $this->maxLineLength ? Utf8::slice($line, 0, $this->maxLineLength) . ' [cut]' : $line);
                }
            }
        }
        $text = Page::render($lines, $offset, $this->limit, 'matches', "No lines match '$pattern'");
        if ($binary > 0) {
            $text .= "\n[$binary binary files skipped]";
        }

        return new Result([new Text($text)], meta: ['count' => \count($lines)]);
    }
}
