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

        $plan    = [];   // abs => the content this patch leaves there, or null for a path it deletes: the planned state
        $writers = [];   // abs => the section path that planned its content
        $reports = [];
        foreach ($sections as $section) {
            $reports[] = match ($section->kind) {
                SectionKind::Add    => $this->planAdd($section, $plan, $writers),
                SectionKind::Delete => $this->planDelete($section, $plan),
                SectionKind::Update => $this->planUpdate($section, $plan, $writers),
            };
        }

        foreach ($plan as $abs => $content) { // still planning: a path blocked by a file or a directory fails the patch before anything is written
            if (null === $content) {
                continue;
            }
            if (\is_dir($abs)) {
                throw new \RuntimeException("apply_patch: '" . $this->root->relative($abs) . "' is a directory; a file cannot be written there");
            }
            for ($dir = \dirname($abs); !\file_exists($dir); $dir = \dirname($dir)) {
            }
            if (!\is_dir($dir)) {
                throw new \RuntimeException("apply_patch: '" . $this->root->relative($abs) . "' cannot be created: '" . $this->root->relative($dir) . "' is a file, not a directory");
            }
        }
        foreach ($plan as $abs => $content) {
            if (null === $content && (\is_file($abs) || \is_link($abs))) { // a path only ever planned, never on disk, has nothing to delete
                $this->journal?->record($abs);
                if (!\unlink($abs)) {
                    throw new \RuntimeException("Could not delete '$abs'");
                }
            }
        }
        foreach ($plan as $abs => $content) {
            if (null === $content) {
                continue;
            }
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

    /**
     * Plans `$content` for `$abs` from `$section`: a path another section already landed a different
     * file on (an add, or a move from elsewhere) would keep only the last, so that fails the patch.
     *
     * @param array<string, ?string> $plan
     * @param array<string, string>  $writers
     */
    private function land(string $abs, string $content, Section $section, array &$plan, array &$writers): void
    {
        if (isset($plan[$abs]) && $writers[$abs] !== $section->path && $this->root->resolve($section->path) !== $abs) {
            throw new \RuntimeException("apply_patch: '" . $this->root->relative($abs) . "' is written by two sections, from '{$writers[$abs]}' and from '{$section->path}'");
        }
        $plan[$abs]    = $content;
        $writers[$abs] = $section->path;
    }

    /**
     * @param array<string, ?string> $plan
     * @param array<string, string>  $writers
     */
    private function planAdd(Section $section, array &$plan, array &$writers): string
    {
        $abs = $this->root->resolve($section->path);
        if (\is_dir($abs)) {
            throw new \RuntimeException("'{$section->path}' is a directory");
        }
        if (!\array_key_exists($abs, $plan) && \is_file($abs)) { // deleted earlier in this patch is fine
            throw new \RuntimeException("'{$section->path}' exists; change it with *** Update File, or delete it first");
        }
        $content = (string) $section->content;
        $this->land($abs, $content, $section, $plan, $writers);

        return "Added '{$section->path}' (" . Lines::count($content) . ' lines)';
    }

    /** @param array<string, ?string> $plan */
    private function planDelete(Section $section, array &$plan): string
    {
        $abs = $this->root->resolve($section->path);
        if (\array_key_exists($abs, $plan)) {
            $content = $plan[$abs] ?? throw new \RuntimeException("'{$section->path}' was already deleted earlier in this patch");
        } elseif (\is_file($abs)) {
            $content = (string) \file_get_contents($abs);
        } else {
            throw new \RuntimeException("'{$section->path}' does not exist; apply_patch cannot delete it");
        }
        $plan[$abs] = null;

        return "Deleted '{$section->path}' (" . Lines::count($content) . ' lines)';
    }

    /**
     * @param array<string, ?string> $plan    the content an earlier section planned for a path, or null once deleted
     * @param array<string, string>  $writers
     */
    private function planUpdate(Section $section, array &$plan, array &$writers): string
    {
        $abs = $this->root->resolve($section->path);
        if (\array_key_exists($abs, $plan)) {
            $content = $plan[$abs] ?? throw new \RuntimeException("'{$section->path}' was deleted earlier in this patch; it cannot be updated");
        } else {
            if (!\is_file($abs)) {
                throw new \RuntimeException("'{$section->path}' does not exist");
            }
            $content = \file_get_contents($abs);
            if (false === $content) {
                throw new \RuntimeException("'{$section->path}' could not be read");
            }
            if (\str_contains(\substr($content, 0, 8192), "\0")) {
                throw new \RuntimeException("'{$section->path}' is a binary file (" . \strlen($content) . ' bytes)');
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
            $after       = Matcher::withEol(Matcher::reindent($hunk->after, $located->fromIndent, $located->toIndent, $located->indentLines), $content);
            $content     = \substr_replace($content, $after, $located->offset, $located->length);
            $endLine     = $located->startLine + \substr_count($after, "\n");
            $regions[]   = Lines::region($content, $located->startLine, $endLine);
            $added += $hunk->added;
            $removed += $hunk->removed;
        }

        $target = $this->root->resolve($section->moveTo ?? $section->path);
        if ($target !== $abs) {
            if (!\array_key_exists($target, $plan) && \is_file($target)) { // deleted earlier in this patch is fine
                throw new \RuntimeException("cannot move '{$section->path}' to '{$section->moveTo}': it exists; delete it first, or update it instead");
            }
            $plan[$abs] = null; // the source goes, whether on disk or only planned by an earlier section
        }
        $this->land($target, $content, $section, $plan, $writers);
        $summary = null === $section->moveTo
            ? "Updated '{$section->path}' (+$added -$removed):"
            : "Moved '{$section->path}' to '{$section->moveTo}' (+$added -$removed):";

        return $summary . "\n" . \implode("\n", $regions);
    }

    private function ambiguousMessage(string $path, int $hunkIndex, Ambiguous $ambiguous): string
    {
        return "apply_patch: '$path', hunk " . ($hunkIndex + 1) . ': context matches ' . \count($ambiguous->lines) . ' times when matched with ' . Matcher::DESCRIPTIONS[$ambiguous->level] . ', at lines ' . \implode(', ', $ambiguous->lines) . '; include more surrounding context';
    }
}
