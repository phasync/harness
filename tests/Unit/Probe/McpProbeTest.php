<?php

declare(strict_types=1);

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
