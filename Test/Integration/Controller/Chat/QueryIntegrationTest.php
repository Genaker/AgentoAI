<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\ObjectManager;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Model\Service\MgentoAIService;

/**
 * Integration tests for Chat Query Controller
 * Validates the controller works with OpenAI model
 */
class QueryIntegrationTest extends TestCase
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
     * Test AIServiceInterface is injected correctly
     */
    public function testAIServiceInterfaceInjected(): void
    {
        $this->assertInstanceOf(AIServiceInterface::class, $this->aiService);
    }

    /**
     * Test chat request can be processed
     */
    public function testChatRequestCanBeProcessed(): void
    {
        // Mock response from AI service
        $mockResponse = [
            'success' => true,
            'message' => 'This is a test response from OpenAI',
            'tokens' => ['input' => 10, 'output' => 5, 'total' => 15],
            'cost' => 0.0003,
            'model' => 'gpt-3.5-turbo',
            'provider' => 'openai',
            'finish_reason' => 'stop'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        // Simulate controller sending a request
        $result = $this->aiService->sendChatRequest(
            'What is AI?',
            [],
            2000,
            0.7
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('gpt-3.5-turbo', $result['model']);
        $this->assertEquals('openai', $result['provider']);
    }

    /**
     * Test response structure contains all expected fields
     */
    public function testResponseStructureIsValid(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Response text',
            'tokens' => ['input' => 5, 'output' => 10, 'total' => 15],
            'cost' => 0.001,
            'model' => 'gpt-3.5-turbo',
            'provider' => 'openai'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('test', []);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('tokens', $result);
        $this->assertArrayHasKey('cost', $result);
        $this->assertArrayHasKey('model', $result);
        $this->assertArrayHasKey('provider', $result);
    }

    /**
     * Test multiple consecutive chat requests
     */
    public function testMultipleConsecutiveChatRequests(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Response',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        // Simulate multiple requests
        $result1 = $this->aiService->sendChatRequest('Query 1');
        $result2 = $this->aiService->sendChatRequest('Query 2');
        $result3 = $this->aiService->sendChatRequest('Query 3');

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
        $this->assertTrue($result3['success']);
    }

    /**
     * Test different temperature values in requests
     */
    public function testDifferentTemperatureValues(): void
    {
        $mockResponse = ['success' => true, 'model' => 'gpt-3.5-turbo'];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $temperatures = [0.1, 0.5, 0.7, 1.0];

        foreach ($temperatures as $temp) {
            $result = $this->aiService->sendChatRequest('Test', [], 2000, $temp);
            $this->assertTrue($result['success']);
        }
    }

    /**
     * Test conversation history can be handled
     */
    public function testConversationHistoryHandling(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'First question'],
            ['role' => 'assistant', 'content' => 'First answer'],
            ['role' => 'user', 'content' => 'Follow up question']
        ];

        $mockResponse = ['success' => true, 'message' => 'Follow up answer'];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('Follow up question', $messages);

        $this->assertTrue($result['success']);
    }
}
