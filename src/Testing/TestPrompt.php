<?php

declare(strict_types=1);

namespace Harness\Testing;

use Harness\Cancel;
use Harness\Message;
use Harness\Options;
use Harness\Prompt;
use Harness\ToolSpec;

/**
 * A `Prompt` for unit-testing a `ModelMiddleware` without a run: `$middleware->stream(TestPrompt::make(
 * messages: [...]), fn (Prompt $p) => $model->stream($p))`. Its `Cancel` is never set.
 */
final class TestPrompt
{
    /**
     * @param list<Message>  $messages
     * @param list<ToolSpec> $tools
     */
    public static function make(?string $system = null, array $messages = [], array $tools = [], Options $options = new Options(), bool $continue = false): Prompt
    {
        return new Prompt($system, $messages, $tools, $options, new Cancel(static fn (): bool => false), $continue);
    }
}
