<?php
namespace Genaker\MagentoMcpAi\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Psr\Log\LoggerInterface;

class Model implements ArrayInterface
{
    const XML_PATH_API_KEY = 'magentomcpai/general/api_key';
    const XML_PATH_API_DOMAIN = 'magentomcpai/general/api_domain';
    const OPENAI_MODELS_ENDPOINT = '/v1/models';
    const CACHE_KEY = 'openai_models_cache';
    const CACHE_LIFETIME = 3600; // 1 hour

    /*
     * Debug: To test model fetching manually:
     * 1. Configure API key in admin
     * 2. Clear cache: php bin/magento cache:clean
     * 3. Check logs: tail -f var/log/magento2/mcpai.log
     * 4. Visit admin config page to trigger model loading
     */

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var JsonHelper
     */
    private $jsonHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var \Magento\Framework\App\CacheInterface
     */
    private $cache;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        JsonHelper $jsonHelper,
        LoggerInterface $logger,
        \Magento\Framework\App\CacheInterface $cache
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->jsonHelper = $jsonHelper;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function toOptionArray()
    {
        // Try to get models from API first
        $dynamicModels = $this->getModelsFromApi();
       
        if (!empty($dynamicModels)) {
            return $dynamicModels;
        }

        // Fallback to static models if API fails
        return $this->getStaticModels();
    }

    /**
     * Get models from OpenAI API
     *
     * @return array
     */
    private function getModelsFromApi()
    {
        try {
            // Check cache first
            $cachedModels = false; //$this->cache->load(self::CACHE_KEY);
            if ($cachedModels) {
                return $this->jsonHelper->jsonDecode($cachedModels);
            }

            $apiKey = $this->getApiKey();
            if (empty($apiKey)) {
                $this->logger->info('OpenAI API key not configured, using static fallback models');
                return [];
            }

            $apiDomain = $this->getApiDomain();
            $endpoint = $apiDomain . self::OPENAI_MODELS_ENDPOINT;

            // Configure curl
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ]);

            $this->curl->setTimeout(10); // 10 second timeout
            $this->curl->get($endpoint);

            $statusCode = $this->curl->getStatus();
            $response = $this->curl->getBody();
            if ($statusCode !== 200) {
                $this->logger->warning('OpenAI API request failed', [
                    'status_code' => $statusCode,
                    'response' => $response
                ]);
                return [];
            }

            $responseData = $this->jsonHelper->jsonDecode($response);
            
            if (!isset($responseData['data']) || !is_array($responseData['data'])) {
                $this->logger->warning('Invalid OpenAI API response format');
                return [];
            }

            $models = $this->processApiModels($responseData['data']);
            
            $this->logger->info('Successfully processed OpenAI models', [
                'total_models_fetched' => count($responseData['data']),
                'filtered_models' => count($models),
                'models' => array_column($models, 'value')
            ]);
            
            // Cache the results
            $this->cache->save(
                $this->jsonHelper->jsonEncode($models),
                self::CACHE_KEY,
                [],
                self::CACHE_LIFETIME
            );

            return $models;

        } catch (\Exception $e) {
            $this->logger->error('Error fetching models from OpenAI API: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Process and filter API models
     *
     * @param array $apiModels
     * @return array
     */
    private function processApiModels($apiModels)
    {
        $modelFamilies = [];

        foreach ($apiModels as $model) {
            if (!isset($model['id'])) {
                continue;
            }

            $modelId = $model['id'];

            // Skip deprecated, fine-tuned, or irrelevant models
            if ($this->shouldSkipModel($modelId)) {
                continue;
            }

            $family = $this->getModelFamily($modelId);
            if (!$family) {
                continue;
            }

            // Store all models in their family groups
            if (!isset($modelFamilies[$family])) {
                $modelFamilies[$family] = [];
            }

            $modelFamilies[$family][] = [
                'id' => $modelId,
                'version' => $this->extractModelVersion($modelId),
                'date' => $model['created'] ?? 0
            ];
        }
        // Get the latest version from each family
        $latestModels = [];
        foreach ($modelFamilies as $family => $models) {
            $latestModel = $this->getLatestModelFromFamily($models);
            if ($latestModel) {
                $latestModels[] = [
                    'value' => $latestModel['id'],
                    'label' => __($this->formatModelName($latestModel['id'])),
                    'family' => $family
                ];
            }
        }

        // Sort by model priority
        usort($latestModels, function($a, $b) {
            return $this->compareModelPriority($a['family'], $b['family']);
        });

        return $latestModels;
    }

    /**
     * Get model family name
     *
     * @param string $modelId
     * @return string|null
     */
    private function getModelFamily($modelId)
    {
        $modelLower = strtolower($modelId);

        // Define model families
        $families = [
            'gpt-5-nano' => 'gpt-5-nano',
            'gpt-4o' => 'gpt-4o',
            'gpt-4-turbo' => 'gpt-4-turbo', 
            'gpt-4' => 'gpt-4',
            'gpt-3.5-turbo' => 'gpt-3.5-turbo',
            'claude' => 'claude',
            'text-' => 'completion'
        ];

        foreach ($families as $pattern => $family) {
            if (strpos($modelLower, $pattern) === 0) {
                return $family;
            }
        }

        // For GPT models not caught above
        if (strpos($modelLower, 'gpt') === 0) {
            return $modelLower;
        }

        return null;
    }

    /**
     * Extract version information from model ID
     *
     * @param string $modelId
     * @return array
     */
    private function extractModelVersion($modelId)
    {
        // Extract date patterns like YYYY-MM-DD
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $modelId, $matches)) {
            return [
                'type' => 'date',
                'value' => $matches[1],
                'timestamp' => strtotime($matches[1])
            ];
        }

        // Extract version numbers
        if (preg_match('/v?(\d+)\.?(\d*)/', $modelId, $matches)) {
            $major = (int)$matches[1];
            $minor = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : 0;
            return [
                'type' => 'version',
                'major' => $major,
                'minor' => $minor,
                'value' => $major * 100 + $minor
            ];
        }

        // Check for special versions
        if (strpos($modelId, 'preview') !== false) {
            return ['type' => 'preview', 'value' => 1000]; // Preview versions are newer
        }

        if (strpos($modelId, 'turbo') !== false) {
            return ['type' => 'turbo', 'value' => 500];
        }

        return ['type' => 'base', 'value' => 0];
    }

    /**
     * Get the latest model from a family group
     *
     * @param array $models
     * @return array|null
     */
    private function getLatestModelFromFamily($models)
    {
        if (empty($models)) {
            return null;
        }

        // Sort by creation date first, then by version
        usort($models, function($a, $b) {
            // Prefer newer creation dates
            if ($a['date'] !== $b['date']) {
                return $b['date'] - $a['date'];
            }

            // Then sort by version
            $aVersion = $a['version'];
            $bVersion = $b['version'];

            // Same version type comparison
            if ($aVersion['type'] === $bVersion['type']) {
                if ($aVersion['type'] === 'date' && isset($aVersion['timestamp'])) {
                    return $bVersion['timestamp'] - $aVersion['timestamp'];
                }
                return $bVersion['value'] - $aVersion['value'];
            }

            // Different version types - priority order
            $typePriority = ['date' => 4, 'preview' => 3, 'turbo' => 2, 'version' => 1, 'base' => 0];
            return ($typePriority[$bVersion['type']] ?? 0) - ($typePriority[$aVersion['type']] ?? 0);
        });

        return $models[0];
    }

    /**
     * Compare model family priority for sorting
     *
     * @param string $familyA
     * @param string $familyB
     * @return int
     */
    private function compareModelPriority($familyA, $familyB)
    {
        $priority = [
            'gpt-5-nano' => 75,
            'gpt-4o' => 100,
            'gpt-4-turbo' => 90,
            'gpt-4' => 80,
            'gpt-3.5-turbo' => 70,
            'claude' => 60,
            'gpt-other' => 50,
            'completion' => 40
        ];

        $priorityA = $priority[$familyA] ?? 0;
        $priorityB = $priority[$familyB] ?? 0;

        return $priorityB - $priorityA;
    }

    /**
     * Check if model should be skipped
     *
     * @param string $modelId
     * @return bool
     */
    private function shouldSkipModel($modelId)
    {
        $skipPatterns = [
            'image',
            'instruct',      // Instruction-tuned models
            'babbage',       // Older models
            'ada',           // Older models
            'curie',         // Older models
            'davinci',       // Older models (unless GPT-3.5/4)
            'whisper',       // Audio models
            'tts',           // Text-to-speech
            'dall-e',        // Image generation
            'embedding',     // Embedding models
            'text-similarity', // Similarity models
            'text-search',   // Search models
            'code-search',   // Code search models
            'ft:',           // Fine-tuned models
            ':ft-',          // Fine-tuned models
        ];

        $modelLower = strtolower($modelId);
        
        foreach ($skipPatterns as $pattern) {
            if (strpos($modelLower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format model name for display
     *
     * @param string $modelId
     * @return string
     */
    private function formatModelName($modelId)
    {
        // Handle specific model naming patterns
        $nameMap = [
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
            'gpt-3.5-turbo-16k' => 'GPT-3.5 Turbo (16K)',
            'gpt-4' => 'GPT-4',
            'gpt-4-32k' => 'GPT-4 (32K)',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-4-turbo-preview' => 'GPT-4 Turbo Preview',
            'gpt-4o' => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o Mini',
            'gpt-5' => 'GPT-5 (272K)',
            'gpt-5-nano' => 'GPT-5 Nano',
        ];
        /*
        GPT-5 Mini

Input: $0.25 / 1M tokens

Output: $2.00 / 1M tokens

GPT-5 Nano (cheapest)

Input: $0.05 / 1M tokens

Output: $0.40 / 1M tokens
*/

        if (isset($nameMap[$modelId])) {
            return $nameMap[$modelId];
        }

        // Generic formatting for unknown models
        $formatted = str_replace(['-', '_'], [' ', ' '], $modelId);
        $formatted = ucwords($formatted);
        $formatted = str_replace('Gpt', 'GPT', $formatted);
        
        return $formatted;
    }



    /**
     * Get static fallback models (latest versions only)
     *
     * @return array
     */
    private function getStaticModels()
    {
        return [
            ['value' => 'gpt-5-nano', 'label' => __('GPT-5 Nano (Default, Cheapest)')],
            ['value' => 'gpt-4o', 'label' => __('GPT-4o (Latest)')],
            ['value' => 'gpt-4o-mini', 'label' => __('GPT-4o Mini (Latest)')],
            ['value' => 'gpt-4-turbo', 'label' => __('GPT-4 Turbo (Latest)')],
            ['value' => 'gpt-3.5-turbo', 'label' => __('GPT-3.5 Turbo (Latest)')],
        ];
    }

    /**
     * Get API key from configuration
     *
     * @return string|null
     */
    private function getApiKey()
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get API domain from configuration
     *
     * @return string
     */
    private function getApiDomain()
    {
        $domain = $this->scopeConfig->getValue(
            self::XML_PATH_API_DOMAIN,
            ScopeInterface::SCOPE_STORE
        );

        return $domain ?: 'https://api.openai.com';
    }
} 