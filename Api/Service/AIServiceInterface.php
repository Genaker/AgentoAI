<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Api\Service;

/**
 * AI Service Interface
 * 
 * Generic interface for AI services supporting multiple providers
 * Abstracts the underlying implementation (OpenAI, Claude, Gemini, etc)
 * 
 * @api
 */
interface AIServiceInterface
{
    /**
     * Send a chat request
     *
     * @param string $message User message
     * @param array $messages Previous messages (conversation history)
     * @param int $maxTokens Maximum tokens in response
     * @param float $temperature Temperature for randomness
     * @param array $tools Function definitions
     * @return array Response with 'success', 'message', 'tokens', 'cost', 'model', 'provider'
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function sendChatRequest(
        string $message,
        array $messages = [],
        int $maxTokens = 2000,
        float $temperature = 0.7,
        array $tools = []
    ): array;

    /**
     * Get chat completion (simple wrapper)
     *
     * @param string $prompt User prompt
     * @param array $messages Conversation history
     * @param int $maxTokens Max tokens
     * @param float $temperature Temperature
     * @return string Response text
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getChatCompletion(
        string $prompt,
        array $messages = [],
        int $maxTokens = 2000,
        float $temperature = 0.7
    ): string;

    /**
     * Get completion with structured response
     *
     * @param string $prompt Prompt text
     * @param int $maxTokens Max tokens
     * @return array Response with 'success', 'completion', 'tokens', 'cost'
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCompletion(string $prompt, int $maxTokens = 2000): array;

    /**
     * Get API key
     *
     * @return string API key
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getApiKey(): string;

    /**
     * Generate embeddings
     *
     * @param string $text Text to embed
     * @return array Embedding vector
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generateEmbeddings(string $text): array;

    /**
     * Generate image
     *
     * @param string $prompt Image description
     * @param int $size Image size
     * @return array Generated image info
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generateImage(string $prompt, int $size = 512): array;

    /**
     * Send function calling request
     *
     * @param string $message User message
     * @param array $functions Function definitions
     * @param int $maxTokens Max tokens
     * @return array Response with function calls
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function sendFunctionCallingRequest(
        string $message,
        array $functions,
        int $maxTokens = 4096
    ): array;

    /**
     * Transcribe audio
     *
     * @param string $filePath Audio file path
     * @param string $model Model to use
     * @return array Transcription result
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function transcribeAudio(string $filePath, string $model = 'whisper-1'): array;

    /**
     * Generate speech
     *
     * @param string $text Text to speak
     * @param string $voice Voice type
     * @return array Speech generation result
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generateSpeech(string $text, string $voice = 'alloy'): array;

    /**
     * Get available providers
     *
     * @return array List of available providers
     */
    public function getAvailableProviders(): array;

    /**
     * Get pricing information
     *
     * @param string $provider Provider name
     * @return array Pricing info
     */
    public function getPricing(string $provider): array;
}
