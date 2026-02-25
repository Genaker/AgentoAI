<?php
namespace Genaker\MagentoMcpAi\Model;

use Genaker\MagentoMcpAi\Api\CustomerChatbotInterface;
use Genaker\MagentoMcpAi\Api\Data\ChatResponseInterface;
use Genaker\MagentoMcpAi\Api\Data\ChatResponseInterfaceFactory;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

class CustomerChatbot implements CustomerChatbotInterface
{
    const CACHE_KEY_PREFIX = 'customer_chatbot_';
    const CACHE_LIFETIME = 86400; // 24 hours
    
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
     * @var CacheInterface
     */
    private $cache;
    
    /**
     * @var Json
     */
    private $json;
    
    /**
     * @var ChatResponseInterfaceFactory
     */
    private $chatResponseFactory;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param AIServiceInterface $aiService
     * @param LoggerInterface $logger
     * @param CacheInterface $cache
     * @param Json $json
     * @param ChatResponseInterfaceFactory $chatResponseFactory
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        AIServiceInterface $aiService,
        LoggerInterface $logger,
        CacheInterface $cache,
        Json $json,
        ChatResponseInterfaceFactory $chatResponseFactory
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->aiService = $aiService;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->json = $json;
        $this->chatResponseFactory = $chatResponseFactory;
    }

    /**
     * Process a customer query and return a response
     *
     * @param string $query Customer's question or query
     * @param string $context Optional additional context for the query
     * @param string $apiKey API key for authentication
     * @return \Genaker\MagentoMcpAi\Api\Data\ChatResponseInterface
     * @throws LocalizedException
     */
    public function processQuery($query, $context = null, $apiKey = null)
    {
        try {
            // Validate API key if provided
            if ($apiKey !== null) {
                $storedApiKey = $this->scopeConfig->getValue(
                    'magentomcpai/general/api_key',
                    ScopeInterface::SCOPE_STORE
                );
                
                if (empty($apiKey) || $apiKey !== $storedApiKey) {
                    throw new LocalizedException(__('Invalid API key'));
                }
            }
            
            // Check if chatbot is enabled
            if (!$this->isChatbotEnabled()) {
                throw new LocalizedException(__('Customer chatbot is disabled'));
            }
            
            // Check if we have a cached response for the query
            $response = $this->getCachedResponse($query);
            
            if (!$response) {
                // Generate new response from AI
                $response = $this->generateAiResponse($query, $this->parseContext($context));
                
                // Cache the response for future use
                $this->cacheResponse($query, $response);
            }
            
            // Create and return response object
            $chatResponse = $this->chatResponseFactory->create();
            $chatResponse->setSuccess(true);
            $chatResponse->setMessage($response);
            
            return $chatResponse;
        } catch (LocalizedException $e) {
            $this->logger->error('Customer Chatbot error: ' . $e->getMessage());
            
            $chatResponse = $this->chatResponseFactory->create();
            $chatResponse->setSuccess(false);
            $chatResponse->setMessage($e->getMessage());
            
            return $chatResponse;
        } catch (\Exception $e) {
            $this->logger->error('Customer Chatbot error: ' . $e->getMessage());
            
            $chatResponse = $this->chatResponseFactory->create();
            $chatResponse->setSuccess(false);
            $chatResponse->setMessage(__('An error occurred while processing your request.'));
            
            return $chatResponse;
        }
    }
    
    /**
     * Check if chatbot is enabled
     *
     * @return bool
     */
    private function isChatbotEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            'magentomcpai/chatbot/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }
    
    /**
     * Get cached response for a query
     *
     * @param string $query
     * @return string|null
     */
    private function getCachedResponse($query)
    {
        $cacheKey = self::CACHE_KEY_PREFIX . md5($query);
        $cachedData = $this->cache->load($cacheKey);
        
        if ($cachedData) {
            try {
                return $this->json->unserialize($cachedData);
            } catch (\Exception $e) {
                $this->logger->error('Error unserializing cached chatbot response: ' . $e->getMessage());
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Cache a response for a query
     *
     * @param string $query
     * @param string $response
     * @return void
     */
    private function cacheResponse($query, $response)
    {
        $cacheKey = self::CACHE_KEY_PREFIX . md5($query);
        
        $this->cache->save(
            $this->json->serialize($response),
            $cacheKey,
            ['CUSTOMER_CHATBOT_CACHE'],
            self::CACHE_LIFETIME
        );
    }
    
    /**
     * Generate AI response for a query
     *
     * @param string $query
     * @param array $context
     * @return string
     */
    private function generateAiResponse($query, $context)
    {
        $apiKey = $this->scopeConfig->getValue(
            'magentomcpai/general/api_key',
            ScopeInterface::SCOPE_STORE
        );
        
        if (!$apiKey) {
            throw new LocalizedException(__('OpenAI API key is not configured'));
        }
        
        $model = $this->scopeConfig->getValue(
            'magentomcpai/chatbot/model',
            ScopeInterface::SCOPE_STORE
        ) ?: 'gpt-5-nano';
        
        // Build system message
        $systemMessage = $this->buildSystemMessage($context);
        
        // Add FAQs to context if available
        $faqs = $this->getFaqs();
        
        $messages = [
            [
                'role' => 'system',
                'content' => $systemMessage
            ]
        ];
        
        if (!empty($faqs)) {
            $messages[] = [
                'role' => 'system',
                'content' => "Here are some frequently asked questions and answers to reference:\n" . $faqs
            ];
        }
        
        // Add user query
        $messages[] = [
            'role' => 'user',
            'content' => $query
        ];
        
        // Extract the last user message as the main message string
        // sendChatRequest expects: (string $message, array $messages = [], int $maxTokens = 2000, float $temperature = 0.7, array $tools = [])
        $lastUserMessage = $query;
        $maxTokens = 2000;
        $temperature = 0.7;
        
        $response = $this->aiService->sendChatRequest(
            $lastUserMessage,
            $messages,
            $maxTokens,
            $temperature
        );
        
        return $response['message'] ?? '';
    }
    
    /**
     * Build system message for AI with store context
     *
     * @param array $context
     * @return string
     */
    private function buildSystemMessage($context)
    {
        $storeName = $context['store_name'] ?? 'our store';
        
        $message = "You are a helpful customer service assistant for {$storeName}. ";
        $message .= "Your job is to provide friendly, concise, and accurate responses to customer questions. ";
        $message .= "Always be polite and helpful. ";
        $message .= "If you don't know the answer to a question, suggest that the customer contact customer service. ";
        $message .= "Do not make up information you don't have. ";
        
        // Add store contact info if available
        if (isset($context['store_phone'])) {
            $message .= "Our customer service phone number is: " . $context['store_phone'] . ". ";
        }
        
        if (isset($context['store_email'])) {
            $message .= "Our customer service email is: " . $context['store_email'] . ". ";
        }
        
        return $message;
    }
    
    /**
     * Get FAQs from configuration
     *
     * @return string
     */
    private function getFaqs()
    {
        return $this->scopeConfig->getValue(
            'magentomcpai/chatbot/faqs',
            ScopeInterface::SCOPE_STORE
        ) ?: '';
    }
    
    /**
     * Parse context from string to array
     *
     * @param string|null $context
     * @return array
     */
    private function parseContext($context)
    {
        if (empty($context)) {
            return $this->getDefaultContext();
        }
        
        try {
            $parsedContext = $this->json->unserialize($context);
            return is_array($parsedContext) ? $parsedContext : $this->getDefaultContext();
        } catch (\Exception $e) {
            $this->logger->error('Error parsing context: ' . $e->getMessage());
            return $this->getDefaultContext();
        }
    }
    
    /**
     * Get default store context
     *
     * @return array
     */
    private function getDefaultContext()
    {
        $storeName = $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );
        
        $storePhone = $this->scopeConfig->getValue(
            'general/store_information/phone',
            ScopeInterface::SCOPE_STORE
        );
        
        $storeEmail = $this->scopeConfig->getValue(
            'trans_email/ident_general/email',
            ScopeInterface::SCOPE_STORE
        );
        
        return [
            'store_name' => $storeName,
            'store_phone' => $storePhone,
            'store_email' => $storeEmail
        ];
    }
}
