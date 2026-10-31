<?php

declare(strict_types=1);

/*
 * The file tools, the plan tools and a Reminder, on one task. Tool calls are printed as they
 * happen, and the plan again after every plan_set/plan_update result, so you watch it form and
 * change as the agent works. Writes are approved automatically: this example is for a throwaway
 * directory, not a CLI guard; examples/files.php shows asking on the terminal instead.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8 php examples/plan.php <dir> "task"
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
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Result;
use Harness\Role;
use Harness\Run;
use Harness\ToolMiddleware;
use Harness\Tools;
use Harness\Tools\Files;
use Harness\Tools\Plan\Plan;
use Harness\Tools\Plan\PlanSet;
use Harness\Tools\Plan\PlanUpdate;
use Harness\Tools\Plan\Reminder;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url  = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$dir  = $argv[1] ?? exit("Usage: php examples/plan.php <dir> \"task\"\n");
$task = \implode(' ', \array_slice($argv, 2));
if ('' === $task) {
    exit("Usage: php examples/plan.php <dir> \"task\"\n");
}
$root = new Files\Root($dir);
$plan = new Plan();

// A policy asking only for writes (examples/files.php), answered at once instead of on the
// terminal: fine for a directory made for this run, not for one that matters.
$policy = new class implements ToolMiddleware {
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        if ([] === $context->answers() && null !== $context->spec && !$context->spec->hints->readOnly) {
            return $context->needsInput([new Approval(reason: "{$call->name} changes files")]);
        }

        return $next($call, $context);
    }
};
$autoApprove = new class implements ToolMiddleware {
    public function call(ToolCall $call, Call $context, Closure $next): Result
    {
        $result = $next($call, $context);
        while (null !== ($pending = $context->pending())) {
            $context->answer(\array_fill_keys(\array_map(static fn ($need) => $need->id, $pending->needs), Decision::allow()));
            $result = $next($call, $context);
        }

        return $result;
    }
};

$tools = new Tools(
    new Files\ReadFile($root), new Files\ListDir($root), new Files\Glob($root), new Files\Grep($root),
    new Files\WriteFile($root), new Files\EditFile($root),
    new PlanSet($plan), new PlanUpdate($plan),
);

$model = new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default');
$run   = new Run(
    $model,
    $tools,
    system: "You work on the files in {$root->path} with your tools. Paths are relative to it. Use plan_set to lay out multi-step work, and plan_update as you go. Be brief.",
    middleware: [$autoApprove, $policy, new Reminder($plan)],
    options: new Options(maxOutputTokens: 1500),
);

/** @var array<string, string> $names tool call id => tool name; ToolEnded carries the id, not the name */
$names = [];

function render(Event $event, array &$names, Plan $plan): void
{
    if ($event instanceof Event\TextDelta) {
        echo $event->text;
    } elseif ($event instanceof Event\ToolCallReady) {
        $names[$event->id] = $event->name;
        echo "\n\e[36m→ {$event->name} " . \json_encode($event->input, \JSON_UNESCAPED_SLASHES) . "\e[0m\n";
    } elseif ($event instanceof Event\ToolEnded) {
        echo $event->outcome instanceof Result
            ? "\e[2m← " . ($event->outcome->error ? 'error: ' : '') . \strtok($event->outcome->content[0]->text ?? '(media)', "\n") . "\e[0m\n"
            : "\e[31m← {$event->outcome->getMessage()}\e[0m\n";
        if (\str_starts_with($names[$event->id] ?? '', 'plan_')) {
            echo "\e[33m" . $plan->render() . "\e[0m\n";
        }
    } elseif ($event instanceof Event\TurnEnded) {
        echo "\n\e[2m[{$event->reason->value}" . ($event->error ? ": {$event->error->value}" : '') . ", {$event->usage->input} in, {$event->usage->output} out]\e[0m\n";
    }
    // A thinking model's reasoning (Event\ReasoningDelta) is never printed.
}

fiber\run(function () use ($run, $task, &$names, $plan) {
    foreach ($run->turn(new Message(Role::User, [new Text($task)])) as $event) {
        render($event, $names, $plan);
    }
    if (Harness\Reason::Failed === $run->ended()->reason) {
        echo "\e[31m{$run->ended()->error->getMessage()}\e[0m\n";
    }
});
