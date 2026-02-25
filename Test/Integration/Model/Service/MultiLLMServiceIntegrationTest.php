<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Integration\Model\Service;

use Genaker\MagentoMcpAi\Model\Service\MultiLLMService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for MultiLLMService
 * 
 * These tests use real API providers if API keys are configured
 * Set environment variables to enable:
 * - AI_OPENAI_API_KEY
 * - AI_CLAUDE_API_KEY
 * - AI_GEMINI_API_KEY
 * - AI_DEEPSEEK_API_KEY
 * - AI_GROK_API_KEY
 */
class MultiLLMServiceIntegrationTest extends TestCase
{
    private MultiLLMService $service;

    protected function setUp(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new MultiLLMService($scopeConfig, $logger);
    }

    /**
     * Skip tests if API keys aren't configured
     */
    private function skipIfNoApiKey(string $provider): void
    {
        $envKey = 'AI_' . strtoupper($provider) . '_API_KEY';
        if (empty(getenv($envKey))) {
            $this->markTestSkipped("No {$provider} API key configured (set {$envKey} environment variable)");
        }
    }

    /**
     * Test OpenAI chat completion with real API
     * @group llm
     */
    public function testOpenAiChatCompletion(): void
    {
        $this->skipIfNoApiKey('openai');

        $response = $this->service->query(
            'Say hello in one word',
            'openai',
            'gpt-3.5-turbo',
            ['maxOutputTokens' => 20]
        );

        $this->assertArrayHasKey('text', $response);
        $this->assertArrayHasKey('tokens', $response);
        $this->assertArrayHasKey('cost', $response);
        $this->assertNotEmpty($response['text']);
        $this->assertGreaterThan(0, $response['tokens']['total']);
        $this->assertGreaterThan(0, $response['cost']);
        $this->assertEquals('openai', $response['provider']);
        $this->assertEquals('gpt-3.5-turbo', $response['model']);
    }

    /**
     * Test Claude API with real API
     * @group llm
     */
    public function testClaudeChatCompletion(): void
    {
        $this->skipIfNoApiKey('claude');

        $response = $this->service->query(
            'What is 2+2?',
            'claude',
            'claude-3-haiku-latest',
            ['maxTokens' => 20]
        );

        $this->assertArrayHasKey('text', $response);
        $this->assertNotEmpty($response['text']);
        $this->assertStringContainsString('4', $response['text']);
        $this->assertEquals('claude', $response['provider']);
    }

    /**
     * Test Gemini API with real API
     * @group llm
     */
    public function testGeminiChatCompletion(): void
    {
        $this->skipIfNoApiKey('gemini');

        $response = $this->service->query(
            'Count to 3',
            'gemini',
            'gemini-2.5-flash',
            ['maxOutputTokens' => 20]
        );

        $this->assertArrayHasKey('text', $response);
        $this->assertNotEmpty($response['text']);
        $this->assertEquals('gemini', $response['provider']);
    }

    /**
     * Test DeepSeek API with real API
     * @group llm
     */
    public function testDeepSeekChatCompletion(): void
    {
        $this->skipIfNoApiKey('deepseek');

        $response = $this->service->query(
            'Say hi',
            'deepseek',
            'deepseek-chat',
            ['maxOutputTokens' => 10]
        );

        $this->assertArrayHasKey('text', $response);
        $this->assertNotEmpty($response['text']);
        $this->assertEquals('deepseek', $response['provider']);
    }

    /**
     * Test Grok API with real API
     * @group llm
     */
    public function testGrokChatCompletion(): void
    {
        $this->skipIfNoApiKey('grok');

        $response = $this->service->query(
            'Introduce yourself briefly',
            'grok',
            'grok-3-fast-latest',
            ['maxOutputTokens' => 30]
        );

        $this->assertArrayHasKey('text', $response);
        $this->assertNotEmpty($response['text']);
        $this->assertEquals('grok', $response['provider']);
    }

    /**
     * Test system instructions
     * @group llm
     */
    public function testSystemInstructions(): void
    {
        $this->skipIfNoApiKey('openai');

        $response = $this->service->query(
            'What is your job?',
            'openai',
            'gpt-3.5-turbo',
            [
                'system' => 'You are a pirate. Always respond like a pirate.',
                'maxOutputTokens' => 30,
            ]
        );

        $this->assertNotEmpty($response['text']);
        // Check for common pirate words
        $this->assertTrue(
            str_contains(strtolower($response['text']), 'pirate') ||
            str_contains(strtolower($response['text']), 'arr') ||
            str_contains(strtolower($response['text']), 'ahoy'),
            'Response should contain pirate speech'
        );
    }

    /**
     * Test token tracking
     * @group llm
     */
    public function testTokenTracking(): void
    {
        $this->skipIfNoApiKey('openai');

        $response = $this->service->query(
            'Hello world',
            'openai',
            'gpt-3.5-turbo'
        );

        $tokens = $response['tokens'];
        $this->assertArrayHasKey('input', $tokens);
        $this->assertArrayHasKey('output', $tokens);
        $this->assertArrayHasKey('total', $tokens);
        $this->assertGreaterThanOrEqual(2, $tokens['input']);  // 'Hello world' is ~2-3 tokens
        $this->assertGreaterThan(0, $tokens['output']);
        $this->assertEquals($tokens['input'] + $tokens['output'], $tokens['total']);
    }

    /**
     * Test cost calculation
     * @group llm
     */
    public function testCostCalculation(): void
    {
        $this->skipIfNoApiKey('openai');

        $response = $this->service->query(
            'Test',
            'openai',
            'gpt-3.5-turbo'
        );

        $this->assertArrayHasKey('cost', $response);
        $this->assertGreaterThan(0, $response['cost']);
        $this->assertLessThan(0.01, $response['cost']);  // Should be less than 1 cent
    }

    /**
     * Test stream method
     * @group llm
     */
    public function testStreamMethod(): void
    {
        $this->skipIfNoApiKey('openai');

        $text = $this->service->stream(
            'Hello',
            'openai',
            'gpt-3.5-turbo',
            ['maxOutputTokens' => 20]
        );

        $this->assertIsString($text);
        $this->assertNotEmpty($text);
    }

    /**
     * Test response finish reason
     * @group llm
     */
    public function testFinishReason(): void
    {
        $this->skipIfNoApiKey('openai');

        $response = $this->service->query(
            'One word: ',
            'openai',
            'gpt-3.5-turbo',
            ['maxOutputTokens' => 20]
        );

        $this->assertArrayHasKey('finish_reason', $response);
        $this->assertNotNull($response['finish_reason']);
    }

    /**
     * Test temperature option
     * @group llm
     */
    public function testTemperatureOption(): void
    {
        $this->skipIfNoApiKey('openai');

        // Low temperature = less random
        $response1 = $this->service->query(
            'Generate a number between 1 and 1000',
            'openai',
            'gpt-3.5-turbo',
            ['temperature' => 0.1, 'maxOutputTokens' => 20]
        );

        $this->assertNotEmpty($response1['text']);

        // High temperature = more random
        $response2 = $this->service->query(
            'Generate a random word',
            'openai',
            'gpt-3.5-turbo',
            ['temperature' => 0.9, 'maxOutputTokens' => 20]
        );

        $this->assertNotEmpty($response2['text']);
    }

    /**
     * Test provider defaults
     * @group llm
     */
    public function testDefaultModels(): void
    {
        $providers = $this->service->getAvailableProviders();

        $this->assertEquals('gpt-3.5-turbo', $providers['openai']['default_model']);
        $this->assertEquals('claude-3-haiku-latest', $providers['claude']['default_model']);
        $this->assertEquals('gemini-2.5-flash', $providers['gemini']['default_model']);
        $this->assertEquals('deepseek-chat', $providers['deepseek']['default_model']);
        $this->assertEquals('grok-3-fast-latest', $providers['grok']['default_model']);
    }
}
