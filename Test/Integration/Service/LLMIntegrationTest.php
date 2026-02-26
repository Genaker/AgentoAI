<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Service;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Service\LLM;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for LLM Service
 */
class LLMIntegrationTest extends TestCase
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var AIServiceInterface
     */
    private $aiService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LLM
     */
    private $llmService;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->aiService = $this->createStub(AIServiceInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->llmService = new LLM($this->scopeConfig, $this->aiService, $this->logger);
    }

    /**
     * Test LLM service initialization
     */
    public function testLLMServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(LLM::class, $this->llmService);
    }

    /**
     * Test that LLM service has required dependencies
     */
    public function testLLMServiceHasRequiredDependencies(): void
    {
        $reflection = new \ReflectionClass($this->llmService);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE);
        
        $propertyNames = array_map(fn($prop) => $prop->getName(), $properties);
        
        $this->assertContains('scopeConfig', $propertyNames);
        $this->assertContains('aiService', $propertyNames);
        $this->assertContains('apiKey', $propertyNames);
    }

    /**
     * Test that LLM service has required public methods
     */
    public function testLLMServiceHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists($this->llmService, 'getApiKey'));
        $this->assertTrue(method_exists($this->llmService, 'LLM'));
    }

    /**
     * Test getApiKey method signature
     */
    public function testGetApiKeyMethodSignature(): void
    {
        $reflection = new \ReflectionMethod($this->llmService, 'getApiKey');
        
        $this->assertTrue($reflection->isPublic());
        $this->assertEquals('string', $reflection->getReturnType());
    }

    /**
     * Test LLM method signature
     */
    public function testLLMMethodSignature(): void
    {
        $reflection = new \ReflectionMethod($this->llmService, 'LLM');
        
        $this->assertTrue($reflection->isPublic());
        $parameters = $reflection->getParameters();
        
        $this->assertCount(3, $parameters);
        $this->assertEquals('query', $parameters[0]->getName());
        $this->assertEquals('model', $parameters[1]->getName());
        $this->assertEquals('temperature', $parameters[2]->getName());
        
        // Check defaults
        $this->assertEquals('gpt-5-nano', $parameters[1]->getDefaultValue());
        $this->assertEquals(1, $parameters[2]->getDefaultValue());
    }

    /**
     * Test integration with mocked API service - string query
     */
    public function testIntegrationWithStringQuery(): void
    {
        $apiKey = 'test-key';
        $query = 'What is AI?';
        
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $mockResponse = [
            'success' => true,
            'message' => 'AI is artificial intelligence',
            'tokens' => ['input' => 5, 'output' => 10, 'total' => 15],
        ];
        
        $this->aiService->method('sendChatRequest')->willReturn($mockResponse);
        
        $result = $this->llmService->LLM($query);
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test integration with mocked API service - array query
     */
    public function testIntegrationWithArrayQuery(): void
    {
        $apiKey = 'test-key';
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Explain AI']
        ];
        
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $mockResponse = [
            'success' => true,
            'message' => 'AI explanation here',
            'tokens' => ['input' => 15, 'output' => 20, 'total' => 35],
        ];
        
        $this->aiService->method('sendChatRequest')->willReturn($mockResponse);
        
        $result = $this->llmService->LLM($messages);
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test that API key is retrieved from configuration
     */
    public function testApiKeyRetrievedFromConfiguration(): void
    {
        $expectedKey = 'secret-api-key-12345';
        
        $this->scopeConfig
            ->expects($this->atLeastOnce())
            ->method('getValue')
            ->with(
                'magentomcpai/general/api_key',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            )
            ->willReturn($expectedKey);
        
        $key = $this->llmService->getApiKey();
        
        $this->assertEquals($expectedKey, $key);
    }

    /**
     * Test that missing API key throws exception
     */
    public function testMissingApiKeyThrowsException(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('OpenAI API key is not set');
        
        $this->llmService->getApiKey();
    }

    /**
     * Test that AI service is called with correct parameters
     */
    public function testAiServiceCalledWithCorrectParameters(): void
    {
        $apiKey = 'test-key';
        $query = 'Test question';
        $model = 'gpt-3.5-turbo';
        $temperature = 0.7;
        
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $this->aiService
            ->expects($this->once())
            ->method('sendChatRequest')
            ->with(
                $query,
                [],
                2000,
                $temperature
            )
            ->willReturn(['success' => true]);
        
        $this->llmService->LLM($query, $model, $temperature);
    }

    /**
     * Test that response from AI service is returned unchanged
     */
    public function testResponseFromAiServiceReturnedUnchanged(): void
    {
        $apiKey = 'test-key';
        $query = 'Test';
        
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $expectedResponse = [
            'success' => true,
            'message' => 'Response text',
            'tokens' => ['input' => 5, 'output' => 15, 'total' => 20],
            'cost' => 0.001,
            'model' => 'gpt-3.5-turbo',
            'provider' => 'openai',
            'finish_reason' => 'stop'
        ];
        
        $this->aiService->method('sendChatRequest')->willReturn($expectedResponse);
        
        $result = $this->llmService->LLM($query);
        
        $this->assertEquals($expectedResponse, $result);
    }

    /**
     * Test service handles multiple consecutive requests
     */
    public function testMultipleConsecutiveRequests(): void
    {
        $apiKey = 'test-key';
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $mockResponse = ['success' => true, 'message' => 'Response'];
        $this->aiService->method('sendChatRequest')->willReturn($mockResponse);
        
        // Make multiple requests
        $result1 = $this->llmService->LLM('Query 1');
        $result2 = $this->llmService->LLM('Query 2');
        $result3 = $this->llmService->LLM('Query 3');
        
        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
        $this->assertTrue($result3['success']);
    }

    /**
     * Test service with different model configurations
     */
    public function testWithDifferentModelConfigurations(): void
    {
        $apiKey = 'test-key';
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $models = ['gpt-3.5-turbo', 'gpt-4', 'claude-3-haiku', 'gemini-pro'];
        
        foreach ($models as $model) {
            $response = ['success' => true, 'model' => $model];
            $this->aiService->method('sendChatRequest')->willReturn($response);
            
            $result = $this->llmService->LLM('Test', $model);
            $this->assertTrue($result['success']);
        }
    }

    /**
     * Test that temperature values are passed correctly
     */
    public function testTemperaturePassedCorrectly(): void
    {
        $temperatures = [0.0, 0.5, 1.0, 1.5, 2.0];
        $apiKey = 'test-key';
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        // Create a mock instead of stub to track expectations properly
        $aiServiceMock = $this->createMock(AIServiceInterface::class);
        $llmService = new LLM($this->scopeConfig, $aiServiceMock, $this->logger);
        
        // Track temperatures that were actually passed
        $actualTemperatures = [];
        
        $aiServiceMock
            ->expects($this->exactly(count($temperatures)))
            ->method('sendChatRequest')
            ->willReturnCallback(function($message, $messages, $maxTokens, $temperature) use (&$actualTemperatures) {
                $actualTemperatures[] = $temperature;
                return ['success' => true];
            });
        
        foreach ($temperatures as $temp) {
            $llmService->LLM('Test', 'gpt-3.5-turbo', $temp);
        }
        
        // Verify all temperatures were passed correctly
        $this->assertEquals($temperatures, $actualTemperatures);
    }

    /**
     * Test exception propagation from AI service
     */
    public function testExceptionPropagationFromAiService(): void
    {
        $apiKey = 'test-key';
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $this->aiService
            ->method('sendChatRequest')
            ->willThrowException(new LocalizedException(__('AI service unavailable')));
        
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('AI service unavailable');
        
        $this->llmService->LLM('Test query');
    }

    /**
     * Test LLM extracts last user message from messages array
     */
    public function testExtractsLastUserMessageFromArray(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'assistant', 'content' => 'How can I help?'],
            ['role' => 'user', 'content' => 'Final user message']
        ];
        $apiKey = 'test-key';
        
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $this->aiService
            ->expects($this->once())
            ->method('sendChatRequest')
            ->with(
                'Final user message',  // Should extract this
                $messages,
                2000,
                1
            )
            ->willReturn(['success' => true]);
        
        $this->llmService->LLM($messages);
    }

    /**
     * Test that maxTokens is always 2000
     */
    public function testMaxTokensIsAlways2000(): void
    {
        $apiKey = 'test-key';
        $this->scopeConfig->method('getValue')->willReturn($apiKey);
        
        $this->aiService
            ->expects($this->exactly(3))
            ->method('sendChatRequest')
            ->withConsecutive(
                ['Query1', [], 2000, 0.5],
                ['Query2', [], 2000, 0.7],
                ['Query3', [], 2000, 1.0]
            )
            ->willReturn(['success' => true]);
        
        $this->llmService->LLM('Query1', 'gpt-3.5-turbo', 0.5);
        $this->llmService->LLM('Query2', 'gpt-4', 0.7);
        $this->llmService->LLM('Query3', 'claude-3', 1.0);
    }
}
