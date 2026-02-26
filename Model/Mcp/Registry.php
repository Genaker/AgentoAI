<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Mcp;

use Genaker\MagentoMcpAi\Api\Mcp\McpServerInterface;
use Psr\Log\LoggerInterface;

/**
 * MCP Server Registry
 *
 * Collects McpServerInterface instances registered via DI (mirrors the pattern
 * of Model/DatabaseTool/Registry for built-in PHP tools).
 *
 * Tools exposed by MCP servers appear to the LLM alongside built-in tools.
 * Their names are prefixed "mcp__{serverName}__{toolName}" so they cannot
 * collide with built-in tool names.
 *
 * Registration example in di.xml:
 * -----------------------------------------------------------------------
 * <virtualType name="MyFilesystemMcpServer"
 *              type="Genaker\MagentoMcpAi\Model\Mcp\Server\StdioMcpServer">
 *     <arguments>
 *         <argument name="name"    xsi:type="string">filesystem</argument>
 *         <argument name="command" xsi:type="string">npx -y @modelcontextprotocol/server-filesystem /var/www/html/pub</argument>
 *     </arguments>
 * </virtualType>
 *
 * <type name="Genaker\MagentoMcpAi\Model\Mcp\Registry">
 *     <arguments>
 *         <argument name="servers" xsi:type="array">
 *             <item name="filesystem" xsi:type="object">MyFilesystemMcpServer</item>
 *         </argument>
 *     </arguments>
 * </type>
 * -----------------------------------------------------------------------
 */
class Registry
{
    /**
     * @var McpServerInterface[] Keyed by server name
     */
    private array $servers = [];

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var McpToolAdapter[][] Lazy cache: serverName → [toolName => McpToolAdapter]
     */
    private array $adapterCache = [];

    /**
     * @param array           $servers McpServerInterface[] injected via DI
     * @param LoggerInterface $logger
     */
    public function __construct(array $servers = [], LoggerInterface $logger = null)
    {
        foreach ($servers as $server) {
            if ($server instanceof McpServerInterface) {
                $this->servers[$server->getName()] = $server;
            }
        }

        // Logger is optional so the registry remains usable in unit tests without DI
        $this->logger = $logger ?? new class implements LoggerInterface {
            public function emergency($message, array $context = []): void {}
            public function alert($message, array $context = []): void {}
            public function critical($message, array $context = []): void {}
            public function error($message, array $context = []): void {}
            public function warning($message, array $context = []): void {}
            public function notice($message, array $context = []): void {}
            public function info($message, array $context = []): void {}
            public function debug($message, array $context = []): void {}
            public function log($level, $message, array $context = []): void {}
        };
    }

    // -------------------------------------------------------------------------
    // Server access
    // -------------------------------------------------------------------------

    /**
     * Return all registered MCP servers.
     *
     * @return McpServerInterface[]
     */
    public function getServers(): array
    {
        return $this->servers;
    }

    /**
     * Return a server by its name, or null if not registered.
     */
    public function getServer(string $name): ?McpServerInterface
    {
        return $this->servers[$name] ?? null;
    }

    // -------------------------------------------------------------------------
    // Tool access (for the LLM)
    // -------------------------------------------------------------------------

    /**
     * Return all MCP tools in OpenAI function-calling format.
     *
     * Calls listTools() on each server lazily (cached per server after first call).
     * If a server is unavailable or errors, its tools are omitted gracefully.
     */
    public function getToolsForAI(): array
    {
        $tools = [];

        foreach ($this->servers as $server) {
            try {
                $serverTools = $server->listTools();
            } catch (\Throwable $e) {
                $this->logger->warning('[MagentoMcpAi] McpRegistry: skipping server due to error', [
                    'server' => $server->getName(),
                    'error'  => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($serverTools as $toolSchema) {
                $toolName = $toolSchema['name'] ?? '';
                if ($toolName === '') {
                    continue;
                }

                $adapter = $this->getOrCreateAdapter($server, $toolName, $toolSchema);

                $tools[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $adapter->getName(),
                        'description' => $adapter->getDescription(),
                        'parameters'  => $adapter->getParametersSchema(),
                    ],
                ];
            }
        }

        return $tools;
    }

    /**
     * Look up an MCP tool by its qualified name (e.g. "mcp__filesystem__read_file").
     *
     * Returns null if the name is not an MCP tool or the server/tool is not found.
     */
    public function getTool(string $qualifiedName): ?McpToolAdapter
    {
        $parsed = McpToolAdapter::parseQualifiedName($qualifiedName);
        if ($parsed === null) {
            return null;
        }

        [$serverName, $toolName] = $parsed;

        $server = $this->servers[$serverName] ?? null;
        if ($server === null) {
            return null;
        }

        // Ensure adapter cache is populated for this server
        if (!isset($this->adapterCache[$serverName])) {
            $this->populateAdapterCache($server);
        }

        return $this->adapterCache[$serverName][$toolName] ?? null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return an existing adapter from cache or create and cache a new one.
     */
    private function getOrCreateAdapter(
        McpServerInterface $server,
        string             $toolName,
        array              $toolSchema
    ): McpToolAdapter {
        $sName = $server->getName();
        if (!isset($this->adapterCache[$sName][$toolName])) {
            $this->adapterCache[$sName][$toolName] = new McpToolAdapter($server, $toolName, $toolSchema);
        }
        return $this->adapterCache[$sName][$toolName];
    }

    /**
     * Populate the adapter cache for a server by calling listTools().
     * Errors are caught and logged; the cache entry is set to [] on failure.
     */
    private function populateAdapterCache(McpServerInterface $server): void
    {
        $sName = $server->getName();
        $this->adapterCache[$sName] = [];

        try {
            foreach ($server->listTools() as $toolSchema) {
                $toolName = $toolSchema['name'] ?? '';
                if ($toolName !== '') {
                    $this->adapterCache[$sName][$toolName] = new McpToolAdapter($server, $toolName, $toolSchema);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[MagentoMcpAi] McpRegistry: could not populate adapter cache', [
                'server' => $sName,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
