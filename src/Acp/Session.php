<?php

declare(strict_types=1);

namespace Harness\Acp;

use Harness\Run;
use Harness\Transcript\JsonlTranscript;

/**
 * One ACP session of an `Agent`: its id, working directory and `Run`, the transcript it is kept in
 * when the agent has a session directory, and what the client decided for good
 * (`allow_always`/`reject_always`, by tool name). Internal to the module.
 *
 * @internal
 */
final class Session
{
    /** True while a `session/prompt` runs. */
    public bool $prompting = false;
    /** True once the client sent `session/cancel` for the running prompt. */
    public bool $cancelled = false;
    /** @var array<string, bool> tool name => allowed, from the "always" options */
    public array $always = [];
    /** @var array<string, string> streamed output of running tool calls, by update id */
    public array $output = [];
    /** @var array<string, list<array<string, mixed>>> the diff content of edit tool calls, by update id */
    public array $diffs = [];

    public function __construct(
        public readonly string $id,
        public readonly string $cwd,
        public readonly Run $run,
        public readonly ?JsonlTranscript $transcript,
    ) {
    }
}
