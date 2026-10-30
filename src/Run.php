<?php

declare(strict_types=1);

namespace Harness;

use function fiber\await;
use function fiber\await_all;
use function fiber\go;
use function fiber\run;
use Harness\Event\InputRequested;
use Harness\Event\MessageEnded;
use Harness\Event\MessageStarted;
use Harness\Event\ReasoningDelta as ReasoningEvent;
use Harness\Event\StepEnded;
use Harness\Event\StepStarted;
use Harness\Event\TextDelta as TextEvent;
use Harness\Event\ToolCallReady;
use Harness\Event\ToolCallStarted;
use Harness\Event\ToolDecided;
use Harness\Event\ToolEnded;
use Harness\Event\ToolInputDelta as ToolInputEvent;
use Harness\Event\TurnEnded;
use Harness\Event\TurnStarted;
use Harness\Http\TransportException;
use Harness\Internal\EventQueue;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Part\ToolResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A conversation with a model and its tools. `turn()` takes a user message (or the answers to a
 * turn that ended awaiting input) and yields the turn's events; `ended()` then says how it ended.
 * The run keeps every message in memory; `messages` and `pending` restore one in another process.
 *
 * A turn runs in its own coroutine, inside a new `\Scope` and its own `fiber\run()`, so it ends with
 * every coroutine it started finished. Iterate it inside `fiber\run()`. A consumer that stops
 * iterating cancels the turn. Middleware is applied in array order, outermost first.
 */
final class Run
{
    /** @var list<Message> */
    private array $messages;
    /** @var list<Pending> */
    private array $pending;
    /** @var list<ModelMiddleware|ToolMiddleware> */
    private readonly array $middleware;
    /** @var list<\Closure(Step): bool> */
    private readonly array $stop;
    private readonly LoggerInterface $log;
    private int $turns         = 0;
    private bool $running      = false;
    private bool $cancelled    = false;
    /** The tool call this run's current turn is nested in, if any. */
    private ?Call $parentCall  = null;
    private ?Ended $ended      = null;
    /** @var \WeakMap<\Throwable, string> where an exception came from: 'model', 'tool' or 'middleware' */
    private \WeakMap $origin;
    /** @var array<string, Tool> the tools of the current step, by name */
    private array $stepTools = [];

    /**
     * @param list<ModelMiddleware|ToolMiddleware> $middleware outermost first
     * @param list<\Closure(Step): bool>|null      $stop       OR'd; null means `[Stop::maxSteps(20)]`
     * @param list<Message>                        $messages   the conversation so far
     * @param list<Pending>                        $pending    the calls the last turn left waiting
     */
    public function __construct(
        public readonly Model $model,
        public readonly ToolSource $tools,
        public readonly ?string $system = null,
        public readonly mixed $context = null,
        array $middleware = [],
        ?array $stop = null,
        ?LoggerInterface $log = null,
        public readonly Options $options = new Options(),
        array $messages = [],
        array $pending = [],
    ) {
        foreach ($middleware as $m) {
            if (!$m instanceof ModelMiddleware && !$m instanceof ToolMiddleware) {
                throw new \InvalidArgumentException('Middleware must implement ModelMiddleware or ToolMiddleware, got ' . \get_debug_type($m));
            }
        }
        $stop ??= [Stop::maxSteps(20)];
        foreach ($stop as $s) {
            if (!$s instanceof \Closure) {
                throw new \InvalidArgumentException('Stop predicates are closures fn (Step $step): bool, got ' . \get_debug_type($s));
            }
        }
        foreach ($messages as $m) {
            if (!$m instanceof Message) {
                throw new \InvalidArgumentException('messages must be Message objects, got ' . \get_debug_type($m));
            }
        }
        foreach ($pending as $p) {
            if (!$p instanceof Pending) {
                throw new \InvalidArgumentException('pending must be Pending objects, got ' . \get_debug_type($p));
            }
        }
        $this->middleware = \array_values($middleware);
        $this->stop       = \array_values($stop);
        $this->log        = $log ?? new NullLogger();
        $this->messages   = \array_values($messages);
        $this->pending    = \array_values($pending);
        $this->origin     = new \WeakMap();
        $this->checkPending();
    }

    /**
     * A new Run with this one's configuration and a copy of its messages, or the `$messages` and
     * `$pending` given (a restored conversation, validated as by the constructor).
     * `Agents\AgentTool` runs a fork per call, so every call starts from the same context, and
     * restores a paused child with the messages and pending calls it kept.
     *
     * @param list<Message>|null $messages null: a copy of this run's
     * @param list<Pending>      $pending
     */
    public function fork(?array $messages = null, array $pending = []): self
    {
        if (null === $messages && [] !== $this->pending) {
            throw new \LogicException('Cannot fork a run that awaits input; pass the messages and pending calls to restore');
        }

        return new self($this->model, $this->tools, $this->system, $this->context, $this->middleware, $this->stop, $this->log, $this->options, $messages ?? $this->messages, $pending);
    }

    /**
     * Runs one turn and yields its events, the last being `TurnEnded`. `$input` is a user message,
     * or, after a turn that ended `AwaitingInput`, one `Answer` per pending call. `$parentCall` is the
     * tool call this run is nested in (`Agents\AgentTool` passes it): events then carry its id as
     * `parent`, and cancelling the outer run cancels this turn too. Must be iterated
     * inside `fiber\run()`. Breaking out of the loop cancels the turn. A `\LogicException` (misuse,
     * such as an option the provider cannot honour) is rethrown from the iteration after `TurnEnded`.
     *
     * @param Message|list<Answer> $input
     *
     * @return \Generator<int, Event>
     */
    public function turn(Message|array $input, ?Call $parentCall = null): \Generator
    {
        if ($this->running) {
            throw new \LogicException('A turn is already running on this Run; finish iterating it first');
        }
        $answers          = $this->checkInput($input);
        $this->running    = true;
        $this->cancelled  = false;
        $this->parentCall = $parentCall;
        $this->ended      = null;
        $parent           = $parentCall?->id;
        $turn             = ++$this->turns;
        $queue            = new EventQueue();

        $globals = [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $_REQUEST];
        try {
            $task = go(fn () => (new \Scope())->run(function () use ($globals, $input, $answers, $turn, $parent, $queue) {
                // A new scope may start with empty superglobals; the turn sees its creator's.
                [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $_REQUEST] = $globals;
                try {
                    return run(fn () => $this->loop($input, $answers, $turn, $parent, $queue));
                } finally {
                    $queue->close();
                }
            }));
        } catch (\Throwable $e) {
            // Typically fiber\NotInRunException: the turn never started.
            $this->running = false;
            throw $e;
        }
        try {
            while (($event = $queue->next()) !== null) {
                yield $event;
            }
            await($task);
        } finally {
            if (!$queue->isClosed()) {
                $this->cancel();
            }
            $queue->detach();
        }
    }

    /** How the last turn ended. Throws while a turn runs or before the first. */
    public function ended(): Ended
    {
        if ($this->running) {
            throw new \LogicException('The turn is still running; ended() is available once its events are exhausted');
        }

        return $this->ended ?? throw new \LogicException('No turn has run yet');
    }

    /**
     * Asks the running turn to stop: the model stream is abandoned (the transport sees the turn's
     * `Cancel` signal within its poll interval, 250 ms for `Http\StreamTransport`), tool calls not
     * yet started get a "cancelled" error result, running ones see `Call::isCancelled()`, nested
     * runs are cancelled with it. The turn ends `Reason::Cancelled`, without the partial assistant
     * message. Has no effect when no turn is running.
     */
    public function cancel(): void
    {
        if ($this->running) {
            $this->cancelled = true;
        }
    }

    /**
     * True from `turn()` until the turn's last event, `TurnEnded`, was handled: while it is,
     * `cancel()` has an effect and `ended()` throws.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /** True while a running turn has been cancelled, directly or through the run it is nested in. */
    public function isCancelled(): bool
    {
        return $this->running && ($this->cancelled || ($this->parentCall?->isCancelled() ?? false));
    }

    /** @return list<Message> every message of the conversation */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @param Message|list<Answer> $input
     *
     * @return array<string, Answer>
     */
    private function checkInput(Message|array $input): array
    {
        $ids = \array_map(static fn (Pending $p) => $p->call->id, $this->pending);
        if ($input instanceof Message) {
            if (Role::User !== $input->role) {
                throw new \InvalidArgumentException('A turn starts from a user message');
            }
            if ([] !== $this->pending) {
                throw new \LogicException('The run awaits input for tool calls [' . \implode(', ', $ids) . ']; pass one Answer per call to turn()');
            }

            return [];
        }
        if ([] === $this->pending) {
            throw new \LogicException('Nothing awaits input; start a turn with a user Message');
        }
        $answers = [];
        foreach ($input as $a) {
            if (!$a instanceof Answer) {
                throw new \InvalidArgumentException('turn() takes a Message or a list of Answer, got ' . \get_debug_type($a));
            }
            if (isset($answers[$a->callId])) {
                throw new \InvalidArgumentException("Two answers for tool call {$a->callId}");
            }
            $answers[$a->callId] = $a;
        }
        $given = \array_keys($answers);
        if ([] !== \array_diff($ids, $given) || [] !== \array_diff($given, $ids)) {
            throw new \InvalidArgumentException('Answer every pending tool call exactly once: pending [' . \implode(', ', $ids) . '], answered [' . \implode(', ', $given) . ']');
        }
        foreach ($this->pending as $p) {
            $answer = $answers[$p->call->id]->answer;
            if ($p->needs instanceof Approval) {
                if (!$answer instanceof Decision) {
                    throw new \InvalidArgumentException("Tool call {$p->call->id} asked for approval; answer with a Decision");
                }
            } elseif (!\is_array($answer)) {
                throw new \InvalidArgumentException("Tool call {$p->call->id} asked questions; answer with an array keyed by question id");
            } else {
                Call::checkAnswers($p->call->id, $p->needs, $answer);
            }
        }

        return $answers;
    }

    /** The pending calls must be exactly the unanswered calls of the last assistant message. */
    private function checkPending(): void
    {
        $unanswered = [];
        foreach ($this->messages as $m) {
            foreach ($m->parts as $part) {
                if ($part instanceof ToolCall) {
                    $unanswered[$part->id] = true;
                } elseif ($part instanceof ToolResult) {
                    unset($unanswered[$part->callId]);
                }
            }
        }
        $pending = \array_map(static fn (Pending $p) => $p->call->id, $this->pending);
        $open    = \array_keys($unanswered);
        if ([] !== \array_diff($open, $pending) || [] !== \array_diff($pending, $open)) {
            throw new \InvalidArgumentException('The messages leave tool calls [' . \implode(', ', $open) . '] without results, but pending lists [' . \implode(', ', $pending) . ']; pass the Pending list the last turn ended with');
        }
    }

    /** @param array<string, Answer> $answers */
    private function loop(Message|array $input, array $answers, int $turn, ?string $parent, EventQueue $queue): void
    {
        $emit    = $queue->push(...);
        $cancel  = new Cancel($this->isCancelled(...));
        $finish  = null;
        $start   = \count($this->messages);
        $usage   = new Usage();
        $retries = 0;
        $pending = [];
        $reason  = Reason::Done;
        $error   = null;
        $kind    = null;
        $emit(new TurnStarted($turn, $parent));
        try {
            if ($input instanceof Message) {
                $this->messages[] = $input;
            } else {
                $emit(new StepStarted($turn, $parent, 0));
                $this->stepTools     = $this->readTools();
                [$results, $pending] = $this->runCalls(\array_map(static fn (Pending $p) => $p->call, $this->pending), $answers, [], $turn, $parent, $emit, $retries);
                $this->pending       = [];
                if ([] !== $results) {
                    $this->messages[] = new Message(Role::User, $results);
                }
                $emit(new StepEnded($turn, $parent, 0));
                $this->checkRetries($retries);
                if ([] !== $pending) {
                    $reason = Reason::AwaitingInput;
                }
            }
            for ($n = 1; Reason::AwaitingInput !== $reason; ++$n) {
                if ($this->isCancelled()) {
                    $reason = Reason::Cancelled;
                    break;
                }
                $emit(new StepStarted($turn, $parent, $n));
                $this->stepTools = $this->readTools();
                $specs           = \array_values(\array_map(static fn (Tool $t) => $t->spec(), $this->stepTools));
                $reply           = $this->modelStep(new Prompt($this->system, $this->messages, $specs, $this->options, $cancel), $turn, $parent, $emit);
                if (null === $reply) {
                    $emit(new StepEnded($turn, $parent, $n));
                    $reason = Reason::Cancelled;
                    break;
                }
                [$message, $stepUsage, $invalid, $finish] = $reply;
                $usage                                    = $usage->plus($stepUsage);
                $this->messages[]                         = $message;
                $calls                                    = \array_values(\array_filter($message->parts, static fn (Part $p) => $p instanceof ToolCall));
                if ([] === $calls) {
                    $emit(new StepEnded($turn, $parent, $n));
                    $reason = Reason::Done;
                    break;
                }
                [$results, $pending] = $this->runCalls($calls, [], $invalid, $turn, $parent, $emit, $retries);
                if ([] !== $results) {
                    $this->messages[] = new Message(Role::User, $results);
                }
                $emit(new StepEnded($turn, $parent, $n));
                $this->checkRetries($retries);
                if ([] !== $pending) {
                    $reason = Reason::AwaitingInput;
                    break;
                }
                if ($this->isCancelled()) {
                    $reason = Reason::Cancelled;
                    break;
                }
                $step = new Step($n, $message, $calls, $results, $stepUsage, $usage);
                foreach ($this->stop as $predicate) {
                    if ($predicate($step)) {
                        $reason = Reason::Stopped;
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            $reason = Reason::Failed;
            $error  = $e;
            $kind   = match (true) {
                $e instanceof ModelException                 => $e->kind,
                $e instanceof TransportException             => ErrorKind::TransportError,
                $e instanceof ToolRetriesExceeded            => ErrorKind::ToolRetriesExceeded,
                ($this->origin[$e] ?? null) === 'middleware' => ErrorKind::MiddlewareError,
                default                                      => ErrorKind::ModelError,
            };
            $this->log->error('Turn {turn} failed: {message}', ['turn' => $turn, 'message' => $e->getMessage(), 'exception' => $e, 'kind' => $kind->value]);
        }
        $this->pending = $pending;
        $this->ended   = new Ended($reason, \array_slice($this->messages, $start), $pending, $usage, $error, $kind, $finish);
        if ([] !== $pending) {
            $emit(new InputRequested($turn, $parent, $pending));
        }
        $emit(new TurnEnded($turn, $parent, $reason, $usage, $kind, $finish));
        $this->running = false;
        if ($error instanceof \LogicException) {
            throw $error;
        }
    }

    /**
     * One model call. Returns the assistant message, its usage, the invalid tool inputs and the
     * finish reason, or null when the turn was cancelled while it streamed (the partial message is
     * discarded).
     *
     * @return array{0: Message, 1: Usage, 2: array<string, string>, 3: FinishReason}|null
     */
    private function modelStep(Prompt $prompt, int $turn, ?string $parent, \Closure $emit): ?array
    {
        $id = 'msg_' . \bin2hex(\random_bytes(8));
        $emit(new MessageStarted($turn, $parent, $id));
        $acc = new Accumulator();
        try {
            foreach ($this->modelChain(0, $prompt) as $delta) {
                if (!$delta instanceof Delta) {
                    throw $this->mark(new \LogicException('A model yielded ' . \get_debug_type($delta) . ', not a Harness\Delta'), 'model');
                }
                try {
                    $acc->add($delta);
                } catch (\LogicException $e) {
                    throw $this->mark($e, 'model');
                }
                match (true) {
                    $delta instanceof Delta\TextDelta      => $emit(new TextEvent($turn, $parent, $id, $delta->text)),
                    $delta instanceof Delta\ReasoningDelta => $emit(new ReasoningEvent($turn, $parent, $id, $delta->text)),
                    $delta instanceof Delta\ToolCallStart  => $emit(new ToolCallStarted($turn, $parent, $delta->id, $delta->name)),
                    $delta instanceof Delta\ToolInputDelta => $emit(new ToolInputEvent($turn, $parent, $delta->id, $delta->json)),
                    $delta instanceof Delta\ToolCallEnd    => $emit(new ToolCallReady($turn, $parent, $delta->id, ($c = $acc->toolCall($delta->id))->name, $c->input)),
                    default                                => null,
                };
                if ($this->isCancelled()) {
                    return null;
                }
            }
        } catch (Cancelled $e) {
            if ($this->isCancelled()) {
                return null;
            }
            throw $e;
        } catch (\Throwable $e) {
            if (!isset($this->origin[$e]) && !$e instanceof ModelException && !$e instanceof TransportException) {
                $this->mark($e, 'middleware');
            }
            throw $e;
        }
        $finish  = $acc->finish() ?? throw new ModelException(ErrorKind::ModelError, 'The model stream ended without a Finish delta');
        $message = $acc->message();
        $invalid = [];
        foreach ($message->parts as $part) {
            if ($part instanceof ToolCall && ($why = $acc->invalidInput($part->id)) !== null) {
                $invalid[$part->id] = $why;
            }
        }
        $emit(new MessageEnded($turn, $parent, $id, $message, $acc->usage(), $finish));

        return [$message, $acc->usage(), $invalid, $finish];
    }

    /** @return iterable<Delta> */
    private function modelChain(int $i, Prompt $prompt): iterable
    {
        for (; $i < \count($this->middleware); ++$i) {
            if ($this->middleware[$i] instanceof ModelMiddleware) {
                return $this->middleware[$i]->stream($prompt, fn (Prompt $p): iterable => $this->modelChain($i + 1, $p));
            }
        }

        return $this->callModel($prompt);
    }

    /** @return \Generator<int, Delta> */
    private function callModel(Prompt $prompt): \Generator
    {
        try {
            yield from $this->model->stream($prompt);
        } catch (\Throwable $e) {
            throw $this->mark($e, 'model');
        }
    }

    private function toolChain(int $i, ToolCall $call, Call $context): Result
    {
        for (; $i < \count($this->middleware); ++$i) {
            if ($this->middleware[$i] instanceof ToolMiddleware) {
                return $this->middleware[$i]->call($call, $context, fn (ToolCall $c, Call $ctx): Result => $this->toolChain($i + 1, $c, $ctx));
            }
        }
        $tool = $this->stepTools[$call->name] ?? null;
        if (null === $tool) {
            throw $this->mark(new Retry("Unknown tool '{$call->name}'; the tools are: " . \implode(', ', \array_keys($this->stepTools))), 'tool');
        }
        try {
            return $tool->call($call->input, $context);
        } catch (\Throwable $e) {
            throw $this->mark($e, 'tool');
        }
    }

    /** @return array<string, Tool> */
    private function readTools(): array
    {
        $tools = [];
        foreach ($this->tools->tools() as $tool) {
            if (!$tool instanceof Tool) {
                throw new \LogicException(\get_debug_type($this->tools) . '::tools() yielded ' . \get_debug_type($tool) . ', not a Harness\Tool');
            }
            $name = $tool->spec()->name;
            if (isset($tools[$name])) {
                throw new \LogicException("Two tools are named '$name'");
            }
            $tools[$name] = $tool;
        }

        return $tools;
    }

    /**
     * Runs the tool calls of a step: the read-only ones together, then the others one at a time, in
     * the model's order. Returns the results in the model's order and the calls left pending.
     *
     * @param list<ToolCall>        $calls
     * @param array<string, Answer> $answers
     * @param array<string, string> $invalid call id => why its input was rejected
     *
     * @return array{0: list<ToolResult>, 1: list<Pending>}
     */
    private function runCalls(array $calls, array $answers, array $invalid, int $turn, ?string $parent, \Closure $emit, int &$retries): array
    {
        // Calls that cannot run (malformed input, unknown tool) have no side effects either.
        $together = fn (ToolCall $c): bool => isset($invalid[$c->id]) || !isset($this->stepTools[$c->name]) || $this->stepTools[$c->name]->spec()->hints->readOnly;
        // A closure, not fn: $retries is shared by reference with every call's fiber.
        $start = function (ToolCall $call) use ($answers, $invalid, $turn, $parent, $emit, &$retries): object {
            return go(function () use ($call, $answers, $invalid, $turn, $parent, $emit, &$retries) {
                return $this->runCall($call, $answers[$call->id] ?? null, $invalid[$call->id] ?? null, $turn, $parent, $emit, $retries);
            });
        };
        $tasks    = [];
        foreach ($calls as $i => $call) {
            if ($together($call)) {
                $tasks[$i] = $start($call);
            }
        }
        $outcomes = await_all($tasks);
        foreach ($calls as $i => $call) {
            if (!isset($tasks[$i])) {
                $outcomes[$i] = await($start($call));
            }
        }
        \ksort($outcomes);
        $results = [];
        $pending = [];
        foreach ($outcomes as $outcome) {
            if ($outcome instanceof Pending) {
                $pending[] = $outcome;
            } else {
                $results[] = $outcome;
            }
        }

        return [$results, $pending];
    }

    private function runCall(ToolCall $call, ?Answer $answer, ?string $invalid, int $turn, ?string $parent, \Closure $emit, int &$retries): ToolResult|Pending
    {
        if ($this->isCancelled()) {
            $result = new Result([new Text('Cancelled before it ran')], error: true);
            $emit(new ToolEnded($turn, $parent, $call->id, $result));

            return new ToolResult($call->id, $result->content, true);
        }
        if (null !== $invalid) {
            ++$retries;
            $result = new Result([new Text($invalid)], error: true);
            $emit(new ToolEnded($turn, $parent, $call->id, $result));

            return new ToolResult($call->id, $result->content, true);
        }
        $state = null;
        if (null !== $answer) {
            foreach ($this->pending as $p) {
                if ($p->call->id === $call->id) {
                    $state = $p->state;
                }
            }
        }
        $decision = $answer?->answer instanceof Decision ? $answer->answer : null;
        if (null !== $decision) {
            $emit(new ToolDecided($turn, $parent, $call->id, $decision));
            // An approval asked with state is the tool's own (Agents\AgentTool forwards a child's):
            // the tool is called again and handles the denial itself.
            if (!$decision->allowed && null === $state) {
                $result = new Result([new Text('Denied: ' . $decision->reason)], error: true);
                $emit(new ToolEnded($turn, $parent, $call->id, $result));

                return new ToolResult($call->id, $result->content, true);
            }
        }
        $context = new Call(
            $call->id,
            $this,
            $this->context,
            ($this->stepTools[$call->name] ?? null)?->spec(),
            $call,
            $turn,
            $parent,
            $emit,
            $decision,
            \is_array($answer?->answer) ? $answer->answer : null,
            $state,
        );
        try {
            $result = $this->toolChain(0, $call, $context);
        } catch (Retry $e) {
            ++$retries;
            $result = new Result([new Text('' === $e->getMessage() ? 'Retry' : $e->getMessage())], error: true);
            $emit(new ToolEnded($turn, $parent, $call->id, $result));

            return new ToolResult($call->id, $result->content, true);
        } catch (\Throwable $e) {
            if (($this->origin[$e] ?? null) !== 'tool') {
                throw $this->mark($e, 'middleware');
            }
            $this->log->error('Tool {name} ({id}) threw {class}: {message}', ['name' => $call->name, 'id' => $call->id, 'class' => $e::class, 'message' => $e->getMessage(), 'exception' => $e]);
            $emit(new ToolEnded($turn, $parent, $call->id, $e));

            return new ToolResult($call->id, [new Text('' === $e->getMessage() ? $e::class : $e->getMessage())], true);
        }
        if (null !== $context->pending()) {
            return $context->pending();
        }
        $emit(new ToolEnded($turn, $parent, $call->id, $result));

        return new ToolResult($call->id, $result->content, $result->error);
    }

    private function checkRetries(int $retries): void
    {
        if ($retries > $this->options->toolRetries) {
            throw new ToolRetriesExceeded("The model made $retries correctable tool errors this turn; Options::\$toolRetries allows {$this->options->toolRetries}");
        }
    }

    private function mark(\Throwable $e, string $origin): \Throwable
    {
        $this->origin[$e] ??= $origin;

        return $e;
    }
}
