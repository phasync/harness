<?php

declare(strict_types=1);

namespace Harness;

use Harness\Delta\Finish;
use Harness\Delta\ReasoningDelta;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\Part\Reasoning;
use Harness\Part\Text;
use Harness\Part\ToolCall;

/**
 * Folds the deltas of one reply into the assistant `Message`. Parts keep the order in which they
 * began; consecutive text (or reasoning) deltas extend the last part when it has the same kind.
 * A tool call whose input is not a JSON object is kept with an empty input and listed by
 * `invalidInput()`, so the run can tell the model rather than guess. A delta after `Finish`, or one
 * naming an unknown or already-ended tool call, is a provider bug and throws `\LogicException`.
 */
final class Accumulator
{
    /** @var list<array{0: string, 1: mixed}> kind ('text'|'reasoning'|'call') and payload */
    private array $parts = [];

    /** @var array<string, array{name: string, json: string, ended: bool}> */
    private array $calls = [];

    /** @var array<string, string> call id => why its input was rejected */
    private array $invalid = [];

    private Usage $usage;
    private ?FinishReason $finish = null;

    public function __construct()
    {
        $this->usage = new Usage();
    }

    public function add(Delta $delta): void
    {
        if (null !== $this->finish) {
            throw new \LogicException('Delta ' . \get_debug_type($delta) . ' arrived after Finish');
        }
        $last = \array_key_last($this->parts);
        switch (true) {
            case $delta instanceof TextDelta:
                if ('' === $delta->text) {
                    return;
                }
                if (null !== $last && 'text' === $this->parts[$last][0]) {
                    $this->parts[$last][1] .= $delta->text;
                } else {
                    $this->parts[] = ['text', $delta->text];
                }

                return;
            case $delta instanceof ReasoningDelta:
                if (null !== $last && 'reasoning' === $this->parts[$last][0]) {
                    $this->parts[$last][1][0] .= $delta->text;
                    $this->parts[$last][1][1] = \array_replace($this->parts[$last][1][1], $delta->raw);
                } elseif ('' !== $delta->text || [] !== $delta->raw) {
                    $this->parts[] = ['reasoning', [$delta->text, $delta->raw]];
                }

                return;
            case $delta instanceof ToolCallStart:
                if (isset($this->calls[$delta->id])) {
                    throw new \LogicException("Tool call {$delta->id} started twice");
                }
                $this->calls[$delta->id] = ['name' => $delta->name, 'json' => '', 'ended' => false];
                $this->parts[]           = ['call', $delta->id];

                return;
            case $delta instanceof ToolInputDelta:
                $this->openCall($delta->id);
                $this->calls[$delta->id]['json'] .= $delta->json;

                return;
            case $delta instanceof ToolCallEnd:
                $this->openCall($delta->id);
                $this->calls[$delta->id]['ended'] = true;

                return;
            case $delta instanceof Usage:
                $this->usage = new Usage(
                    \max($this->usage->input, $delta->input),
                    \max($this->usage->output, $delta->output),
                    \max($this->usage->cacheRead, $delta->cacheRead),
                    \max($this->usage->cacheWrite, $delta->cacheWrite),
                );

                return;
            case $delta instanceof Finish:
                $this->finish = $delta->reason;

                return;
        }
        throw new \LogicException('Unknown delta ' . \get_debug_type($delta));
    }

    /** The call as it stands, input decoded; for `ToolCallReady` once `ToolCallEnd` arrived. */
    public function toolCall(string $id): ToolCall
    {
        $call = $this->calls[$id] ?? throw new \LogicException("No tool call $id");

        return new ToolCall($id, $call['name'], $this->decode($id, $call['json']));
    }

    /** The assistant message so far. Tool calls that never got `ToolCallEnd` are closed here. */
    public function message(): Message
    {
        $parts = [];
        foreach ($this->parts as [$kind, $payload]) {
            $parts[] = match ($kind) {
                'text'      => new Text($payload),
                'reasoning' => new Reasoning($payload[0], $payload[1]),
                'call'      => $this->toolCall($payload),
            };
        }

        return new Message(Role::Assistant, $parts);
    }

    /** Why the named call's input was rejected, or null when it was a JSON object. */
    public function invalidInput(string $id): ?string
    {
        return $this->invalid[$id] ?? null;
    }

    public function usage(): Usage
    {
        return $this->usage;
    }

    /** The finish reason, or null while the reply is still streaming. */
    public function finish(): ?FinishReason
    {
        return $this->finish;
    }

    private function openCall(string $id): void
    {
        if (!isset($this->calls[$id])) {
            throw new \LogicException("Tool call $id was not started");
        }
        if ($this->calls[$id]['ended']) {
            throw new \LogicException("Tool call $id already ended");
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $id, string $json): array
    {
        if ('' === \trim($json)) {
            return [];
        }
        try {
            $input = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->invalid[$id] = 'The input was not valid JSON (' . $e->getMessage() . '): ' . $json;

            return [];
        }
        if (!\is_array($input) || (\array_is_list($input) && [] !== $input)) {
            $this->invalid[$id] = 'The input must be a JSON object, got: ' . $json;

            return [];
        }

        return $input;
    }
}
