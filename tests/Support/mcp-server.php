<?php

declare(strict_types=1);

/*
 * A tiny MCP server (revision 2026-07-28) over stdio, in plain PHP, for the Mcp module's tests and
 * examples/mcp.php: newline-delimited JSON-RPC on stdin/stdout, one request at a time, except that
 * `wait` answers only after the next `release`, so responses can arrive out of order.
 */

const VERSION = '2026-07-28';

$lists     = 0;
$cancelled = [];
$held      = null;

function send(array $message): void
{
    \fwrite(\STDOUT, \json_encode($message, \JSON_UNESCAPED_SLASHES) . "\n");
}

function text(string $text): array
{
    return ['resultType' => 'complete', 'content' => [['type' => 'text', 'text' => $text]]];
}

$tools = [
    ['name'           => 'echo', 'description' => 'Repeats the text', 'annotations' => ['readOnlyHint' => true],
        'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
    ['name'           => 'files.read', 'description' => 'Reads a file', 'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
    ['name' => 'image', 'description' => 'A tiny image', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'fail', 'description' => 'Always fails', 'annotations' => ['destructiveHint' => false, 'idempotentHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'ask', 'description' => 'Asks the user for a colour and a count', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'confirm', 'description' => 'Asks the user to confirm', 'inputSchema' => ['type' => 'object']],
    ['name' => 'poll', 'description' => 'Needs one retry with its state', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'slow', 'description' => 'Reports progress, then finishes', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'wait', 'description' => 'Answers after the next release', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'release', 'description' => 'Releases a waiting call', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'hang', 'description' => 'Never answers', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'stats', 'description' => 'How often tools/list was called, and the cancelled requests', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name' => 'structured', 'description' => 'Structured content only', 'annotations' => ['readOnlyHint' => true], 'inputSchema' => ['type' => 'object']],
    ['name'           => 'regional', 'description' => 'Mirrors its region into a header', 'annotations' => ['readOnlyHint' => true],
        'inputSchema' => ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]],
    ['name'           => 'broken', 'description' => 'An invalid x-mcp-header: clients leave it out',
        'inputSchema' => ['type' => 'object', 'properties' => ['n' => ['type' => 'number', 'x-mcp-header' => 'N']]]],
];

while (($line = \fgets(\STDIN)) !== false) {
    $m = \json_decode($line, true);
    if (!isset($m['id'])) {
        if ('notifications/cancelled' === ($m['method'] ?? null)) {
            $cancelled[] = $m['params']['requestId'];
        }
        continue;
    }
    $id     = $m['id'];
    $params = $m['params'] ?? [];
    if (VERSION !== ($params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null)) {
        send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32022, 'message' => 'Unsupported protocol version', 'data' => ['supported' => [VERSION]]]]);
        continue;
    }
    $result = match ($m['method']) {
        'server/discover' => ['resultType' => 'complete', 'supportedVersions' => [VERSION], 'capabilities' => ['tools' => new stdClass()],
            '_meta'                        => ['io.modelcontextprotocol/serverInfo' => ['name' => 'harness-test', 'version' => '1']], 'instructions' => 'Test tools.', 'ttlMs' => 60000, 'cacheScope' => 'public'],
        'tools/list' => (static function () use (&$lists, $tools) {
            ++$lists;

            return ['resultType' => 'complete', 'tools' => $tools, 'ttlMs' => 60000, 'cacheScope' => 'public'];
        })(),
        'tools/call' => null,
        default      => ['error' => ['code' => -32601, 'message' => "Method not found: {$m['method']}"]],
    };
    if (null !== $result) {
        send(isset($result['error']) ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => $result['error']] : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        continue;
    }
    $args  = $params['arguments'] ?? [];
    $token = $params['_meta']['progressToken'] ?? null;
    $state = isset($params['requestState']) ? \json_decode(\base64_decode($params['requestState'], true), true) : null;
    $input = $params['inputResponses'] ?? null;
    switch ($params['name']) {
        case 'echo':
            $result = text($args['text']);
            break;
        case 'files.read':
            $result = ['resultType' => 'complete', 'content' => [
                ['type' => 'resource_link', 'uri' => 'file:///a.txt', 'name' => 'a.txt', 'title' => 'File A'],
                ['type' => 'resource', 'resource' => ['uri' => 'file:///a.txt', 'mimeType' => 'text/plain', 'text' => 'contents of a']],
                ['type' => 'resource', 'resource' => ['uri' => 'file:///b.bin', 'mimeType' => 'application/octet-stream', 'blob' => \base64_encode("\x00\x01")]],
            ]];
            break;
        case 'image':
            $result = ['resultType' => 'complete', 'content' => [['type' => 'image', 'mimeType' => 'image/png', 'data' => \base64_encode('PNGDATA')], ['type' => 'audio', 'mimeType' => 'audio/wav', 'data' => \base64_encode('WAV')]]];
            break;
        case 'fail':
            $result = ['content' => [['type' => 'text', 'text' => 'it failed']], 'isError' => true];
            break;
        case 'ask':
            if (null === $input || !isset($input['prefs']['content'])) {
                $result = ['resultType' => 'input_required', 'requestState' => \base64_encode(\json_encode(['asked' => 'prefs'])), 'inputRequests' => ['prefs' => [
                    'method' => 'elicitation/create',
                    'params' => ['mode' => 'form', 'message' => 'Your preferences', 'requestedSchema' => ['type' => 'object', 'properties' => [
                        'colour' => ['type' => 'string', 'title' => 'Colour', 'enum' => ['red', 'blue']],
                        'count'  => ['type' => 'integer', 'description' => 'How many'],
                    ], 'required' => ['colour', 'count']]],
                ]]];
                break;
            }
            $c      = $input['prefs']['content'];
            $result = text("colour {$c['colour']}, count " . \var_export($c['count'], true) . ', state ' . ($state['asked'] ?? 'none'));
            break;
        case 'confirm':
            $result = null === $input
                ? ['resultType' => 'input_required', 'inputRequests' => ['ok' => ['method' => 'elicitation/create', 'params' => ['message' => 'Go ahead?', 'requestedSchema' => ['type' => 'object', 'properties' => new stdClass()]]]]]
                : text("confirmed: {$input['ok']['action']}");
            break;
        case 'poll':
            $result = null === $state
                ? ['resultType' => 'input_required', 'requestState' => \base64_encode(\json_encode(['round' => 1]))]
                : text("done after round {$state['round']}");
            break;
        case 'slow':
            if (null !== $token) {
                foreach ([1, 2] as $p) {
                    send(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => $token, 'progress' => $p, 'total' => 2, 'message' => "step $p"]]);
                    \usleep(20000);
                }
            }
            $result = text('slow done');
            break;
        case 'wait':
            $held = $id;
            continue 2;
        case 'release':
            send(['jsonrpc' => '2.0', 'id' => $id, 'result' => text('released')]);
            if (null !== $held) {
                send(['jsonrpc' => '2.0', 'id' => $held, 'result' => text('waited')]);
                $held = null;
            }
            continue 2;
        case 'hang':
            continue 2;
        case 'stats':
            $result = text(\json_encode(['lists' => $lists, 'cancelled' => $cancelled]));
            break;
        case 'structured':
            $result = ['resultType' => 'complete', 'structuredContent' => ['temperature' => 21.5]];
            break;
        case 'regional':
            $result = text('region ' . ($args['region'] ?? 'none'));
            break;
        default:
            send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => "Unknown tool: {$params['name']}"]]);
            continue 2;
    }
    send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}
