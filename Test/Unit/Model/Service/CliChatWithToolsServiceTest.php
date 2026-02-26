<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Service;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Model\DatabaseTool\Registry as ToolRegistry;
use Genaker\MagentoMcpAi\Model\Service\CliChatWithToolsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CliChatWithToolsServiceTest extends TestCase
{
    private AIServiceInterface $aiService;
    private ToolRegistry $toolRegistry;
    private LoggerInterface $logger;
    private CliChatWithToolsService $service;

    protected function setUp(): void
    {
        $this->aiService    = $this->createMock(AIServiceInterface::class);
        $this->toolRegistry = $this->createMock(ToolRegistry::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->service = new CliChatWithToolsService(
            $this->aiService,
            $this->toolRegistry,
            $this->logger
        );
    }

    // -------------------------------------------------------------------------
    // Custom system message
    // -------------------------------------------------------------------------

    public function testCustomSystemMessageIsUsed(): void
    {
        $capturedMessages = null;

        $this->aiService
            ->expects($this->once())
            ->method('sendChatRequest')
            ->willReturnCallback(function ($prompt, $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return ['message' => 'Final answer', 'tool_calls' => null];
            });

        $this->service->setCustomSystemMessage('custom expert persona');
        $this->service->processQueryWithTools('hello', [], [['type' => 'function', 'function' => ['name' => 'tool1']]]);

        $systemMessages = array_filter($capturedMessages, fn($m) => $m['role'] === 'system');
        $this->assertNotEmpty($systemMessages);

        $systemContent = reset($systemMessages)['content'];
        $this->assertStringContainsString('custom expert persona', $systemContent);
    }

    public function testDefaultSystemMessageUsedWhenNoCustomSet(): void
    {
        $capturedMessages = null;

        $this->aiService
            ->method('sendChatRequest')
            ->willReturnCallback(function ($prompt, $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return ['message' => 'Final answer', 'tool_calls' => null];
            });

        // Tools must be non-empty for system message to be injected
        $this->service->processQueryWithTools('hello', [], [['type' => 'function', 'function' => ['name' => 'tool1']]]);

        $systemMessages = array_filter($capturedMessages, fn($m) => $m['role'] === 'system');
        $this->assertNotEmpty($systemMessages);

        $systemContent = reset($systemMessages)['content'];
        $this->assertStringContainsString('Magento 2', $systemContent);
    }

    // -------------------------------------------------------------------------
    // Native tool calls preferred over text parsing
    // -------------------------------------------------------------------------

    public function testNativeToolCallsArePreferredOverTextParsing(): void
    {
        // AI returns native tool call AND a JSON text that would also parse as a tool call
        $nativeToolCall = [
            'name'      => 'execute_sql_query',
            'arguments' => ['query' => 'SELECT 1', 'reason' => 'test'],
        ];

        $this->aiService
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => '{"tool": "describe_table", "arguments": {"table_name": "test"}}', // text JSON
                'tool_calls' => [$nativeToolCall], // native — should win
            ]);

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['success' => true, 'data' => []]);

        $this->toolRegistry
            ->method('getTool')
            ->with('execute_sql_query') // must use the native call's name
            ->willReturn($mockTool);

        $result = $this->service->processQueryWithTools('query db', [], []);

        $this->assertEquals('tool_called', $result['status']);
        $this->assertEquals('execute_sql_query', $result['tool_name']);
    }

    public function testTextParsingFallsBackWhenNoNativeToolCalls(): void
    {
        // AI returns JSON in text and no native tool_calls
        $this->aiService
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => '{"tool": "execute_sql_query", "arguments": {"query": "SELECT 1", "reason": "test"}}',
                'tool_calls' => null,
            ]);

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['success' => true, 'data' => []]);

        $this->toolRegistry
            ->method('getTool')
            ->with('execute_sql_query')
            ->willReturn($mockTool);

        $result = $this->service->processQueryWithTools('query db', [], []);

        $this->assertEquals('tool_called', $result['status']);
        $this->assertEquals('execute_sql_query', $result['tool_name']);
    }

    // -------------------------------------------------------------------------
    // next_query is the original query (not "DO NOT USE TOOLS")
    // -------------------------------------------------------------------------

    public function testNextQueryIsOriginalQueryAfterToolCall(): void
    {
        $this->aiService
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => '{"tool": "execute_sql_query", "arguments": {"query": "SELECT 1", "reason": "test"}}',
                'tool_calls' => null,
            ]);

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['success' => true, 'data' => []]);

        $this->toolRegistry->method('getTool')->willReturn($mockTool);

        $result = $this->service->processQueryWithTools('original user question', [], []);

        $this->assertEquals('tool_called', $result['status']);

        // next_query must be the original question — NOT the old "Based on tool results… DO NOT use tools" pattern
        $this->assertStringNotContainsString('DO NOT', $result['next_query'] ?? '');
        $this->assertStringNotContainsString('Do NOT use any tools', $result['next_query'] ?? '');
        $this->assertEquals('original user question', $result['next_query']);
    }

    // -------------------------------------------------------------------------
    // Final answer (no tool call)
    // -------------------------------------------------------------------------

    public function testFinalAnswerReturnedWhenNoToolCall(): void
    {
        $this->aiService
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => 'Here is the answer.',
                'tool_calls' => null,
            ]);

        $result = $this->service->processQueryWithTools('hello', [], []);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Here is the answer.', $result['message']);
    }

    // -------------------------------------------------------------------------
    // setAllowDangerous
    // -------------------------------------------------------------------------

    public function testSetAllowDangerousPropagatesFlag(): void
    {
        $capturedDangerous = null;

        $this->aiService
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => '{"tool": "execute_sql_query", "arguments": {"query": "DELETE FROM test", "reason": "test"}}',
                'tool_calls' => null,
            ]);

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function ($args, $dangerous) use (&$capturedDangerous) {
                $capturedDangerous = $dangerous;
                return ['success' => true];
            });

        $this->toolRegistry->method('getTool')->willReturn($mockTool);

        $this->service->setAllowDangerous(true);
        $this->service->processQueryWithTools('delete records', [], []);

        $this->assertTrue($capturedDangerous);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testErrorReturnedWhenAIServiceThrows(): void
    {
        $this->aiService
            ->method('sendChatRequest')
            ->willThrowException(new \Exception('Connection refused'));

        $result = $this->service->processQueryWithTools('question', [], []);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Connection refused', $result['message']);
    }

    public function testErrorReturnedWhenToolNotFound(): void
    {
        $this->aiService
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => '{"tool": "nonexistent_tool", "arguments": {}}',
                'tool_calls' => null,
            ]);

        $this->toolRegistry->method('getTool')->willReturn(null);

        $result = $this->service->processQueryWithTools('question', [], []);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('nonexistent_tool', $result['message']);
    }

    // -------------------------------------------------------------------------
    // Self-correction inner monologue
    // -------------------------------------------------------------------------

    public function testSelfCorrectionTriggeredOnToolError(): void
    {
        // First AI call returns a native tool call; second (self-correction) returns reasoning.
        $this->aiService
            ->expects($this->exactly(2))
            ->method('sendChatRequest')
            ->willReturnOnConsecutiveCalls(
                [
                    'message'    => '',
                    'tool_calls' => [['name' => 'execute_sql_query', 'arguments' => ['query' => 'SELECT * FROM bad_table', 'reason' => 'test']]],
                ],
                [
                    'message'    => 'The table bad_table does not exist. Use describe_table first.',
                    'tool_calls' => null,
                ]
            );

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['success' => false, 'error' => 'Table not found: bad_table']);

        $this->toolRegistry->method('getTool')->willReturn($mockTool);

        $result = $this->service->processQueryWithTools('show me orders', [], [['type' => 'function', 'function' => ['name' => 'execute_sql_query']]]);

        $this->assertEquals('tool_called', $result['status']);

        // Self-correction message must appear in history
        $corrections = array_filter(
            $result['conversation_history'],
            fn($m) => $m['role'] === 'assistant' && str_contains($m['content'], '[Self-correction]')
        );
        $this->assertNotEmpty($corrections, 'Self-correction message not found in conversation history');
        $this->assertStringContainsString(
            'bad_table does not exist',
            array_values($corrections)[0]['content']
        );
    }

    public function testSelfCorrectionNotTriggeredOnSuccessfulToolResult(): void
    {
        // Only one AI call should happen — no self-correction on success
        $this->aiService
            ->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => '',
                'tool_calls' => [['name' => 'execute_sql_query', 'arguments' => ['query' => 'SELECT 1', 'reason' => 'test']]],
            ]);

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['success' => true, 'result' => 'data', 'preview' => 'data', 'row_count' => 1]);

        $this->toolRegistry->method('getTool')->willReturn($mockTool);

        $result = $this->service->processQueryWithTools('query', [], []);

        // No self-correction entry in history
        $corrections = array_filter(
            $result['conversation_history'],
            fn($m) => $m['role'] === 'assistant' && str_contains($m['content'], '[Self-correction]')
        );
        $this->assertEmpty($corrections);
    }

    public function testSelfCorrectionSkippedWhenDisabled(): void
    {
        // With self-correction disabled, only one AI call for a tool error
        $this->aiService
            ->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn([
                'message'    => '',
                'tool_calls' => [['name' => 'execute_sql_query', 'arguments' => ['query' => 'SELECT 1', 'reason' => 'test']]],
            ]);

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['success' => false, 'error' => 'Some error']);

        $this->toolRegistry->method('getTool')->willReturn($mockTool);

        $this->service->setSelfCorrectionEnabled(false);
        $result = $this->service->processQueryWithTools('query', [], []);

        $this->assertEquals('tool_called', $result['status']);

        // No self-correction should appear in history
        $corrections = array_filter(
            $result['conversation_history'],
            fn($m) => $m['role'] === 'assistant' && str_contains($m['content'], '[Self-correction]')
        );
        $this->assertEmpty($corrections);
    }

    public function testSelfCorrectionTriggeredOnErrorKeyPresent(): void
    {
        // 'error' key alone (without 'success' key) should also trigger self-correction
        $this->aiService
            ->expects($this->exactly(2))
            ->method('sendChatRequest')
            ->willReturnOnConsecutiveCalls(
                ['message' => '', 'tool_calls' => [['name' => 'execute_sql_query', 'arguments' => ['query' => 'BAD SQL', 'reason' => 'test']]]],
                ['message' => 'SQL syntax is invalid. Fix the query.', 'tool_calls' => null]
            );

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['error' => 'Syntax error near BAD']);

        $this->toolRegistry->method('getTool')->willReturn($mockTool);

        $result = $this->service->processQueryWithTools('query', [], []);

        $corrections = array_filter(
            $result['conversation_history'],
            fn($m) => $m['role'] === 'assistant' && str_contains($m['content'], '[Self-correction]')
        );
        $this->assertNotEmpty($corrections);
    }

    public function testSelfCorrectionGracefulWhenAICallFails(): void
    {
        // Self-correction API call throws — should not propagate; tool_called still returned
        $this->aiService
            ->expects($this->exactly(2))
            ->method('sendChatRequest')
            ->willReturnOnConsecutiveCalls(
                ['message' => '', 'tool_calls' => [['name' => 'execute_sql_query', 'arguments' => ['query' => 'SELECT 1', 'reason' => 'test']]]],
                $this->throwException(new \Exception('Timeout'))
            );

        $mockTool = $this->createMock(DatabaseToolInterface::class);
        $mockTool->method('execute')->willReturn(['success' => false, 'error' => 'DB error']);

        $this->toolRegistry->method('getTool')->willReturn($mockTool);

        // Should not throw
        $result = $this->service->processQueryWithTools('query', [], []);
        $this->assertEquals('tool_called', $result['status']);

        // No self-correction entry (it failed silently)
        $corrections = array_filter(
            $result['conversation_history'],
            fn($m) => $m['role'] === 'assistant' && str_contains($m['content'], '[Self-correction]')
        );
        $this->assertEmpty($corrections);
    }
}
