<?php

declare(strict_types=1);

/*
 * The files agent with a Policy instead of a hand-written gate: reads run, edits and writes ask on
 * the terminal, nothing else runs. The policy asks with needsInput(); the terminal middleware placed
 * before it answers at once (the README's inline idiom), so the turn never ends awaiting input. A
 * web host would drop the terminal middleware and answer the pending call in its next request.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8 php examples/policy.php <dir> [task]
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Approval;
use Harness\Call;
use Harness\Decision;
use Harness\Event;
use Harness\Http\StreamTransport;
use Harness\Message;
use Harness\Options;
use Harness\Part\Text;
use Harness\Part\ToolCall;
use Harness\Policy\Policy;
use Harness\Policy\Rule;
use Harness\Policy\Verdict;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\ToolMiddleware;
use Harness\Tools;
use Harness\Tools\Files;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url  = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$dir  = $argv[1] ?? exit("Usage: php examples/policy.php <dir> [task]\n");
$task = \implode(' ', \array_slice($argv, 2)) ?: 'Create notes.txt with three ideas for a garden, then read it back.';
$root = new Files\Root($dir);

$policy = new Policy([
    Rule::hints(Verdict::Allow, readOnly: true),
    Rule::tool('*_file', Verdict::Ask),
], default: Verdict::Deny);

// The inline idiom: answer whatever the call is waiting for, then call $next again.
$terminal = new class implements ToolMiddleware {
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        $result = $next($call, $context);
        while (null !== ($pending = $context->pending())) {
            $answers = [];
            $denied  = false;
            foreach ($pending->needs as $need) {
                echo "\n\e[33m" . ($need instanceof Approval ? "Allow {$call->name} " . \json_encode(\array_diff_key($call->input, ['content' => 1]), \JSON_UNESCAPED_SLASHES) . " ({$need->reason})? [y/N]" : $need->text) . "\e[0m ";
                $line = \trim((string) \fgets(fiber\readable(\STDIN)));
                if ($need instanceof Approval) {
                    $answers[$need->id] = 'y' === \strtolower($line) ? Decision::allow() : Decision::deny('the user said no');
                    $denied             = $denied || !$answers[$need->id]->allowed;
                } else {
                    $answers[$need->id] = $line;
                }
            }
            $context->answer($answers);
            if ($denied && null === $pending->state) {
                return new Result([new Text('Denied: the user said no')], error: true);
            }
            $result = $next($call, $context);
        }

        return $result;
    }
};

$run = new Run(
    new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default'),
    new Tools(new Files\ReadFile($root), new Files\ListDir($root), new Files\Glob($root), new Files\Grep($root), new Files\WriteFile($root), new Files\EditFile($root)),
    system: "You work on the files in {$root->path} with your tools. Paths are relative to it. Be brief.\n\n" . $policy->describe(),
    middleware: [$terminal, $policy],
    options: new Options(maxOutputTokens: 2000),
);

fiber\run(function () use ($run, $task) {
    foreach ($run->turn(new Message(Role::User, [new Text($task)])) as $e) {
        // A sub-agent's events carry its call id in `parent`: only the host's own turn ends the line.
        match (true) {
            $e instanceof Event\TextDelta     => print ($e->text),
            $e instanceof Event\ToolCallReady => print ("\n\e[36m→ {$e->name} " . \json_encode($e->input, \JSON_UNESCAPED_SLASHES) . "\e[0m\n"),
            $e instanceof Event\ToolEnded     => print ($e->outcome instanceof Result
                ? "\e[2m← " . ($e->outcome->error ? 'error: ' : '') . \strtok($e->outcome->content[0]->text ?? '(media)', "\n") . "\e[0m\n"
                : "\e[31m← {$e->outcome->getMessage()}\e[0m\n"),
            $e instanceof Event\TurnEnded && null === $e->parent => print ("\n\e[2m[{$e->reason->value}, {$e->usage->input} in, {$e->usage->output} out]\e[0m\n"),
            default                                              => null,
        };
    }
});
