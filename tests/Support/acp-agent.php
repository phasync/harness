<?php

declare(strict_types=1);

// An ACP agent over stdio with scripted models, for tests/Unit/AcpTest.php:
//   php acp-agent.php <scenario> [session dir]
// Scenarios: chat (reasoning and text), tools (file tools and a shell under a Policy), subagent (a
// child whose write needs approval), slow (a long reply to cancel), question (a tool that asks).
require __DIR__ . '/../../vendor/autoload.php';

use Harness\Acp\Agent;
use Harness\Agents\AgentTool;
use Harness\Call;
use Harness\ClosureTool;
use Harness\Delta\Finish;
use Harness\Delta\ReasoningDelta;
use Harness\Delta\TextDelta;
use Harness\Delta\ToolCallEnd;
use Harness\Delta\ToolCallStart;
use Harness\Delta\ToolInputDelta;
use Harness\FinishReason;
use Harness\Hints;
use Harness\Policy\Policy;
use Harness\Policy\Rule;
use Harness\Policy\Shell;
use Harness\Policy\Verdict;
use Harness\Question;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;
use Harness\Tools\Files;
use Harness\Tools\Shell\Shell as ShellTool;
use Harness\ToolSpec;
use Harness\Usage;

\fiber\setup(match (\getenv('HARNESS_ADAPTER') ?: 'phasync') {
    'phasync' => new FiberInterop\Phasync\Adapter(),
    'revolt'  => new FiberInterop\Revolt\Adapter(),
});

[, $scenario, $dir] = $argv + [null, null, null];

$say   = fn (string $text, FinishReason $finish = FinishReason::Stop) => [new TextDelta($text), new Usage(10, 5), new Finish($finish)];
$calls = function (array ...$calls): array {
    $deltas = [];
    foreach ($calls as [$id, $name, $input]) {
        $deltas[] = new ToolCallStart($id, $name);
        $deltas[] = new ToolInputDelta($id, \json_encode((object) $input));
        $deltas[] = new ToolCallEnd($id);
    }

    return [...$deltas, new Usage(10, 5), new Finish(FinishReason::ToolCalls)];
};

$runs = match ($scenario) {
    'chat' => fn (string $cwd) => new Run(new FakeModel(
        [new ReasoningDelta('Thinking it over.'), new TextDelta('Hello '), new TextDelta('there.'), new Usage(10, 5), new Finish(FinishReason::Stop)],
        $say('Second answer.', FinishReason::Length),
    ), new Tools()),
    'tools' => function (string $cwd) use ($calls, $say) {
        $root   = new Files\Root($cwd);
        $policy = new Policy([
            Rule::shell([Shell::allow('echo'), Shell::deny('rm')]),
            Rule::hints(Verdict::Allow, readOnly: true),
            Rule::tool('write_file', Verdict::Ask),
            Rule::tool('edit_file', Verdict::Ask),
        ], Verdict::Deny);

        return new Run(new FakeModel(
            $calls(['r1', 'read_file', ['path' => 'a.txt']], ['s1', 'shell', ['command' => 'echo hi']]),
            $calls(['w1', 'write_file', ['path' => 'a.txt', 'content' => "new\n"]], ['e1', 'edit_file', ['path' => 'b.txt', 'old' => 'one', 'new' => 'two']]),
            $calls(['w2', 'write_file', ['path' => 'c.txt', 'content' => "c\n"]], ['x1', 'shell', ['command' => 'rm -rf /']]),
            $say('All done.'),
        ), new Tools(new Files\ReadFile($root), new Files\WriteFile($root), new Files\EditFile($root), new ShellTool($cwd)), middleware: [$policy]);
    },
    'subagent' => function (string $cwd) use ($calls, $say) {
        $root  = new Files\Root($cwd);
        $child = new Run(new FakeModel(
            $calls(['cw', 'write_file', ['path' => 'note.txt', 'content' => "from the child\n"]]),
            $say('Wrote it.'),
        ), new Tools(new Files\WriteFile($root)), middleware: [new Policy([Rule::tool('write_file', Verdict::Ask)])]);

        return new Run(new FakeModel(
            $calls(['a1', 'writer', ['prompt' => 'write the note']]),
            $say('The writer is done.'),
        ), new Tools(new AgentTool($child, 'writer', 'Writes notes')), middleware: [new Policy([Rule::tool('writer', Verdict::Allow)])]);
    },
    'slow' => fn (string $cwd) => new Run(new FakeModel(function () {
        for ($i = 0; $i < 400; ++$i) {
            yield new TextDelta("word$i ");
            \fiber\sleep(0.01);
        }
        yield new Finish(FinishReason::Stop);
    }), new Tools()),
    'question' => fn (string $cwd) => new Run(new FakeModel(
        $calls(['q1', 'ask', []]),
        $say('Fine.'),
    ), new Tools(new ClosureTool(
        new ToolSpec('ask', 'Asks the user', ['type' => 'object'], new Hints(readOnly: true)),
        fn (array $in, Call $call) => [] === $call->answers() ? $call->needsInput([new Question('colour', 'Which colour?')]) : Result::text('declined: ' . $call->answers()['colour']->reason),
    ))),
};

\fiber\run(fn () => (new Agent($runs, 'harness-test', '0.0.0', $dir))->serve());
