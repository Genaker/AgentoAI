<?php
namespace Genaker\MagentoMcpAi\Model\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Genaker\MagentoMcpAi\RAG\StopWords;
use Psr\Log\LoggerInterface;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;

/**
 * Generic AI Service - Wrapper around MultiLLMService
 * 
 * This class maintains the same interface as OpenAiService but uses the generic
 * MultiLLMService under the hood, allowing provider switching via configuration.
 * 
 * Provider can be selected via config: magentomcpai/general/ai_provider
 * Model can be selected via config: magentomcpai/general/ai_model
 */
class MgentoAIService implements AIServiceInterface
{
    // Endpoint path constants (for backwards compatibility)
    const CHAT_COMPLETIONS_PATH = '/v1/chat/completions';
    const FILES_PATH = '/v1/files';
    const ANSWERS_PATH = '/v1/answers';
    const COMPLETIONS_PATH = '/v1/completions';
    const ASSISTANTS_PATH = '/v1/assistants';
    const EMBEDDINGS_PATH = '/v1/embeddings';
    const IMAGES_PATH = '/v1/images/generations';
    const AUDIO_TRANSCRIPTION_PATH = '/v1/audio/transcriptions';
    const AUDIO_SPEECH_PATH = '/v1/audio/speech';
    const THREADS_PATH = '/v1/threads';
    const THREAD_MESSAGES_PATH = '/v1/threads/%s/messages';
    const THREAD_RUNS_PATH = '/v1/threads/%s/runs';
    const THREAD_RUN_STATUS_PATH = '/v1/threads/%s/runs/%s';
    const GOOGLE_SPEECH_API_ENDPOINT = 'https://speech.googleapis.com/v1/speech:recognize';
    const GOOGLE_VISION_API_ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

    // Config paths for provider selection
    const CONFIG_AI_PROVIDER = 'magentomcpai/general/ai_provider';
    const CONFIG_AI_MODEL = 'magentomcpai/general/ai_model';
    const CONFIG_DEBUG_MODE = 'magentomcpai/general/debug_mode';

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var JsonHelper
     */
    private $jsonHelper;

    /**
     * @var File
     */
    private $file;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var MultiLLMService
     */
    private $multiLLMService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    // Token tracking properties (for backwards compatibility)
    public $prompt_tokens;
    public $completion_tokens;
    public $total_tokens;
    public $prompt_tokens_details;
    public $cached_tokens;
    public $audio_tokens;

    /**
     * @param Curl $curl
     * @param JsonHelper $jsonHelper
     * @param File $file
     * @param ScopeConfigInterface $scopeConfig
     * @param MultiLLMService $multiLLMService
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        JsonHelper $jsonHelper,
        File $file,
        ScopeConfigInterface $scopeConfig,
        MultiLLMService $multiLLMService,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->jsonHelper = $jsonHelper;
        $this->file = $file;
        $this->scopeConfig = $scopeConfig;
        $this->multiLLMService = $multiLLMService;
        $this->logger = $logger;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    protected function isDebugMode(): bool
    {
        return (bool)$this->scopeConfig->getValue(
            self::CONFIG_DEBUG_MODE,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get the AI provider from config
     *
     * @return string
     */
    protected function getProvider(): string
    {
        $provider = $this->scopeConfig->getValue(
            self::CONFIG_AI_PROVIDER,
            ScopeInterface::SCOPE_STORE
        );
        return $provider ? strtolower($provider) : 'openai';
    }

    /**
     * Get the AI model from config
     *
     * @return string|null
     */
    protected function getModel(): ?string
    {
        $model = $this->scopeConfig->getValue(
            self::CONFIG_AI_MODEL,
            ScopeInterface::SCOPE_STORE
        );
        return $model ?: null;  // null = use default for provider
    }

    /**
     * Get the API domain from config (for backwards compatibility)
     *
     * @return string
     */
    protected function getApiDomain(): string
    {
        $domain = $this->scopeConfig->getValue(
            'magentomcpai/general/api_domain',
            ScopeInterface::SCOPE_STORE
        );
        return $domain ? rtrim($domain, '/') : 'https://api.openai.com';
    }

    /**
     * Get the API key from config (now delegates to MultiLLMService)
     *
     * @return string
     * @throws LocalizedException
     */
    public function getApiKey(): string
    {
        $provider = $this->getProvider();
        
        // Use MultiLLMService config paths
        $configPath = match($provider) {
            'openai' => 'magentomcpai/llm/openai_api_key',
            'claude' => 'magentomcpai/llm/claude_api_key',
            'gemini' => 'magentomcpai/llm/gemini_api_key',
            'deepseek' => 'magentomcpai/llm/deepseek_api_key',
            'grok' => 'magentomcpai/llm/grok_api_key',
            default => 'magentomcpai/llm/openai_api_key',
        };

        $apiKey = $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE);
        
        // Fall back to environment variable
        if (empty($apiKey)) {
            $envKey = 'AI_' . strtoupper($provider) . '_API_KEY';
            $apiKey = getenv($envKey);
        }
        
        if (!$apiKey) {
            throw new LocalizedException(
                __("API key not configured for provider: %1", $provider)
            );
        }

        return $apiKey;
    }

    /**
     * Send a chat request using generic AI service
     *
     * @param string $message User message
     * @param array $messages Previous messages (conversation history)
     * @param int $maxTokens Maximum tokens in response
     * @param float $temperature Temperature for randomness
     * @param array $tools Function definitions
     * @return array Response from AI service
     * @throws LocalizedException
     */
    public function sendChatRequest(
        string $message,
        array $messages = [],
        int $maxTokens = 2000,
        float $temperature = 0.7,
        array $tools = []
    ): array {
        try {
            $provider = $this->getProvider();
            $model = $this->getModel();

            // Extract system message from messages array if present
            $systemInstruction = '';
            $filteredMessages = [];
            foreach ($messages as $msg) {
                if (isset($msg['role']) && $msg['role'] === 'system') {
                    $systemInstruction = $msg['content'] ?? '';
                } else {
                    $filteredMessages[] = $msg;
                }
            }

            // Include conversation history in prompt (MultiLLMService sends single prompt only)
            // Clearly separate history from the current question to answer
            $promptToSend = $message;
            if (!empty($filteredMessages)) {
                $historyParts = [];
                foreach ($filteredMessages as $msg) {
                    $role = $msg['role'] ?? 'user';
                    $content = $msg['content'] ?? '';
                    $historyParts[] = ucfirst($role) . ': ' . $content;
                }
                // Detect ReAct-style tool results in history to use correct framing
                $hasToolResults = false;
                foreach ($filteredMessages as $msg) {
                    $content = $msg['content'] ?? '';
                    if (strpos($content, 'Tool result:') === 0 || strpos($content, 'Data received:') === 0) {
                        $hasToolResults = true;
                        break;
                    }
                }
                $historyLabel = $hasToolResults
                    ? "=== CONVERSATION HISTORY WITH TOOL RESULTS (use these results to answer the question) ==="
                    : "=== PREVIOUS CONVERSATION ===";
                $promptToSend = $historyLabel . "\n\n"
                    . implode("\n\n", $historyParts)
                    . "\n\n=== CURRENT QUESTION ===\n\n" . $message;
            }
            
            // Build options - GPT-5 models do not support temperature; use reasoning_effort to avoid empty responses
            $options = [
                'maxOutputTokens' => $maxTokens,
            ];
            $effectiveModel = $model ?? $this->multiLLMService->getDefaultModel($provider);
            $modelLower = strtolower($effectiveModel);
            if (str_starts_with($modelLower, 'gpt-5')) {
                // GPT-5 reasoning models: use low effort to avoid consuming token budget on reasoning
                $options['reasoning'] = ['effort' => 'low'];
            } else {
                $options['temperature'] = $temperature;
            }
            
            // Add system instruction if provided
            if (!empty($systemInstruction)) {
                $options['system'] = $systemInstruction;
            }

            // Convert tools format for AIAccess library
            // AIAccess expects: ['type' => 'function', 'name' => ..., 'description' => ..., 'parameters' => ...]
            // Registry returns OpenAI format: ['type' => 'function', 'function' => ['name' => ..., ...]]
            if (!empty($tools)) {
                $formattedTools = [];
                foreach ($tools as $tool) {
                    if (isset($tool['function']['name'])) {
                        // Convert from OpenAI format to AIAccess format
                        $formattedTools[] = [
                            'type' => $tool['type'] ?? 'function',
                            'name' => $tool['function']['name'],
                            'description' => $tool['function']['description'] ?? '',
                            'parameters' => $tool['function']['parameters'] ?? []
                        ];
                    } elseif (isset($tool['name'])) {
                        // Already has name at root, but ensure type is present
                        if (!isset($tool['type'])) {
                            $tool['type'] = 'function';
                        }
                        $formattedTools[] = $tool;
                    }
                }
                if (!empty($formattedTools)) {
                    $options['tools'] = $formattedTools;
                }
            }

            // Debug: Log what we're sending (if debug mode is enabled)
            if ($this->isDebugMode() && $this->logger) {
                $debugInfo = [
                    'message' => $message,
                    'message_length' => strlen($message),
                    'messages_count' => count($messages),
                    'provider' => $provider,
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                    'tools_count' => count($tools),
                    'options' => $options
                ];
                $this->logger->info('[AI DEBUG] Request Details', $debugInfo);
                
                // Log full messages and tools in debug mode
                if (!empty($messages)) {
                    $this->logger->info('[AI DEBUG] Conversation History', ['messages' => $messages]);
                }
                if (!empty($tools)) {
                    $this->logger->info('[AI DEBUG] Available Tools', ['tools' => $tools]);
                }
            }
            
            // Send query (with conversation history prepended when present)
            $response = $this->multiLLMService->query(
                $promptToSend,
                $provider,
                $model,
                $options
            );

            // Update token tracking for backwards compatibility
            $this->prompt_tokens = $response['tokens']['input'] ?? 0;
            $this->completion_tokens = $response['tokens']['output'] ?? 0;
            $this->total_tokens = $response['tokens']['total'] ?? 0;

            // Always log basic request info
            $this->logger->info(
                "AI Chat Request",
                [
                    'provider' => $provider,
                    'model' => $model,
                    'prompt_tokens' => $this->prompt_tokens,
                    'completion_tokens' => $this->completion_tokens,
                    'total_tokens' => $this->total_tokens,
                    'cost' => $response['cost'] ?? 0,
                ]
            );
            
            // Debug: Log detailed response info if debug mode is enabled
            if ($this->isDebugMode() && $this->logger) {
                $debugResponse = [
                    'response_keys' => array_keys($response),
                    'text_length' => strlen($response['text'] ?? ''),
                    'text_preview' => substr($response['text'] ?? '', 0, 200),
                    'tokens' => $response['tokens'] ?? [],
                    'cost' => $response['cost'] ?? 0,
                    'model' => $response['model'] ?? $model,
                    'provider' => $response['provider'] ?? $provider,
                    'finish_reason' => $response['finish_reason'] ?? 'unknown',
                    'tool_calls' => $response['tool_calls'] ?? null,
                ];
                $this->logger->info('[AI DEBUG] Response Details', $debugResponse);
                
                // Log full response in debug mode
                $this->logger->info('[AI DEBUG] Full Response', ['response' => $response]);
            }

            // Ensure message is not empty
            $message = $response['text'] ?? $response['message'] ?? '';
            
            // Check if response contains tool calls instead of text
            $toolCalls = $response['tool_calls'] ?? null;
            
            if (empty($message) && !empty($toolCalls)) {
                // If we have tool calls but no text, the AI wants to call a tool
                // We need to format this for the caller to handle
                $this->logger->info('AI response contains tool calls instead of text', [
                    'tool_calls_count' => is_array($toolCalls) ? count($toolCalls) : 1,
                    'tool_calls' => $toolCalls
                ]);
                
                // Format tool call as JSON string for parsing
                if (is_array($toolCalls) && !empty($toolCalls)) {
                    $firstToolCall = $toolCalls[0];
                    $toolName = $firstToolCall['name'] ?? $firstToolCall['function']['name'] ?? '';
                    $toolArgs = $firstToolCall['arguments'] ?? $firstToolCall['function']['arguments'] ?? [];
                    
                    // Format as JSON that parseToolCallFromResponse can understand
                    $message = json_encode([
                        'tool' => $toolName,
                        'function' => $toolName,
                        'arguments' => is_string($toolArgs) ? json_decode($toolArgs, true) : $toolArgs
                    ]);
                }
            }
            
            if (empty($message) && empty($toolCalls)) {
                $this->logger->warning(
                    "AI Chat Request returned empty message and no tool calls",
                    [
                        'provider' => $provider,
                        'model' => $model,
                        'response_keys' => array_keys($response),
                        'full_response' => $response
                    ]
                );
            }
            
            return [
                'success' => true,
                'message' => $message,
                'tokens' => $response['tokens'] ?? [],
                'cost' => $response['cost'] ?? 0,
                'model' => $response['model'] ?? $model,
                'provider' => $response['provider'] ?? $provider,
                'finish_reason' => $response['finish_reason'] ?? 'unknown',
                'tool_calls' => $toolCalls,
            ];

        } catch (\Exception $e) {
            $this->logger->error('[MagentoMcpAi] AI Chat Request Failed', [
                'error' => $e->getMessage(),
                'provider' => $provider ?? null,
                'model' => $model ?? null,
                'message_preview' => substr($message ?? '', 0, 200),
                'messages_count' => count($messages ?? []),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new LocalizedException(__("AI service error: %1", $e->getMessage()), $e);
        }
    }

    /**
     * Get chat completion (wrapper for sendChatRequest for backwards compatibility)
     *
     * @param string $prompt User prompt
     * @param array $messages Conversation history
     * @param int $maxTokens Max tokens
     * @param float $temperature Temperature
     * @return string Response text
     * @throws LocalizedException
     */
    public function getChatCompletion(
        string $prompt,
        array $messages = [],
        int $maxTokens = 2000,
        float $temperature = 0.7
    ): string {
        $response = $this->sendChatRequest($prompt, $messages, $maxTokens, $temperature);
        return $response['message'];
    }

    /**
     * Generate embeddings using AI service
     *
     * @param string $text Text to embed
     * @return array Embedding vector
     * @throws LocalizedException
     */
    public function generateEmbeddings(string $text): array
    {
        try {
            $provider = $this->getProvider();
            
            // Only OpenAI and Gemini support embeddings via AIAccess
            if (!in_array($provider, ['openai', 'gemini'])) {
                throw new LocalizedException(
                    __("Embeddings not supported for provider: %1. Supported: openai, gemini", $provider)
                );
            }

            $this->logger->info("Generating embeddings for provider: $provider");

            // Note: AIAccess has calculateEmbeddings method
            // This would require extending MultiLLMService or using AIAccess directly
            // For now, returning a placeholder
            throw new LocalizedException(
                __("Embeddings feature requires direct AIAccess usage. Contact support.")
            );

        } catch (\Exception $e) {
            $this->logger->error("Embedding generation failed: " . $e->getMessage());
            throw new LocalizedException(__("Embedding error: %1", $e->getMessage()), $e);
        }
    }

    /**
     * Get completion (legacy method for backwards compatibility)
     *
     * @param string $prompt Prompt text
     * @param int $maxTokens Max tokens
     * @return array Completion response
     * @throws LocalizedException
     */
    public function getCompletion(string $prompt, int $maxTokens = 2000): array
    {
        $response = $this->sendChatRequest($prompt, [], $maxTokens);
        
        return [
            'success' => true,
            'completion' => $response['message'],
            'tokens' => $response['tokens'],
            'cost' => $response['cost'],
        ];
    }

    /**
     * Send function calling request
     *
     * @param string $message User message
     * @param array $functions Function definitions
     * @param int $maxTokens Max tokens
     * @return array Response with function calls
     * @throws LocalizedException
     */
    public function sendFunctionCallingRequest(
        string $message,
        array $functions,
        int $maxTokens = 4096
    ): array {
        return $this->sendChatRequest($message, [], $maxTokens, 0.7, ['tools' => $functions]);
    }

    /**
     * Upload file (stub - requires provider-specific implementation)
     *
     * @param string $filePath File path
     * @param string $apiKey API key
     * @param string $purpose Upload purpose
     * @return array Upload result
     * @throws LocalizedException
     */
    public function uploadFile(
        string $filePath,
        string $apiKey,
        string $purpose = 'assistants'
    ): array {
        throw new LocalizedException(
            __("File upload is provider-specific. Use the original OpenAiService for file operations.")
        );
    }

    /**
     * Generate image
     *
     * @param string $prompt Image description
     * @param int $size Image size (256, 512, 1024)
     * @return array Generated image info
     * @throws LocalizedException
     */
    public function generateImage(string $prompt, int $size = 512): array
    {
        throw new LocalizedException(
            __("Image generation requires provider-specific implementation. Contact support.")
        );
    }

    /**
     * Transcribe audio
     *
     * @param string $filePath Audio file path
     * @param string $model Model to use
     * @return array Transcription result
     * @throws LocalizedException
     */
    public function transcribeAudio(string $filePath, string $model = 'whisper-1'): array
    {
        throw new LocalizedException(
            __("Audio transcription requires provider-specific implementation. Contact support.")
        );
    }

    /**
     * Generate speech
     *
     * @param string $text Text to speak
     * @param string $voice Voice type
     * @return array Speech generation result
     * @throws LocalizedException
     */
    public function generateSpeech(string $text, string $voice = 'alloy'): array
    {
        throw new LocalizedException(
            __("Speech generation requires provider-specific implementation. Contact support.")
        );
    }

    /**
     * Get available providers for UI display
     *
     * @return array
     */
    public function getAvailableProviders(): array
    {
        return $this->multiLLMService->getAvailableProviders();
    }

    /**
     * Get pricing information
     *
     * @param string $provider Provider name
     * @return array
     */
    public function getPricing(string $provider): array
    {
        return $this->multiLLMService->getPricing($provider);
    }
}
