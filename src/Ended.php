<?php

declare(strict_types=1);

namespace Harness;

/**
 * How a turn ended: the reason, the messages it appended (persist these to keep your own
 * history), the calls awaiting input, the usage of its model calls and, for `Reason::Failed`, the
 * error and its kind.
 */
final readonly class Ended
{
    /**
     * @param list<Message> $appended
     * @param list<Pending> $pending
     */
    public function __construct(
        public Reason $reason,
        public array $appended,
        public array $pending,
        public Usage $usage,
        public ?\Throwable $error = null,
        public ?ErrorKind $errorKind = null,
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
        ];
    }
}
