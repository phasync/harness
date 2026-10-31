<?php

declare(strict_types=1);

use Harness\Tests\Support\AcpClient;

beforeEach(function () {
    $this->dir = \sys_get_temp_dir() . '/harness-acp-' . \bin2hex(\random_bytes(4));
    \mkdir($this->dir);
    \mkdir($this->dir . '/sessions');
});

afterEach(function () {
    foreach ([...\glob($this->dir . '/sessions/*'), ...\glob($this->dir . '/*.txt')] as $f) {
        \unlink($f);
    }
    \rmdir($this->dir . '/sessions');
    \rmdir($this->dir);
});

/** The test agent for a scenario; the client's permission answers and update hook. */
function acp(string $scenario, ?string $sessions = null, ?Closure $permission = null, ?Closure $onUpdate = null): AcpClient
{
    return new AcpClient([\PHP_BINARY, \dirname(__DIR__) . '/Support/acp-agent.php', $scenario, ...(null === $sessions ? [] : [$sessions])], $permission, $onUpdate);
}

/** Starts a session in $cwd and returns its id. */
function session(AcpClient $client, string $cwd): string
{
    $client->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => ['fs' => ['readTextFile' => false, 'writeTextFile' => false], 'terminal' => false]]);

    return $client->request('session/new', ['cwd' => $cwd, 'mcpServers' => []])['sessionId'];
}

/** @return array<string, mixed> the last update of one tool call */
function lastOf(AcpClient $client, string $id): array
{
    $updates = updatesOf($client, $id);

    return $updates[\count($updates) - 1];
}

/** @return list<array<string, mixed>> the updates of one tool call, in order */
function updatesOf(AcpClient $client, string $id): array
{
    return \array_values(\array_filter($client->updates, fn (array $u) => ($u['toolCallId'] ?? null) === $id));
}

it('initializes, streams a prompt\'s text and reasoning as chunks, and maps the stop reason', function () {
    $client = acp('chat');
    $init   = \fiber\run(fn () => $client->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new stdClass()]));
    expect($init)->toBe([
        'protocolVersion'   => 1,
        'agentCapabilities' => ['loadSession' => false, 'promptCapabilities' => ['image' => false, 'audio' => false, 'embeddedContext' => true], 'mcpCapabilities' => ['http' => false, 'sse' => false]],
        'authMethods'       => [],
        'agentInfo'         => ['name' => 'harness-test', 'version' => '0.0.0'],
    ]);
    [$first, $second] = \fiber\run(function () use ($client) {
        $id = $client->request('session/new', ['cwd' => $this->dir, 'mcpServers' => []])['sessionId'];

        return [
            $client->request('session/prompt', ['sessionId' => $id, 'prompt' => [['type' => 'text', 'text' => 'hi']]]),
            $client->request('session/prompt', ['sessionId' => $id, 'prompt' => [['type' => 'text', 'text' => 'again'], ['type' => 'resource', 'resource' => ['uri' => 'file:///x.txt', 'text' => 'x']]]]),
        ];
    });
    expect($first)->toBe(['stopReason' => 'end_turn'])
        ->and($second)->toBe(['stopReason' => 'max_tokens'])
        ->and(\array_slice($client->updates, 0, 3))->toBe([
            ['sessionUpdate' => 'agent_thought_chunk', 'content' => ['type' => 'text', 'text' => 'Thinking it over.']],
            ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => 'Hello ']],
            ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => 'there.']],
        ])
        ->and($client->close())->toBe(0);
});

it('answers misuse with JSON-RPC errors', function () {
    $client = acp('chat');
    $errors = \fiber\run(function () use ($client) {
        $id = session($client, $this->dir);

        return [
            $client->request('session/frobnicate', []),
            $client->request('session/new', ['cwd' => 'relative/dir']),
            $client->request('session/prompt', ['sessionId' => 'sess_nope', 'prompt' => [['type' => 'text', 'text' => 'x']]]),
            $client->request('session/prompt', ['sessionId' => $id, 'prompt' => [['type' => 'image', 'data' => 'AAAA', 'mimeType' => 'image/png']]]),
            $client->request('session/load', ['sessionId' => $id, 'cwd' => $this->dir, 'mcpServers' => []]),
        ];
    });
    expect(\array_map(fn (array $e) => $e['error']['code'], $errors))->toBe([-32601, -32602, -32602, -32602, -32601])
        ->and($errors[1]['error']['message'])->toContain('absolute path')
        ->and($errors[3]['error']['message'])->toContain('text, resource_link and resource blocks')
        ->and($client->close())->toBe(0);
});

it('maps tool calls with their kind, locations and diffs, and permissions with the "always" options remembered', function () {
    \file_put_contents($this->dir . '/a.txt', "old\n");
    \file_put_contents($this->dir . '/b.txt', "one\n");
    $answers = ['allow_always', 'reject_once'];
    $client  = acp('tools', permission: function (array $params) use (&$answers) {
        return ['outcome' => ['outcome' => 'selected', 'optionId' => \array_shift($answers)]];
    });
    $result = \fiber\run(fn () => $client->request('session/prompt', ['sessionId' => session($client, $this->dir), 'prompt' => [['type' => 'text', 'text' => 'go']]]));
    $abs    = fn (string $f) => $this->dir . '/' . $f;

    expect($result)->toBe(['stopReason' => 'end_turn'])
        ->and(updatesOf($client, 'r1')[0])->toBe(['sessionUpdate' => 'tool_call', 'toolCallId' => 'r1', 'title' => 'read_file a.txt', 'kind' => 'read', 'status' => 'pending', 'rawInput' => ['path' => 'a.txt'], 'locations' => [['path' => $abs('a.txt')]]])
        ->and(\array_column(updatesOf($client, 'r1'), 'status'))->toBe(['pending', 'in_progress', 'completed'])
        ->and(updatesOf($client, 's1')[0]['kind'])->toBe('execute')
        ->and(updatesOf($client, 's1')[0]['title'])->toBe('shell echo hi')
        // The shell's output streams as text content, then the result.
        ->and(\array_column(updatesOf($client, 's1'), 'status'))->toContain('completed')
        ->and(lastOf($client, 's1')['content'])->toBe([['type' => 'content', 'content' => ['type' => 'text', 'text' => "Exit code 0\nhi\n"]]]);

    // Two approvals asked at once; the write is allowed always, the edit rejected once.
    expect($client->permissions)->toHaveCount(2)
        ->and($client->permissions[0]['toolCall'])->toBe(['toolCallId' => 'w1', 'title' => "the tool 'write_file'", 'kind' => 'edit', 'status' => 'pending', 'rawInput' => ['path' => 'a.txt', 'content' => "new\n"]])
        ->and(\array_column($client->permissions[0]['options'], 'kind'))->toBe(['allow_once', 'allow_always', 'reject_once', 'reject_always'])
        ->and(updatesOf($client, 'w1')[0]['content'])->toBe([['type' => 'diff', 'path' => $abs('a.txt'), 'oldText' => "old\n", 'newText' => "new\n"]])
        ->and(updatesOf($client, 'e1')[0]['content'])->toBe([['type' => 'diff', 'path' => $abs('b.txt'), 'oldText' => 'one', 'newText' => 'two']])
        ->and(lastOf($client, 'w1')['status'])->toBe('completed')
        ->and(lastOf($client, 'e1')['status'])->toBe('failed')
        ->and(lastOf($client, 'e1')['content'][1]['content']['text'])->toBe('Denied: The user rejected it')
        ->and(\file_get_contents($abs('a.txt')))->toBe("new\n")
        ->and(\file_get_contents($abs('b.txt')))->toBe("one\n")
        // The second write was allowed by "always", without asking; the policy denied rm.
        ->and(\file_get_contents($abs('c.txt')))->toBe("c\n")
        ->and(lastOf($client, 'x1')['content'][0]['content']['text'])->toBe("Denied by the policy rule: the shell command 'rm' is never run")
        ->and($client->close())->toBe(0);
});

it('flattens a sub-agent\'s pause into a permission request, and shows its calls under prefixed ids', function () {
    $client = acp('subagent', permission: fn (array $params) => ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow_once']]);
    $result = \fiber\run(fn () => $client->request('session/prompt', ['sessionId' => session($client, $this->dir), 'prompt' => [['type' => 'text', 'text' => 'go']]]));
    expect($result)->toBe(['stopReason' => 'end_turn'])
        ->and($client->permissions)->toHaveCount(1)
        ->and($client->permissions[0]['toolCall']['toolCallId'])->toBe('a1')
        ->and($client->permissions[0]['toolCall']['title'])->toBe("the tool 'write_file'")
        ->and(updatesOf($client, 'a1/cw')[0]['title'])->toBe('write_file note.txt')
        ->and(lastOf($client, 'a1/cw')['status'])->toBe('completed')
        ->and(lastOf($client, 'a1')['status'])->toBe('completed')
        ->and(\file_get_contents($this->dir . '/note.txt'))->toBe("from the child\n")
        // The child's text is in its result, not the session's message chunks.
        ->and(\implode('', \array_map(fn ($u) => $u['content']['text'], \array_filter($client->updates, fn ($u) => 'agent_message_chunk' === $u['sessionUpdate']))))->toBe('The writer is done.')
        ->and($client->close())->toBe(0);
});

it('cancels a running prompt on session/cancel, and a pending permission answered "cancelled"', function () {
    $sent   = false;
    $client = acp('slow', onUpdate: function (array $update, AcpClient $client) use (&$sent) {
        if (!$sent && 'agent_message_chunk' === $update['sessionUpdate']) {
            $sent = true;
            $client->notify('session/cancel', ['sessionId' => $GLOBALS['acpSession']]);
        }
    });
    $result = \fiber\run(function () use ($client) {
        $GLOBALS['acpSession'] = session($client, $this->dir);

        return $client->request('session/prompt', ['sessionId' => $GLOBALS['acpSession'], 'prompt' => [['type' => 'text', 'text' => 'talk']]]);
    });
    expect($result)->toBe(['stopReason' => 'cancelled'])
        ->and(\count($client->updates))->toBeLessThan(100)
        ->and($client->close())->toBe(0);

    \file_put_contents($this->dir . '/a.txt', "old\n");
    \file_put_contents($this->dir . '/b.txt', "one\n");
    $client = acp('tools', permission: fn () => ['outcome' => ['outcome' => 'cancelled']]);
    $result = \fiber\run(fn () => $client->request('session/prompt', ['sessionId' => session($client, $this->dir), 'prompt' => [['type' => 'text', 'text' => 'go']]]));
    expect($result)->toBe(['stopReason' => 'cancelled'])
        ->and($client->permissions)->toHaveCount(1)
        ->and(\file_get_contents($this->dir . '/a.txt'))->toBe("old\n")
        ->and($client->close())->toBe(0);
});

it('declines a question, which ACP cannot ask', function () {
    $client = acp('question');
    $result = \fiber\run(fn () => $client->request('session/prompt', ['sessionId' => session($client, $this->dir), 'prompt' => [['type' => 'text', 'text' => 'go']]]));
    expect($result)->toBe(['stopReason' => 'end_turn'])
        ->and(lastOf($client, 'q1')['content'][0]['content']['text'])->toBe('declined: The client cannot answer questions (ACP has no way to ask one): Which colour?')
        ->and($client->close())->toBe(0);
});

it('keeps sessions in a directory and replays one on session/load in a new process', function () {
    $sessions = $this->dir . '/sessions';
    $client   = acp('chat', $sessions);
    $id       = \fiber\run(function () use ($client) {
        $id = session($client, $this->dir);
        $client->request('session/prompt', ['sessionId' => $id, 'prompt' => [['type' => 'text', 'text' => 'hi']]]);

        return $id;
    });
    expect($client->close())->toBe(0);

    $again  = acp('chat', $sessions);
    $result = \fiber\run(function () use ($again, $id) {
        $init = $again->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => new stdClass()]);
        expect($init['agentCapabilities']['loadSession'])->toBeTrue();
        $loaded = $again->request('session/load', ['sessionId' => $id, 'cwd' => $this->dir, 'mcpServers' => []]);

        return [$loaded, $again->request('session/prompt', ['sessionId' => $id, 'prompt' => [['type' => 'text', 'text' => 'more']]])];
    });
    expect($result[0])->toBe([])
        ->and(\array_slice($again->updates, 0, 3))->toBe([
            ['sessionUpdate' => 'user_message_chunk', 'content' => ['type' => 'text', 'text' => 'hi']],
            ['sessionUpdate' => 'agent_thought_chunk', 'content' => ['type' => 'text', 'text' => 'Thinking it over.']],
            ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => 'Hello there.']],
        ])
        // A new process's scripted model starts over: its first reply answers the loaded conversation.
        ->and($result[1])->toBe(['stopReason' => 'end_turn'])
        ->and($again->close())->toBe(0);
});
