<?php

declare(strict_types=1);

use Harness\Agents\AgentTool;
use Harness\Result;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Tools;

it('agent tool with empty prompt throws Retry for invalid input', function () {
    $child  = new Run(new FakeModel(says('ok')), new Tools());
    $agent  = new AgentTool($child, 'test', 'Test agent');
    $parent = new Run(new FakeModel(calls(['p1', 'test', ['prompt' => '']]), says('done')), new Tools($agent));

    $events = turn($parent, user('test'));

    // The empty prompt should cause a Retry, which results in an error message in the tool result.
    // The tool error result contains the Retry message
    $toolResults = \array_values(\array_filter(
        \array_merge(...\array_map(static fn ($m) => $m->parts, $parent->messages())),
        static fn ($p) => $p instanceof Harness\Part\ToolResult,
    ));

    expect($toolResults)->toHaveCount(1)
        ->and($toolResults[0]->error)->toBeTrue()
        ->and($toolResults[0]->content[0]->text)->toContain("'prompt' is required");
});
