<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Service;

use AIAccess\Provider\OpenAI\Client as OpenAIClient;
use AIAccess\Provider\Claude\Client as ClaudeClient;
use AIAccess\Provider\Gemini\Client as GeminiClient;
use AIAccess\Provider\DeepSeek\Client as DeepSeekClient;
use AIAccess\Provider\Grok\Client as GrokClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Generic Multi-LLM AI Service
 * 
 * Supports multiple AI providers:
 * - OpenAI (GPT-4, GPT-3.5, etc)
 * - Anthropic Claude (Claude 3, etc)
 * - Google Gemini
 * - DeepSeek
 * - xAI Grok
 * 
 * Usage:
 *   $response = $aiService->query('Your question', 'openai', 'gpt-3.5-turbo');
 *   $response = $aiService->query('Your question', 'claude', 'claude-3-haiku-latest');
 *   $response = $aiService->query('Your question', 'gemini', 'gemini-2.5-flash');
 */
class MultiLLMService
{
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    
    // Config path for debug mode
    private const CONFIG_DEBUG_MODE = 'magentomcpai/general/debug_mode';
    
    // API key configuration paths
    private const CONFIG_PATHS = [
        'openai' => 'magentomcpai/llm/openai_api_key',
        'claude' => 'magentomcpai/llm/claude_api_key',
        'gemini' => 'magentomcpai/llm/gemini_api_key',
        'deepseek' => 'magentomcpai/llm/deepseek_api_key',
        'grok' => 'magentomcpai/llm/grok_api_key',
    ];
    
    // Default models for each provider
    private const DEFAULT_MODELS = [
        'openai' => 'gpt-5-nano',       // Cheapest OpenAI model (cheaper than gpt-3.5-turbo)
        'claude' => 'claude-3-haiku-latest',  // Cheapest Claude model
        'gemini' => 'gemini-2.5-flash',
        'deepseek' => 'deepseek-chat',
        'grok' => 'grok-3-fast-latest',
    ];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Query an AI model with automatic provider selection
     *
     * @param string $prompt The user prompt
     * @param string $provider Provider name (openai, claude, gemini, deepseek, grok)
     * @param string|null $model Model name (uses default if null)
     * @param array $options Additional options (temperature, maxTokens, etc)
     * @return array Response with 'text', 'tokens', 'cost'
     * @throws LocalizedException
     */
    public function query(
        string $prompt,
        string $provider = 'openai',
        ?string $model = null,
        array $options = []
    ): array {
        $provider = strtolower($provider);
        
        if (!isset(self::CONFIG_PATHS[$provider])) {
            throw new LocalizedException(
                __("Unsupported provider: %1. Supported: openai, claude, gemini, deepseek, grok", $provider)
            );
        }

        $model = $model ?? self::DEFAULT_MODELS[$provider];
        $apiKey = $this->getApiKey($provider);
        
        $isDebugMode = (bool)$this->scopeConfig->getValue(
            self::CONFIG_DEBUG_MODE,
            ScopeInterface::SCOPE_STORE
        );
        
        $this->logger->info("Querying {$provider} with model {$model}");
        
        // Debug: Log request details
        if ($isDebugMode) {
            $this->logger->info('[AI DEBUG] MultiLLMService Query', [
                'provider' => $provider,
                'model' => $model,
                'prompt_length' => strlen($prompt),
                'prompt_preview' => substr($prompt, 0, 200),
                'options' => $options,
            ]);
        }

        try {
            $client = $this->createClient($provider, $apiKey);
            $chat = $client->createChat($model);
            
            // Apply system instruction if provided
            if (isset($options['system'])) {
                $systemInstruction = $options['system'];
                $chat->setSystemInstruction($systemInstruction);
                if ($isDebugMode) {
                    $this->logger->info('[AI DEBUG] System Instruction Set', [
                        'length' => strlen($systemInstruction),
                        'preview' => substr($systemInstruction, 0, 200),
                    ]);
                }
                unset($options['system']);
            }
            
            // Apply model options (temperature, maxTokens, etc)
            if (!empty($options)) {
                if ($isDebugMode) {
                    $this->logger->info('[AI DEBUG] Setting Chat Options', ['options' => $options]);
                }
                $chat->setOptions(...$options);
            }
            
            // Send message
            if ($isDebugMode) {
                $this->logger->info('[AI DEBUG] Sending Message to AI Provider');
            }
            $response = $chat->sendMessage($prompt);
            $content = $response->getText() ?? '';
            // Fallback: extract text from raw response when getText() is empty (e.g. GPT-5 reasoning models)
            if (empty($content) && method_exists($response, 'getRawResponse')) {
                $raw = $response->getRawResponse();
                if (is_array($raw)) {
                    $content = $this->extractTextFromRawResponse($raw) ?? $content;
                }
            }
            
            // Debug: Log response details
            if ($isDebugMode) {
                $responseInfo = [
                    'response_class' => get_class($response),
                    'content_length' => strlen($content),
                    'content_preview' => substr($content, 0, 200),
                    'finish_reason' => $response->getFinishReason()?->name ?? 'unknown',
                ];
                
                // Try to get tool calls if available
                if (method_exists($response, 'getToolCalls')) {
                    $toolCalls = $response->getToolCalls();
                    $responseInfo['tool_calls'] = $toolCalls;
                    $responseInfo['tool_calls_count'] = is_array($toolCalls) ? count($toolCalls) : 0;
                }
                
                // Try to get content parts if available
                if (method_exists($response, 'getContent')) {
                    $responseContent = $response->getContent();
                    $responseInfo['content_method'] = $responseContent;
                }
                
                // Try to get message parts
                if (method_exists($response, 'getMessage')) {
                    $responseMessage = $response->getMessage();
                    $responseInfo['message'] = $responseMessage;
                    if (is_object($responseMessage)) {
                        $responseInfo['message_class'] = get_class($responseMessage);
                        if (method_exists($responseMessage, 'getContent')) {
                            $responseInfo['message_content'] = $responseMessage->getContent();
                        }
                    }
                }
                
                // Try to get choices/parts
                if (method_exists($response, 'getChoices')) {
                    $choices = $response->getChoices();
                    $responseInfo['choices'] = $choices;
                }
                
                // Get all available methods for debugging
                $responseMethods = get_class_methods($response);
                $responseInfo['available_methods'] = array_filter($responseMethods, function($method) {
                    return strpos($method, 'get') === 0 || strpos($method, 'has') === 0;
                });
                
                $this->logger->info('[AI DEBUG] Response Details', $responseInfo);
            }
            
            // Log warning if content is empty (even if debug mode is off)
            if (empty($content)) {
                $this->logger->warning('Empty content from AI response', [
                    'provider' => $provider,
                    'model' => $model,
                    'finish_reason' => $response->getFinishReason()?->name ?? 'unknown',
                ]);
            }
            
            // Get token usage
            $usage = $response->getUsage();
            $tokens = [
                'input' => $usage->inputTokens ?? 0,
                'output' => $usage->outputTokens ?? 0,
                'total' => ($usage->inputTokens ?? 0) + ($usage->outputTokens ?? 0),
            ];
            
            // Calculate cost
            $cost = $this->calculateCost($provider, $tokens);
            
            // Check for tool calls in response
            $toolCalls = null;
            if (method_exists($response, 'getToolCalls')) {
                $toolCalls = $response->getToolCalls();
            }
            // AIAccess ChatResponse may not have getToolCalls - extract from raw response (Responses API)
            if (empty($toolCalls) && method_exists($response, 'getRawResponse')) {
                $toolCalls = $this->extractToolCallsFromRawResponse($response->getRawResponse());
            }
            
            $result = [
                'text' => $content,
                'tokens' => $tokens,
                'cost' => $cost,
                'model' => $model,
                'provider' => $provider,
                'finish_reason' => $response->getFinishReason()?->name ?? 'unknown',
                'tool_calls' => $toolCalls,
            ];
            
            // Debug: Log final result
            if ($isDebugMode) {
                $this->logger->info('[AI DEBUG] Query Result', [
                    'text_length' => strlen($content),
                    'tokens' => $tokens,
                    'cost' => $cost,
                    'finish_reason' => $result['finish_reason'],
                    'has_tool_calls' => !empty($toolCalls),
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $errorInfo = [
                'provider' => $provider,
                'model' => $model,
                'error' => $e->getMessage(),
                'prompt_length' => strlen($prompt),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ];
            $this->logger->error('[MagentoMcpAi] Error querying LLM provider', $errorInfo);
            throw new LocalizedException(__("AI service error: %1", $e->getMessage()), $e);
        }
    }

    /**
     * Extract tool calls from OpenAI Responses API raw output.
     *
     * Handles two formats:
     *  - OpenAI Responses API: {"type":"function_call","name":...,"arguments":...} at output[] level
     *  - Anthropic/Claude format: {"type":"tool_use",...} nested inside output[].content[]
     *
     * @param array $rawResponse Raw API response
     * @return array|null Array of tool calls or null
     */
    private function extractToolCallsFromRawResponse(array $rawResponse): ?array
    {
        $output = $rawResponse['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }
        $toolCalls = [];
        foreach ($output as $item) {
            // OpenAI Responses API: function_call is a top-level item in the output array
            if (($item['type'] ?? null) === 'function_call') {
                $args = $item['arguments'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true) ?? [];
                }
                $toolCalls[] = [
                    'id'        => $item['id'] ?? $item['call_id'] ?? '',
                    'name'      => $item['name'] ?? '',
                    'arguments' => $args,
                ];
                continue;
            }

            // Anthropic/Claude format: tool_use nested inside content blocks
            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $input = $block['input'] ?? $block['arguments'] ?? [];
                    if (is_string($input)) {
                        $input = json_decode($input, true) ?? [];
                    }
                    $toolCalls[] = [
                        'id'        => $block['id'] ?? '',
                        'name'      => $block['name'] ?? '',
                        'arguments' => $input,
                    ];
                }
            }
        }
        return $toolCalls ?: null;
    }

    /**
     * Extract text content from OpenAI Responses API raw output
     * Used when getText() returns empty (e.g. GPT-5 reasoning models)
     *
     * @param array $rawResponse Raw API response
     * @return string|null Extracted text or null
     */
    private function extractTextFromRawResponse(array $rawResponse): ?string
    {
        if (!empty($rawResponse['text']) && is_string($rawResponse['text'])) {
            return $rawResponse['text'];
        }
        $output = $rawResponse['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }
        $textParts = [];
        foreach ($output as $item) {
            if (!is_array($item['content'] ?? null)) {
                continue;
            }
            foreach ($item['content'] as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'tool_use') {
                    continue;
                }
                $text = $block['text'] ?? $block['content'] ?? null;
                if (is_string($text) && $text !== '') {
                    $textParts[] = $text;
                } elseif (is_array($text) && isset($text['text'])) {
                    $textParts[] = $text['text'];
                }
            }
        }
        return $textParts ? implode("\n", $textParts) : null;
    }

    /**
     * Stream a response (for long-running operations)
     * Returns raw response for streaming/chunking
     *
     * @param string $prompt User prompt
     * @param string $provider Provider name
     * @param string|null $model Model name
     * @param array $options Additional options
     * @return string Response text
     */
    public function stream(
        string $prompt,
        string $provider = 'openai',
        ?string $model = null,
        array $options = []
    ): string {
        $result = $this->query($prompt, $provider, $model, $options);
        return $result['text'];
    }

    /**
     * Get default model for a provider
     *
     * @param string $provider Provider name
     * @return string Default model identifier
     */
    public function getDefaultModel(string $provider): string
    {
        return self::DEFAULT_MODELS[strtolower($provider)] ?? 'gpt-5-nano';
    }

    /**
     * Get available providers
     *
     * @return array List of provider names with their default models
     */
    public function getAvailableProviders(): array
    {
        $providers = [];
        
        foreach (self::CONFIG_PATHS as $name => $path) {
            $apiKey = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
            
            $providers[$name] = [
                'name' => $name,
                'available' => !empty($apiKey),
                'default_model' => self::DEFAULT_MODELS[$name] ?? null,
                'api_key_configured' => !empty($apiKey),
            ];
        }
        
        return $providers;
    }

    /**
     * Get provider pricing information
     *
     * @param string $provider Provider name
     * @return array Pricing info [input_price_per_1m_tokens, output_price_per_1m_tokens]
     */
    public function getPricing(string $provider): array
    {
        // Pricing as of 2026-02 (approximate values)
        $pricing = [
            'openai' => [
                'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],     // per 1M tokens (default)
                'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],  // per 1M tokens
                'gpt-4-turbo' => ['input' => 10.00, 'output' => 30.00],
                'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            ],
            'claude' => [
                'claude-3-haiku-latest' => ['input' => 0.80, 'output' => 4.00],
                'claude-3-5-sonnet' => ['input' => 3.00, 'output' => 15.00],
            ],
            'gemini' => [
                'gemini-2.5-flash' => ['input' => 0.075, 'output' => 0.30],  // per 1M tokens
            ],
            'deepseek' => [
                'deepseek-chat' => ['input' => 0.14, 'output' => 0.28],  // per 1M tokens
            ],
            'grok' => [
                'grok-3-fast-latest' => ['input' => 5.00, 'output' => 15.00],  // per 1M tokens
            ],
        ];
        
        return $pricing[$provider] ?? [];
    }

    /**
     * Create appropriate client based on provider
     *
     * @param string $provider Provider name
     * @param string $apiKey API key
     * @return object Client instance
     * @throws LocalizedException
     */
    private function createClient(string $provider, string $apiKey): object
    {
        return match($provider) {
            'openai' => new OpenAIClient($apiKey),
            'claude' => new ClaudeClient($apiKey),
            'gemini' => new GeminiClient($apiKey),
            'deepseek' => new DeepSeekClient($apiKey),
            'grok' => new GrokClient($apiKey),
            default => throw new LocalizedException(__("Unsupported provider: %1", $provider)),
        };
    }

    /**
     * Get API key from configuration or environment
     *
     * @param string $provider Provider name
     * @return string API key
     * @throws LocalizedException
     */
    private function getApiKey(string $provider): string
    {
        // Get API key from config path
        $configPath = self::CONFIG_PATHS[$provider];
        $apiKey = $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE);
        
        // Fall back to environment variable
        if (empty($apiKey)) {
            $envKey = 'AI_' . strtoupper($provider) . '_API_KEY';
            $apiKey = getenv($envKey);
        }
        
        if (empty($apiKey)) {
            throw new LocalizedException(
                __("API key for %1 is not configured. Set it in System > Configuration > Genaker > AI or env var %2", 
                   $provider, 
                   $envKey ?? self::CONFIG_PATHS[$provider])
            );
        }
        
        return $apiKey;
    }

    /**
     * Calculate cost for a query
     *
     * @param string $provider Provider name
     * @param array $tokens Token counts
     * @return float Cost in USD
     */
    private function calculateCost(string $provider, array $tokens): float
    {
        // Simplified - assumes using cheapest default model for each provider
        $pricing = match($provider) {
            'openai' => ['input' => 0.05, 'output' => 0.40],      // gpt-5-nano (default)
            'claude' => ['input' => 0.80, 'output' => 4.00],      // claude-3-haiku
            'gemini' => ['input' => 0.075, 'output' => 0.30],     // gemini-2.5-flash
            'deepseek' => ['input' => 0.14, 'output' => 0.28],    // deepseek-chat
            'grok' => ['input' => 5.00, 'output' => 15.00],       // grok-3-fast
            default => ['input' => 0, 'output' => 0],
        };
        
        $inputCost = ($tokens['input'] / 1_000_000) * $pricing['input'];
        $outputCost = ($tokens['output'] / 1_000_000) * $pricing['output'];
        
        return round($inputCost + $outputCost, 6);
    }
}
