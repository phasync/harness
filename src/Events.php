<?php

declare(strict_types=1);

namespace Harness;

use Harness\Event\InputRequested;
use Harness\Event\MessageEnded;
use Harness\Event\MessageStarted;
use Harness\Event\ReasoningDelta;
use Harness\Event\StepEnded;
use Harness\Event\StepStarted;
use Harness\Event\TextDelta;
use Harness\Event\ToolCallReady;
use Harness\Event\ToolCallStarted;
use Harness\Event\ToolDecided;
use Harness\Event\ToolEnded;
use Harness\Event\ToolInputDelta;
use Harness\Event\ToolProgress;
use Harness\Event\TurnEnded;
use Harness\Event\TurnStarted;

/**
 * Rebuilds a typed event from `Event::toArray()` (or its JSON decoded as an array), choosing the
 * class by `type`; the counterpart of `Parts::fromArray()`. A `ToolEnded` that carried a Throwable
 * comes back with a `ReplayedError`.
 */
final class Events
{
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): Event
    {
        $t = $data['turn'] ?? throw new \InvalidArgumentException('An event array needs "turn"');
        $p = $data['parent'] ?? null;

        return match ($data['type'] ?? null) {
            'turn_started'      => new TurnStarted($t, $p),
            'turn_ended'        => new TurnEnded($t, $p, Reason::from($data['reason']), Usage::fromArray($data['usage']), null === $data['error'] ? null : ErrorKind::from($data['error']), null === ($data['finish'] ?? null) ? null : FinishReason::from($data['finish'])),
            'step_started'      => new StepStarted($t, $p, $data['n']),
            'step_ended'        => new StepEnded($t, $p, $data['n']),
            'message_started'   => new MessageStarted($t, $p, $data['id']),
            'text_delta'        => new TextDelta($t, $p, $data['id'], $data['text']),
            'reasoning_delta'   => new ReasoningDelta($t, $p, $data['id'], $data['text']),
            'tool_call_started' => new ToolCallStarted($t, $p, $data['id'], $data['name']),
            'tool_input_delta'  => new ToolInputDelta($t, $p, $data['id'], $data['json']),
            'tool_call_ready'   => new ToolCallReady($t, $p, $data['id'], $data['name'], $data['input']),
            'tool_decided'      => new ToolDecided($t, $p, $data['id'], Decision::fromArray($data['decision'])),
            'tool_progress'     => new ToolProgress($t, $p, $data['id'], $data['value']),
            'tool_ended'        => new ToolEnded($t, $p, $data['id'], isset($data['outcome']['class'])
                ? new ReplayedError($data['outcome']['class'], $data['outcome']['message'])
                : Result::fromArray($data['outcome'])),
            'message_ended'     => new MessageEnded($t, $p, $data['id'], Message::fromArray($data['message']), Usage::fromArray($data['usage']), FinishReason::from($data['finish'])),
            'input_requested'   => new InputRequested($t, $p, \array_map(Pending::fromArray(...), $data['pending'])),
            default             => throw new \InvalidArgumentException('Unknown event type ' . \var_export($data['type'] ?? null, true)),
        };
    }
}
