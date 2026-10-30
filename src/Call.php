<?php

declare(strict_types=1);

namespace Harness;

use Harness\Event\ToolProgress;
use Harness\Part\ToolCall;

/**
 * The context of one tool call, always the tool's second parameter: the call `id`, the `run` it
 * belongs to, the host's `context` object (from `Run`) and the `spec` of the tool called (null when
 * the model named no such tool), whose hints policy middleware reads. A tool reports progress, checks for
 * cancellation, and may end the turn awaiting the host with `needsInput()`. On resume the same call
 * is made again with `answers()` (keyed by need id; `decision()` is the `approval` one) and
 * `state()`. A call that asked without state is not made again when an approval was denied: the
 * model gets "Denied: <reason>" (see `Approval`).
 */
final class Call
{
    private ?Pending $pending = null;

    /**
     * @internal created by Run for every tool call
     *
     * @param \Closure(Event): void $emit
     * @param array<string, mixed>  $answers
     */
    public function __construct(
        public readonly string $id,
        public readonly Run $run,
        public readonly mixed $context,
        public readonly ?ToolSpec $spec,
        private readonly ToolCall $toolCall,
        private readonly int $turn,
        private readonly ?string $parent,
        private readonly \Closure $emit,
        private array $answers = [],
        private mixed $state = null,
    ) {
    }

    /** Emits a `ToolProgress` event for this call. */
    public function progress(string|array $value): void
    {
        ($this->emit)(new ToolProgress($this->turn, $this->parent, $this->id, $value));
    }

    /** True once the run was cancelled; a long tool checks it and returns early. */
    public function isCancelled(): bool
    {
        return $this->run->isCancelled();
    }

    /**
     * Ends this call awaiting the host: the turn finishes `Reason::AwaitingInput` once the step's
     * other calls are done, listing a `Pending` with `$needs` and `$state` (JSON-safe). Return the
     * Result it gives; the model never sees it. On resume the call is made again with the answers
     * (see `Approval` for a denial).
     *
     * @param non-empty-list<Need> $needs each with an id unique within the call
     */
    public function needsInput(array $needs, mixed $state = null): Result
    {
        if (null !== $this->pending) {
            throw new \LogicException("Tool call {$this->id} already asked for input");
        }
        $this->pending = new Pending($this->toolCall, $needs, $state);

        return Result::text('Awaiting input from the host');
    }

    /** What this call is waiting for, or null. Middleware that answers at once reads it after `$next()`. */
    public function pending(): ?Pending
    {
        return $this->pending;
    }

    /**
     * Answers the pending request in place, for middleware that asks the user at once (a CLI): the
     * pending request is cleared and `answers()` and `state()` set, so the middleware can call
     * `$next` again, or return its own Result. Keyed by need id, validated as an `Answer` is.
     *
     * @param array<string, Decision|string|int|float|bool|array<mixed>> $answers
     */
    public function answer(array $answers): void
    {
        if (null === $this->pending) {
            throw new \LogicException("Tool call {$this->id} has not asked for input");
        }
        self::checkAnswers($this->id, $this->pending->needs, $answers);
        $this->answers = $answers;
        $this->state   = $this->pending->state;
        $this->pending = null;
    }

    /** The answer to the need with id `approval`, when this call is made again after one, else null. */
    public function decision(): ?Decision
    {
        $decision = $this->answers['approval'] ?? null;

        return $decision instanceof Decision ? $decision : null;
    }

    /**
     * The host's answers keyed by need id when this call is made again after `needsInput()`, else
     * empty: a `Decision` per approval, a value or a declining `Decision` per question.
     *
     * @return array<string, Decision|string|int|float|bool|array<mixed>>
     */
    public function answers(): array
    {
        return $this->answers;
    }

    /** The state the tool passed to `needsInput()`, when made again after an answer. */
    public function state(): mixed
    {
        return $this->state;
    }

    /**
     * Forwards an event of a nested run (its `parent` set) to this run's stream. `Agents\AgentTool`
     * uses it; a tool reporting on itself uses `progress()`.
     */
    public function emit(Event $event): void
    {
        if (null === $event->parent) {
            throw new \LogicException('Only events of a nested run (parent set) are forwarded; use progress() for this call');
        }
        ($this->emit)($event);
    }

    /**
     * @internal the answers must name every need exactly once: a Decision for an approval, a value
     * (one of the choices, when given) or a denying Decision for a question
     *
     * @param list<Need>           $needs
     * @param array<string, mixed> $answers
     */
    public static function checkAnswers(string $callId, array $needs, array $answers): void
    {
        $ids   = \array_map(static fn (Need $n) => $n->id, $needs);
        $given = \array_map(\strval(...), \array_keys($answers));
        if ([] !== \array_diff($ids, $given) || [] !== \array_diff($given, $ids)) {
            throw new \InvalidArgumentException("Tool call $callId needs [" . \implode(', ', $ids) . '], answered [' . \implode(', ', $given) . ']; answer each need by its id');
        }
        foreach ($needs as $need) {
            $a = $answers[$need->id];
            if ($need instanceof Approval) {
                if (!$a instanceof Decision) {
                    throw new \InvalidArgumentException("Need '{$need->id}' of tool call $callId is an approval; answer it with a Decision, got " . \get_debug_type($a));
                }
                continue;
            }
            if ($a instanceof Decision) {
                if ($a->allowed) {
                    throw new \InvalidArgumentException("Need '{$need->id}' of tool call $callId is a question; answer it with a value, or decline it with Decision::deny()");
                }
                continue;
            }
            if ($need instanceof Question && null !== $need->choices && !\in_array($a, $need->choices, true)) {
                throw new \InvalidArgumentException("The answer to question '{$need->id}' must be one of: " . \implode(', ', $need->choices));
            }
        }
    }
}
