<?php

declare(strict_types=1);

namespace Harness\Tools\Plan;

use Harness\Message;
use Harness\ModelMiddleware;
use Harness\Part\Text;
use Harness\Prompt;
use Harness\Role;

/**
 * Keeps the goal and the open plan in front of the model: a small model drifts from the original
 * ask after many steps, and the system prompt alone does not help, since it is read once and the
 * plan changes as the agent works. On every `$every`-th model call counted from construction (1:
 * every call), `Reminder` appends one user-role message to a copy of the prompt's messages, never
 * `Run::messages()` (the host's transcript is untouched, as `Compact` leaves it): "Goal: " followed
 * by the first user message of the conversation (its text parts, cut at `$goalChars` with an
 * ellipsis), then, only while the plan holds steps, a blank line, "Plan:" and `Plan::render()`.
 *
 * The message goes after the last message, where a user message may go; when the last message is
 * already a user message (the step's own turn: the user's text, or a step's tool results), the
 * reminder is folded into a copy of that message as an extra text part instead of a second user
 * message, since some providers reject two user messages in a row.
 *
 * Placed after `Compact` in a `Run`'s middleware (`[Compact, Reminder]`), `Reminder` sees the
 * prompt Compact already folded and adds the reminder on top of it; because the reminder never
 * reaches `Run::messages()`, a later compaction never folds it either.
 */
final class Reminder implements ModelMiddleware
{
    private int $calls = 0;

    public function __construct(
        private readonly Plan $plan,
        private readonly int $every = 1,
        private readonly int $goalChars = 600,
    ) {
        if ($this->every < 1) {
            throw new \InvalidArgumentException('every must be at least 1');
        }
        if ($this->goalChars < 1) {
            throw new \InvalidArgumentException('goalChars must be at least 1');
        }
    }

    public function stream(Prompt $prompt, \Closure $next): iterable
    {
        if (0 !== ++$this->calls % $this->every) {
            return $next($prompt);
        }

        $text = 'Goal: ' . $this->goal($prompt->messages);
        if (!$this->plan->isEmpty()) {
            $text .= "\n\nPlan:\n" . $this->plan->render();
        }

        $messages = $prompt->messages;
        $i        = \count($messages) - 1;
        if (isset($messages[$i]) && Role::User === $messages[$i]->role) {
            $messages[$i] = new Message(Role::User, [...$messages[$i]->parts, new Text($text)]);
        } else {
            $messages[] = new Message(Role::User, [new Text($text)]);
        }

        return $next(new Prompt($prompt->system, $messages, $prompt->tools, $prompt->options, $prompt->cancel, $prompt->continue));
    }

    /**
     * The text parts of the first user message in `$messages`, joined, cut at `$goalChars`.
     *
     * @param list<Message> $messages
     */
    private function goal(array $messages): string
    {
        foreach ($messages as $message) {
            if (Role::User !== $message->role) {
                continue;
            }
            $text = \implode('', \array_map(static fn ($part): string => $part instanceof Text ? $part->text : '', $message->parts));
            if ('' !== $text) {
                return \strlen($text) > $this->goalChars ? \substr($text, 0, $this->goalChars) . '…' : $text;
            }
        }

        throw new \LogicException('Reminder found no user message with text in the prompt; it needs at least the conversation\'s first message');
    }
}
