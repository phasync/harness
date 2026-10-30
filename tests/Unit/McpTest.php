<?php

declare(strict_types=1);

use function fiber\go;
use function fiber\sleep;
use Harness\Answer;
use Harness\Cancel;
use Harness\Hints;
use Harness\Http\Response;
use Harness\Mcp\HttpConnection;
use Harness\Mcp\McpException;
use Harness\Mcp\McpServer;
use Harness\Mcp\StdioConnection;
use Harness\Part\Media;
use Harness\Part\Reference;
use Harness\Part\Text;
use Harness\Question;
use Harness\Reason;
use Harness\Run;
use Harness\Testing\FakeModel;
use Harness\Testing\FakeTransport;
use Harness\Testing\TestCall;
use Harness\Tool;

function mcpServer(string $prefix = ''): McpServer
{
    return new McpServer(new StdioConnection([\PHP_BINARY, \dirname(__DIR__) . '/Support/mcp-server.php']), $prefix);
}

/** @return array<string, Tool> */
function mcpTools(McpServer $server): array
{
    $tools = [];
    foreach ($server->tools() as $tool) {
        $tools[$tool->spec()->name] = $tool;
    }

    return $tools;
}

it('lists a stdio server\'s tools with hints from their annotations, caching the list by ttlMs', function () {
    [$tools, $stats, $instructions] = \fiber\run(function () {
        $server = mcpServer('t_');
        $tools  = mcpTools($server);
        mcpTools($server);

        return [$tools, $tools['t_stats']->call([], TestCall::make())->content[0]->text, $server->instructions()];
    });
    expect(\array_keys($tools))->toContain('t_echo', 't_files_read', 't_regional')
        ->and(\array_keys($tools))->not->toContain('t_broken')
        ->and($tools['t_echo']->spec()->hints)->toEqual(new Hints(readOnly: true, openWorld: true))
        ->and($tools['t_files_read']->spec()->hints)->toEqual(new Hints(readOnly: true))
        ->and($tools['t_fail']->spec()->hints)->toEqual(new Hints(idempotent: true, openWorld: true))
        ->and($tools['t_confirm']->spec()->hints)->toEqual(new Hints(destructive: true, openWorld: true))
        ->and(\json_encode($tools['t_files_read']->spec()->schema))->toBe('{"type":"object","properties":{}}')
        ->and(\json_decode($stats, true)['lists'])->toBe(1)
        ->and($instructions)->toBe('Test tools.');
});

it('maps content, structured content and errors to results', function () {
    $r = \fiber\run(function () {
        $t = mcpTools(mcpServer());

        return \array_map(fn (string $n) => $t[$n]->call('echo' === $n ? ['text' => 'hi'] : [], TestCall::make()), ['echo' => 'echo', 'files' => 'files_read', 'image' => 'image', 'fail' => 'fail', 'structured' => 'structured']);
    });
    expect($r['echo']->content)->toEqual([new Text('hi')])
        ->and($r['files']->content)->toEqual([new Reference('file:///a.txt', 'File A'), new Text('contents of a'), new Media('application/octet-stream', "\x00\x01")])
        ->and($r['image']->content)->toEqual([new Media('image/png', 'PNGDATA'), new Media('audio/wav', 'WAV')])
        ->and($r['fail']->error)->toBeTrue()
        ->and($r['fail']->content)->toEqual([new Text('it failed')])
        ->and($r['structured']->value)->toBe(['temperature' => 21.5])
        ->and($r['structured']->content)->toEqual([new Text('{"temperature":21.5}')]);
});

it('turns input_required into questions, and resumes across a process boundary with inputResponses and requestState', function () {
    $model = fn () => new FakeModel(calls(['c1', 'ask', []]), says('ok'));
    $run   = \fiber\run(function () use ($model) {
        $run = new Run($model(), mcpServer());
        foreach ($run->turn(user('go')) as $e) {
        }

        return $run;
    });
    $pending = $run->ended()->pending;
    expect($run->ended()->reason)->toBe(Reason::AwaitingInput)
        ->and($pending[0]->needs)->toEqual([new Question('prefs/colour', 'Your preferences (Colour)', ['red', 'blue']), new Question('prefs/count', 'Your preferences (How many)')]);
    $stored = \json_decode(\json_encode(['m' => \array_map(fn ($m) => $m->toArray(), $run->messages()), 'p' => \array_map(fn ($p) => $p->toArray(), $pending)]), true);

    $text = \fiber\run(function () use ($stored) {
        $run = new Run(new FakeModel(says('thanks')), mcpServer(), messages: \array_map(Harness\Message::fromArray(...), $stored['m']), pending: \array_map(Harness\Pending::fromArray(...), $stored['p']));
        foreach ($run->turn([new Answer('c1', ['prefs/colour' => 'blue', 'prefs/count' => '3'])]) as $e) {
        }

        return $run->messages()[2]->parts[0]->content[0]->text;
    });
    expect($text)->toBe('colour blue, count 3, state prefs');
});

it('asks a field-less elicitation as accept or decline, and retries a state-only input_required at once', function () {
    [$confirm, $poll] = \fiber\run(function () {
        $t    = mcpTools(mcpServer());
        $call = TestCall::make();
        $t['confirm']->call([], $call);
        $q = $call->pending()->needs;
        $call->answer(['ok' => 'decline']);

        return [[$q, $t['confirm']->call([], $call)->content[0]->text], $t['poll']->call([], TestCall::make())->content[0]->text];
    });
    expect($confirm)->toEqual([[new Question('ok', 'Go ahead?', ['accept', 'decline'])], 'confirmed: decline'])
        ->and($poll)->toBe('done after round 1');
});

it('routes progress, out-of-order responses and cancellation over one stdio process', function () {
    [$progress, $waited, $released, $cancelled, $stats] = \fiber\run(function () {
        $t        = mcpTools(mcpServer());
        $progress = [];
        $t['slow']->call([], TestCall::make(emit: function ($e) use (&$progress) {
            $progress[] = $e->value;
        }));
        $wait     = go(fn () => $t['wait']->call([], TestCall::make())->content[0]->text);
        sleep(0.05);
        $released = $t['release']->call([], TestCall::make())->content[0]->text;
        $waited   = \fiber\await($wait);

        $flag   = false;
        $t0     = \microtime(true);
        $server = mcpServer();
        mcpTools($server);
        $req = go(function () use ($server, &$flag) {
            try {
                $server->request('tools/call', ['name' => 'hang', 'arguments' => new stdClass()], new Cancel(function () use (&$flag) {
                    return $flag;
                }));
            } catch (Harness\Cancelled $e) {
                return $e->getMessage();
            }
        });
        sleep(0.1);
        $flag      = true;
        $cancelled = [\fiber\await($req), \microtime(true) - $t0];
        $stats     = \json_decode(mcpTools($server)['stats']->call([], TestCall::make())->content[0]->text, true);

        return [$progress, $waited, $released, $cancelled, $stats];
    });
    expect($progress)->toBe([['progress' => 1, 'total' => 2, 'message' => 'step 1'], ['progress' => 2, 'total' => 2, 'message' => 'step 2']])
        ->and([$released, $waited])->toBe(['released', 'waited'])
        ->and($cancelled[0])->toBe('Cancelled while waiting for MCP tools/call')
        ->and($cancelled[1])->toBeLessThan(1.0)
        ->and($stats['cancelled'])->toHaveCount(1);
});

it('fails loudly when the server process dies', function () {
    $server = new McpServer(new StdioConnection([\PHP_BINARY, '-r', 'exit(3);']));
    expect(fn () => \fiber\run(fn () => \iterator_to_array($server->tools())))->toThrow(McpException::class, 'The MCP server exited (status 3)');
});

it('speaks Streamable HTTP: headers per request, JSON and event-stream responses', function () {
    $json      = fn (array $result) => fn ($url, $headers, $body) => new Response(200, ['content-type' => 'application/json'], [\json_encode(['jsonrpc' => '2.0', 'id' => \json_decode($body, true)['id'], 'result' => $result])]);
    $transport = new FakeTransport(
        $json(['resultType' => 'complete', 'supportedVersions' => ['2026-07-28'], 'capabilities' => ['tools' => []]]),
        $json(['tools' => [
            ['name' => 'regional', 'inputSchema' => ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]],
        ], 'ttlMs' => 0]),
        function ($url, $headers, $body) {
            $id = \json_decode($body, true)['id'];

            return new Response(200, ['content-type' => 'text/event-stream'], [
                'data: ' . \json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => $id, 'progress' => 1]]) . "\n\n",
                'data: ' . \json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => 'from SSE']]]]) . "\n\n",
            ]);
        },
        fn () => new Response(400, ['content-type' => 'application/json'], ['{"jsonrpc":"2.0","id":1,"error":{"code":-32020,"message":"Header mismatch"}}']),
    );
    $server                    = new McpServer(new HttpConnection($transport, 'https://mcp.example/mcp', ['Authorization' => 'Bearer k']));
    [$result, $events, $error] = \fiber\run(function () use ($server) {
        $tool   = \iterator_to_array($server->tools())[0];
        $events = [];
        $result = $tool->call(['region' => 'Hällo'], TestCall::make(emit: function ($e) use (&$events) {
            $events[] = $e->value;
        }));
        try {
            $tool->call(['region' => 'x'], TestCall::make());
        } catch (McpException $e) {
            return [$result, $events, [$e->rpcCode, $e->getMessage()]];
        }
    });
    $call = $transport->requests[2];
    $body = \json_decode($call['body'], true);
    expect($result->content)->toEqual([new Text('from SSE')])
        ->and($events)->toBe([['progress' => 1]])
        ->and($error)->toBe([-32020, 'MCP tools/call failed (-32020): Header mismatch'])
        ->and($call['url'])->toBe('https://mcp.example/mcp')
        ->and($call['headers'])->toMatchArray([
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method'           => 'tools/call',
            'Mcp-Name'             => 'regional',
            'Mcp-Param-Region'     => '=?base64?' . \base64_encode('Hällo') . '?=',
            'Accept'               => 'application/json, text/event-stream',
            'Authorization'        => 'Bearer k',
        ])
        ->and($transport->requests[0]['headers']['Mcp-Method'])->toBe('server/discover')
        ->and($transport->requests[0]['headers'])->not->toHaveKey('Mcp-Name')
        ->and($body['params']['_meta']['io.modelcontextprotocol/protocolVersion'])->toBe('2026-07-28')
        ->and($body['params']['_meta']['io.modelcontextprotocol/clientCapabilities'])->toBe(['elicitation' => ['form' => []]])
        ->and(\json_decode($transport->requests[0]['body'])->params->_meta->{'io.modelcontextprotocol/clientCapabilities'}->elicitation->form)->toEqual(new stdClass());
});

it('refuses a server that does not support 2026-07-28', function () {
    $transport = new FakeTransport(fn ($url, $headers, $body) => new Response(200, ['content-type' => 'application/json'], [\json_encode(['jsonrpc' => '2.0', 'id' => \json_decode($body, true)['id'], 'result' => ['supportedVersions' => ['2025-11-25']]])]));
    expect(fn () => \fiber\run(fn () => (new McpServer(new HttpConnection($transport, 'http://x/mcp')))->tools()))
        ->toThrow(McpException::class, 'supports protocol versions [2025-11-25], not 2026-07-28');
});
