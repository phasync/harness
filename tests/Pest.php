<?php

declare(strict_types=1);

// The unit suite runs once per adapter: HARNESS_ADAPTER=phasync|revolt (composer test runs both).
$adapter = \getenv('HARNESS_ADAPTER') ?: 'phasync';
\fiber\setup(match ($adapter) {
    'phasync' => new FiberInterop\Phasync\Adapter(),
    'revolt'  => new FiberInterop\Revolt\Adapter(),
    default   => throw new InvalidArgumentException("HARNESS_ADAPTER must be phasync or revolt, got '$adapter'"),
});

use Harness\Delta\Finish;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\Event;
use Harness\FinishReason;
use Harness\Message;
use Harness\Part\Text;
use Harness\Role;
use Harness\Run;
use Harness\Usage;

/**
 * Runs one turn inside fiber\run() and returns its events.
 *
 * @return list<Event>
 */
function turn(Run $run, Message|array $input): array
{
    return \fiber\run(static function () use ($run, $input): array {
        $events = [];
        foreach ($run->turn($input) as $event) {
            $events[] = $event;
        }

        return $events;
    });
}

function user(string $text): Message
{
    return new Message(Role::User, [new Text($text)]);
}

/** @return list<string> */
function types(array $events): array
{
    return \array_map(static fn (Event $e) => $e->toArray()['type'], $events);
}

/** A reply of plain text. */
function says(string $text, int $in = 10, int $out = 5): array
{
    return [new TextDelta($text), new Usage($in, $out), new Finish(FinishReason::Stop)];
}

/**
 * A reply calling tools: each call is [id, name, input].
 *
 * @param list<array{0: string, 1: string, 2: array<string, mixed>}> $calls
 */
function calls(array ...$calls): array
{
    $deltas = [];
    foreach ($calls as [$id, $name, $input]) {
        $deltas[] = new ToolCallStart($id, $name);
        $deltas[] = new ToolInputDelta($id, \json_encode((object) $input, \JSON_THROW_ON_ERROR));
        $deltas[] = new ToolCallEnd($id);
    }
    $deltas[] = new Usage(10, 5);
    $deltas[] = new Finish(FinishReason::ToolCalls);

    return $deltas;
}

/** A Call for testing a tool directly, outside a run; progress events are collected in `$events`. */
function callFor(string $id = 'call_1', array &$events = []): Harness\Call
{
    return new Harness\Call(
        $id,
        new Run(new Harness\Testing\FakeModel(), new Harness\Tools()),
        null,
        new Harness\Part\ToolCall($id, 'test', []),
        1,
        null,
        static function (Event $e) use (&$events): void {
            $events[] = $e;
        },
    );
}

/** A tool from a name, hints and a closure. */
function tool(string $name, Closure $fn, ?Harness\Hints $hints = null): Harness\ClosureTool
{
    return new Harness\ClosureTool(new Harness\ToolSpec($name, "The $name tool", ['type' => 'object'], $hints ?? new Harness\Hints()), $fn);
}
