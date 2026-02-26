<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Mcp\Server;

use Genaker\MagentoMcpAi\Model\Mcp\AbstractMcpServer;
use Psr\Log\LoggerInterface;

/**
 * MCP server using stdio transport.
 *
 * Launches an external command as a subprocess and communicates with it using
 * newline-delimited JSON-RPC 2.0 over stdin/stdout, following the MCP stdio
 * transport specification.
 *
 * Register via di.xml using a virtualType:
 * -----------------------------------------------------------------------
 * <virtualType name="MyFilesystemMcpServer"
 *              type="Genaker\MagentoMcpAi\Model\Mcp\Server\StdioMcpServer">
 *     <arguments>
 *         <argument name="name"    xsi:type="string">filesystem</argument>
 *         <argument name="command" xsi:type="string">
 *             npx -y @modelcontextprotocol/server-filesystem /var/www/html/pub
 *         </argument>
 *     </arguments>
 * </virtualType>
 * <type name="Genaker\MagentoMcpAi\Model\Mcp\Registry">
 *     <arguments>
 *         <argument name="servers" xsi:type="array">
 *             <item name="filesystem" xsi:type="object">MyFilesystemMcpServer</item>
 *         </argument>
 *     </arguments>
 * </type>
 * -----------------------------------------------------------------------
 */
class StdioMcpServer extends AbstractMcpServer
{
    /**
     * Seconds to wait for a line from the subprocess before timing out.
     */
    private const READ_TIMEOUT_SECONDS = 10;

    /**
     * @var string Shell command to launch the MCP server subprocess
     */
    private string $command;

    /**
     * @var array Additional environment variables for the subprocess
     */
    private array $env;

    /**
     * @var resource|null Process resource returned by proc_open()
     */
    private $process = null;

    /**
     * @var array Pipe file descriptors: [0 => stdin, 1 => stdout, 2 => stderr]
     */
    private array $pipes = [];

    /**
     * @param string          $name    Unique server name (used as tool prefix)
     * @param string          $command Shell command to launch the MCP server
     * @param array           $env     Additional environment variables
     * @param LoggerInterface $logger
     */
    public function __construct(
        string          $name,
        string          $command,
        LoggerInterface $logger,
        array           $env = []
    ) {
        parent::__construct($name, $logger);
        $this->command = trim($command);
        $this->env     = $env;
    }

    // -------------------------------------------------------------------------
    // AbstractMcpServer — transport implementation
    // -------------------------------------------------------------------------

    /**
     * Launch the subprocess.
     *
     * @throws \RuntimeException if proc_open() fails
     */
    protected function initializeConnection(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],   // stdin  — we write to this
            1 => ['pipe', 'w'],   // stdout — we read from this
            2 => ['pipe', 'w'],   // stderr — captured for debug
        ];

        // Merge current env with any additional vars provided via DI
        $processEnv = array_merge($_SERVER, $_ENV, $this->env);

        $this->process = proc_open(
            $this->command,
            $descriptors,
            $this->pipes,
            null,
            $processEnv
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException(
                sprintf('Failed to start MCP server process: %s', $this->command)
            );
        }

        // Non-blocking stdout so we can implement our own timeout
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->logger->debug('[MagentoMcpAi] MCP stdio process started', [
            'server'  => $this->name,
            'command' => $this->command,
        ]);
    }

    /**
     * Send a JSON-RPC request line and read the response line.
     *
     * @throws \RuntimeException on write/read failure or JSON parse error
     */
    protected function sendRpcRequest(string $method, array $params, int $id): array
    {
        $this->ensureConnected();

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => empty($params) ? new \stdClass() : $params,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->writeLine($request);

        $this->logger->debug('[MagentoMcpAi] MCP → server', [
            'server' => $this->name,
            'method' => $method,
        ]);

        $responseLine = $this->readLine();

        try {
            $response = json_decode($responseLine, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                sprintf('MCP server (%s) returned invalid JSON: %s', $this->name, $responseLine)
            );
        }

        $this->logger->debug('[MagentoMcpAi] MCP ← server', [
            'server' => $this->name,
            'method' => $method,
            'has_result' => isset($response['result']),
            'has_error'  => isset($response['error']),
        ]);

        return $response;
    }

    /**
     * Send a notification (fire-and-forget — write only, do not read response).
     */
    protected function sendNotificationRaw(string $json): void
    {
        $this->ensureConnected();
        $this->writeLine($json);
    }

    // -------------------------------------------------------------------------
    // McpServerInterface
    // -------------------------------------------------------------------------

    public function isAvailable(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        return $status !== false && ($status['running'] ?? false);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function ensureConnected(): void
    {
        if (!is_resource($this->process)) {
            throw new \RuntimeException(
                sprintf('MCP server (%s) process is not running', $this->name)
            );
        }
    }

    /**
     * Write a JSON line to the subprocess stdin.
     *
     * @throws \RuntimeException on write failure
     */
    private function writeLine(string $json): void
    {
        $line = $json . "\n";
        $written = fwrite($this->pipes[0], $line);
        if ($written === false) {
            throw new \RuntimeException(
                sprintf('Failed to write to MCP server (%s) stdin', $this->name)
            );
        }
    }

    /**
     * Read one JSON line from the subprocess stdout, with timeout.
     *
     * @throws \RuntimeException on timeout or closed pipe
     */
    private function readLine(): string
    {
        $deadline = microtime(true) + self::READ_TIMEOUT_SECONDS;
        $buffer   = '';

        while (microtime(true) < $deadline) {
            $chunk = fgets($this->pipes[1]);

            if ($chunk === false) {
                // Nothing available yet — check if process is still alive
                if (!$this->isAvailable()) {
                    // Drain stderr for diagnostic info
                    $stderr = stream_get_contents($this->pipes[2]) ?: '';
                    throw new \RuntimeException(
                        sprintf(
                            'MCP server (%s) process exited unexpectedly. Stderr: %s',
                            $this->name,
                            substr($stderr, 0, 500)
                        )
                    );
                }
                usleep(5_000); // 5 ms back-off
                continue;
            }

            $buffer .= $chunk;

            // MCP uses newline-delimited JSON; each message is a complete line
            if (str_ends_with(rtrim($buffer), '}') || str_ends_with(rtrim($buffer), ']')) {
                return rtrim($buffer);
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Timed out waiting for response from MCP server (%s) after %d seconds',
                $this->name,
                self::READ_TIMEOUT_SECONDS
            )
        );
    }

    // -------------------------------------------------------------------------
    // Cleanup
    // -------------------------------------------------------------------------

    public function __destruct()
    {
        if (!is_resource($this->process)) {
            return;
        }

        // Close pipes to signal EOF to the process
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        // Give the process a moment to exit cleanly, then terminate if needed
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!($status['running'] ?? true)) {
                break;
            }
            usleep(50_000);
        }

        proc_close($this->process);
        $this->process = null;
    }
}
