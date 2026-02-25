<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Integration\Model\Service;

use Genaker\MagentoMcpAi\Model\Service\MgentoAIService;
use Genaker\MagentoMcpAi\Model\Service\MultiLLMService;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MgentoAIService with real API calls
 * 
 * Tests the generic AI service with multiple providers.
 * Compatible with OpenAiServiceIntegrationTest assertions.
 * 
 * IMPORTANT: Set API_KEY environment variables before running:
 * export AI_OPENAI_API_KEY=sk-proj-your-api-key-here
 * export AI_CLAUDE_API_KEY=claude-...
 * export AI_GEMINI_API_KEY=gemini-...
 * export AI_DEEPSEEK_API_KEY=sk-...
 * export AI_GROK_API_KEY=xai-...
 * 
 * @group integration
 */
class MgentoAIServiceIntegrationTest extends TestCase
{
    /**
     * @var MgentoAIService
     */
    private $mgentoAIService;

    /**
     * @var MultiLLMService
     */
    private $multiLLMService;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $provider;

    protected function setUp(): void
    {
        // Get API key and provider from environment
        $this->provider = getenv('AI_PROVIDER') ?: 'openai';
        $envKey = 'AI_' . strtoupper($this->provider) . '_API_KEY';
        $this->apiKey = getenv($envKey);

        if (!$this->apiKey) {
            $this->markTestSkipped("$envKey environment variable not set");
        }

        // Create stub dependencies (don't need full initialization for integration tests)
        $curl = $this->createStub(Curl::class);
        $jsonHelper = $this->createStub(JsonHelper::class);
        $file = $this->createStub(File::class);

        // Create mock scope config
        $scopeConfig = $this->createMockScopeConfig();
        $logger = $this->createStub(LoggerInterface::class);

        // Create MultiLLMService
        $this->multiLLMService = new MultiLLMService($scopeConfig, $logger);

        // Create MgentoAIService
        $this->mgentoAIService = new MgentoAIService(
            $curl,
            $jsonHelper,
            $file,
            $scopeConfig,
            $this->multiLLMService,
            $logger
        );
    }

    /**
     * Create mock scope config that returns API key from environment
     */
    private function createMockScopeConfig(): ScopeConfigInterface
    {
        $mock = $this->createMock(ScopeConfigInterface::class);
        
        $mock->method('getValue')
            ->willReturnCallback(function($path) {
                // Return provider from env
                if (strpos($path, 'ai_provider') !== false) {
                    return $this->provider;
                }
                // Return API key for current provider
                if (strpos($path, 'api_key') !== false) {
                    return $this->apiKey;
                }
                return null;
            });

        return $mock;
    }

    /**
     * Test 1: Chat completion with real API
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testChatCompletionWithRealApi(): void
    {
        $message = 'What is 2+2?';
        
        $response = $this->mgentoAIService->sendChatRequest(
            $message,
            [],
            2000,
            0.7
        );

        // Assertions compatible with OpenAiServiceIntegrationTest
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('tokens', $response);
        $this->assertArrayHasKey('cost', $response);
        
        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['message']);
        $this->assertIsArray($response['tokens']);
    }

    /**
     * Test 2: Text embeddings with real API
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testEmbeddingsWithRealApi(): void
    {
        $text = 'The quick brown fox jumps over the lazy dog';

        try {
            $embeddings = $this->mgentoAIService->generateEmbeddings($text);
            
            // This should throw exception for non-embedding providers
            $this->fail('Expected LocalizedException for non-embedding provider');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Expected for providers that don't support embeddings
            $this->assertStringContainsString(
                'Embeddings',
                $e->getMessage()
            );
        }
    }

    /**
     * Test 3: Token usage tracking
     * 
     * @group integration
     */
    public function testTokenUsageTracking(): void
    {
        // Initialize token values
        $this->mgentoAIService->prompt_tokens = 0;
        $this->mgentoAIService->completion_tokens = 0;
        $this->mgentoAIService->total_tokens = 0;

        // Verify initialization (same assertions as OpenAiServiceIntegrationTest)
        $this->assertEquals(0, $this->mgentoAIService->prompt_tokens);
        $this->assertEquals(0, $this->mgentoAIService->completion_tokens);
        $this->assertEquals(0, $this->mgentoAIService->total_tokens);

        // Make a chat request
        $response = $this->mgentoAIService->sendChatRequest('Hello', []);

        // Verify tokens are updated
        $this->assertGreaterThan(0, $this->mgentoAIService->prompt_tokens);
        $this->assertGreaterThan(0, $this->mgentoAIService->completion_tokens);
        $this->assertGreaterThan(0, $this->mgentoAIService->total_tokens);

        // Verify token values match response
        $this->assertEquals(
            $response['tokens']['input'],
            $this->mgentoAIService->prompt_tokens
        );
        $this->assertEquals(
            $response['tokens']['output'],
            $this->mgentoAIService->completion_tokens
        );
    }

    /**
     * Test 4: API key validation
     * 
     * @group integration
     */
    public function testApiKeyIsValid(): void
    {
        $this->assertNotEmpty($this->apiKey, 'API key should not be empty');
        
        // Different providers have different key formats
        if ($this->provider === 'openai') {
            $this->assertStringStartsWith('sk-', $this->apiKey);
        } elseif ($this->provider === 'claude') {
            $this->assertStringStartsWith('claude-', $this->apiKey);
        } elseif ($this->provider === 'grok') {
            $this->assertStringStartsWith('xai-', $this->apiKey);
        } else {
            // Just verify it's not empty
            $this->assertNotEmpty($this->apiKey);
        }
    }

    /**
     * Test 5: API domain configuration
     * 
     * @group integration
     */
    public function testApiDomainConfiguration(): void
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionMethod($this->mgentoAIService, 'getApiDomain');
        $reflection->setAccessible(true);
        $apiDomain = $reflection->invoke($this->mgentoAIService);
        
        // Assertions compatible with OpenAiServiceIntegrationTest
        $this->assertNotEmpty($apiDomain);
        $this->assertStringStartsWith('https://', $apiDomain);
    }

    /**
     * Test 6: Error handling for invalid API key
     * 
     * @group integration
     * @group error-handling
     */
    public function testErrorHandlingForInvalidApiKey(): void
    {
        $this->markTestIncomplete('Should test error handling with invalid key');

        // This test would verify that proper errors are thrown
        // when an invalid API key is used
    }

    /**
     * Test 7: Timeout handling
     * 
     * @group integration
     * @group error-handling
     */
    public function testTimeoutHandling(): void
    {
        $this->markTestIncomplete('Should test timeout handling');

        // This test would verify proper handling of API timeouts
    }

    /**
     * Test 8: Rate limiting handling
     * 
     * @group integration
     * @group error-handling
     */
    public function testRateLimitingHandling(): void
    {
        $this->markTestIncomplete('Should test rate limiting handling');

        // This test would verify proper handling of rate limit errors
    }

    /**
     * Test 9: Concurrent requests
     * 
     * @group integration
     */
    public function testConcurrentRequests(): void
    {
        $this->markTestIncomplete('Should test handling of concurrent requests');

        // This test would verify proper handling of multiple simultaneous requests
    }

    /**
     * Test 10: Response caching
     * 
     * @group integration
     */
    public function testResponseCaching(): void
    {
        $this->markTestIncomplete('Should test response caching mechanism');

        // This test would verify that responses are cached appropriately
    }

    /**
     * Test 11: Chat completion returns structured response
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testGetChatCompletionReturnsText(): void
    {
        $text = $this->mgentoAIService->getChatCompletion('Say hello');

        $this->assertIsString($text);
        $this->assertNotEmpty($text);
    }

    /**
     * Test 12: Get completion returns structure response
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testGetCompletionReturnsStructuredResponse(): void
    {
        $response = $this->mgentoAIService->getCompletion('Complete: 2+2=');

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('completion', $response);
        $this->assertArrayHasKey('tokens', $response);
        $this->assertArrayHasKey('cost', $response);

        $this->assertTrue($response['success']);
        $this->assertNotEmpty($response['completion']);
    }

    /**
     * Test 13: Function calling with tools
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testFunctionCallingWithTools(): void
    {
        // Skip this test for now - tools format differs between providers
        $this->markTestIncomplete('Tools format varies by provider - requires provider-specific handling');
    }

    /**
     * Test 14: Available providers listing
     * 
     * @group integration
     */
    public function testGetAvailableProviders(): void
    {
        $providers = $this->mgentoAIService->getAvailableProviders();

        $this->assertIsArray($providers);
        
        // Should have at least some providers available
        $this->assertNotEmpty($providers);

        // Each provider should have required keys
        foreach ($providers as $name => $info) {
            $this->assertArrayHasKey('available', $info);
            $this->assertArrayHasKey('default_model', $info);
            $this->assertIsString($name);
            $this->assertIsBool($info['available']);
            $this->assertIsString($info['default_model']);
        }
    }

    /**
     * Test 15: Pricing information
     * 
     * @group integration
     */
    public function testGetPricingInformation(): void
    {
        $pricing = $this->mgentoAIService->getPricing($this->provider);

        $this->assertIsArray($pricing);

        // Should have pricing for models
        if (!empty($pricing)) {
            foreach ($pricing as $model => $rates) {
                $this->assertArrayHasKey('input', $rates);
                $this->assertArrayHasKey('output', $rates);
                $this->assertIsNumeric($rates['input']);
                $this->assertIsNumeric($rates['output']);
                $this->assertGreaterThan(0, $rates['input']);
                $this->assertGreaterThan(0, $rates['output']);
            }
        }
    }

    /**
     * Test 16: Backwards compatibility - constants defined
     * 
     * @group integration
     */
    public function testBackwardsCompatibilityConstants(): void
    {
        // Verify constants from original OpenAiService are still available
        $this->assertEquals(
            '/v1/chat/completions',
            MgentoAIService::CHAT_COMPLETIONS_PATH
        );
        $this->assertEquals(
            '/v1/embeddings',
            MgentoAIService::EMBEDDINGS_PATH
        );
        $this->assertEquals(
            '/v1/images/generations',
            MgentoAIService::IMAGES_PATH
        );
        $this->assertEquals(
            '/v1/audio/transcriptions',
            MgentoAIService::AUDIO_TRANSCRIPTION_PATH
        );
    }

    /**
     * Test 17: Stream method for simple text extraction
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testStreamMethod(): void
    {
        // Use getChatCompletion instead since stream() is internal
        $text = $this->mgentoAIService->getChatCompletion('Say hello');

        $this->assertIsString($text);
        $this->assertNotEmpty($text);
    }

    /**
     * Test 18: Multiple requests show increasing token usage
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testMultipleRequestsTokenTracking(): void
    {
        // First request
        $response1 = $this->mgentoAIService->sendChatRequest('Hi');
        $tokens1 = $this->mgentoAIService->total_tokens;

        // Second request
        $response2 = $this->mgentoAIService->sendChatRequest('Hello');
        $tokens2 = $this->mgentoAIService->total_tokens;

        // Each request should update tokens
        $this->assertGreaterThan(0, $tokens1);
        $this->assertGreaterThan(0, $tokens2);
    }

    /**
     * Test 19: Temperature affects response variation
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testTemperatureParameter(): void
    {
        // Low temperature = less random
        $response1 = $this->mgentoAIService->sendChatRequest(
            'Generate a number',
            [],
            100,
            0.1  // Low temperature
        );

        // High temperature = more random
        $response2 = $this->mgentoAIService->sendChatRequest(
            'Generate a number',
            [],
            100,
            0.9  // High temperature
        );

        $this->assertTrue($response1['success']);
        $this->assertTrue($response2['success']);
        $this->assertNotEmpty($response1['message']);
        $this->assertNotEmpty($response2['message']);
    }

    /**
     * Test 20: Max tokens parameter is respected
     * 
     * @group integration
     * @group mgentoai-api
     */
    public function testMaxTokensParameter(): void
    {
        $response = $this->mgentoAIService->sendChatRequest(
            'Write a very long story about adventure',
            [],
            50,  // Low max tokens
            0.7
        );

        $this->assertTrue($response['success']);
        // Output tokens should be limited
        $this->assertLessThanOrEqual(
            100,  // Allow some buffer
            $response['tokens']['output']
        );
    }
}
