<?php

declare(strict_types=1);

namespace Harness\Agents;

use Harness\Call;
use Harness\Hints;
use Harness\Message;
use Harness\Part\Text;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\Tool;
use Harness\ToolSpec;

/**
 * A sub-agent as a tool: `call()` runs a turn of a fork of `$child` (its configuration and
 * messages, so every call starts from the same context) with `prompt` as the user message, and
 * returns the final assistant text. The child's events are forwarded to the parent's stream with
 * `parent` set to this call's id; the child is nested through `Run::turn()`'s `\$parentCall`, so cancelling
 * the parent cancels it. `value` is the child's
 * `Ended`. The hints follow the child's tools: read-only when all are, destructive or open-world
 * when any is. A child turn that fails or ends awaiting input is an error for the parent's model.
 */
final readonly class AgentTool implements Tool
{
    public function __construct(private Run $child, private string $name, private string $description)
    {
    }

    public function spec(): ToolSpec
    {
        $readOnly    = true;
        $destructive = false;
        $openWorld   = false;
        foreach ($this->child->tools->tools() as $tool) {
            $h           = $tool->spec()->hints;
            $readOnly    = $readOnly && $h->readOnly;
            $destructive = $destructive || $h->destructive;
            $openWorld   = $openWorld || $h->openWorld;
        }

        return new ToolSpec($this->name, $this->description, [
            'type'       => 'object',
            'properties' => ['prompt' => ['type' => 'string', 'description' => 'The complete task for the agent; it sees nothing else of this conversation']],
            'required'   => ['prompt'],
        ], new Hints(readOnly: $readOnly, destructive: $destructive, openWorld: $openWorld));
    }

    public function call(array $input, Call $call): Result
    {
        $prompt = $input['prompt'] ?? null;
        if (!\is_string($prompt) || '' === $prompt) {
            throw new \Harness\Retry("'prompt' is required: the task for the agent");
        }
        $run = $this->child->fork();
        foreach ($run->turn(new Message(Role::User, [new Text($prompt)]), $call) as $event) {
            $call->emit($event);
        }
        $ended = $run->ended();
        if (Reason::Failed === $ended->reason) {
            throw new \RuntimeException("The agent '{$this->name}' failed ({$ended->errorKind?->value}): " . $ended->error?->getMessage(), 0, $ended->error);
        }
        if (Reason::AwaitingInput === $ended->reason) {
            throw new \RuntimeException("The agent '{$this->name}' stopped awaiting input from the host, which a sub-agent cannot get; answer approvals in the child's middleware");
        }
        $text = '';
        foreach (\array_reverse($ended->appended) as $message) {
            if (Role::Assistant === $message->role) {
                foreach ($message->parts as $part) {
                    if ($part instanceof Text) {
                        $text .= $part->text;
                    }
                }
                break;
            }
        }
        if (Reason::Cancelled === $ended->reason) {
            return new Result([new Text("The agent '{$this->name}' was cancelled")], value: $ended, error: true);
        }

        return new Result([new Text('' === $text ? '(the agent gave no text)' : $text)], value: $ended, meta: ['usage' => $ended->usage->toArray(), 'reason' => $ended->reason->value]);
    }
}
