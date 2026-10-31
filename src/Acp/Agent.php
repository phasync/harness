<?php

declare(strict_types=1);

namespace Harness\Acp;

use Harness\Answer;
use Harness\Approval;
use Harness\Decision;
use Harness\Event;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Message;
use Harness\Part\Media;
use Harness\Part\Reasoning;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Harness\Pending;
use Harness\Question;
use Harness\Reason;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\Tool;
use Harness\Transcript\JsonlTranscript;

/**
 * An Agent Client Protocol (v1) agent over stdio, as an adapter over a `Run`'s event stream: an
 * editor (the client) starts the agent's executable and speaks newline-delimited JSON-RPC to it.
 * Handles `initialize`, `authenticate` (no methods), `session/new`, `session/load` (with a session
 * directory), `session/prompt` and the `session/cancel` notification; `$runs($cwd)` builds the
 * `Run` of each new session.
 *
 * A prompt's events become `session/update` notifications: text `agent_message_chunk`, reasoning
 * `agent_thought_chunk`, a tool call `tool_call` (its `kind` from the tool's `Hints`, a `diff` for
 * `edit_file` and `write_file`, the file in `locations`) and `tool_call_update` as it runs (a
 * string `ToolProgress`, such as `shell` output, as text content) and ends. A sub-agent's tool calls
 * appear under ids prefixed with its call's id; its text stays inside its result. A call that
 * pauses for an `Approval` (a `Policy` `Ask`, nested ones included) becomes a
 * `session/request_permission` with `allow_once`, `allow_always`, `reject_once` and
 * `reject_always`, the "always" ones remembered per tool name for the session; the answers resume
 * the run. A `Question` is declined (ACP has no way to ask one). The response's `stopReason`
 * follows `Reason` and `FinishReason`; a failed turn is a JSON-RPC error. The client's `fs/*` and
 * `terminal/*` methods are not used, and the `mcpServers` of a session are not connected: the
 * run's own tools run.
 */
final class Agent
{
    private const PROTOCOL = 1;

    /** @var array<string, Session> */
    private array $sessions   = [];
    private ?Channel $channel = null;

    /**
     * @param \Closure(string): Run $runs       builds the Run of a new session, given its absolute working directory
     * @param string|null           $sessionDir a directory to keep each session's messages in, so `session/load` works across processes
     */
    public function __construct(
        private readonly \Closure $runs,
        private readonly string $name = 'phasync/harness',
        private readonly string $version = '0.0.1',
        private readonly ?string $sessionDir = null,
    ) {
        if (null !== $sessionDir && !\is_dir($sessionDir)) {
            throw new \InvalidArgumentException("The ACP session directory '$sessionDir' is not a directory");
        }
    }

    /**
     * Serves one client until its input ends. Call inside `fiber\run()`.
     *
     * @param resource $in
     * @param resource $out
     */
    public function serve(mixed $in = \STDIN, mixed $out = \STDOUT): void
    {
        $this->channel = new Channel($in, $out);
        $this->channel->serve($this->request(...), $this->notification(...));
        foreach ($this->sessions as $session) {
            $session->run->cancel();
        }
    }

    /** @param array<string, mixed> $params */
    private function request(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize'     => $this->initialize(),
            'authenticate'   => new \stdClass(),
            'session/new'    => $this->newSession($params),
            'session/load'   => $this->loadSession($params),
            'session/prompt' => $this->prompt($params),
            default          => throw new AcpError(-32601, "Method not found: $method"),
        };
    }

    /** @param array<string, mixed> $params */
    private function notification(string $method, array $params): void
    {
        if ('session/cancel' === $method && null !== ($session = $this->sessions[$params['sessionId'] ?? ''] ?? null) && $session->prompting) {
            $session->cancelled = true;
            $session->run->cancel();
        }
    }

    /** @return array<string, mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion'   => self::PROTOCOL,
            'agentCapabilities' => [
                'loadSession'        => null !== $this->sessionDir,
                'promptCapabilities' => ['image' => false, 'audio' => false, 'embeddedContext' => true],
                'mcpCapabilities'    => ['http' => false, 'sse' => false],
            ],
            'authMethods' => [],
            'agentInfo'   => ['name' => $this->name, 'version' => $this->version],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{sessionId: string}
     */
    private function newSession(array $params): array
    {
        $cwd                 = self::cwd($params);
        $id                  = 'sess_' . \bin2hex(\random_bytes(12));
        $this->sessions[$id] = new Session($id, $cwd, $this->run($cwd), null === $this->sessionDir ? null : new JsonlTranscript("{$this->sessionDir}/$id.jsonl"));

        return ['sessionId' => $id];
    }

    /** @param array<string, mixed> $params */
    private function loadSession(array $params): \stdClass
    {
        if (null === $this->sessionDir) {
            throw new AcpError(-32601, 'This agent keeps no sessions (it has no session directory), so it cannot load one');
        }
        $id = $params['sessionId'] ?? null;
        if (!\is_string($id) || 1 !== \preg_match('/^sess_[0-9a-f]{24}$/', $id) || !\is_file("{$this->sessionDir}/$id.jsonl")) {
            throw new AcpError(-32602, 'session/load: no session ' . \json_encode($id));
        }
        $cwd        = self::cwd($params);
        $transcript = new JsonlTranscript("{$this->sessionDir}/$id.jsonl");
        $messages   = $transcript->load();
        $session    = new Session($id, $cwd, $this->run($cwd)->fork($messages), $transcript);
        $specs      = $this->specs($session);
        foreach ($messages as $message) {
            foreach ($message->parts as $part) {
                match (true) {
                    $part instanceof Text && Role::User === $message->role => $this->update($session, ['sessionUpdate' => 'user_message_chunk', 'content' => ['type' => 'text', 'text' => $part->text]]),
                    $part instanceof Text                                  => $this->update($session, ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => $part->text]]),
                    $part instanceof Reasoning                             => $this->update($session, ['sessionUpdate' => 'agent_thought_chunk', 'content' => ['type' => 'text', 'text' => $part->text]]),
                    $part instanceof ToolCall                              => $this->update($session, $this->toolCall($session, $part->id, $part->name, $part->input, $specs[$part->name] ?? null)),
                    $part instanceof ToolResult                            => $this->update($session, ['sessionUpdate' => 'tool_call_update', 'toolCallId' => $part->callId, 'status' => $part->error ? 'failed' : 'completed', 'content' => [...$session->diffs[$part->callId] ?? [], ...self::content(self::text($part->content))]]),
                    default                                                => null,
                };
            }
        }
        $this->sessions[$id] = $session;

        return new \stdClass();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{stopReason: string}
     */
    private function prompt(array $params): array
    {
        $session = $this->sessions[$params['sessionId'] ?? ''] ?? throw new AcpError(-32602, 'session/prompt: no session ' . \json_encode($params['sessionId'] ?? null));
        if ($session->prompting) {
            throw new AcpError(-32600, 'session/prompt: a prompt is already running in this session; wait for its response or send session/cancel');
        }
        $input               = new Message(Role::User, self::parts($params['prompt'] ?? null));
        $session->prompting  = true;
        $session->cancelled  = false;
        $specs               = $this->specs($session);
        try {
            while (true) {
                try {
                    foreach ($session->run->turn($input) as $event) {
                        if ($session->cancelled && $event instanceof Event\TurnStarted) {
                            $session->run->cancel();     // a resume after a cancelled permission request
                        }
                        $this->event($session, $event, $specs);
                    }
                } catch (\LogicException $e) {
                    throw new AcpError(-32603, 'The turn failed: ' . $e->getMessage(), $e);
                }
                $ended = $session->run->ended();
                $session->transcript?->append($ended->appended);
                if (Reason::AwaitingInput !== $ended->reason) {
                    break;
                }
                $input = $this->answers($session, $ended->pending, $specs);
            }
        } finally {
            $session->prompting = false;
            $session->output    = [];
        }
        if (Reason::Failed === $ended->reason) {
            throw new AcpError(-32603, "The turn failed ({$ended->errorKind?->value}): " . $ended->error?->getMessage());
        }

        return ['stopReason' => match ($ended->reason) {
            Reason::Cancelled, Reason::Interrupted => 'cancelled',
            Reason::Stopped                        => 'max_turn_requests',
            default                                => match ($ended->finish) {
                FinishReason::Length  => 'max_tokens',
                FinishReason::Refusal => 'refusal',
                default               => 'end_turn',
            },
        }];
    }

    /**
     * The answers to the paused calls: each `Approval` asked of the client with
     * `session/request_permission`, unless an "always" option decided it for that tool already.
     *
     * @param list<Pending>       $pending
     * @param array<string, Tool> $specs
     *
     * @return list<Answer>
     */
    private function answers(Session $session, array $pending, array $specs): array
    {
        $answers = [];
        foreach ($pending as $p) {
            $byNeed = [];
            foreach ($p->needs as $need) {
                $name              = $p->call->name;
                $byNeed[$need->id] = match (true) {
                    $session->cancelled            => Decision::deny('The user cancelled the prompt'),
                    $need instanceof Question      => Decision::deny('The client cannot answer questions (ACP has no way to ask one): ' . $need->text),
                    isset($session->always[$name]) => $session->always[$name] ? Decision::allow() : Decision::deny("The user rejects '$name' for this session"),
                    default                        => $this->permission($session, $p, $need, $specs[$name] ?? null),
                };
            }
            $answers[] = new Answer($p->call->id, $byNeed);
        }

        return $answers;
    }

    private function permission(Session $session, Pending $p, Approval $need, ?Tool $tool): Decision
    {
        $name   = $p->call->name;
        $call   = $this->toolCall($session, $p->call->id, $name, $p->call->input, $tool);
        $result = $this->channel->request('session/request_permission', [
            'sessionId' => $session->id,
            'toolCall'  => ['toolCallId' => $call['toolCallId'], 'title' => $need->reason ?? $call['title'], 'kind' => $call['kind'], 'status' => 'pending', 'rawInput' => $call['rawInput']],
            'options'   => [
                ['optionId' => 'allow_once', 'name' => 'Allow', 'kind' => 'allow_once'],
                ['optionId' => 'allow_always', 'name' => "Always allow $name", 'kind' => 'allow_always'],
                ['optionId' => 'reject_once', 'name' => 'Reject', 'kind' => 'reject_once'],
                ['optionId' => 'reject_always', 'name' => "Always reject $name", 'kind' => 'reject_always'],
            ],
        ]);
        $outcome = $result['outcome'] ?? null;
        if ('cancelled' === ($outcome['outcome'] ?? null)) {
            $session->cancelled = true;

            return Decision::deny('The user cancelled the prompt');
        }
        $option = 'selected' === ($outcome['outcome'] ?? null) ? $outcome['optionId'] ?? null : null;
        if (!\in_array($option, ['allow_once', 'allow_always', 'reject_once', 'reject_always'], true)) {
            throw new AcpError(-32602, 'session/request_permission: the client answered ' . \json_encode($outcome) . '; expected {"outcome": "selected", "optionId": one of the options} or {"outcome": "cancelled"}');
        }
        if (\str_ends_with($option, '_always')) {
            $session->always[$name] = 'allow_always' === $option;
        }

        return match ($option) {
            'allow_once', 'allow_always' => Decision::allow(),
            'reject_once'                => Decision::deny('The user rejected it'),
            'reject_always'              => Decision::deny("The user rejects '$name' for this session"),
        };
    }

    /** @param array<string, Tool> $specs */
    private function event(Session $session, Event $e, array $specs): void
    {
        $top = null === $e->parent;
        $uid = fn (string $id): string => $top ? $id : "{$e->parent}/$id";
        match (true) {
            $e instanceof Event\TextDelta && $top      => $this->update($session, ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => $e->text]]),
            $e instanceof Event\ReasoningDelta && $top => $this->update($session, ['sessionUpdate' => 'agent_thought_chunk', 'content' => ['type' => 'text', 'text' => $e->text]]),
            $e instanceof Event\ToolCallReady          => $this->update($session, $this->toolCall($session, $uid($e->id), $e->name, $e->input, $top ? $specs[$e->name] ?? null : null)),
            $e instanceof Event\MessageEnded           => $this->started($session, $e->message, $uid),
            $e instanceof Event\ToolProgress           => \is_string($e->value) ? $this->progress($session, $uid($e->id), $e->value) : null,
            $e instanceof Event\ToolEnded              => $this->ended($session, $uid($e->id), $e->outcome),
            default                                    => null,
        };
    }

    /** The calls of a finished assistant message are about to run. */
    private function started(Session $session, Message $message, \Closure $uid): void
    {
        foreach ($message->parts as $part) {
            if ($part instanceof ToolCall) {
                $this->update($session, ['sessionUpdate' => 'tool_call_update', 'toolCallId' => $uid($part->id), 'status' => 'in_progress']);
            }
        }
    }

    private function progress(Session $session, string $id, string $chunk): void
    {
        $session->output[$id] = ($session->output[$id] ?? '') . $chunk;
        $this->update($session, ['sessionUpdate' => 'tool_call_update', 'toolCallId' => $id, 'content' => [...$session->diffs[$id] ?? [], ...self::content($session->output[$id])]]);
    }

    private function ended(Session $session, string $id, Result|\Throwable $outcome): void
    {
        unset($session->output[$id]);
        $failed = $outcome instanceof \Throwable || $outcome->error;
        $text   = $outcome instanceof \Throwable ? $outcome->getMessage() : self::text($outcome->content);
        $this->update($session, ['sessionUpdate' => 'tool_call_update', 'toolCallId' => $id, 'status' => $failed ? 'failed' : 'completed', 'content' => [...$session->diffs[$id] ?? [], ...self::content($text)]]);
        unset($session->diffs[$id]);
    }

    /**
     * A `tool_call` update for a call: its title, its kind from the tool's hints, its file in
     * `locations` and, for `edit_file` and `write_file`, a diff.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function toolCall(Session $session, string $id, string $name, array $input, ?Tool $tool): array
    {
        $path    = \is_string($input['path'] ?? null) ? ('/' === $input['path'][0] ? $input['path'] : \rtrim($session->cwd, '/') . '/' . $input['path']) : null;
        $summary = match (true) {
            \is_string($input['command'] ?? null)  => $input['command'],
            null !== $path                         => $input['path'],
            [] === $input                          => '',
            default                                => \substr(\json_encode($input, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), 0, 80),
        };
        $update = [
            'sessionUpdate' => 'tool_call',
            'toolCallId'    => $id,
            'title'         => \trim("$name $summary"),
            'kind'          => null === $tool ? 'other' : self::kind($tool->spec()->hints),
            'status'        => 'pending',
            'rawInput'      => (object) $input,
        ];
        if (null !== $path) {
            $update['locations'] = [['path' => $path]];
        }
        $diff = match (true) {
            'edit_file' === $name && \is_string($input['old'] ?? null) && \is_string($input['new'] ?? null) => ['type' => 'diff', 'path' => $path, 'oldText' => $input['old'], 'newText' => $input['new']],
            'write_file' === $name && \is_string($input['content'] ?? null)                                 => ['type' => 'diff', 'path' => $path, 'oldText' => null !== $path && \is_file($path) ? \file_get_contents($path) : null, 'newText' => $input['content']],
            default                                                                                         => null,
        };
        if (null !== $diff && null !== $path) {
            $session->diffs[$id] = [$diff];
            $update['content']   = [$diff];
        }

        return $update;
    }

    /** @param array<string, mixed> $update */
    private function update(Session $session, array $update): void
    {
        $this->channel->notify('session/update', ['sessionId' => $session->id, 'update' => $update]);
    }

    private function run(string $cwd): Run
    {
        $run = ($this->runs)($cwd);
        if (!$run instanceof Run) {
            throw new \LogicException('The ACP agent\'s run factory returned ' . \get_debug_type($run) . ', not a Harness\Run');
        }

        return $run;
    }

    /** @return array<string, Tool> the session's top-level tools by name */
    private function specs(Session $session): array
    {
        $tools = [];
        foreach ($session->run->tools->tools() as $tool) {
            $tools[$tool->spec()->name] = $tool;
        }

        return $tools;
    }

    /** ACP's tool kind from a tool's hints. */
    private static function kind(Hints $hints): string
    {
        return match (true) {
            $hints->readOnly  => $hints->openWorld ? 'fetch' : 'read',
            $hints->openWorld => 'execute',
            default           => 'edit',
        };
    }

    /** @param array<string, mixed> $params */
    private static function cwd(array $params): string
    {
        $cwd = $params['cwd'] ?? null;
        if (!\is_string($cwd) || '' === $cwd || '/' !== $cwd[0]) {
            throw new AcpError(-32602, 'A session needs "cwd", an absolute path, got ' . \json_encode($cwd));
        }

        return $cwd;
    }

    /**
     * The parts of a prompt's content blocks: text, resource links and embedded text resources.
     *
     * @return list<Text|Reference>
     */
    private static function parts(mixed $blocks): array
    {
        if (!\is_array($blocks) || [] === $blocks || !\array_is_list($blocks)) {
            throw new AcpError(-32602, 'session/prompt needs "prompt", a non-empty list of content blocks');
        }
        $parts = [];
        foreach ($blocks as $block) {
            $parts[] = match ($block['type'] ?? null) {
                'text'          => new Text((string) ($block['text'] ?? '')),
                'resource_link' => new Reference((string) ($block['uri'] ?? throw new AcpError(-32602, 'A resource_link block needs "uri"')), $block['title'] ?? $block['name'] ?? null),
                'resource'      => \is_string($block['resource']['text'] ?? null)
                    ? new Text('[' . ($block['resource']['uri'] ?? 'resource') . "]\n" . $block['resource']['text'])
                    : throw new AcpError(-32602, 'Only text resources can be embedded in a prompt to this agent'),
                default => throw new AcpError(-32602, 'This agent takes text, resource_link and resource blocks (promptCapabilities), got ' . \json_encode($block['type'] ?? null)),
            };
        }

        return $parts;
    }

    /** @param list<\Harness\Part> $parts */
    private static function text(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            $text .= match (true) {
                $part instanceof Text      => $part->text,
                $part instanceof Reference => "[{$part->uri}]",
                $part instanceof Media     => "[{$part->mime}]",
                default                    => '',
            };
        }

        return $text;
    }

    /** @return list<array{type: 'content', content: array{type: 'text', text: string}}> */
    private static function content(string $text): array
    {
        return '' === $text ? [] : [['type' => 'content', 'content' => ['type' => 'text', 'text' => $text]]];
    }
}
