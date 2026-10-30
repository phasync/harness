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

/** `list_dir`: the entries of a directory, sorted, directories with a trailing slash, paged by `offset`. */
final readonly class ListDir implements Tool
{
    public function __construct(private Root $root, private int $limit = 500)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('list_dir', 'List a directory. Directories end with /.', [
            'type'       => 'object',
            'properties' => [
                'path'   => ['type' => 'string', 'description' => 'Directory relative to the root (default .)'],
                'offset' => ['type' => 'integer', 'description' => 'Entries to skip (default 0)'],
            ],
        ], new Hints(readOnly: true, idempotent: true));
    }

    public function call(array $input, Call $call): Result
    {
        $path   = Input::string($input, 'path', '.');
        $offset = Input::int($input, 'offset', 0, 0);
        $abs    = $this->root->resolve($path);
        if (!\is_dir($abs)) {
            throw new \RuntimeException("'$path' is not a directory");
        }
        $names = \scandir($abs);
        if (false === $names) {
            throw new \RuntimeException("'$path' could not be read");
        }
        $entries = [];
        foreach ($names as $name) {
            if ('.' !== $name && '..' !== $name) {
                $entries[] = \is_dir($abs . '/' . $name) ? $name . '/' : $name;
            }
        }

        return new Result([new Text(Page::render($entries, $offset, $this->limit, 'entries', "'$path' is empty"))], meta: ['count' => \count($entries)]);
    }
}
