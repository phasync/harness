<?php

declare(strict_types=1);

use Harness\Mcp\McpException;
use Harness\Mcp\McpServer;
use Harness\Mcp\StdioConnection;

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
