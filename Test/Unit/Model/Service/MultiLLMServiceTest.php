<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Service;

use Genaker\MagentoMcpAi\Model\Service\MultiLLMService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MultiLLMServiceTest extends TestCase
{
    private MultiLLMService $service;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MultiLLMService($this->scopeConfig, $this->logger);
    }

    /**
     * Test that available providers are returned correctly
     */
    public function testGetAvailableProviders(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $providers = $this->service->getAvailableProviders();

        $this->assertArrayHasKey('openai', $providers);
        $this->assertArrayHasKey('claude', $providers);
        $this->assertArrayHasKey('gemini', $providers);
        $this->assertArrayHasKey('deepseek', $providers);
        $this->assertArrayHasKey('grok', $providers);

        $this->assertFalse($providers['openai']['available']);
        $this->assertFalse($providers['claude']['available']);

        // Verify each provider has required keys
        foreach ($providers as $provider) {
            $this->assertArrayHasKey('name', $provider);
            $this->assertArrayHasKey('available', $provider);
            $this->assertArrayHasKey('default_model', $provider);
        }
    }

    /**
     * Test that pricing information is available
     */
    public function testGetPricingReturnsValidPricing(): void
    {
        $openaiPricing = $this->service->getPricing('openai');
        $this->assertNotEmpty($openaiPricing);
        $this->assertArrayHasKey('gpt-3.5-turbo', $openaiPricing);
        $this->assertArrayHasKey('input', $openaiPricing['gpt-3.5-turbo']);
        $this->assertArrayHasKey('output', $openaiPricing['gpt-3.5-turbo']);

        $claudePricing = $this->service->getPricing('claude');
        $this->assertNotEmpty($claudePricing);
        $this->assertArrayHasKey('claude-3-haiku-latest', $claudePricing);

        $geminiPricing = $this->service->getPricing('gemini');
        $this->assertNotEmpty($geminiPricing);
        $this->assertArrayHasKey('gemini-2.5-flash', $geminiPricing);
    }

    /**
     * Test that unsupported provider throws exception
     */
    public function testQueryWithUnsupportedProviderThrowsException(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unsupported provider');

        $this->service->query('test prompt', 'invalid_provider');
    }

    /**
     * Test that missing API key throws exception
     */
    public function testQueryWithMissingApiKeyThrowsException(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        putenv('AI_OPENAI_API_KEY=');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('API key');

        $this->service->query('test prompt', 'openai');
    }

    /**
     * Test provider availability with configured API keys
     */
    public function testAvailableProvidersWithApiKeys(): void
    {
        // Mock to return values based on the config path
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(function ($path) {
                $map = [
                    'magentomcpai/llm/openai_api_key' => 'sk-test-openai',
                    'magentomcpai/llm/claude_api_key' => null,
                    'magentomcpai/llm/gemini_api_key' => 'gemini-key',
                    'magentomcpai/llm/deepseek_api_key' => null,
                    'magentomcpai/llm/grok_api_key' => 'xai-key',
                ];
                return $map[$path] ?? null;
            });

        $providers = $this->service->getAvailableProviders();

        $this->assertTrue($providers['openai']['available']);
        $this->assertFalse($providers['claude']['available']);
        $this->assertTrue($providers['gemini']['available']);
        $this->assertFalse($providers['deepseek']['available']);
        $this->assertTrue($providers['grok']['available']);
    }

    /**
     * Test pricing for all major models
     */
    public function testPricingForAllProviders(): void
    {
        $providers = ['openai', 'claude', 'gemini', 'deepseek', 'grok'];

        foreach ($providers as $provider) {
            $pricing = $this->service->getPricing($provider);
            $this->assertNotEmpty($pricing, "Pricing for {$provider} should not be empty");

            // Verify each pricing entry has input and output
            foreach ($pricing as $model => $rates) {
                $this->assertArrayHasKey('input', $rates, "Missing input pricing for {$provider}/{$model}");
                $this->assertArrayHasKey('output', $rates, "Missing output pricing for {$provider}/{$model}");
                $this->assertGreaterThan(0, $rates['input'], "Input price should be positive for {$provider}");
                $this->assertGreaterThan(0, $rates['output'], "Output price should be positive for {$provider}");
            }
        }
    }

    /**
     * Test default models configuration
     */
    public function testDefaultModelsAreCheapest(): void
    {
        // Test that default models are the cheapest options
        $defaultModels = [
            'openai' => 'gpt-5-nano',
            'claude' => 'claude-3-haiku-latest',
            'gemini' => 'gemini-2.5-flash',
            'deepseek' => 'deepseek-chat',
            'grok' => 'grok-3-fast-latest',
        ];

        foreach ($defaultModels as $provider => $expectedModel) {
            $providers = $this->service->getAvailableProviders();
            $this->assertEquals(
                $expectedModel,
                $providers[$provider]['default_model'],
                "Default model mismatch for {$provider}"
            );
        }
    }

    /**
     * Test that extractToolCallsFromRawResponse handles OpenAI Responses API function_call format.
     *
     * gpt-5-nano (and other OpenAI models via Responses API) return tool calls as top-level
     * items with type "function_call" directly inside the "output" array.
     */
    public function testExtractToolCallsFromOpenAIResponsesApiFormat(): void
    {
        $rawResponse = [
            'output' => [
                [
                    'type'      => 'function_call',
                    'id'        => 'fc_abc123',
                    'call_id'   => 'call_abc123',
                    'name'      => 'execute_sql_query',
                    'arguments' => '{"query":"SELECT COUNT(*) FROM customer_entity"}',
                ],
            ],
        ];

        $method = new \ReflectionMethod(MultiLLMService::class, 'extractToolCallsFromRawResponse');
        $method->setAccessible(true);
        $result = $method->invoke($this->service, $rawResponse);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('execute_sql_query', $result[0]['name']);
        $this->assertEquals(['query' => 'SELECT COUNT(*) FROM customer_entity'], $result[0]['arguments']);
        $this->assertEquals('fc_abc123', $result[0]['id']);
    }

    /**
     * Test that extractToolCallsFromRawResponse still handles Anthropic tool_use format.
     */
    public function testExtractToolCallsFromAnthropicToolUseFormat(): void
    {
        $rawResponse = [
            'output' => [
                [
                    'type'    => 'message',
                    'content' => [
                        [
                            'type'  => 'tool_use',
                            'id'    => 'toolu_01',
                            'name'  => 'grep_files',
                            'input' => ['pattern' => 'eval(', 'directory' => 'pub'],
                        ],
                    ],
                ],
            ],
        ];

        $method = new \ReflectionMethod(MultiLLMService::class, 'extractToolCallsFromRawResponse');
        $method->setAccessible(true);
        $result = $method->invoke($this->service, $rawResponse);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('grep_files', $result[0]['name']);
        $this->assertEquals(['pattern' => 'eval(', 'directory' => 'pub'], $result[0]['arguments']);
    }

    /**
     * Test that extractToolCallsFromRawResponse returns null when no tool calls present.
     */
    public function testExtractToolCallsReturnsNullWhenEmpty(): void
    {
        $rawResponse = [
            'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello']]],
            ],
        ];

        $method = new \ReflectionMethod(MultiLLMService::class, 'extractToolCallsFromRawResponse');
        $method->setAccessible(true);
        $result = $method->invoke($this->service, $rawResponse);

        $this->assertNull($result);
    }

    /**
     * Test that extractToolCallsFromRawResponse handles multiple function_calls.
     */
    public function testExtractToolCallsHandlesMultipleFunctionCalls(): void
    {
        $rawResponse = [
            'output' => [
                [
                    'type'      => 'function_call',
                    'id'        => 'fc_1',
                    'name'      => 'get_magento_info',
                    'arguments' => '{}',
                ],
                [
                    'type'      => 'function_call',
                    'id'        => 'fc_2',
                    'name'      => 'execute_sql_query',
                    'arguments' => ['query' => 'SELECT 1'],
                ],
            ],
        ];

        $method = new \ReflectionMethod(MultiLLMService::class, 'extractToolCallsFromRawResponse');
        $method->setAccessible(true);
        $result = $method->invoke($this->service, $rawResponse);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('get_magento_info', $result[0]['name']);
        $this->assertEquals('execute_sql_query', $result[1]['name']);
        $this->assertEquals(['query' => 'SELECT 1'], $result[1]['arguments']);
    }
}
