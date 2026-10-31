<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Call;
use Harness\Hints;
use Harness\Part\Text;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `glob`: files whose path relative to `path` matches a pattern: `*` and `?` within a name, `**`
 * across directories, `[abc]` and `{a,b}`. Sorted, paged by `offset`; `.git` directories are skipped;
 * a path holding a control character is shown as a JSON string literal.
 */
final readonly class Glob implements Tool
{
    public function __construct(private Root $root, private int $limit = 500)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('glob', 'Find files by path pattern, e.g. "**/*.php" or "src/{a,b}/*.md". * and ? stay within a name, ** spans directories. Skips .git.', [
            'type'       => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Pattern matched against paths relative to `path`'],
                'path'    => ['type' => 'string', 'description' => 'Directory to search, relative to the root (default .)'],
                'offset'  => ['type' => 'integer', 'description' => 'Matches to skip (default 0)'],
            ],
            'required' => ['pattern'],
        ], new Hints(readOnly: true, idempotent: true));
    }

    public function call(array $input, Call $call): Result
    {
        $pattern = Input::string($input, 'pattern');
        $path    = Input::string($input, 'path', '.');
        $offset  = Input::int($input, 'offset', 0, 0);
        $base    = $this->root->resolve($path);
        if (!\is_dir($base)) {
            throw new \RuntimeException("'$path' is not a directory");
        }
        $regex   = self::regex($pattern);
        $matches = [];
        foreach (self::files($base) as $rel) {
            if (1 === \preg_match($regex, $rel)) {
                $matches[] = Page::name($this->root->relative($base . '/' . $rel));
            }
        }
        \sort($matches);

        return new Result([new Text(Page::render($matches, $offset, $this->limit, 'matches', "No files match '$pattern'"))], meta: ['count' => \count($matches)]);
    }

    /**
     * Every file under `$base`, as paths relative to it; `.git` is skipped, symlinked directories are not followed.
     *
     * @return \Generator<int, string>
     */
    public static function files(string $base): \Generator
    {
        $dirs = [''];
        while ([] !== $dirs) {
            $dir   = \array_pop($dirs);
            $names = \scandir($base . ('' === $dir ? '' : '/' . $dir));
            if (false === $names) {
                continue;
            }
            foreach ($names as $name) {
                if ('.' === $name || '..' === $name || '.git' === $name) {
                    continue;
                }
                $rel = '' === $dir ? $name : $dir . '/' . $name;
                $abs = $base . '/' . $rel;
                if (\is_dir($abs) && !\is_link($abs)) {
                    $dirs[] = $rel;
                } elseif (\is_file($abs)) {
                    yield $rel;
                }
            }
        }
    }

    /** The anchored PCRE for a glob pattern. */
    public static function regex(string $glob): string
    {
        $out   = '';
        $depth = 0;
        $n     = \strlen($glob);
        for ($i = 0; $i < $n; ++$i) {
            $c = $glob[$i];
            if ('*' === $c && ($glob[$i + 1] ?? '') === '*') {
                ++$i;
                if (($glob[$i + 1] ?? '') === '/') {
                    ++$i;
                    $out .= '(?:.*/)?';
                } else {
                    $out .= '.*';
                }
            } elseif ('*' === $c) {
                $out .= '[^/]*';
            } elseif ('?' === $c) {
                $out .= '[^/]';
            } elseif ('[' === $c) {
                $end = \strpos($glob, ']', $i + 1);
                if (false === $end) {
                    $out .= '\[';
                } else {
                    $class = \substr($glob, $i + 1, $end - $i - 1);
                    $out .= '[' . (\str_starts_with($class, '!') ? '^' . \substr($class, 1) : $class) . ']';
                    $i = $end;
                }
            } elseif ('{' === $c) {
                ++$depth;
                $out .= '(?:';
            } elseif ('}' === $c && $depth > 0) {
                --$depth;
                $out .= ')';
            } elseif (',' === $c && $depth > 0) {
                $out .= '|';
            } else {
                $out .= \preg_quote($c, '#');
            }
        }

        return '#^' . $out . '$#s'; // s: a path may hold a newline, and ** must span it
    }
}
