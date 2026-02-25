<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model\DatabaseTool;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Genaker\MagentoMcpAi\Model\DatabaseTool\DescribeTable;
use Magento\Framework\Exception\LocalizedException;

/**
 * Integration tests for DescribeTable tool
 */
class DescribeTableTest extends TestCase
{
    /**
     * @var ResourceConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resourceConnection;

    /**
     * @var AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $connection;

    /**
     * @var DescribeTable
     */
    private $tool;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        $this->tool = new DescribeTable($this->resourceConnection);
    }

    /**
     * Test tool can be instantiated
     */
    public function testToolCanBeInstantiated(): void
    {
        $this->assertInstanceOf(DescribeTable::class, $this->tool);
    }

    /**
     * Test tool implements DatabaseToolInterface
     */
    public function testToolImplementsInterface(): void
    {
        $this->assertInstanceOf(\Genaker\MagentoMcpAi\Api\DatabaseToolInterface::class, $this->tool);
    }

    /**
     * Test tool name
     */
    public function testToolName(): void
    {
        $this->assertEquals('describe_table', $this->tool->getName());
    }

    /**
     * Test tool description
     */
    public function testToolDescription(): void
    {
        $description = $this->tool->getDescription();
        $this->assertNotEmpty($description);
        $this->assertIsString($description);
        $this->assertStringContainsString('table', strtolower($description));
    }

    /**
     * Test parameters schema
     */
    public function testParametersSchema(): void
    {
        $schema = $this->tool->getParametersSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('required', $schema);
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('table_name', $schema['properties']);
        $this->assertContains('table_name', $schema['required']);
    }

    /**
     * Test execute with valid table name
     */
    public function testExecuteWithValidTableName(): void
    {
        $arguments = [
            'table_name' => 'customer_entity'
        ];

        $mockResults = [
            [
                'Field' => 'entity_id',
                'Type' => 'int(11)',
                'Null' => 'NO',
                'Key' => 'PRI',
                'Default' => null,
                'Extra' => 'auto_increment'
            ],
            [
                'Field' => 'email',
                'Type' => 'varchar(255)',
                'Null' => 'NO',
                'Key' => 'UNI',
                'Default' => null,
                'Extra' => ''
            ]
        ];

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->with('customer_entity')
            ->willReturn('`customer_entity`');

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with('DESCRIBE `customer_entity`')
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('table', $result);
        $this->assertEquals('customer_entity', $result['table']);
        $this->assertArrayHasKey('columns', $result);
        $this->assertEquals($mockResults, $result['columns']);
        $this->assertArrayHasKey('preview', $result);
    }

    /**
     * Test execute throws exception when table name is missing
     */
    public function testExecuteThrowsExceptionWhenTableNameMissing(): void
    {
        $arguments = [];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Table name is required');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute handles database errors
     */
    public function testExecuteHandlesDatabaseErrors(): void
    {
        $arguments = [
            'table_name' => 'non_existent_table'
        ];

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->willReturn('`non_existent_table`');

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willThrowException(new \Exception('Table does not exist'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Failed to describe table');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute with empty table structure
     */
    public function testExecuteWithEmptyTableStructure(): void
    {
        $arguments = [
            'table_name' => 'empty_table'
        ];

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->willReturn('`empty_table`');

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['columns']);
        $this->assertStringContainsString('No schema information available', $result['preview']);
    }

    /**
     * Test preview formatting
     */
    public function testPreviewFormatting(): void
    {
        $arguments = [
            'table_name' => 'sales_order'
        ];

        $mockResults = [
            [
                'Field' => 'entity_id',
                'Type' => 'int(11)',
                'Null' => 'NO',
                'Key' => 'PRI'
            ],
            [
                'Field' => 'customer_id',
                'Type' => 'int(11)',
                'Null' => 'YES',
                'Key' => 'MUL'
            ],
            [
                'Field' => 'status',
                'Type' => 'varchar(32)',
                'Null' => 'NO',
                'Key' => ''
            ]
        ];

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->willReturn('`sales_order`');

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('preview', $result);
        $preview = $result['preview'];
        
        // Should contain field information
        $this->assertStringContainsString('Field: entity_id', $preview);
        $this->assertStringContainsString('Type: int(11)', $preview);
        $this->assertStringContainsString('Key: PRI', $preview);
        $this->assertStringContainsString('Field: customer_id', $preview);
        $this->assertStringContainsString('Field: status', $preview);
    }

    /**
     * Test execute with table name containing special characters
     */
    public function testExecuteWithSpecialCharactersInTableName(): void
    {
        $arguments = [
            'table_name' => 'customer_entity_varchar'
        ];

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->with('customer_entity_varchar')
            ->willReturn('`customer_entity_varchar`');

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
    }

    /**
     * Test execute ignores allowDangerous flag (read-only operation)
     */
    public function testExecuteIgnoresAllowDangerousFlag(): void
    {
        $arguments = [
            'table_name' => 'customer_entity'
        ];

        $this->connection->expects($this->exactly(2))
            ->method('quoteIdentifier')
            ->willReturn('`customer_entity`');

        $this->connection->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturn([]);

        // Should work the same regardless of allowDangerous flag
        $result1 = $this->tool->execute($arguments, false);
        $result2 = $this->tool->execute($arguments, true);

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
    }

    /**
     * Test execute with complex table structure
     */
    public function testExecuteWithComplexTableStructure(): void
    {
        $arguments = [
            'table_name' => 'catalog_product_entity'
        ];

        $mockResults = [
            ['Field' => 'entity_id', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => 'PRI'],
            ['Field' => 'attribute_set_id', 'Type' => 'smallint(5)', 'Null' => 'NO', 'Key' => 'MUL'],
            ['Field' => 'type_id', 'Type' => 'varchar(32)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'sku', 'Type' => 'varchar(64)', 'Null' => 'YES', 'Key' => 'UNI'],
            ['Field' => 'has_options', 'Type' => 'smallint(6)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'required_options', 'Type' => 'smallint(5)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'created_at', 'Type' => 'timestamp', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'updated_at', 'Type' => 'timestamp', 'Null' => 'NO', 'Key' => '']
        ];

        $this->connection->expects($this->once())
            ->method('quoteIdentifier')
            ->willReturn('`catalog_product_entity`');

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertEquals(8, count($result['columns']));
        $this->assertStringContainsString('entity_id', $result['preview']);
        $this->assertStringContainsString('sku', $result['preview']);
    }
}
