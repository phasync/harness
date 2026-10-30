<?php

declare(strict_types=1);

/*
 * The request/response host idiom: each invocation is one request that runs one turn, prints its
 * events as JSON lines (what an SSE or WebSocket route would forward) and keeps the conversation and
 * the pending calls in a JSON file between requests. A write needs the user's approval; a tool can
 * ask the user a question.
 *
 *   php examples/web.php state.json say "Remember that my favourite colour is blue"
 *   php examples/web.php state.json answer '{"<call id>": "allow"}'          # or {"deny": "why"}
 *   php examples/web.php state.json answer '{"<call id>": {"answer": "blue"}}'   # answers to questions
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Answer;
use Harness\Approval;
use Harness\Call;
use Harness\ClosureTool;
use Harness\Decision;
use Harness\Hints;
use Harness\Http\StreamTransport;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Pending;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Question;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\ToolMiddleware;
use Harness\Tools;
use Harness\ToolSpec;
use Harness\Transcript\JsonlEvents;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url                      = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
[, $file, $command, $arg] = $argv + [null, null, null, null];
if (null === $file || !\in_array($command, ['say', 'answer'], true) || null === $arg) {
    exit("Usage: php examples/web.php <state.json> say <message> | answer <json>\n");
}
$state = \is_file($file) ? \json_decode(\file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR) : ['messages' => [], 'pending' => [], 'notes' => []];

$ask = new ClosureTool(
    new ToolSpec('ask_user', 'Ask the user a question and get the answer', ['type' => 'object', 'properties' => ['question' => ['type' => 'string']], 'required' => ['question']], new Hints(readOnly: true)),
    fn (array $in, Call $call): Result => null === $call->answers()
        ? $call->needsInput([new Question('answer', $in['question'])])
        : Result::text($call->answers()['answer']),
);
$remember = new ClosureTool(
    new ToolSpec('remember', 'Save a note for later conversations', ['type' => 'object', 'properties' => ['note' => ['type' => 'string']], 'required' => ['note']]),
    function (array $in, Call $call) use (&$state): Result {
        $state['notes'][] = $in['note'];

        return Result::text('Saved');
    },
);
// The policy, the only gate: the turn must end here, since the browser answers in a later request.
$policy = new class implements ToolMiddleware {
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        if (null === $context->decision() && null === $context->answers() && null !== $context->spec && !$context->spec->hints->readOnly) {
            return $context->needsInput(new Approval("Save a note: {$call->input['note']}"));
        }

        return $next($call, $context);
    }
};

$run = new Run(
    new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default'),
    new Tools($ask, $remember),
    system: 'You are a brief assistant. Notes so far: ' . (\implode('; ', $state['notes']) ?: 'none') . '. Save new facts about the user with remember.',
    middleware: [$policy],
    options: new Options(maxOutputTokens: 1000),
    messages: \array_map(Message::fromArray(...), $state['messages']),
    pending: \array_map(Pending::fromArray(...), $state['pending']),
);

$input = 'say' === $command
    ? new Message(Role::User, [new Text($arg)])
    : \array_map(
        fn (string $id, mixed $a) => new Answer($id, match (true) {
            'allow' === $a                     => Decision::allow(),
            \is_array($a) && isset($a['deny']) => Decision::deny($a['deny']),
            default                            => $a,
        }),
        \array_keys($answers = \json_decode($arg, true, 512, \JSON_THROW_ON_ERROR)),
        $answers,
    );

$sink = new JsonlEvents('php://stdout');
fiber\run(function () use ($run, $input, $sink) {
    foreach ($run->turn($input) as $event) {
        $sink->write($event);
    }
});

$state['messages'] = \array_map(fn (Message $m) => $m->toArray(), $run->messages());
$state['pending']  = \array_map(fn (Pending $p) => $p->toArray(), $run->ended()->pending);
\file_put_contents($file, \json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
