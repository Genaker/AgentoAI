<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Mcp;

use Genaker\MagentoMcpAi\Api\Mcp\McpServerInterface;
use Genaker\MagentoMcpAi\Model\Mcp\McpToolAdapter;
use Genaker\MagentoMcpAi\Model\Mcp\Registry;
use PHPUnit\Framework\TestCase;

class RegistryTest extends TestCase
{
    /**
     * Build a mock MCP server returning the given tools list.
     */
    private function makeServer(string $name, array $tools): McpServerInterface
    {
        $server = $this->createMock(McpServerInterface::class);
        $server->method('getName')->willReturn($name);
        $server->method('listTools')->willReturn($tools);
        $server->method('isAvailable')->willReturn(true);
        return $server;
    }

    public function testGetServersReturnsRegistered(): void
    {
        $server   = $this->makeServer('myserver', []);
        $registry = new Registry([$server]);

        $servers = $registry->getServers();
        $this->assertCount(1, $servers);
        $this->assertSame($server, $servers['myserver']);
    }

    public function testGetServerByName(): void
    {
        $server   = $this->makeServer('db', []);
        $registry = new Registry([$server]);

        $this->assertSame($server, $registry->getServer('db'));
        $this->assertNull($registry->getServer('nonexistent'));
    }

    public function testGetToolsForAIReturnsOpenAIFormat(): void
    {
        $server = $this->makeServer('fs', [[
            'name'        => 'read_file',
            'description' => 'Reads a file.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ]]);

        $registry = new Registry([$server]);
        $tools    = $registry->getToolsForAI();

        $this->assertCount(1, $tools);
        $this->assertEquals('function', $tools[0]['type']);
        $this->assertEquals('mcp__fs__read_file', $tools[0]['function']['name']);
        $this->assertStringContainsString('MCP', $tools[0]['function']['description']);
    }

    public function testGetToolsForAIMergesAllServers(): void
    {
        $server1 = $this->makeServer('git', [[
            'name' => 'git_log', 'description' => 'Git log', 'inputSchema' => [],
        ]]);
        $server2 = $this->makeServer('db', [[
            'name' => 'query', 'description' => 'SQL query', 'inputSchema' => [],
        ]]);

        $registry = new Registry([$server1, $server2]);
        $tools    = $registry->getToolsForAI();

        $this->assertCount(2, $tools);

        $names = array_column(array_column($tools, 'function'), 'name');
        $this->assertContains('mcp__git__git_log', $names);
        $this->assertContains('mcp__db__query', $names);
    }

    public function testGetToolsForAIReturnsEmptyWhenNoServers(): void
    {
        $registry = new Registry([]);
        $this->assertSame([], $registry->getToolsForAI());
    }

    public function testGetToolsForAIHandlesUnavailableServerGracefully(): void
    {
        $badServer = $this->createMock(McpServerInterface::class);
        $badServer->method('getName')->willReturn('broken');
        $badServer->method('listTools')->willThrowException(new \RuntimeException('Server down'));

        $goodServer = $this->makeServer('good', [[
            'name' => 'ok_tool', 'description' => 'Works fine', 'inputSchema' => [],
        ]]);

        // Should not throw; bad server is skipped, good server tools included
        $registry = new Registry([$badServer, $goodServer]);
        $tools    = $registry->getToolsForAI();

        $this->assertCount(1, $tools);
        $this->assertEquals('mcp__good__ok_tool', $tools[0]['function']['name']);
    }

    public function testGetToolReturnsAdapterForKnownTool(): void
    {
        $server = $this->makeServer('myserver', [[
            'name' => 'get_data', 'description' => 'Data tool', 'inputSchema' => [],
        ]]);

        $registry = new Registry([$server]);
        $adapter  = $registry->getTool('mcp__myserver__get_data');

        $this->assertInstanceOf(McpToolAdapter::class, $adapter);
        $this->assertEquals('mcp__myserver__get_data', $adapter->getName());
    }

    public function testGetToolReturnsNullForBuiltinToolName(): void
    {
        $registry = new Registry([]);
        $this->assertNull($registry->getTool('execute_sql_query'));
    }

    public function testGetToolReturnsNullForUnknownMcpServer(): void
    {
        $registry = new Registry([]);
        $this->assertNull($registry->getTool('mcp__nonexistent__tool'));
    }

    public function testGetToolReturnsNullForUnknownToolOnKnownServer(): void
    {
        $server   = $this->makeServer('fs', []);
        $registry = new Registry([$server]);
        $this->assertNull($registry->getTool('mcp__fs__nonexistent'));
    }

    public function testGetToolsForAISkipsToolsWithEmptyName(): void
    {
        $server = $this->makeServer('srv', [
            ['name' => '', 'description' => 'no name'],
            ['name' => 'valid_tool', 'description' => 'has name', 'inputSchema' => []],
        ]);

        $registry = new Registry([$server]);
        $tools    = $registry->getToolsForAI();

        $this->assertCount(1, $tools);
        $this->assertEquals('mcp__srv__valid_tool', $tools[0]['function']['name']);
    }

    public function testRegistryIgnoresNonServerEntries(): void
    {
        // DI may inject non-McpServerInterface items; they must be silently ignored
        $server   = $this->makeServer('ok', []);
        $registry = new Registry([$server, 'not_a_server', null, 42]);

        $this->assertCount(1, $registry->getServers());
    }
}
