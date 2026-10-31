<?php

declare(strict_types=1);

use function fiber\go;
use Harness\Cancel;
use Harness\Http\Response;
use Harness\Mcp\HttpConnection;
use Harness\Mcp\McpException;
use Harness\Mcp\McpServer;
use Harness\Mcp\StdioConnection;
use Harness\Testing\FakeTransport;

it('rejects StdioConnection with empty command list', function () {
    expect(fn () => new StdioConnection([]))->toThrow(InvalidArgumentException::class, 'non-empty list');
});

it('throws McpException for a nonexistent command without leaking a PHP warning', function () {
    // proc_open() emits a real E_WARNING ("posix_spawn() failed") before StdioConnection notices
    // $process === false and throws; with phpunit.xml's failOnWarning=true that warning alone
    // fails the run even though the exception assertion below holds. StdioConnection::start()
    // does not suppress it (unlike Http\StreamTransport::guard()), so this currently fails.
    $warnings = [];
    \set_error_handler(function (int $no, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    }, \E_WARNING);
    try {
        $server = new McpServer(new StdioConnection(['/nonexistent/command']));
        expect(fn () => \fiber\run(fn () => \iterator_to_array($server->tools())))
            ->toThrow(McpException::class, 'Could not start the MCP server');
    } finally {
        \restore_error_handler();
    }
    expect($warnings)->toBe([]);
});

it('throws when HttpConnection receives a 500 response instead of JSON', function () {
    $json      = fn (array $result) => fn ($url, $headers, $body) => new Response(200, ['content-type' => 'application/json'], [\json_encode(['jsonrpc' => '2.0', 'id' => \json_decode($body, true)['id'], 'result' => $result])]);
    $transport = new FakeTransport(
        $json(['resultType' => 'complete', 'supportedVersions' => ['2026-07-28'], 'capabilities' => ['tools' => []]]),
        fn () => new Response(500, ['content-type' => 'text/plain'], ['Internal Server Error'])
    );
    $server = new McpServer(new HttpConnection($transport, 'https://example.com/mcp'));
    expect(fn () => \fiber\run(fn () => \iterator_to_array($server->tools())))
        ->toThrow(McpException::class, 'HTTP 500');
});

it('queues concurrent StdioConnection writes when two coroutines send at once', function () {
    // Test that StdioConnection.send() queues fibers when one is already writing.
    // This exercises lines 117-118 in StdioConnection: the waiting[] queue and await().
    // Checked against existing test: "routes progress, out-of-order responses and cancellation over one stdio process"
    // which also uses concurrent go() calls but with different focus (progress/cancellation).
    $results = \fiber\run(function () {
        $server = new McpServer(new StdioConnection([\PHP_BINARY, \dirname(__DIR__, 2) . '/Support/mcp-server.php']));
        $tools  = [];
        foreach ($server->tools() as $tool) {
            $tools[$tool->spec()->name] = $tool;
        }

        // Create two concurrent requests that will try to send at nearly the same time.
        // Both coroutines call tool->call() which calls request() which calls send().
        $c1 = go(fn () => $tools['echo']->call(['text' => 'first'], callFor())->content[0]->text);
        $c2 = go(fn () => $tools['echo']->call(['text' => 'second'], callFor())->content[0]->text);

        return [\fiber\await($c1), \fiber\await($c2)];
    });
    expect($results)->toEqual(['first', 'second']);
});

it('throws McpException when server responds with a JSON-RPC error', function () {
    // Test that StdioConnection.request() throws McpException::fromError() when the server
    // sends a JSON-RPC error response (e.g., unknown tool). This exercises line 84 in
    // StdioConnection: throw McpException::fromError($method, $message['error']);
    // Checked against existing test: "maps content, structured content and errors to results"
    // which tests error results from tools, not JSON-RPC error responses.
    $exception = \fiber\run(function () {
        $server = new McpServer(new StdioConnection([\PHP_BINARY, \dirname(__DIR__, 2) . '/Support/mcp-server.php']));
        try {
            // Call a non-existent tool, causing the server to send a JSON-RPC error.
            // The test server sends error code -32602 for unknown tools.
            $server->request('tools/call', ['name' => 'nonexistent_tool', 'arguments' => new stdClass()], new Cancel(fn () => false));
        } catch (McpException $e) {
            return [$e->rpcCode, \str_contains($e->getMessage(), 'Unknown tool')];
        }
    });
    expect($exception)->toEqual([-32602, true]);
});

it('throws McpException when server writes non-JSON to stdout', function () {
    // Test that StdioConnection.dispatch() throws when the server sends a line that is not valid JSON.
    // This exercises lines 199-200 in StdioConnection: catch JsonException and throw McpException.
    // Checked against existing test: "speaks Streamable HTTP: headers per request, JSON and event-stream responses"
    // which tests non-JSON responses from HTTP servers, not stdio servers.

    // Create a tiny inline server that emits invalid JSON.
    $tmpfile = \sys_get_temp_dir() . '/mcp-invalid-' . \bin2hex(\random_bytes(4)) . '.php';
    \file_put_contents($tmpfile, '<?php \fwrite(\STDOUT, "not valid json\n");');

    try {
        $exception = \fiber\run(function () use ($tmpfile) {
            $server = new McpServer(new StdioConnection([\PHP_BINARY, $tmpfile]));
            try {
                // The server will emit "not valid json\n" immediately.
                // When StdioConnection tries to decode it, JsonException is thrown and caught.
                $server->request('server/discover', [], new Cancel(fn () => false));
            } catch (McpException $e) {
                return \str_contains($e->getMessage(), 'not JSON-RPC');
            }
        });
        expect($exception)->toBeTrue();
    } finally {
        if (\is_file($tmpfile)) {
            \unlink($tmpfile);
        }
    }
});

it('throws McpException when tool result contains unknown content type', function () {
    // Test that McpTool.result() throws when the server returns a content item with an unknown type.
    // This exercises line 184 in McpTool: default => throw new McpException('...unknown type...')
    // The result() method handles 'text', 'image', 'audio', 'resource', 'resource_link', but
    // any other type causes an exception. This is the most user-visible uncovered line in McpTool.
    // Checked against existing test: "maps content, structured content and errors to results"
    // which tests known content types (text, resource_link, resource, image, audio), not unknown ones.

    $json      = fn (array $result) => fn ($url, $headers, $body) => new Response(200, ['content-type' => 'application/json'], [\json_encode(['jsonrpc' => '2.0', 'id' => \json_decode($body, true)['id'], 'result' => $result])]);
    $transport = new FakeTransport(
        $json(['resultType' => 'complete', 'supportedVersions' => ['2026-07-28'], 'capabilities' => ['tools' => []]]),
        $json(['tools' => [['name' => 'test', 'inputSchema' => ['type' => 'object']]]]),
        $json(['resultType' => 'complete', 'content' => [['type' => 'unknown_type', 'data' => 'some data']]])
    );
    $server = new McpServer(new HttpConnection($transport, 'https://example.com/mcp'));

    $exception = \fiber\run(function () use ($server) {
        $tools = [];
        foreach ($server->tools() as $tool) {
            $tools[$tool->spec()->name] = $tool;
        }
        try {
            $tools['test']->call([], callFor());
        } catch (McpException $e) {
            return \str_contains($e->getMessage(), 'unknown type');
        }
    });
    expect($exception)->toBeTrue();
});
