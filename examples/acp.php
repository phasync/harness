<?php

declare(strict_types=1);

/*
 * An Agent Client Protocol agent: an editor that speaks ACP (Zed, or any ACP client) starts this
 * file as its agent and talks JSON-RPC to it over stdio. Each session gets the file tools and a
 * shell in its working directory under a Policy: reads and a few shell commands run, writes and
 * other commands ask the editor's user. Sessions are kept in a directory so the editor can load
 * them again. Nothing is written to stdout but the protocol.
 *
 *   HARNESS_OPENAI_URL=http://127.0.0.1:8012/v1 HARNESS_MODEL=qwen3.8 php examples/acp.php [session dir]
 *
 * A Zed agent_servers entry: {"command": "php", "args": ["/path/to/examples/acp.php"], "env": {...}}
 */

require __DIR__ . '/../vendor/autoload.php';

use Harness\Acp\Agent;
use Harness\Http\StreamTransport;
use Harness\Options;
use Harness\Policy\Policy;
use Harness\Policy\Rule;
use Harness\Policy\Shell;
use Harness\Policy\Verdict;
use Harness\Provider\OpenAI\ChatCompletions;
use Harness\Run;
use Harness\Tools;
use Harness\Tools\Files;
use Harness\Tools\Shell\Shell as ShellTool;

// This repository installs two adapters for its tests; an application installs one and skips this line.
fiber\setup(new FiberInterop\Phasync\Adapter());

$url   = \getenv('HARNESS_OPENAI_URL') ?: exit("Set HARNESS_OPENAI_URL (and HARNESS_MODEL)\n");
$model = new ChatCompletions(new StreamTransport(), $url, \getenv('HARNESS_MODEL') ?: 'default');
$dir   = $argv[1] ?? null;

$policy = new Policy([
    Rule::shell([
        Shell::allow('ls'), Shell::allow('cat'), Shell::allow('grep'), Shell::allow('wc'),
        Shell::allow('git', ['status', 'diff', 'log', 'show'], flags: ['--stat', '-p', '--oneline', '-n']),
        Shell::deny('rm'),
    ]),
    Rule::hints(Verdict::Allow, readOnly: true),
]);                                                 // anything else: ask the editor's user

$agent = new Agent(function (string $cwd) use ($model, $policy): Run {
    $root = new Files\Root($cwd);

    return new Run(
        $model,
        new Tools(new Files\ListDir($root), new Files\ReadFile($root), new Files\Grep($root), new Files\WriteFile($root), new Files\EditFile($root), new ShellTool($cwd)),
        system: "You are a coding agent working in $cwd. Read before you change anything; keep changes small.\n\n" . $policy->describe(),
        middleware: [$policy],
        options: new Options(maxOutputTokens: 2000),
    );
}, 'harness-example', '0.0.1-beta6', $dir);

fiber\run(fn () => $agent->serve());
