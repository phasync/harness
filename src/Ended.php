<?php

declare(strict_types=1);

namespace Harness;

/**
 * How a turn ended: the reason, the messages it appended (persist these to keep your own
 * history), the calls awaiting input, the usage of its model calls, for `Reason::Failed` the error
 * and its kind, and `finish`, the `FinishReason` of the turn's last assistant message (null when
 * the turn completed none). `Reason::Done` only means "no tool calls": a reply cut by the token
 * limit or refused is `Done` with `finish` `Length` or `Refusal`. A cancelled or failed turn
 * discards the step in progress (its partial assistant message, and on failure the results of its
 * calls): `appended` and `Run::messages()` hold only complete steps, every `ToolCall` with its
 * `ToolResult` or among `pending`, so a host may build a new `Run` on them to retry. A failed
 * resume leaves the calls it answered in `pending`, still awaiting input.
 *
 * `partial` is set only for `Reason::Interrupted` while the model streamed: the assistant message
 * as delivered so far (its text as streamed, its reasoning, the tool calls that were complete),
 * which is nowhere else; `finish` is null then. `queued` holds the messages given to
 * `Run::interject()` that did not land because the turn ended before another model call, the
 * steering ones first, then the queued ones, each in the order given.
 */
final readonly class Ended
{
    /**
     * @param list<Message> $appended
     * @param list<Pending> $pending
     * @param list<Message> $queued
     */
    public function __construct(
        public Reason $reason,
        public array $appended,
        public array $pending,
        public Usage $usage,
        public ?\Throwable $error = null,
        public ?ErrorKind $errorKind = null,
        public ?FinishReason $finish = null,
        public ?Message $partial = null,
        public array $queued = [],
    ) {
    }

    /**
     * JSON-safe: the messages and pending calls as arrays, the error as `{class, message}`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason'    => $this->reason->value,
            'appended'  => \array_map(static fn (Message $m) => $m->toArray(), $this->appended),
            'pending'   => \array_map(static fn (Pending $p) => $p->toArray(), $this->pending),
            'usage'     => $this->usage->toArray(),
            'error'     => null === $this->error ? null : ['class' => $this->error::class, 'message' => $this->error->getMessage()],
            'errorKind' => $this->errorKind?->value,
            'finish'    => $this->finish?->value,
            'partial'   => $this->partial?->toArray(),
            'queued'    => \array_map(static fn (Message $m) => $m->toArray(), $this->queued),
        ];
    }
}
