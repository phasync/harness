<?php

declare(strict_types=1);

namespace Harness\Testing;

use Harness\Call;
use Harness\Event;
use Harness\Part\ToolCall;
use Harness\Run;
use Harness\Tools;
use Harness\ToolSpec;

/**
 * A `Call` for unit-testing a tool or a tool middleware without a run: `$tool->call($input,
 * TestCall::make())`. It belongs to an idle `Run` over a `FakeModel`, so `isCancelled()` is false;
 * `needsInput()`, `pending()` and `answer()` work as in a turn. `$emit` receives the events the call
 * emits (`progress()`); without it they are dropped.
 */
final class TestCall
{
    /** @param (\Closure(Event): void)|null $emit */
    public static function make(?ToolSpec $spec = null, mixed $context = null, string $id = 'test', ?\Closure $emit = null): Call
    {
        return new Call(
            $id,
            new Run(new FakeModel(), new Tools(), context: $context),
            $context,
            $spec,
            new ToolCall($id, $spec->name ?? 'test', []),
            1,
            null,
            $emit ?? static function (Event $event): void {
            },
        );
    }
}
