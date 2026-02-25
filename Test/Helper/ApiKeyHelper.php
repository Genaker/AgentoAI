<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Helper class for managing API keys in tests securely
 * 
 * Usage:
 *   - Set environment variable: export OPENAI_API_KEY=sk-proj-your-key
 *   - Use in test: $apiKey = ApiKeyHelper::getApiKey();
 *   - Never hardcode API keys in tests!
 */
class ApiKeyHelper
{
    /**
     * Get OpenAI API key from environment variable
     * 
     * @return string|null
     */
    public static function getApiKey(): ?string
    {
        return getenv('OPENAI_API_KEY') ?: null;
    }

    /**
     * Check if API key is available
     * 
     * @return bool
     */
    public static function isApiKeyAvailable(): bool
    {
        return !empty(self::getApiKey());
    }

    /**
     * Get API key or throw exception if not available
     * 
     * @return string
     * @throws \RuntimeException
     */
    public static function getApiKeyOrThrow(): string
    {
        $apiKey = self::getApiKey();
        
        if (!$apiKey) {
            throw new \RuntimeException(
                'OPENAI_API_KEY environment variable not set. '
                . 'Please set it before running integration tests: '
                . 'export OPENAI_API_KEY=sk-proj-your-api-key'
            );
        }

        return $apiKey;
    }

    /**
     * Validate API key format
     * 
     * @param string $apiKey
     * @return bool
     */
    public static function isValidApiKeyFormat(string $apiKey): bool
    {
        return strpos($apiKey, 'sk-') === 0 && strlen($apiKey) > 20;
    }

    /**
     * Mask API key for logging (show only first and last 4 chars)
     * 
     * @param string $apiKey
     * @return string
     */
    public static function maskApiKey(string $apiKey): string
    {
        if (strlen($apiKey) < 8) {
            return '****';
        }

        $first = substr($apiKey, 0, 4);
        $last = substr($apiKey, -4);
        $masked = $first . '...' . $last;

        return $masked;
    }

    /**
     * Set API key in environment (useful for tests)
     * 
     * @param string $apiKey
     * @return void
     */
    public static function setApiKey(string $apiKey): void
    {
        putenv("OPENAI_API_KEY={$apiKey}");
    }

    /**
     * Clear API key from environment
     * 
     * @return void
     */
    public static function clearApiKey(): void
    {
        putenv('OPENAI_API_KEY');
    }

    /**
     * Get API key status for debugging
     * 
     * @return array
     */
    public static function getStatus(): array
    {
        $apiKey = self::getApiKey();

        return [
            'is_available' => self::isApiKeyAvailable(),
            'is_valid_format' => $apiKey ? self::isValidApiKeyFormat($apiKey) : false,
            'masked_key' => $apiKey ? self::maskApiKey($apiKey) : 'NOT_SET',
            'env_var_name' => 'OPENAI_API_KEY'
        ];
    }
}
