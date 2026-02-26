<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Mcp;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Genaker\MagentoMcpAi\Api\Mcp\McpServerInterface;

/**
 * Wraps a single MCP tool so it can be used transparently as a DatabaseToolInterface.
 *
 * Tool names follow the convention:  mcp__{serverName}__{toolName}
 * Double underscores act as unambiguous delimiters even when tool names
 * contain single underscores (e.g. read_file → mcp__filesystem__read_file).
 */
class McpToolAdapter implements DatabaseToolInterface
{
    private const PREFIX = 'mcp__';
    private const SEP    = '__';

    /**
     * @var McpServerInterface
     */
    private McpServerInterface $server;

    /**
     * @var string Bare tool name as returned by listTools() (no prefix)
     */
    private string $toolName;

    /**
     * @var array MCP tool schema: ['name', 'description', 'inputSchema']
     */
    private array $schema;

    public function __construct(McpServerInterface $server, string $toolName, array $schema)
    {
        $this->server   = $server;
        $this->toolName = $toolName;
        $this->schema   = $schema;
    }

    /**
     * Returns "mcp__{serverName}__{toolName}".
     */
    public function getName(): string
    {
        return self::PREFIX . $this->server->getName() . self::SEP . $this->toolName;
    }

    public function getDescription(): string
    {
        $description = $this->schema['description'] ?? $this->toolName;
        return $description . ' [MCP: ' . $this->server->getName() . ']';
    }

    /**
     * Return the MCP inputSchema directly — it is already OpenAPI-compatible.
     *
     * Normalises 'properties' from PHP [] to \stdClass so json_encode()
     * produces the JSON object {} that the Claude API requires.
     * PHP class constants cannot hold object expressions, so servers may
     * define empty-property schemas as [] — we fix that here.
     */
    public function getParametersSchema(): array
    {
        $schema = $this->schema['inputSchema'] ?? [
            'type'       => 'object',
            'properties' => new \stdClass(),
        ];

        if (isset($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * Delegate execution to the MCP server and normalise the result.
     *
     * @param array $arguments
     * @param bool  $allowDangerous Ignored — MCP servers handle their own access controls
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $result = $this->server->callTool($this->toolName, $arguments);

        // Server already returns normalised format from AbstractMcpServer
        if (isset($result['success'])) {
            return $result;
        }

        // Fallback if server returned unexpected format
        return [
            'success' => true,
            'result'  => is_string($result) ? $result : json_encode($result),
            'preview' => is_string($result) ? substr($result, 0, 200) : substr(json_encode($result), 0, 200),
        ];
    }

    // -------------------------------------------------------------------------
    // Static helper
    // -------------------------------------------------------------------------

    /**
     * Build a qualified tool name from server + bare tool name.
     */
    public static function buildQualifiedName(string $serverName, string $toolName): string
    {
        return self::PREFIX . $serverName . self::SEP . $toolName;
    }

    /**
     * Parse a qualified name into [serverName, toolName].
     * Returns null if the name is not an MCP tool name.
     *
     * @return array{0:string,1:string}|null
     */
    public static function parseQualifiedName(string $qualifiedName): ?array
    {
        if (!str_starts_with($qualifiedName, self::PREFIX)) {
            return null;
        }
        $rest  = substr($qualifiedName, strlen(self::PREFIX));
        $pos   = strpos($rest, self::SEP);
        if ($pos === false) {
            return null;
        }
        return [
            substr($rest, 0, $pos),           // serverName
            substr($rest, $pos + strlen(self::SEP)), // toolName
        ];
    }
}
