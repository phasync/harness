<?php

declare(strict_types=1);

namespace Harness\Testing;

use Harness\Delta;
use Harness\Model;
use Harness\Prompt;

/**
 * A scripted model for tests: each call takes the next reply, a list of deltas or a closure
 * `fn (Prompt): iterable<Delta>` (which may `fiber\sleep()` or throw). `prompts` records every
 * prompt it was given. Running out of replies throws.
 */
final class FakeModel implements Model
{
    /** @var list<Prompt> */
    public array $prompts = [];

    /** @var list<iterable<Delta>|\Closure(Prompt): iterable<Delta>> */
    private array $replies;

    /** @param iterable<Delta>|\Closure(Prompt): iterable<Delta> ...$replies */
    public function __construct(iterable|\Closure ...$replies)
    {
        $this->replies = \array_values($replies);
    }

    public function stream(Prompt $prompt): iterable
    {
        $this->prompts[] = $prompt;
        if ([] === $this->replies) {
            throw new \LogicException('FakeModel has no reply left for call ' . \count($this->prompts));
        }
        $reply = \array_shift($this->replies);
        yield from $reply instanceof \Closure ? $reply($prompt) : $reply;
    }
}
