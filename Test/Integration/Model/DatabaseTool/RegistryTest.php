<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model\DatabaseTool;

use PHPUnit\Framework\TestCase;
use Genaker\MagentoMcpAi\Model\DatabaseTool\Registry;
use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;

/**
 * Integration tests for Tool Registry
 */
class RegistryTest extends TestCase
{
    /**
     * @var Registry
     */
    private $registry;

    protected function setUp(): void
    {
        // Create mock tools
        $executeSqlTool = $this->createMock(DatabaseToolInterface::class);
        $executeSqlTool->method('getName')->willReturn('execute_sql_query');
        $executeSqlTool->method('getDescription')->willReturn('Execute SQL query');
        $executeSqlTool->method('getParametersSchema')->willReturn(['type' => 'object']);

        $describeTableTool = $this->createMock(DatabaseToolInterface::class);
        $describeTableTool->method('getName')->willReturn('describe_table');
        $describeTableTool->method('getDescription')->willReturn('Describe table');
        $describeTableTool->method('getParametersSchema')->willReturn(['type' => 'object']);

        $updateTool = $this->createMock(DatabaseToolInterface::class);
        $updateTool->method('getName')->willReturn('update_customer');
        $updateTool->method('getDescription')->willReturn('Update customer');
        $updateTool->method('getParametersSchema')->willReturn(['type' => 'object']);

        $this->registry = new Registry([
            $executeSqlTool,
            $describeTableTool,
            $updateTool
        ]);
    }

    /**
     * Test registry can be instantiated
     */
    public function testRegistryCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Registry::class, $this->registry);
    }

    /**
     * Test get all tools
     */
    public function testGetAllTools(): void
    {
        $tools = $this->registry->getTools();
        
        $this->assertIsArray($tools);
        $this->assertCount(3, $tools);
        $this->assertArrayHasKey('execute_sql_query', $tools);
        $this->assertArrayHasKey('describe_table', $tools);
        $this->assertArrayHasKey('update_customer', $tools);
    }

    /**
     * Test get tool by name
     */
    public function testGetToolByName(): void
    {
        $tool = $this->registry->getTool('execute_sql_query');
        
        $this->assertNotNull($tool);
        $this->assertInstanceOf(DatabaseToolInterface::class, $tool);
        $this->assertEquals('execute_sql_query', $tool->getName());
    }

    /**
     * Test get non-existent tool returns null
     */
    public function testGetNonExistentToolReturnsNull(): void
    {
        $tool = $this->registry->getTool('non_existent_tool');
        
        $this->assertNull($tool);
    }

    /**
     * Test get tools for AI in safe mode
     */
    public function testGetToolsForAIInSafeMode(): void
    {
        $tools = $this->registry->getToolsForAI(false);
        
        $this->assertIsArray($tools);
        // Should exclude dangerous tools (update_customer)
        $this->assertCount(2, $tools);
        
        $toolNames = array_column(array_column($tools, 'function'), 'name');
        $this->assertContains('execute_sql_query', $toolNames);
        $this->assertContains('describe_table', $toolNames);
        $this->assertNotContains('update_customer', $toolNames);
    }

    /**
     * Test get tools for AI in dangerous mode
     */
    public function testGetToolsForAIInDangerousMode(): void
    {
        $tools = $this->registry->getToolsForAI(true);
        
        $this->assertIsArray($tools);
        // Should include all tools
        $this->assertCount(3, $tools);
        
        $toolNames = array_column(array_column($tools, 'function'), 'name');
        $this->assertContains('execute_sql_query', $toolNames);
        $this->assertContains('describe_table', $toolNames);
        $this->assertContains('update_customer', $toolNames);
    }

    /**
     * Test tools format for AI
     */
    public function testToolsFormatForAI(): void
    {
        $tools = $this->registry->getToolsForAI(false);
        
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('type', $tool);
            $this->assertEquals('function', $tool['type']);
            $this->assertArrayHasKey('function', $tool);
            $this->assertArrayHasKey('name', $tool['function']);
            $this->assertArrayHasKey('description', $tool['function']);
            $this->assertArrayHasKey('parameters', $tool['function']);
        }
    }

    /**
     * Test registry with empty tools array
     */
    public function testRegistryWithEmptyToolsArray(): void
    {
        $emptyRegistry = new Registry([]);
        
        $this->assertEmpty($emptyRegistry->getTools());
        $this->assertEmpty($emptyRegistry->getToolsForAI(false));
        $this->assertNull($emptyRegistry->getTool('any_tool'));
    }

    /**
     * Test registry filters dangerous tools correctly
     */
    public function testRegistryFiltersDangerousTools(): void
    {
        // Create tools with dangerous names
        $insertTool = $this->createMock(DatabaseToolInterface::class);
        $insertTool->method('getName')->willReturn('insert_data');
        
        $deleteTool = $this->createMock(DatabaseToolInterface::class);
        $deleteTool->method('getName')->willReturn('delete_records');
        
        $dropTool = $this->createMock(DatabaseToolInterface::class);
        $dropTool->method('getName')->willReturn('drop_table');
        
        $safeTool = $this->createMock(DatabaseToolInterface::class);
        $safeTool->method('getName')->willReturn('read_data');
        $safeTool->method('getDescription')->willReturn('Read data');
        $safeTool->method('getParametersSchema')->willReturn(['type' => 'object']);

        $registry = new Registry([
            $insertTool,
            $deleteTool,
            $dropTool,
            $safeTool
        ]);

        $safeModeTools = $registry->getToolsForAI(false);
        $this->assertCount(1, $safeModeTools);
        $this->assertEquals('read_data', $safeModeTools[0]['function']['name']);

        $dangerousModeTools = $registry->getToolsForAI(true);
        $this->assertCount(4, $dangerousModeTools);
    }
}
