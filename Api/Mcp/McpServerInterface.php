<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Api\Mcp;

/**
 * Contract for an MCP (Model Context Protocol) server connection.
 *
 * An MCP server is an external process or service that exposes tools the LLM can call.
 * Implementations connect to the server via a transport (stdio, HTTP) and relay
 * tool calls using the JSON-RPC 2.0 based MCP protocol.
 *
 * Tools exposed by MCP servers are available to the LLM alongside the built-in PHP tools
 * (DatabaseToolInterface). Their names are prefixed "mcp__{serverName}__{toolName}" so
 * they never collide with built-in tools.
 *
 * @see \Genaker\MagentoMcpAi\Model\Mcp\Registry
 * @see \Genaker\MagentoMcpAi\Model\Mcp\McpToolAdapter
 */
interface McpServerInterface
{
    /**
     * Unique identifier for this server (used as the tool name prefix).
     * Must contain only alphanumeric characters and underscores.
     */
    public function getName(): string;

    /**
     * Return the list of tools this server exposes.
     *
     * Each element is an associative array matching the MCP tools/list schema:
     *   ['name' => string, 'description' => string, 'inputSchema' => array]
     *
     * Implementations should cache the result after the first call; the list is
     * fetched once per PHP process lifetime.
     *
     * Returns an empty array if the server is unavailable or returns no tools.
     */
    public function listTools(): array;

    /**
     * Execute a named tool on this server and return the normalised result.
     *
     * On success: ['success' => true,  'result' => string, 'preview' => string]
     * On failure: ['success' => false, 'error'  => string]
     *
     * @param string $toolName  Bare tool name as returned by listTools() (without prefix)
     * @param array  $arguments Tool arguments matching the tool's inputSchema
     */
    public function callTool(string $toolName, array $arguments): array;

    /**
     * Return true if the server is currently reachable and ready to handle calls.
     */
    public function isAvailable(): bool;
}
