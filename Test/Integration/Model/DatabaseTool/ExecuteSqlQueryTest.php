<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model\DatabaseTool;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Genaker\MagentoMcpAi\Model\DatabaseTool\ExecuteSqlQuery;
use Magento\Framework\Exception\LocalizedException;

/**
 * Integration tests for ExecuteSqlQuery tool
 */
class ExecuteSqlQueryTest extends TestCase
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
     * @var ExecuteSqlQuery
     */
    private $tool;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        $this->tool = new ExecuteSqlQuery($this->resourceConnection);
    }

    /**
     * Test tool can be instantiated
     */
    public function testToolCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ExecuteSqlQuery::class, $this->tool);
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
        $this->assertEquals('execute_sql_query', $this->tool->getName());
    }

    /**
     * Test tool description
     */
    public function testToolDescription(): void
    {
        $description = $this->tool->getDescription();
        $this->assertNotEmpty($description);
        $this->assertIsString($description);
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
        $this->assertArrayHasKey('query', $schema['properties']);
        $this->assertArrayHasKey('reason', $schema['properties']);
        $this->assertContains('query', $schema['required']);
        $this->assertContains('reason', $schema['required']);
    }

    /**
     * Test execute with SELECT query
     */
    public function testExecuteWithSelectQuery(): void
    {
        $arguments = [
            'query' => 'SELECT * FROM customer_entity LIMIT 10',
            'reason' => 'Get customers'
        ];

        $mockResults = [
            ['entity_id' => 1, 'email' => 'test@example.com'],
            ['entity_id' => 2, 'email' => 'test2@example.com']
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with('SELECT * FROM customer_entity LIMIT 10')
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('row_count', $result);
        $this->assertEquals(2, $result['row_count']);
        $this->assertArrayHasKey('columns', $result);
        $this->assertEquals(['entity_id', 'email'], $result['columns']);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals($mockResults, $result['data']);
        $this->assertArrayHasKey('preview', $result);
    }

    /**
     * Test execute adds LIMIT to SELECT queries
     */
    public function testExecuteAddsLimitToSelect(): void
    {
        $arguments = [
            'query' => 'SELECT * FROM customer_entity',
            'reason' => 'Get all customers'
        ];

        $mockResults = [['id' => 1]];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('LIMIT 100'))
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
    }

    /**
     * Test execute does not add LIMIT if already present
     */
    public function testExecuteDoesNotAddLimitIfPresent(): void
    {
        $arguments = [
            'query' => 'SELECT * FROM customer_entity LIMIT 50',
            'reason' => 'Get customers'
        ];

        $mockResults = [['id' => 1]];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with('SELECT * FROM customer_entity LIMIT 50')
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
    }

    /**
     * Test execute with DESCRIBE query
     */
    public function testExecuteWithDescribeQuery(): void
    {
        $arguments = [
            'query' => 'DESCRIBE customer_entity',
            'reason' => 'Get table structure'
        ];

        $mockResults = [
            ['Field' => 'entity_id', 'Type' => 'int', 'Null' => 'NO', 'Key' => 'PRI']
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with('DESCRIBE customer_entity')
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['row_count']);
    }

    /**
     * Test execute extracts SQL from mixed content (explanations + SQL)
     */
    public function testExecuteExtractsSqlFromMixedContent(): void
    {
        $arguments = [
            'query' => "Here's the query to run:\n\nSELECT COUNT(*) AS cnt FROM customer_entity\n\nThis returns the total customer count.",
            'reason' => 'Count customers'
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('SELECT COUNT(*)'))
            ->willReturn([['cnt' => 42]]);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertEquals(42, $result['data'][0]['cnt']);
    }

    /**
     * Test execute rejects options/choices passed as query
     */
    public function testExecuteRejectsOptionsAsQuery(): void
    {
        $arguments = [
            'query' => "Show full per-customer breakdown (27 rows)\n2) Sort by revenue (desc)\n3) Filter by minimum number of orders (specify min)",
            'reason' => 'Options'
        ];

        $result = $this->tool->execute($arguments, false);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test execute returns error (does not run) when no SQL can be extracted
     */
    public function testExecuteReturnsErrorForNonSqlInput(): void
    {
        $arguments = [
            'query' => "Here's the concise way to aggregate. Option A: use a JOIN. Option B: use a subquery. No actual SQL here.",
            'reason' => 'Documentation'
        ];

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Not a valid SQL statement', $result['error']);
    }

    /**
     * Test execute rejects descriptive text that starts with "show" but is not SQL
     */
    public function testExecuteRejectsShowFollowedByNumber(): void
    {
        $arguments = [
            'query' => "show 1 order with 250 total, several with 150).\n- A subset has 2 orders with total revenue around 200 (e.g., multiple customers show 2 orders totaling 200).\nA",
            'reason' => 'Options'
        ];

        $result = $this->tool->execute($arguments, false);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Not a valid SQL statement', $result['error']);
    }

    /**
     * Test execute throws exception when query is missing
     */
    public function testExecuteThrowsExceptionWhenQueryMissing(): void
    {
        $arguments = [
            'reason' => 'Test'
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('SQL query is required');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute blocks dangerous operations in safe mode
     */
    public function testExecuteBlocksDangerousOperationsInSafeMode(): void
    {
        $arguments = [
            'query' => 'DELETE FROM customer_entity',
            'reason' => 'Delete customers'
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('DELETE operations not allowed');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute allows dangerous operations with flag
     */
    public function testExecuteAllowsDangerousOperationsWithFlag(): void
    {
        $arguments = [
            'query' => 'UPDATE customer_entity SET email = "test@example.com" WHERE entity_id = 1',
            'reason' => 'Update customer email'
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->tool->execute($arguments, true);

        $this->assertTrue($result['success']);
    }

    /**
     * Test execute returns error (does not throw) on database errors so LLM can fix and retry
     */
    public function testExecuteHandlesDatabaseErrors(): void
    {
        $arguments = [
            'query' => 'SELECT * FROM non_existent_table',
            'reason' => 'Test query'
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willThrowException(new \Exception('Table does not exist'));

        $result = $this->tool->execute($arguments, false);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Table does not exist', $result['error']);
        $this->assertStringContainsString('Fix the query', $result['error']);
    }

    /**
     * Test execute with empty results
     */
    public function testExecuteWithEmptyResults(): void
    {
        $arguments = [
            'query' => 'SELECT * FROM customer_entity WHERE entity_id = 99999',
            'reason' => 'Find non-existent customer'
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['row_count']);
        $this->assertEmpty($result['columns']);
        $this->assertStringContainsString('No results found', $result['preview']);
    }

    /**
     * Test execute blocks INSERT in safe mode
     */
    public function testExecuteBlocksInsertInSafeMode(): void
    {
        $arguments = [
            'query' => 'INSERT INTO customer_entity (email) VALUES ("test@example.com")',
            'reason' => 'Insert customer'
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('INSERT operations not allowed');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute blocks UPDATE in safe mode
     */
    public function testExecuteBlocksUpdateInSafeMode(): void
    {
        $arguments = [
            'query' => 'UPDATE customer_entity SET email = "new@example.com"',
            'reason' => 'Update emails'
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('UPDATE operations not allowed');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute blocks DROP in safe mode
     */
    public function testExecuteBlocksDropInSafeMode(): void
    {
        $arguments = [
            'query' => 'DROP TABLE test_table',
            'reason' => 'Drop table'
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('DROP operations not allowed');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute allows SELECT in safe mode
     */
    public function testExecuteAllowsSelectInSafeMode(): void
    {
        $arguments = [
            'query' => 'SELECT * FROM customer_entity',
            'reason' => 'Get customers'
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['id' => 1]]);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
    }

    /**
     * Test execute allows DESCRIBE in safe mode
     */
    public function testExecuteAllowsDescribeInSafeMode(): void
    {
        $arguments = [
            'query' => 'DESCRIBE customer_entity',
            'reason' => 'Get structure'
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
    }

    /**
     * Test execute allows SHOW in safe mode
     */
    public function testExecuteAllowsShowInSafeMode(): void
    {
        $arguments = [
            'query' => 'SHOW TABLES',
            'reason' => 'List tables'
        ];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
    }

    /**
     * Test preview formatting
     */
    public function testPreviewFormatting(): void
    {
        $arguments = [
            'query' => 'SELECT entity_id, email FROM customer_entity LIMIT 15',
            'reason' => 'Get customers'
        ];

        $mockResults = [];
        for ($i = 1; $i <= 15; $i++) {
            $mockResults[] = ['entity_id' => $i, 'email' => "test{$i}@example.com"];
        }

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->willReturn($mockResults);

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('preview', $result);
        $preview = $result['preview'];
        
        // Should contain headers
        $this->assertStringContainsString('entity_id', $preview);
        $this->assertStringContainsString('email', $preview);
        
        // Should contain data rows (limited to 10 for preview)
        $this->assertStringContainsString('test1@example.com', $preview);
    }
}
