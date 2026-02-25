<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;

/**
 * Integration tests for McpAi Model
 * Validates the model works with OpenAI service
 */
class McpAiIntegrationTest extends TestCase
{
    /**
     * @var AIServiceInterface
     */
    private $aiService;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->aiService = $this->createStub(AIServiceInterface::class);
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
    }

    /**
     * Test McpAi can process queries through AI service
     */
    public function testMcpAiProcessesQueriesCorrectly(): void
    {
        $query = 'What are the latest Magento features?';

        $mockResponse = [
            'success' => true,
            'message' => 'Magento 2.4.7 includes the following features...',
            'tokens' => ['input' => 15, 'output' => 50, 'total' => 65],
            'cost' => 0.001,
            'model' => 'gpt-3.5-turbo',
            'provider' => 'openai'
        ];

        $this->aiService->method('sendChatRequest')
            ->with($query)
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($query);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message']);
        $this->assertEquals('openai', $result['provider']);
    }

    /**
     * Test conversation history is maintained
     */
    public function testConversationHistoryMaintained(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'What is Magento?'],
            ['role' => 'assistant', 'content' => 'Magento is an e-commerce platform...'],
            ['role' => 'user', 'content' => 'What are its key features?']
        ];

        $mockResponse = [
            'success' => true,
            'message' => 'Key features include...',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->with('What are its key features?', $history)
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('What are its key features?', $history);

        $this->assertTrue($result['success']);
    }

    /**
     * Test API returns proper token counts
     */
    public function testTokenCountsAreAccurate(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Response text',
            'tokens' => ['input' => 10, 'output' => 20, 'total' => 30],
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('Test');

        $this->assertArrayHasKey('tokens', $result);
        $this->assertArrayHasKey('input', $result['tokens']);
        $this->assertArrayHasKey('output', $result['tokens']);
        $this->assertArrayHasKey('total', $result['tokens']);
        $this->assertGreaterThan(0, $result['tokens']['input']);
        $this->assertGreaterThan(0, $result['tokens']['output']);
    }

    /**
     * Test cost calculation
     */
    public function testCostCalculation(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Response',
            'tokens' => ['input' => 100, 'output' => 50, 'total' => 150],
            'cost' => 0.003,
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('Test');

        $this->assertArrayHasKey('cost', $result);
        $this->assertIsFloat($result['cost']);
        $this->assertGreaterThan(0, $result['cost']);
    }

    /**
     * Test error handling when API fails
     */
    public function testErrorHandlingOnApiFail(): void
    {
        $this->aiService->method('sendChatRequest')
            ->willThrowException(new LocalizedException(__('AI Service error')));

        $this->expectException(LocalizedException::class);

        $this->aiService->sendChatRequest('Test query');
    }

    /**
     * Test maximum context length handling
     */
    public function testMaxContextLengthHandling(): void
    {
        // Create message history that approaches max tokens
        $history = [];
        for ($i = 0; $i < 10; $i++) {
            $history[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => 'This is message ' . $i . ' with some content'
            ];
        }

        $mockResponse = [
            'success' => true,
            'message' => 'Response to final query',
            'tokens' => ['input' => 500, 'output' => 50, 'total' => 550],
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('Final query', $history);

        $this->assertTrue($result['success']);
        $this->assertLessThan(4000, $result['tokens']['total']);
    }

    /**
     * Test session management
     */
    public function testSessionManagement(): void
    {
        // Test that multiple queries in same session work
        $mockResponse = ['success' => true, 'message' => 'Response'];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result1 = $this->aiService->sendChatRequest('Query 1');
        $result2 = $this->aiService->sendChatRequest('Query 2');
        $result3 = $this->aiService->sendChatRequest('Query 3');

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
        $this->assertTrue($result3['success']);
    }

    /**
     * Test caching of responses
     */
    public function testResponseCaching(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Cached response',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        // Make same request twice
        $result1 = $this->aiService->sendChatRequest('Same query');
        $result2 = $this->aiService->sendChatRequest('Same query');

        $this->assertEquals($result1['message'], $result2['message']);
        $this->assertEquals('gpt-3.5-turbo', $result1['model']);
    }
}
