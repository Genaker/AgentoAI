<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Mcp;

use Genaker\MagentoMcpAi\Api\Mcp\McpServerInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for MCP server implementations.
 *
 * Handles the MCP JSON-RPC 2.0 handshake (initialize / notifications/initialized),
 * the tools/list discovery call, and tools/call execution. Subclasses only need to
 * implement the transport layer (initializeConnection + sendRpcRequest).
 *
 * Protocol reference: https://modelcontextprotocol.io/specification/2024-11-05
 */
abstract class AbstractMcpServer implements McpServerInterface
{
    /**
     * MCP protocol version this client speaks.
     */
    private const PROTOCOL_VERSION = '2024-11-05';

    /**
     * @var string Server name (used as tool prefix)
     */
    protected string $name;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var array|null Cached tool list; null = not yet fetched
     */
    private ?array $cachedTools = null;

    /**
     * @var bool Whether the MCP handshake has been completed
     */
    private bool $initialized = false;

    /**
     * @var int Auto-incrementing JSON-RPC request ID
     */
    private int $nextId = 1;

    public function __construct(string $name, LoggerInterface $logger)
    {
        $this->name   = $name;
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // McpServerInterface implementation
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Discover tools from the MCP server (cached after first call).
     *
     * Returns [] if the server is unreachable or returns no tools.
     */
    public function listTools(): array
    {
        if ($this->cachedTools !== null) {
            return $this->cachedTools;
        }

        try {
            $this->ensureInitialized();
            $response = $this->sendRpcRequest('tools/list', [], $this->incrementId());
            $tools = $response['result']['tools'] ?? [];
            $this->cachedTools = is_array($tools) ? $tools : [];
        } catch (\Throwable $e) {
            $this->logger->warning('[MagentoMcpAi] MCP server listTools() failed', [
                'server'  => $this->name,
                'error'   => $e->getMessage(),
            ]);
            $this->cachedTools = [];
        }

        return $this->cachedTools;
    }

    /**
     * Call a tool on the server.
     *
     * Returns normalised result:
     *   Success: ['success' => true,  'result'  => string, 'preview' => string]
     *   Failure: ['success' => false, 'error'   => string]
     */
    public function callTool(string $toolName, array $arguments): array
    {
        try {
            $this->ensureInitialized();
            $id       = $this->incrementId();
            $response = $this->sendRpcRequest('tools/call', [
                'name'      => $toolName,
                'arguments' => $arguments,
            ], $id);

            if (isset($response['error'])) {
                return [
                    'success' => false,
                    'error'   => $response['error']['message'] ?? json_encode($response['error']),
                ];
            }

            return $this->parseMcpResult($response['result'] ?? []);

        } catch (\Throwable $e) {
            $this->logger->error('[MagentoMcpAi] MCP server callTool() failed', [
                'server'    => $this->name,
                'tool'      => $toolName,
                'arguments' => $arguments,
                'error'     => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error'   => 'MCP server error (' . $this->name . '): ' . $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Abstract transport methods (implemented by subclasses)
    // -------------------------------------------------------------------------

    /**
     * Open the transport connection and perform any setup required
     * before the MCP handshake. Called once per process lifetime.
     *
     * @throws \RuntimeException if the connection cannot be established
     */
    abstract protected function initializeConnection(): void;

    /**
     * Send a JSON-RPC request and return the parsed response array.
     *
     * @param string $method JSON-RPC method name
     * @param array  $params Method parameters
     * @param int    $id     Request ID
     * @return array Parsed JSON-RPC response
     * @throws \RuntimeException on transport or parse error
     */
    abstract protected function sendRpcRequest(string $method, array $params, int $id): array;

    /**
     * Send a JSON-RPC notification (fire-and-forget, no response expected).
     *
     * Default implementation calls sendRpcRequest with id=0 and ignores the result.
     * Subclasses may override for true fire-and-forget behaviour.
     */
    protected function sendNotification(string $method, array $params = []): void
    {
        try {
            $this->sendNotificationRaw(json_encode([
                'jsonrpc' => '2.0',
                'method'  => $method,
                'params'  => $params ?: new \stdClass(),
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            // Notifications are best-effort; log but do not throw
            $this->logger->debug('[MagentoMcpAi] MCP notification failed', [
                'server' => $this->name,
                'method' => $method,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write a raw notification string to the transport.
     * Subclasses that need true fire-and-forget override this.
     */
    protected function sendNotificationRaw(string $json): void
    {
        // Default: subclass handles in sendRpcRequest context
        // StdioMcpServer overrides this
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure the MCP handshake has been completed.
     */
    protected function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initializeConnection();

        // Send MCP initialize request
        $id       = $this->incrementId();
        $response = $this->sendRpcRequest('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => new \stdClass()],
            'clientInfo'      => ['name' => 'MagentoMcpAi', 'version' => '1.0'],
        ], $id);

        if (isset($response['error'])) {
            throw new \RuntimeException(
                'MCP initialize failed: ' . ($response['error']['message'] ?? json_encode($response['error']))
            );
        }

        // Send initialized notification (required by spec)
        $this->sendNotification('notifications/initialized');

        $this->initialized = true;

        $this->logger->debug('[MagentoMcpAi] MCP server initialized', [
            'server'  => $this->name,
            'version' => $response['result']['serverInfo'] ?? [],
        ]);
    }

    /**
     * Auto-increment and return the next request ID.
     */
    protected function incrementId(): int
    {
        return $this->nextId++;
    }

    /**
     * Convert an MCP tools/call result to our normalised format.
     *
     * MCP result: {'content': [{'type': 'text', 'text': '...'}, ...], 'isError': bool}
     */
    private function parseMcpResult(array $result): array
    {
        $isError = $result['isError'] ?? false;
        $content = $result['content'] ?? [];

        // Collect all text blocks into a single string
        $parts = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'] ?? '';
            } elseif (($block['type'] ?? '') === 'resource') {
                $parts[] = $block['resource']['text'] ?? json_encode($block['resource'] ?? '');
            } else {
                $parts[] = json_encode($block);
            }
        }

        $text = implode("\n", $parts);

        if ($isError) {
            return [
                'success' => false,
                'error'   => $text ?: 'MCP tool returned an error',
            ];
        }

        return [
            'success' => true,
            'result'  => $text,
            'preview' => substr($text, 0, 300) . (strlen($text) > 300 ? '…' : ''),
        ];
    }
}
