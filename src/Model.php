<?php

declare(strict_types=1);

namespace Harness;

/**
 * A language model. `stream()` yields the reply as deltas; the run folds them into the assistant
 * message with an `Accumulator`. A model that cannot stream yields the whole reply as deltas. It
 * throws `ModelException` for model and endpoint failures, `Http\TransportException` when the
 * connection fails, and `UnsupportedOptionException` for an option it cannot honour.
 */
interface Model
{
    /** @return iterable<Delta> ending with a `Delta\Finish` */
    public function stream(Prompt $prompt): iterable;
}
