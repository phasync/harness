<?php

declare(strict_types=1);

namespace Harness\Mcp;

use Harness\Cancel;
use Harness\Hints;
use Harness\ToolSource;
use Harness\ToolSpec;

/**
 * An MCP server's tools as a `ToolSource`, under protocol revision 2026-07-28: every request is
 * stateless and carries the protocol version, client info and capabilities in `_meta`. The first
 * use calls `server/discover` and fails unless the server supports this revision; `tools/list`
 * (all pages) is cached for its `ttlMs`, so the `Run` reading the tools each step asks again only
 * when the list may have changed.
 *
 * Tool names get `$prefix`, and characters a `ToolSpec` name cannot hold become `_`; calls use the
 * server's own name, and two server tools whose names become the same are an `McpException`, as is
 * a tool whose `inputSchema` is not an object schema (MCP requires one).
 * `annotations` become `Hints` with MCP's defaults (`destructiveHint` and
 * `openWorldHint` true unless the server says otherwise). A tool whose `x-mcp-header` annotations
 * are invalid is left out, as the spec requires. The client declares form elicitation only.
 */
final class McpServer implements ToolSource
{
    public const VERSION = '2026-07-28';

    /** @var array<string, mixed>|null */
    private ?array $discovered = null;
    /** @var list<McpTool> */
    private array $tools   = [];
    private float $expires = 0.0;

    /** @param array{name: string, version: string} $clientInfo */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $prefix = '',
        private readonly array $clientInfo = ['name' => 'phasync/harness', 'version' => '0.0.1'],
    ) {
    }

    public function tools(): iterable
    {
        if (\microtime(true) >= $this->expires) {
            $this->discover();
            $tools  = [];
            $names  = []; // exposed name => the server's name: two server tools must not meet in one
            $cursor = null;
            $ttl    = null;
            $seen   = [];
            do {
                $page   = $this->request('tools/list', null === $cursor ? [] : ['cursor' => $cursor], new Cancel(static fn () => false));
                $ttl    = null === $ttl ? (int) ($page['ttlMs'] ?? 0) : \min($ttl, (int) ($page['ttlMs'] ?? 0));
                $cursor = $page['nextCursor'] ?? null;
                if (null !== $cursor && isset($seen[$cursor])) {
                    throw new McpException("The MCP server's tools/list pagination does not advance: it returned the cursor '$cursor' again");
                }
                $seen[$cursor ?? ''] = true;
                foreach ($page['tools'] ?? [] as $tool) {
                    $headers = self::headerPaths($tool['inputSchema'] ?? []);
                    if (null !== $headers) {
                        $spec = $this->spec($tool);
                        if (isset($names[$spec->name])) {
                            throw new McpException("The MCP server's tools '{$names[$spec->name]}' and '{$tool['name']}' both become '{$spec->name}' as a tool name (characters a name cannot hold become _, and it is cut at 64); their calls could not be told apart");
                        }
                        $names[$spec->name] = $tool['name'];
                        $tools[]            = new McpTool($this, $tool['name'], $spec, $headers);
                    }
                }
            } while (null !== $cursor);
            $this->tools   = $tools;
            $this->expires = \microtime(true) + $ttl / 1000;
        }

        return $this->tools;
    }

    /** The server's `instructions` from `server/discover`, for the system prompt; null when it gave none. */
    public function instructions(): ?string
    {
        return $this->discover()['instructions'] ?? null;
    }

    /**
     * @internal sends a request with this client's `_meta`
     *
     * @param array<string, mixed>                        $params
     * @param (\Closure(array<string, mixed>): void)|null $notify
     * @param array<string, string>                       $headers
     *
     * @return array<string, mixed>
     */
    public function request(string $method, array $params, Cancel $cancel, ?\Closure $notify = null, array $headers = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => self::VERSION,
            'io.modelcontextprotocol/clientInfo'         => $this->clientInfo,
            'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['form' => new \stdClass()]],
        ];

        return $this->connection->request($method, $params, $cancel, $notify, $headers);
    }

    /** @return array<string, mixed> */
    private function discover(): array
    {
        if (null === $this->discovered) {
            $result    = $this->request('server/discover', [], new Cancel(static fn () => false));
            $supported = $result['supportedVersions'] ?? [];
            if (!\in_array(self::VERSION, $supported, true)) {
                throw new McpException('The MCP server supports protocol versions [' . \implode(', ', $supported) . '], not ' . self::VERSION);
            }
            $this->discovered = $result;
        }

        return $this->discovered;
    }

    /** @param array<string, mixed> $tool */
    private function spec(array $tool): ToolSpec
    {
        $a        = $tool['annotations'] ?? [];
        $readOnly = (bool) ($a['readOnlyHint'] ?? false);
        $name     = \substr((string) \preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->prefix . $tool['name']), 0, 64);
        $schema   = $tool['inputSchema'] ?? ['type' => 'object'];
        if (($schema['type'] ?? null) !== 'object') {
            throw new McpException("The MCP server's tool '{$tool['name']}' has an input schema without \"type\": \"object\" at the top (" . \implode(', ', \array_keys((array) $schema)) . '); MCP requires an object schema, so a oneOf or $ref belongs inside its properties');
        }

        return new ToolSpec($name, (string) ($tool['description'] ?? $tool['title'] ?? ''), $schema, new Hints(
            readOnly: $readOnly,
            destructive: !$readOnly && (bool) ($a['destructiveHint'] ?? true),
            idempotent: (bool) ($a['idempotentHint'] ?? false),
            openWorld: (bool) ($a['openWorldHint'] ?? true),
        ));
    }

    /**
     * The `x-mcp-header` annotations of an input schema: header name => property path. Null when
     * one is invalid (not a token, not a string, integer or boolean, or not unique).
     *
     * @param array<string, mixed> $schema
     * @param list<string>         $path
     *
     * @return array<string, list<string>>|null
     */
    private static function headerPaths(array $schema, array $path = [], array &$seen = []): ?array
    {
        $found = [];
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (!\is_array($property)) {
                continue;
            }
            if (isset($property['x-mcp-header'])) {
                $header = $property['x-mcp-header'];
                if (!\is_string($header) || 1 !== \preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $header)
                    || !\in_array($property['type'] ?? null, ['string', 'integer', 'boolean'], true) || isset($seen[\strtolower($header)])) {
                    return null;
                }
                $seen[\strtolower($header)] = true;
                $found[$header]             = [...$path, (string) $name];
            }
            $nested = self::headerPaths($property, [...$path, (string) $name], $seen);
            if (null === $nested) {
                return null;
            }
            $found += $nested;
        }

        return $found;
    }
}
