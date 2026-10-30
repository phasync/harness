<?php

declare(strict_types=1);

namespace Harness;

/**
 * Something the model can call. `call()` gets the decoded input and the call's context; it
 * returns a `Result`, throws `Retry` for a failure the model may correct, or throws anything else
 * for a failure it reports (both become error results the model sees).
 */
interface Tool
{
    public function spec(): ToolSpec;

    /** @param array<string, mixed> $input */
    public function call(array $input, Call $call): Result;
}
