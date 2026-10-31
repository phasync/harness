<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Call;
use Harness\Hints;
use Harness\Internal\Fs;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `write_file`: creates or replaces a file with `content`, creating missing parent directories.
 * `$check`, when given, runs after a successful write (`fn (string $absolutePath): ?string`, an
 * error message or null) and its message, if any, is appended to the result; `$journal`, when
 * given, records the file's previous content (or absence) before the write.
 */
final readonly class WriteFile implements Tool
{
    public function __construct(private Root $root, private ?\Closure $check = null, private ?Journal $journal = null)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('write_file', 'Create a file, or replace its whole content. Parent directories are created.', [
            'type'       => 'object',
            'properties' => [
                'path'    => ['type' => 'string', 'description' => 'Path relative to the root'],
                'content' => ['type' => 'string', 'description' => 'The complete new content'],
            ],
            'required' => ['path', 'content'],
        ], new Hints(destructive: true, idempotent: true));
    }

    public function call(array $input, Call $call): Result
    {
        $path    = Input::string($input, 'path');
        $content = Input::string($input, 'content');
        $abs     = $this->root->resolve($path);
        if (\is_dir($abs)) {
            throw new \RuntimeException("'$path' is a directory");
        }
        $existed = \file_exists($abs);
        for ($dir = \dirname($abs); !\file_exists($dir); $dir = \dirname($dir)) {
        }
        if (!\is_dir($dir)) { // checked before mkdir(), which would warn about it
            throw new \RuntimeException("'$path' cannot be created: '" . $this->root->relative($dir) . "' is a file, not a directory");
        }
        Fs::mkdir(\dirname($abs), "the directory for '$path'");
        $this->journal?->record($abs);
        Fs::write($abs, $content, "'$path'");

        $text = ($existed ? 'Replaced' : 'Created') . " '$path' (" . \strlen($content) . ' bytes)';
        if (null !== $this->check) {
            $message = ($this->check)($abs);
            if (null !== $message) {
                $text .= "\nCheck: $message";
            }
        }

        return Result::text($text);
    }
}
