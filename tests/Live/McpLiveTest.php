<?php

declare(strict_types=1);

use Harness\Mcp\McpServer;
use Harness\Mcp\StdioConnection;

// Live: HARNESS_MCP_COMMAND='npx -y @modelcontextprotocol/server-everything' vendor/bin/pest --group live
// A server speaking revision 2026-07-28 over stdio; the command runs under /bin/sh.
it('lists the tools of a real MCP server', function () {
    $tools = \fiber\run(fn () => \iterator_to_array((new McpServer(new StdioConnection(['/bin/sh', '-c', (string) \getenv('HARNESS_MCP_COMMAND')])))->tools(), false));
    expect($tools)->not->toBeEmpty();
})->group('live')->skip(false === \getenv('HARNESS_MCP_COMMAND'), 'HARNESS_MCP_COMMAND is not set');
