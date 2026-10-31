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
 * `apply_patch`: applies a patch in the V4A format (`*** Begin Patch` / `*** Add File:` /
 * `*** Delete File:` / `*** Update File:` with `*** Move to:` and `@@` hunks / `*** End Patch`).
 * Hunks are located by their context lines through `Matcher`'s exact, line-trimmed and
 * whitespace-normalized levels (never by line numbers); the whole patch is built in memory first,
 * so a hunk whose context is not found, or matches more than once, fails every file in the patch,
 * leaving the directory untouched. `$journal`, when given, records every changed or deleted path's
 * previous content (or absence) before it is touched.
 */
final readonly class ApplyPatch implements Tool
{
    public function __construct(private Root $root, private ?Journal $journal = null)
    {
    }

    public function spec(): ToolSpec
    {
        return new ToolSpec('apply_patch', 'Apply a patch in the V4A format (*** Begin Patch / Add File / Delete File / Update File with @@ hunks / End Patch) to add, delete, update or move several files in one call.', [
            'type'       => 'object',
            'properties' => [
                'patch' => ['type' => 'string', 'description' => 'The patch text, starting with "*** Begin Patch" and ending with "*** End Patch"'],
            ],
            'required' => ['patch'],
        ], new Hints(destructive: true));
    }

    public function call(array $input, Call $call): Result
    {
        $patch    = Input::string($input, 'patch');
        $sections = PatchParser::parse($patch);

        $writes  = [];   // abs => final content
        $deletes = [];   // list<abs>
        $reports = [];
        foreach ($sections as $section) {
            [$report, $fileWrites, $fileDeletes] = match ($section->kind) {
                SectionKind::Add    => $this->planAdd($section),
                SectionKind::Delete => $this->planDelete($section),
                SectionKind::Update => $this->planUpdate($section, $writes),
            };
            $reports[] = $report;
            foreach ($fileWrites as $abs => $content) {
                $writes[$abs] = $content; // a later section for the same file continues from the earlier one's result
            }
            $deletes = [...$deletes, ...$fileDeletes];
        }

        foreach ($deletes as $abs) {
            $this->journal?->record($abs);
            \unlink($abs);
        }
        foreach ($writes as $abs => $content) {
            $this->journal?->record($abs);
            $dir = \dirname($abs);
            if (!\is_dir($dir) && !\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
                throw new \RuntimeException("Could not create the directory for '$abs'");
            }
            if (false === \file_put_contents($abs, $content)) {
                throw new \RuntimeException("Could not write '$abs'");
            }
        }

        return Result::text(\implode("\n\n", $reports));
    }

    /** @return array{0: string, 1: array<string, string>, 2: list<string>} */
    private function planAdd(Section $section): array
    {
        $abs = $this->root->resolve($section->path);
        if (\is_dir($abs)) {
            throw new \RuntimeException("'{$section->path}' is a directory");
        }
        $content = (string) $section->content;
        $lines   = Lines::count($content);

        return ["Added '{$section->path}' ($lines lines)", [$abs => $content], []];
    }

    /** @return array{0: string, 1: array<string, string>, 2: list<string>} */
    private function planDelete(Section $section): array
    {
        $abs = $this->root->resolve($section->path);
        if (!\is_file($abs)) {
            throw new \RuntimeException("'{$section->path}' does not exist; apply_patch cannot delete it");
        }
        $content = (string) \file_get_contents($abs);
        $lines   = Lines::count($content);

        return ["Deleted '{$section->path}' ($lines lines)", [], [$abs]];
    }

    /** @return array{0: string, 1: array<string, string>, 2: list<string>} */
    /** @param array<string, string> $pending content already planned for a path by an earlier section */
    private function planUpdate(Section $section, array $pending): array
    {
        $abs = $this->root->resolve($section->path);
        if (isset($pending[$abs])) {
            $content = $pending[$abs];
        } else {
            if (!\is_file($abs)) {
                throw new \RuntimeException("'{$section->path}' does not exist");
            }
            $content = \file_get_contents($abs);
            if (false === $content) {
                throw new \RuntimeException("'{$section->path}' could not be read");
            }
        }

        $added   = 0;
        $removed = 0;
        $regions = [];
        foreach ($section->hunks as $index => $hunk) {
            if ('' === $hunk->before) {
                throw new \RuntimeException("apply_patch: '{$section->path}', hunk " . ($index + 1) . ' has no context or removed lines to locate it by');
            }
            $located = Matcher::locate($content, $hunk->before, Matcher::LEVEL_WHITESPACE_NORMALIZED);
            if ($located instanceof Ambiguous) {
                throw new \RuntimeException($this->ambiguousMessage($section->path, $index, $located));
            }
            if ($located instanceof NoMatch) {
                throw new \RuntimeException("apply_patch: '{$section->path}', hunk " . ($index + 1) . ": context not found. The closest region is lines {$located->startLine}-{$located->endLine}:\n" . Lines::numbered(\explode("\n", $located->text), $located->startLine));
            }
            $after       = Matcher::withEol(Matcher::reindent($hunk->after, $located->fromIndent, $located->toIndent, $located->indentMap), $content);
            $content     = \substr_replace($content, $after, $located->offset, $located->length);
            $endLine     = $located->startLine + \substr_count($after, "\n");
            $regions[]   = Lines::region($content, $located->startLine, $endLine);
            $added += $hunk->added;
            $removed += $hunk->removed;
        }

        $target  = $this->root->resolve($section->moveTo ?? $section->path);
        $summary = null === $section->moveTo
            ? "Updated '{$section->path}' (+$added -$removed):"
            : "Moved '{$section->path}' to '{$section->moveTo}' (+$added -$removed):";

        return [$summary . "\n" . \implode("\n", $regions), [$target => $content], null === $section->moveTo ? [] : [$abs]];
    }

    private function ambiguousMessage(string $path, int $hunkIndex, Ambiguous $ambiguous): string
    {
        return "apply_patch: '$path', hunk " . ($hunkIndex + 1) . ': context matches ' . \count($ambiguous->lines) . ' times when matched with ' . Matcher::DESCRIPTIONS[$ambiguous->level] . ', at lines ' . \implode(', ', $ambiguous->lines) . '; include more surrounding context';
    }
}
