<?php

declare(strict_types=1);

namespace Harness\Agents;

use Harness\Answer;
use Harness\Approval;
use Harness\Call;
use Harness\Decision;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Message;
use Harness\Part\Text;
use Harness\Pending;
use Harness\Question;
use Harness\Reason;
use Harness\Result;
use Harness\Retry;
use Harness\Role;
use Harness\Run;
use Harness\Tool;
use Harness\ToolSpec;

/**
 * A sub-agent as a tool: `call()` runs a turn of the child with `prompt` as the user message and
 * returns the child's final assistant text. With `fresh` (the default) every call runs a fork of
 * `$child` (its configuration and messages), so each starts from the same context; without it every
 * call continues the same child, whose context grows (in this process; calls then run one at a time).
 *
 * The child's events are forwarded to the parent's stream with `parent` set to this call's id, and
 * cancelling the parent cancels the child. Hints follow the child's tools: read-only when all are,
 * destructive or open-world when any is. `value` is the child's `Ended`; `meta` holds its `usage`,
 * `reason` and `finish`, and a reply cut by the token limit or refused says so in a trailing line.
 * A failed child is an error for the parent's model.
 *
 * When the child ends awaiting input, this call ends with `needsInput()`: one pending child call
 * passes its `Approval` or questions up unchanged; several become one list of questions (an approval
 * as a question `<call id>` with choices allow/deny, a question as `<call id>/<question id>`). The
 * state holds the child's messages and pending calls, so the host answers the parent like any other
 * pending call, in any process, and the restored child runs on with the answers.
 */
final class AgentTool implements Tool
{
    /** The child that later calls continue when not fresh. */
    private Run $current;

    public function __construct(
        private readonly Run $child,
        private readonly string $name,
        private readonly string $description,
        private readonly bool $fresh = true,
    ) {
        $this->current = $child;
    }

    public function spec(): ToolSpec
    {
        $readOnly    = true;
        $destructive = false;
        $openWorld   = false;
        foreach ($this->child->tools->tools() as $tool) {
            $h           = $tool->spec()->hints;
            $readOnly    = $readOnly && $h->readOnly;
            $destructive = $destructive || $h->destructive;
            $openWorld   = $openWorld || $h->openWorld;
        }

        return new ToolSpec($this->name, $this->description, [
            'type'       => 'object',
            'properties' => ['prompt' => ['type' => 'string', 'description' => 'The complete task for the agent; it sees nothing else of this conversation']],
            'required'   => ['prompt'],
        ], new Hints(readOnly: $readOnly, destructive: $destructive, openWorld: $openWorld));
    }

    public function call(array $input, Call $call): Result
    {
        $state = $call->state();
        if (\is_array($state) && isset($state['child'])) {
            $pending = \array_map(Pending::fromArray(...), $state['child']['pending']);
            $run     = $this->child->fork(\array_map(Message::fromArray(...), $state['child']['messages']), $pending);
            $turn    = self::route($pending, $call->decision(), $call->answers());
            if (!$this->fresh) {
                $this->current = $run;
            }
        } else {
            $prompt = $input['prompt'] ?? null;
            if (!\is_string($prompt) || '' === $prompt) {
                throw new Retry("'prompt' is required: the task for the agent");
            }
            $run  = $this->fresh ? $this->child->fork() : $this->current;
            $turn = new Message(Role::User, [new Text($prompt)]);
        }
        foreach ($run->turn($turn, $call) as $event) {
            $call->emit($event);
        }
        $ended = $run->ended();
        if (Reason::Failed === $ended->reason) {
            throw new \RuntimeException("The agent '{$this->name}' failed ({$ended->errorKind?->value}): " . $ended->error?->getMessage(), 0, $ended->error);
        }
        if (Reason::AwaitingInput === $ended->reason) {
            return $call->needsInput(self::needs($ended->pending), ['child' => [
                'messages' => \array_map(static fn (Message $m) => $m->toArray(), $run->messages()),
                'pending'  => \array_map(static fn (Pending $p) => $p->toArray(), $ended->pending),
            ]]);
        }
        if (Reason::Cancelled === $ended->reason) {
            return new Result([new Text("The agent '{$this->name}' was cancelled")], value: $ended, error: true);
        }
        $text = '';
        foreach (\array_reverse($ended->appended) as $message) {
            if (Role::Assistant === $message->role) {
                foreach ($message->parts as $part) {
                    if ($part instanceof Text) {
                        $text .= $part->text;
                    }
                }
                break;
            }
        }
        $text = '' === $text ? '(the agent gave no text)' : $text;
        $text .= match ($ended->finish) {
            FinishReason::Length  => "\n\n[The agent's reply was cut off at its output token limit.]",
            FinishReason::Refusal => "\n\n[The agent refused to answer.]",
            default               => '',
        };

        return new Result([new Text($text)], value: $ended, meta: ['usage' => $ended->usage->toArray(), 'reason' => $ended->reason->value, 'finish' => $ended->finish?->value]);
    }

    /**
     * What the parent's call asks for the child's pending calls.
     *
     * @param list<Pending> $pending
     *
     * @return Approval|list<Question>
     */
    private static function needs(array $pending): Approval|array
    {
        if (1 === \count($pending)) {
            return $pending[0]->needs;
        }
        $questions = [];
        foreach ($pending as $p) {
            if ($p->needs instanceof Approval) {
                $questions[] = new Question($p->call->id, "Allow {$p->call->name}? {$p->needs->reason}", ['allow', 'deny']);
                continue;
            }
            foreach ($p->needs as $q) {
                $questions[] = new Question("{$p->call->id}/{$q->id}", $q->text, $q->choices);
            }
        }

        return $questions;
    }

    /**
     * The child's answers, from the answer to the parent's call.
     *
     * @param list<Pending>             $pending
     * @param array<string, mixed>|null $answers
     *
     * @return list<Answer>
     */
    private static function route(array $pending, ?Decision $decision, ?array $answers): array
    {
        if (1 === \count($pending)) {
            $p = $pending[0];

            return [new Answer($p->call->id, $p->needs instanceof Approval
                ? ($decision ?? throw new \LogicException("The child's call {$p->call->id} asked for approval, but the parent's call got no Decision"))
                : ($answers ?? throw new \LogicException("The child's call {$p->call->id} asked questions, but the parent's call got no answers")))];
        }
        if (null === $answers) {
            throw new \LogicException('The child has several pending calls, asked as questions, but the parent\'s call got no answers');
        }
        $routed = [];
        foreach ($pending as $p) {
            if ($p->needs instanceof Approval) {
                $routed[] = new Answer($p->call->id, 'allow' === $answers[$p->call->id] ? Decision::allow() : Decision::deny('The host denied it'));
                continue;
            }
            $own = [];
            foreach ($p->needs as $q) {
                $own[$q->id] = $answers["{$p->call->id}/{$q->id}"];
            }
            $routed[] = new Answer($p->call->id, $own);
        }

        return $routed;
    }
}
