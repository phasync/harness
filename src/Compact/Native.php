<?php

declare(strict_types=1);

namespace Harness\Compact;

use Harness\ModelException;
use Harness\Part\Compacted;
use Harness\Prompt;

/**
 * A model that compacts a conversation itself (`Provider\OpenAI\Responses`): `checkpoint()` sends
 * the prompt with a request to compact it and returns the provider's encrypted item standing for
 * all of it, readable only by the same model. Throws `ModelException` as `Model::stream()` does,
 * `ErrorKind::ContextTooLong` when the prompt is too long to compact.
 */
interface Native
{
    /** @throws ModelException */
    public function checkpoint(Prompt $prompt): Compacted;
}
