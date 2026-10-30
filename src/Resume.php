<?php

declare(strict_types=1);

namespace Harness;

/**
 * The input of a turn that continues an interrupted reply: `message` is the assistant message the
 * host built from `Ended::$partial` (edited, or as it was). The turn appends it and runs a step
 * with `Prompt::$continue`, so the model goes on from its last part, and the step's
 * `MessageEnded` carries the whole message. Valid only when the run's last message is a user
 * message and nothing awaits input; the providers' support differs (README, Interrupt and resume).
 */
final readonly class Resume
{
    public function __construct(public Message $message)
    {
        if (Role::Assistant !== $message->role) {
            throw new \InvalidArgumentException('A Resume continues an assistant message, got a ' . $message->role->value . ' message');
        }
        if ([] === $message->parts) {
            throw new \InvalidArgumentException('A Resume needs a message with something to continue; to ask for the reply again, run a new Run on messages() without its last user message and turn() with it');
        }
    }
}
