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
 * is made again with `decision()` (after an Approval) or `answers()` (after questions), and `state()`.
 * A denied approval is made again only when it was asked with a state (the tool handles the
 * denial); without one the model gets "Denied: <reason>" and the tool is not called.
 */
final class Call
{
    private ?Pending $pending = null;

    /**
     * @internal created by Run for every tool call
     *
     * @param \Closure(Event): void     $emit
     * @param array<string, mixed>|null $answers
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
        private ?Decision $decision = null,
        private ?array $answers = null,
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
     * Result it gives; the model never sees it. On resume the call is made again with the answer.
     *
     * @param Approval|list<Question> $needs
     */
    public function needsInput(Approval|array $needs, mixed $state = null): Result
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
     * pending request is cleared and `decision()` or `answers()`/`state()` set, so the middleware can
     * call `$next` again, or return its own Result.
     *
     * @param Decision|array<string, mixed> $answer
     */
    public function answer(Decision|array $answer): void
    {
        if (null === $this->pending) {
            throw new \LogicException("Tool call {$this->id} has not asked for input");
        }
        $pending       = $this->pending;
        $this->pending = null;
        if ($answer instanceof Decision) {
            if (!$pending->needs instanceof Approval) {
                throw new \InvalidArgumentException("Tool call {$this->id} asked questions; answer them with an array keyed by question id");
            }
            $this->decision = $answer;
            $this->state    = $pending->state;

            return;
        }
        if ($pending->needs instanceof Approval) {
            throw new \InvalidArgumentException("Tool call {$this->id} asked for approval; answer with a Decision");
        }
        self::checkAnswers($this->id, $pending->needs, $answer);
        $this->answers = $answer;
        $this->state   = $pending->state;
    }

    /** The host's decision when this call is made again after an Approval, else null. */
    public function decision(): ?Decision
    {
        return $this->decision;
    }

    /** @return array<string, mixed>|null the answers keyed by question id when made again after questions, else null */
    public function answers(): ?array
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
     * @internal
     *
     * @param list<Question>       $questions
     * @param array<string, mixed> $answers
     */
    public static function checkAnswers(string $callId, array $questions, array $answers): void
    {
        $ids = \array_map(static fn (Question $q) => $q->id, $questions);
        if ([] !== \array_diff($ids, \array_keys($answers)) || [] !== \array_diff(\array_keys($answers), $ids)) {
            throw new \InvalidArgumentException("Tool call $callId asked questions [" . \implode(', ', $ids) . '], answered [' . \implode(', ', \array_keys($answers)) . ']');
        }
        foreach ($questions as $q) {
            if ([] !== $q->choices && !\in_array($answers[$q->id], $q->choices, true)) {
                throw new \InvalidArgumentException("The answer to question '{$q->id}' must be one of: " . \implode(', ', $q->choices));
            }
        }
    }
}
