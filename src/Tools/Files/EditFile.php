<?php

declare(strict_types=1);

namespace Harness\Tools\Files;

use Harness\Call;
use Harness\Hints;
use Harness\Result;
use Harness\Tool;
use Harness\Tools\Input;
use Harness\ToolSpec;

/**
 * `edit_file`: replaces `old` with `new`. `old` must match exactly once; it is first tried
 * byte-exact, then, only when that matches zero times, through a tolerant cascade (each level
 * accepted only when it alone matches exactly once): line-trimmed, whitespace-normalized,
 * indentation-flexible (a block pasted at the wrong depth; `new` is re-indented by the same delta)
 * and block-anchor (the first and last line of `old` match exactly and the lines between are at
 * least 90% similar). A level matching more than once is the ambiguous error, naming it; the
 * replaced text always keeps the file's own line ending style. `$check`, when given, runs after a
 * successful write (`fn (string $absolutePath): ?string`, an error message or null) and its
 * message, if any, is appended to the result; `$journal`, when given, records the file's previous
 * content before the write.
 */
final readonly class EditFile implements Tool
{
    public function __construct(private Root $root, private ?\Closure $check = null, private ?Journal $journal = null)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('edit_file', 'Replace text in a file. `old` must match exactly once; exact text is tried first, then a tolerant match (whitespace, indentation) if that finds nothing. Include enough surrounding text to make it unique.', [
            'type'       => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path relative to the root'],
                'old'  => ['type' => 'string', 'description' => 'The text to replace'],
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

        $located = Matcher::locate($content, $old);
        if ($located instanceof Ambiguous) {
            throw new \RuntimeException($this->ambiguousMessage($path, $located));
        }
        if ($located instanceof NoMatch) {
            throw new \RuntimeException("'old' was not found in '$path'; read the file and copy the text exactly. The closest region is lines {$located->startLine}-{$located->endLine}:\n" . Lines::numbered(\explode("\n", $located->text), $located->startLine));
        }

        $replacement = Matcher::withEol(Matcher::reindent($new, $located->fromIndent, $located->toIndent, $located->indentLines), $content);
        $updated     = \substr_replace($content, $replacement, $located->offset, $located->length);

        $this->journal?->record($abs);
        if (false === \file_put_contents($abs, $updated)) {
            throw new \RuntimeException("Could not write '$path'");
        }

        $note    = Matcher::LEVEL_EXACT === $located->level ? '' : ' (matched with ' . Matcher::DESCRIPTIONS[$located->level] . " on lines {$located->startLine}-{$located->endLine})";
        $endLine = $located->startLine + \substr_count($replacement, "\n");
        $text    = "Edited '$path'$note:\n" . Lines::region($updated, $located->startLine, $endLine);
        if (null !== $this->check) {
            $message = ($this->check)($abs);
            if (null !== $message) {
                $text .= "\nCheck: $message";
            }
        }

        return Result::text($text);
    }

    private function ambiguousMessage(string $path, Ambiguous $ambiguous): string
    {
        if (Matcher::LEVEL_EXACT === $ambiguous->level) {
            return "'old' occurs " . \count($ambiguous->lines) . " times in '$path', at lines " . \implode(', ', $ambiguous->lines) . '; include more surrounding text so it occurs once';
        }

        return "'old' matches " . \count($ambiguous->lines) . ' times in \'' . $path . '\' when matched with ' . Matcher::DESCRIPTIONS[$ambiguous->level] . ', at lines ' . \implode(', ', $ambiguous->lines) . '; include more surrounding text so it occurs once';
    }
}
