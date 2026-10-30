<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Call;
use Harness\Hints;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/** `edit_file`: replaces one exact, unique occurrence of `old` with `new`. */
final readonly class EditFile implements Tool
{
    public function __construct(private Root $root)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('edit_file', 'Replace an exact string in a file. `old` must occur exactly once: include enough surrounding text to make it unique. Whitespace counts.', [
            'type'       => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path relative to the root'],
                'old'  => ['type' => 'string', 'description' => 'The exact text to replace'],
                'new'  => ['type' => 'string', 'description' => 'The replacement'],
            ],
            'required' => ['path', 'old', 'new'],
        ], new Hints(destructive: true));
    }

    public function call(array $input, Call $call): Result
    {
        $path = Input::string($input, 'path');
        $old  = Input::string($input, 'old');
        $new  = Input::string($input, 'new');
        if ('' === $old) {
            throw new \Harness\Retry("'old' must not be empty; use write_file to create a file");
        }
        $abs = $this->root->resolve($path);
        if (!\is_file($abs)) {
            throw new \RuntimeException("'$path' does not exist");
        }
        $content = \file_get_contents($abs);
        if (false === $content) {
            throw new \RuntimeException("'$path' could not be read");
        }
        $count = \substr_count($content, $old);
        if (0 === $count) {
            throw new \RuntimeException("'old' was not found in '$path'; read the file and copy the text exactly");
        }
        if ($count > 1) {
            throw new \RuntimeException("'old' occurs $count times in '$path'; include more surrounding text so it occurs once");
        }
        $at = \strpos($content, $old);
        if (false === \file_put_contents($abs, \substr_replace($content, $new, (int) $at, \strlen($old)))) {
            throw new \RuntimeException("Could not write '$path'");
        }
        $line = \substr_count($content, "\n", 0, (int) $at) + 1;

        return Result::text("Edited '$path' at line $line");
    }
}
