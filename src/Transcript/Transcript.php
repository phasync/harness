<?php

declare(strict_types=1);

namespace Harness\Transcript;

use Harness\Message;

/**
 * Where a host keeps a conversation between turns: `load()` the messages to build a `Run` with,
 * `append()` what a turn added (`Ended::$appended`), `clear()` to start over.
 */
interface Transcript
{
    /** @return list<Message> */
    public function load(): array;

    /** @param list<Message> $messages */
    public function append(array $messages): void;

    public function clear(): void;
}
