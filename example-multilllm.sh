#!/bin/bash

# MultiLLM Service Example Usage
# This script demonstrates how to use the new generic MultiLLMService

API_KEY="${1:-}"

if [ -z "$API_KEY" ]; then
    echo "Usage: $0 <openai_api_key>"
    echo ""
    echo "Example:"
    echo "  $0 sk-proj-xxxx"
    exit 1
fi

cat > /tmp/test_multilllm.php << 'PHP'
<?php
// Setup Magento autoloader
require_once getenv('PWD') . '/vendor/autoload.php';
require_once getenv('PWD') . '/app/bootstrap.php';

// Use the MultiLLM Service
use Genaker\MagentoMcpAi\Model\Service\MultiLLMService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

// Mock objects for simple usage
$scopeConfig = new class implements ScopeConfigInterface {
    public function getValue($path, $scopeType = 'default', $scopeCode = null) {
        return null;  // Use env vars instead
    }
    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null) {
        return false;
    }
};

$logger = new class implements LoggerInterface {
    public function emergency($message, array $context = []): void {}
    public function alert($message, array $context = []): void {}
    public function critical($message, array $context = []): void {}
    public function error($message, array $context = []): void { echo "[ERROR] $message\n"; }
    public function warning($message, array $context = []): void {}
    public function notice($message, array $context = []): void {}
    public function info($message, array $context = []): void { echo "[INFO] $message\n"; }
    public function debug($message, array $context = []): void {}
    public function log($level, $message, array $context = []): void {}
};

// Create service instance
$service = new MultiLLMService($scopeConfig, $logger);

// Show available providers
echo "\n=== Available Providers ===\n";
$providers = $service->getAvailableProviders();
foreach ($providers as $name => $info) {
    $status = $info['available'] ? '✓' : '✗';
    echo "[$status] {$name} - Default model: {$info['default_model']}\n";
}

// Show pricing
echo "\n=== Pricing (per 1M tokens) ===\n";
foreach (['openai', 'claude', 'gemini', 'deepseek', 'grok'] as $provider) {
    $pricing = $service->getPricing($provider);
    echo "\n$provider:\n";
    foreach ($pricing as $model => $rates) {
        echo "  $model: \$" . number_format($rates['input'], 4) . " input, \$" . number_format($rates['output'], 4) . " output\n";
    }
}

// Test OpenAI if API key is available
if (getenv('AI_OPENAI_API_KEY')) {
    echo "\n=== Testing OpenAI Chat ===\n";
    try {
        $response = $service->query(
            'Write a one-word greeting',
            'openai',
            'gpt-3.5-turbo',
            ['maxOutputTokens' => 20]
        );
        
        echo "Response: " . $response['text'] . "\n";
        echo "Model: " . $response['model'] . "\n";
        echo "Tokens: " . $response['tokens']['total'] . " (input: " . $response['tokens']['input'] . ", output: " . $response['tokens']['output'] . ")\n";
        echo "Cost: \$" . number_format($response['cost'], 6) . "\n";
        echo "Finish Reason: " . $response['finish_reason'] . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Example: Switch providers without code change
echo "\n=== Example: Provider Switching ===\n";
echo "With the MultiLLMService, you can switch providers with just one parameter:\n";
echo "  \$service->query('prompt', 'openai', 'gpt-3.5-turbo');\n";
echo "  \$service->query('prompt', 'claude', 'claude-3-haiku-latest');\n";
echo "  \$service->query('prompt', 'gemini', 'gemini-2.5-flash');\n";
echo "  \$service->query('prompt', 'deepseek', 'deepseek-chat');\n";
echo "  \$service->query('prompt', 'grok', 'grok-3-fast-latest');\n";

echo "\n✓ MultiLLMService is ready to use!\n";
PHP

# Run the test script with API key
AI_OPENAI_API_KEY="$API_KEY" php /tmp/test_multilllm.php
