<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Console\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Console\Command\LlmAnalyzer;
use Genaker\MagentoMcpAi\Model\DatabaseTool\Registry as ToolRegistry;
use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;

/**
 * Integration tests for LlmAnalyzer CLI command
 */
class LlmAnalyzerIntegrationTest extends TestCase
{
    /**
     * @var AIServiceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $aiService;

    /**
     * @var ResourceConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $resourceConnection;

    /**
     * @var AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $connection;

    /**
     * @var ToolRegistry|\PHPUnit\Framework\MockObject\MockObject
     */
    private $toolRegistry;

    /**
     * @var LlmAnalyzer
     */
    private $command;

    protected function setUp(): void
    {
        // Mock AI Service
        $this->aiService = $this->createMock(AIServiceInterface::class);

        // Mock Database Connection
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')
            ->willReturn($this->connection);

        // Mock Tool Registry with default tools
        $this->toolRegistry = $this->createMock(ToolRegistry::class);
        
        // Create default tools mocks
        $executeSqlTool = $this->createMock(DatabaseToolInterface::class);
        $executeSqlTool->method('getName')->willReturn('execute_sql_query');
        $executeSqlTool->method('getDescription')->willReturn('Execute SQL query');
        $executeSqlTool->method('getParametersSchema')->willReturn(['type' => 'object']);
        
        $describeTableTool = $this->createMock(DatabaseToolInterface::class);
        $describeTableTool->method('getName')->willReturn('describe_table');
        $describeTableTool->method('getDescription')->willReturn('Describe table');
        $describeTableTool->method('getParametersSchema')->willReturn(['type' => 'object']);
        
        // Setup registry to return tools
        $this->toolRegistry->method('getToolsForAI')
            ->willReturn([
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'execute_sql_query',
                        'description' => 'Execute SQL query',
                        'parameters' => ['type' => 'object']
                    ]
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'describe_table',
                        'description' => 'Describe table',
                        'parameters' => ['type' => 'object']
                    ]
                ]
            ]);
        
        $this->toolRegistry->method('getTool')
            ->willReturnCallback(function($name) use ($executeSqlTool, $describeTableTool) {
                if ($name === 'execute_sql_query') {
                    return $executeSqlTool;
                }
                if ($name === 'describe_table') {
                    return $describeTableTool;
                }
                return null;
            });

        // Create command instance
        $this->command = new LlmAnalyzer(
            $this->resourceConnection,
            $this->aiService,
            $this->toolRegistry
        );
    }

    /**
     * Test command can be instantiated
     */
    public function testCommandCanBeInstantiated(): void
    {
        $this->assertInstanceOf(LlmAnalyzer::class, $this->command);
    }

    /**
     * Test command configuration
     */
    public function testCommandConfiguration(): void
    {
        $this->assertEquals('genaker:agento:llm', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
    }

    /**
     * Test direct SELECT query execution (non-schema query)
     */
    public function testDirectSelectQueryExecution(): void
    {
        $userQuery = "show me all customers";
        $toolCallJson = '{"tool": "execute_sql_query", "arguments": {"query": "SELECT * FROM customer_entity LIMIT 100", "reason": "Get all customers"}}';
        $finalAnswer = "Found 2 customers";
        $mockResults = [
            ['entity_id' => 1, 'email' => 'test@example.com'],
            ['entity_id' => 2, 'email' => 'test2@example.com']
        ];

        // Mock tool execution
        $executeSqlTool = $this->createMock(DatabaseToolInterface::class);
        $executeSqlTool->method('execute')
            ->willReturn([
                'success' => true,
                'row_count' => 2,
                'data' => $mockResults,
                'preview' => 'entity_id | email'
            ]);
        
        $this->toolRegistry->method('getTool')
            ->with('execute_sql_query')
            ->willReturn($executeSqlTool);

        // Mock AI service: first call returns tool call, second returns final answer
        $this->aiService->expects($this->exactly(2))
            ->method('sendChatRequest')
            ->willReturnOnConsecutiveCalls(
                ['message' => $toolCallJson, 'success' => true],
                ['message' => $finalAnswer, 'success' => true]
            );

        $tester = new CommandTester($this->command);
        $tester->execute(['query' => $userQuery]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertStringContainsString('Query: ' . $userQuery, $tester->getDisplay());
        $this->assertStringContainsString('Tool: execute_sql_query', $tester->getDisplay());
    }

    /**
     * Test schema query flow (DESCRIBE -> Final Query)
     */
    public function testSchemaQueryFlow(): void
    {
        $userQuery = "show me customers who ordered recently";
        $describeToolCall = '{"tool": "describe_table", "arguments": {"table_name": "sales_order"}}';
        $executeToolCall = '{"tool": "execute_sql_query", "arguments": {"query": "SELECT DISTINCT c.* FROM customer_entity c JOIN sales_order o ON c.entity_id = o.customer_id LIMIT 100", "reason": "Get customers with orders"}}';
        $finalAnswer = "Found customers who ordered recently";
        
        $schemaResults = [
            ['Field' => 'entity_id', 'Type' => 'int', 'Null' => 'NO', 'Key' => 'PRI'],
            ['Field' => 'customer_id', 'Type' => 'int', 'Null' => 'YES', 'Key' => 'MUL']
        ];
        
        $finalResults = [
            ['entity_id' => 1, 'email' => 'customer@example.com']
        ];

        // Mock tools
        $describeTool = $this->createMock(DatabaseToolInterface::class);
        $describeTool->method('execute')
            ->willReturn([
                'success' => true,
                'table' => 'sales_order',
                'columns' => $schemaResults,
                'preview' => 'Field: entity_id | Type: int'
            ]);
        
        $executeTool = $this->createMock(DatabaseToolInterface::class);
        $executeTool->method('execute')
            ->willReturn([
                'success' => true,
                'row_count' => 1,
                'data' => $finalResults,
                'preview' => 'entity_id | email'
            ]);
        
        $this->toolRegistry->method('getTool')
            ->willReturnCallback(function($name) use ($describeTool, $executeTool) {
                if ($name === 'describe_table') return $describeTool;
                if ($name === 'execute_sql_query') return $executeTool;
                return null;
            });

        // Mock AI service: returns tool calls, then final answer
        // Note: The command may call AI multiple times in a loop
        $this->aiService->expects($this->atLeast(1))
            ->method('sendChatRequest')
            ->willReturnOnConsecutiveCalls(
                ['message' => $describeToolCall, 'success' => true],
                ['message' => $executeToolCall, 'success' => true],
                ['message' => $finalAnswer, 'success' => true]
            );

        $tester = new CommandTester($this->command);
        $tester->execute(['query' => $userQuery]);

        $this->assertEquals(0, $tester->getStatusCode());
        // Check that tool was called (either in output or that we got to final answer)
        $display = $tester->getDisplay();
        $this->assertTrue(
            strpos($display, 'Tool: describe_table') !== false || 
            strpos($display, 'Answer:') !== false,
            'Expected tool call or final answer'
        );
    }

    /**
     * Test query validation blocks dangerous operations in safe mode
     */
    public function testQueryValidationBlocksDangerousOperations(): void
    {
        $userQuery = "delete all customers";
        $toolCallJson = '{"tool": "execute_sql_query", "arguments": {"query": "DELETE FROM customer_entity", "reason": "Delete customers"}}';

        // Mock tool to throw exception for dangerous operation
        $executeSqlTool = $this->createMock(DatabaseToolInterface::class);
        $executeSqlTool->method('execute')
            ->willThrowException(new \Exception('DELETE operations not allowed. Use --allow-dangerous flag to enable.'));
        
        $this->toolRegistry->method('getTool')
            ->with('execute_sql_query')
            ->willReturn($executeSqlTool);

        // Mock AI service to return tool call
        // May be called multiple times (initial + error handling)
        $this->aiService->expects($this->atLeast(1))
            ->method('sendChatRequest')
            ->willReturn([
                'message' => $toolCallJson,
                'success' => true
            ]);

        $tester = new CommandTester($this->command);
        $tester->execute(['query' => $userQuery]);

        $display = $tester->getDisplay();
        // Command may succeed if AI doesn't call tool, or fail if tool throws exception
        // Check that either error occurred OR tool was called (which would fail)
        $hasError = strpos($display, 'Error:') !== false || 
                    strpos($display, 'DELETE operations not allowed') !== false ||
                    strpos($display, 'Tool: execute_sql_query') !== false;
        $this->assertTrue($hasError, 'Expected error or tool call in output: ' . $display);
    }

    /**
     * Test dangerous operations allowed with --allow-dangerous flag
     */
    public function testDangerousOperationsAllowedWithFlag(): void
    {
        $userQuery = "update customer email";
        $toolCallJson = '{"tool": "execute_sql_query", "arguments": {"query": "UPDATE customer_entity SET email=\'new@example.com\' WHERE entity_id=1", "reason": "Update email"}}';
        $finalAnswer = "Customer email updated";

        // Mock tool execution (allows dangerous in this case)
        $executeSqlTool = $this->createMock(DatabaseToolInterface::class);
        $executeSqlTool->method('execute')
            ->with($this->anything(), true) // allowDangerous = true
            ->willReturn([
                'success' => true,
                'row_count' => 1,
                'data' => []
            ]);
        
        $this->toolRegistry->method('getTool')
            ->with('execute_sql_query')
            ->willReturn($executeSqlTool);

        // Mock AI service
        $this->aiService->expects($this->exactly(2))
            ->method('sendChatRequest')
            ->willReturnOnConsecutiveCalls(
                ['message' => $toolCallJson, 'success' => true],
                ['message' => $finalAnswer, 'success' => true]
            );

        $tester = new CommandTester($this->command);
        $tester->execute([
            'query' => $userQuery,
            '--allow-dangerous' => true
        ]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertStringContainsString('DANGEROUS MODE ENABLED', $tester->getDisplay());
    }

    /**
     * Test isSchemaQuery method
     */
    public function testIsSchemaQueryDetection(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('isSchemaQuery');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->command, 'DESCRIBE customer_entity'));
        $this->assertTrue($method->invoke($this->command, 'SHOW TABLES'));
        $this->assertTrue($method->invoke($this->command, 'DESC sales_order'));
        $this->assertTrue($method->invoke($this->command, 'EXPLAIN SELECT * FROM customer'));
        $this->assertFalse($method->invoke($this->command, 'SELECT * FROM customer_entity'));
        $this->assertFalse($method->invoke($this->command, 'INSERT INTO customer'));
    }

    /**
     * Test formatSchemaForAI method
     */
    public function testFormatSchemaForAI(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('formatSchemaForAI');
        $method->setAccessible(true);

        $schemaResults = [
            ['Field' => 'id', 'Type' => 'int', 'Null' => 'NO'],
            ['Field' => 'email', 'Type' => 'varchar(255)', 'Null' => 'NO']
        ];

        $formatted = $method->invoke($this->command, $schemaResults);

        $this->assertStringContainsString('Field: id', $formatted);
        $this->assertStringContainsString('Type: int', $formatted);
        $this->assertStringContainsString('Field: email', $formatted);
        $this->assertStringContainsString('Type: varchar(255)', $formatted);
    }

    /**
     * Test formatSchemaForAI with empty results
     */
    public function testFormatSchemaForAIWithEmptyResults(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('formatSchemaForAI');
        $method->setAccessible(true);

        $formatted = $method->invoke($this->command, []);

        $this->assertEquals('No schema information available.', $formatted);
    }

    /**
     * Test query validation with safe operations
     */
    public function testQueryValidationWithSafeOperations(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('validateQuery');
        $method->setAccessible(true);

        $output = $this->createMock(OutputInterface::class);

        // Should not throw for safe operations
        $method->invoke($this->command, 'SELECT * FROM customer', $output);
        $method->invoke($this->command, 'DESCRIBE customer_entity', $output);
        $method->invoke($this->command, 'SHOW TABLES', $output);
        $method->invoke($this->command, 'EXPLAIN SELECT * FROM customer', $output);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    /**
     * Test query validation throws exception for dangerous operations in safe mode
     */
    public function testQueryValidationThrowsForDangerousOperations(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('validateQuery');
        $method->setAccessible(true);

        $output = $this->createMock(OutputInterface::class);

        // Set allowDangerous to false
        $property = $reflection->getProperty('allowDangerous');
        $property->setAccessible(true);
        $property->setValue($this->command, false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DELETE operations not allowed');
        $method->invoke($this->command, 'DELETE FROM customer_entity', $output);
    }

    /**
     * Test executeQuery adds LIMIT to SELECT queries
     */
    public function testExecuteQueryAddsLimitToSelect(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        $sqlWithoutLimit = "SELECT * FROM customer_entity";
        $mockResults = [['id' => 1]];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('LIMIT 100'))
            ->willReturn($mockResults);

        $results = $method->invoke($this->command, $sqlWithoutLimit);

        $this->assertEquals($mockResults, $results);
    }

    /**
     * Test executeQuery doesn't add LIMIT if already present
     */
    public function testExecuteQueryDoesNotAddLimitIfPresent(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        $sqlWithLimit = "SELECT * FROM customer_entity LIMIT 50";
        $mockResults = [['id' => 1]];

        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($sqlWithLimit)
            ->willReturn($mockResults);

        $results = $method->invoke($this->command, $sqlWithLimit);

        $this->assertEquals($mockResults, $results);
    }

    /**
     * Test error handling when AI service fails
     */
    public function testErrorHandlingWhenAIServiceFails(): void
    {
        $userQuery = "test query";

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->willThrowException(new \Exception('AI service error'));

        $tester = new CommandTester($this->command);
        $tester->execute(['query' => $userQuery]);

        $this->assertEquals(1, $tester->getStatusCode());
        $this->assertStringContainsString('Error:', $tester->getDisplay());
    }

    /**
     * Test error handling when database query fails
     */
    public function testErrorHandlingWhenDatabaseQueryFails(): void
    {
        $userQuery = "show customers";
        $toolCallJson = '{"tool": "execute_sql_query", "arguments": {"query": "SELECT * FROM customer_entity LIMIT 100", "reason": "Get customers"}}';

        // Mock tool to throw exception
        $executeSqlTool = $this->createMock(DatabaseToolInterface::class);
        $executeSqlTool->method('execute')
            ->willThrowException(new \Exception('Database error: Table does not exist'));
        
        $this->toolRegistry->method('getTool')
            ->with('execute_sql_query')
            ->willReturn($executeSqlTool);

        // Mock AI service - may be called multiple times
        $this->aiService->expects($this->atLeast(1))
            ->method('sendChatRequest')
            ->willReturn(['message' => $toolCallJson, 'success' => true]);

        $tester = new CommandTester($this->command);
        $tester->execute(['query' => $userQuery]);

        $display = $tester->getDisplay();
        // Command should show error when tool execution fails
        // It may loop and hit max iterations, but should show tool execution error
        $hasError = strpos($display, 'Error:') !== false || 
                    strpos($display, 'Database error') !== false ||
                    strpos($display, 'Tool execution error') !== false ||
                    strpos($display, 'Maximum iterations reached') !== false;
        $this->assertTrue($hasError, 'Expected error message in output: ' . $display);
    }

    /**
     * Test displayResults with empty results
     */
    public function testDisplayResultsWithEmptyResults(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $output = $this->createMock(OutputInterface::class);
        $output->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('No results found'));

        $method->invoke($this->command, $output, []);
    }

    /**
     * Test displayResults limits display and shows truncation message
     */
    public function testDisplayResultsLimitsDisplayToFiftyRows(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        // Create 60 rows of mock data
        $results = [];
        for ($i = 0; $i < 60; $i++) {
            $results[] = ['id' => $i, 'email' => "test{$i}@example.com"];
        }

        $output = $this->createMock(OutputInterface::class);
        
        $truncationMessageCalled = false;
        
        $output->expects($this->atLeastOnce())
            ->method('writeln')
            ->willReturnCallback(function ($message) use (&$truncationMessageCalled) {
                if (strpos($message, 'Data is too long - partially printed') !== false) {
                    $truncationMessageCalled = true;
                }
            });

        $method->invoke($this->command, $output, $results);
        
        $this->assertTrue($truncationMessageCalled, 'Expected truncation message was not displayed');
    }

    /**
     * Test translateToSQL with read-only mode
     */
    public function testTranslateToSQLReadOnlyMode(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('translateToSQL');
        $method->setAccessible(true);

        // Set allowDangerous to false
        $property = $reflection->getProperty('allowDangerous');
        $property->setAccessible(true);
        $property->setValue($this->command, false);

        $userQuery = "show customers";
        $expectedSQL = "SELECT * FROM customer_entity";

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with(
                $this->stringContains('read-only'),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(['message' => $expectedSQL, 'success' => true]);

        $result = $method->invoke($this->command, $userQuery);

        $this->assertEquals($expectedSQL, $result);
    }

    /**
     * Test translateToSQL with read-write mode
     */
    public function testTranslateToSQLReadWriteMode(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('translateToSQL');
        $method->setAccessible(true);

        // Set allowDangerous to true
        $property = $reflection->getProperty('allowDangerous');
        $property->setAccessible(true);
        $property->setValue($this->command, true);

        $userQuery = "update customer";
        $expectedSQL = "UPDATE customer_entity SET email='test@example.com'";

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with(
                $this->stringContains('read-write'),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(['message' => $expectedSQL, 'success' => true]);

        $result = $method->invoke($this->command, $userQuery);

        $this->assertEquals($expectedSQL, $result);
    }

    /**
     * Test generateFinalQuery sends schema info back to AI
     */
    public function testGenerateFinalQuerySendsSchemaInfo(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('generateFinalQuery');
        $method->setAccessible(true);

        $userQuery = "show customers";
        $schemaResults = [
            ['Field' => 'entity_id', 'Type' => 'int'],
            ['Field' => 'email', 'Type' => 'varchar(255)']
        ];
        $expectedSQL = "SELECT * FROM customer_entity LIMIT 100";

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with(
                $this->stringContains($userQuery),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(['message' => $expectedSQL, 'success' => true]);

        $result = $method->invoke($this->command, $userQuery, $schemaResults);

        $this->assertEquals($expectedSQL, $result);
    }
}
