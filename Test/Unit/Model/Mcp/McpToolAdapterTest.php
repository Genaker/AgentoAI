<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Mcp;

use Genaker\MagentoMcpAi\Api\Mcp\McpServerInterface;
use Genaker\MagentoMcpAi\Model\Mcp\McpToolAdapter;
use PHPUnit\Framework\TestCase;

class McpToolAdapterTest extends TestCase
{
    private McpServerInterface $server;
    private array $schema;
    private McpToolAdapter $adapter;

    protected function setUp(): void
    {
        $this->server = $this->createMock(McpServerInterface::class);
        $this->server->method('getName')->willReturn('myserver');

        $this->schema = [
            'name'        => 'get_data',
            'description' => 'Fetches data from the system.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required'   => ['id'],
            ],
        ];

        $this->adapter = new McpToolAdapter($this->server, 'get_data', $this->schema);
    }

    public function testGetNameHasMcpPrefix(): void
    {
        $this->assertEquals('mcp__myserver__get_data', $this->adapter->getName());
    }

    public function testGetDescriptionIncludesServerName(): void
    {
        $description = $this->adapter->getDescription();
        $this->assertStringContainsString('myserver', $description);
        $this->assertStringContainsString('MCP', $description);
    }

    public function testGetDescriptionIncludesOriginalDescription(): void
    {
        $this->assertStringContainsString('Fetches data', $this->adapter->getDescription());
    }

    public function testGetParametersSchemaFromInputSchema(): void
    {
        $schema = $this->adapter->getParametersSchema();
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertContains('id', $schema['required']);
    }

    public function testGetParametersSchemaFallsBackWhenMissing(): void
    {
        $adapter = new McpToolAdapter($this->server, 'no_schema_tool', ['name' => 'no_schema_tool']);
        $schema  = $adapter->getParametersSchema();
        $this->assertEquals('object', $schema['type']);
    }

    public function testExecuteCallsServerCallTool(): void
    {
        $this->server->expects($this->once())
            ->method('callTool')
            ->with('get_data', ['id' => 42])
            ->willReturn(['success' => true, 'result' => 'row data', 'preview' => 'row data']);

        $result = $this->adapter->execute(['id' => 42]);

        $this->assertTrue($result['success']);
        $this->assertEquals('row data', $result['result']);
    }

    public function testExecuteReturnsSuccessResult(): void
    {
        $this->server->method('callTool')->willReturn([
            'success' => true,
            'result'  => 'some result text',
            'preview' => 'some result text',
        ]);

        $result = $this->adapter->execute(['id' => 1]);

        $this->assertTrue($result['success']);
        $this->assertEquals('some result text', $result['result']);
    }

    public function testExecuteHandlesCallToolError(): void
    {
        $this->server->method('callTool')->willReturn([
            'success' => false,
            'error'   => 'Connection refused',
        ]);

        $result = $this->adapter->execute(['id' => 1]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection refused', $result['error']);
    }

    public function testExecuteIgnoresAllowDangerous(): void
    {
        // allowDangerous is irrelevant for MCP tools — ensure it does not throw
        $this->server->method('callTool')->willReturn([
            'success' => true,
            'result'  => 'ok',
            'preview' => 'ok',
        ]);

        $result = $this->adapter->execute([], allowDangerous: true);
        $this->assertTrue($result['success']);
    }

    // -------------------------------------------------------------------------
    // Static helper tests
    // -------------------------------------------------------------------------

    public function testBuildQualifiedName(): void
    {
        $name = McpToolAdapter::buildQualifiedName('filesystem', 'read_file');
        $this->assertEquals('mcp__filesystem__read_file', $name);
    }

    public function testParseQualifiedNameSuccess(): void
    {
        $parsed = McpToolAdapter::parseQualifiedName('mcp__filesystem__read_file');
        $this->assertNotNull($parsed);
        $this->assertEquals(['filesystem', 'read_file'], $parsed);
    }

    public function testParseQualifiedNameReturnsNullForBuiltinTool(): void
    {
        $this->assertNull(McpToolAdapter::parseQualifiedName('execute_sql_query'));
    }

    public function testParseQualifiedNameReturnsNullForMalformed(): void
    {
        $this->assertNull(McpToolAdapter::parseQualifiedName('mcp__onlyone'));
    }

    public function testParseQualifiedNameHandlesToolNameWithUnderscores(): void
    {
        $parsed = McpToolAdapter::parseQualifiedName('mcp__git__git_log_search');
        $this->assertNotNull($parsed);
        $this->assertEquals('git', $parsed[0]);
        $this->assertEquals('git_log_search', $parsed[1]);
    }
}
