<?php
namespace Genaker\MagentoMcpAi\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Service\LLM;
use Psr\Log\LoggerInterface;

class LLMTest extends TestCase
{
    /**
     * @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfig;

    /**
     * @var AIServiceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $aiService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var LLM
     */
    private $llmService;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->aiService = $this->createMock(AIServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->llmService = new LLM($this->scopeConfig, $this->aiService, $this->logger);
    }

    /**
     * Test getApiKey returns the API key from config
     */
    public function testGetApiKeyReturnsValidKey(): void
    {
        $expectedApiKey = 'test-api-key-123';
        $this->scopeConfig->method('getValue')->willReturn($expectedApiKey);

        $result = $this->llmService->getApiKey();

        $this->assertEquals($expectedApiKey, $result);
    }

    /**
     * Test getApiKey throws exception when API key is empty
     */
    public function testGetApiKeyThrowsExceptionWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('OpenAI API key is not set in the admin configuration');

        $this->llmService->getApiKey();
    }

    /**
     * Test getApiKey throws exception when API key is not configured
     */
    public function testGetApiKeyThrowsExceptionWhenNull(): void
    {
        // Fresh instance because getApiKey caches
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $aiService = $this->createMock(AIServiceInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $llmService = new LLM($scopeConfig, $aiService, $logger);

        $scopeConfig->method('getValue')->willReturn(null);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('OpenAI API key is not set in the admin configuration');

        $llmService->getApiKey();
    }

    /**
     * Test LLM method converts string query to sendChatRequest call
     */
    public function testLLMMethodConvertsStringQuery(): void
    {
        $query = 'What is AI?';
        $temperature = 0.7;
        $apiKey = 'test-api-key';

        $this->scopeConfig->method('getValue')->willReturn($apiKey);

        $expectedResponse = ['success' => true, 'message' => 'AI is artificial intelligence'];

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with($query, [], 2000, $temperature)
            ->willReturn($expectedResponse);

        $result = $this->llmService->LLM($query, 'gpt-3.5-turbo', $temperature);

        $this->assertEquals($expectedResponse, $result);
    }

    /**
     * Test LLM method handles messages array and extracts user message
     */
    public function testLLMMethodHandlesMessagesArray(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant'],
            ['role' => 'user', 'content' => 'What is AI?']
        ];
        $temperature = 0.7;
        $apiKey = 'test-api-key';

        $this->scopeConfig->method('getValue')->willReturn($apiKey);

        $expectedResponse = ['success' => true];

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with('What is AI?', $messages, 2000, $temperature)
            ->willReturn($expectedResponse);

        $result = $this->llmService->LLM($messages, 'gpt-3.5-turbo', $temperature);

        $this->assertEquals($expectedResponse, $result);
    }

    /**
     * Test LLM method uses default model and temperature
     */
    public function testLLMMethodUsesDefaults(): void
    {
        $query = 'Test query';
        $apiKey = 'test-api-key';

        $this->scopeConfig->method('getValue')->willReturn($apiKey);

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with($query, [], 2000, 1)
            ->willReturn(['success' => true]);

        $result = $this->llmService->LLM($query);

        $this->assertTrue($result['success']);
    }

    /**
     * Test LLM returns response from AI service
     */
    public function testLLMReturnsCompleteResponse(): void
    {
        $query = 'What is machine learning?';
        $apiKey = 'test-api-key';

        $this->scopeConfig->method('getValue')->willReturn($apiKey);

        $expectedResponse = [
            'success' => true,
            'message' => 'Machine learning is a subset of AI...',
            'tokens' => ['input' => 5, 'output' => 50, 'total' => 55],
            'cost' => 0.001,
            'model' => 'gpt-4',
            'provider' => 'openai',
            'finish_reason' => 'stop'
        ];

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn($expectedResponse);

        $result = $this->llmService->LLM($query, 'gpt-4', 0.5);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertEquals('stop', $result['finish_reason']);
    }

    /**
     * Test LLM with empty query string
     */
    public function testLLMWithEmptyQuery(): void
    {
        $apiKey = 'test-api-key';
        $this->scopeConfig->method('getValue')->willReturn($apiKey);

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with('', [], 2000, 1)
            ->willReturn(['success' => true]);

        $result = $this->llmService->LLM('');
        $this->assertTrue($result['success']);
    }

    /**
     * Test LLM passes maxTokens as 2000
     */
    public function testLLMPassesMaxTokens(): void
    {
        $query = 'Test';
        $apiKey = 'test-api-key';

        $this->scopeConfig->method('getValue')->willReturn($apiKey);

        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with($query, [], 2000, 1)
            ->willReturn(['success' => true]);

        $this->llmService->LLM($query);
    }

    /**
     * Test LLM with different temperature values
     */
    public function testLLMWithDifferentTemperatures(): void
    {
        $apiKey = 'test-api-key';

        $temperatures = [0.1, 0.5, 0.7, 1.0];

        foreach ($temperatures as $temp) {
            // Fresh instance for each temperature test
            $scopeConfig = $this->createMock(ScopeConfigInterface::class);
            $aiService = $this->createMock(AIServiceInterface::class);
            $logger = $this->createMock(LoggerInterface::class);
            $llmService = new LLM($scopeConfig, $aiService, $logger);

            $scopeConfig->method('getValue')->willReturn($apiKey);
            $aiService->expects($this->once())
                ->method('sendChatRequest')
                ->with('Test', [], 2000, $temp)
                ->willReturn(['success' => true]);

            $llmService->LLM('Test', 'gpt-3.5-turbo', $temp);
        }
    }

    /**
     * Test LLM service can be instantiated
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(LLM::class, $this->llmService);
    }

    /**
     * Test LLM service has required methods
     */
    public function testServiceHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists($this->llmService, 'getApiKey'));
        $this->assertTrue(method_exists($this->llmService, 'LLM'));
    }
}
