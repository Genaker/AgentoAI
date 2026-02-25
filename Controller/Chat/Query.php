<?php
namespace Genaker\MagentoMcpAi\Controller\Chat;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Genaker\MagentoMcpAi\Model\ConversationRepository;
use Genaker\MagentoMcpAi\Model\Conversation;

class Query implements HttpPostActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;
    
    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;
    
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var AIServiceInterface
     */
    private $openAiService;
    
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
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;
    
    /**
     * @var FilterBuilder
     */
    private $filterBuilder;
    
    /**
     * @var ConversationRepository
     */
    private $conversationRepository;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $resultJsonFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param AIServiceInterface $openAiService
     * @param LoggerInterface $logger
     * @param CacheInterface $cache
     * @param Json $json
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param ConversationRepository $conversationRepository
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        ScopeConfigInterface $scopeConfig,
        AIServiceInterface $openAiService,
        LoggerInterface $logger,
        CacheInterface $cache,
        Json $json,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FilterBuilder $filterBuilder,
        ConversationRepository $conversationRepository
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->openAiService = $openAiService;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->json = $json;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->conversationRepository = $conversationRepository;
    }

    /**
     * Execute action based on request and return result
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $requestData = $this->json->unserialize($this->request->getContent());
            
            if (empty($requestData['query'])) {
                throw new LocalizedException(__('Query is required'));
            }
            
            $userQuery = $requestData['query'];
            $storeContext = $requestData['context'] ?? [];
            $conversationHistory = $requestData['history'] ?? [];
            $conversationSummary = $requestData['summary'] ?? '';
            $customerEmail = $requestData['customer_email'] ?? null;
            $conversationId = $requestData['conversation_id'] ?? null;
            
            // If email requirement is enabled, validate we have an email
            $isEmailRequired = $this->scopeConfig->isSetFlag(
                'magentomcpai/chatbot/require_email', 
                ScopeInterface::SCOPE_STORE
            );
            
            if ($isEmailRequired && !$customerEmail) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Email is required before starting a chat')
                ]);
            }
            
            // Load conversation if ID provided
            $conversation = null;
            if ($conversationId) {
                try {
                    $conversation = $this->conversationRepository->getById($conversationId);
                    
                    // Verify email matches if provided
                    if ($customerEmail && $conversation->getCustomerEmail() !== $customerEmail) {
                        throw new LocalizedException(__('Invalid conversation access'));
                    }
                    
                    // Update last activity time
                    $conversation->setLastActivityAt(date('Y-m-d H:i:s'));
                } catch (\Exception $e) {
                    $this->logger->error('Error loading conversation: ' . $e->getMessage());
                    $conversation = null;
                }
            }
            
            // If no valid conversation and we have an email, try to find or create one
            if (!$conversation && $customerEmail) {
                try {
                    // Try to find existing active conversation
                    $conversation = $this->conversationRepository->getActiveByEmail($customerEmail);
                    
                    // If no active conversation, create a new one
                    if (!$conversation) {
                        $conversation = $this->conversationRepository->create(
                            $customerEmail,
                            null,
                            ['store_id' => $storeContext['store_id'] ?? 0]
                        );
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error creating conversation: ' . $e->getMessage());
                }
            }
            
            // Check if this is a product inquiry
            $productResponse = $this->handleProductQuery($userQuery, $storeContext);
            if ($productResponse) {
                return $resultJson->setData([
                    'success' => true,
                    'message' => $productResponse,
                    'conversation_id' => $conversation ? $conversation->getId() : null
                ]);
            }
            
            $aiResponse = null;
            
            // Check if this is an order lookup query
            $orderLookupResponse = $this->handleOrderLookupQuery($userQuery, $conversationHistory);
            if ($orderLookupResponse) {
                // Add message to conversation if we have one
                if ($conversation) {
                    $conversation->addMessage($userQuery, true);
                    $conversation->addMessage($orderLookupResponse, false);
                    $this->conversationRepository->save($conversation);
                }
                
                return $resultJson->setData([
                    'success' => true,
                    'message' => $orderLookupResponse,
                    'conversation_id' => $conversation ? $conversation->getId() : null
                ]);
            }
            
            // Standard AI response flow
            // Generate prompt
            // Check if the response is cached
            $cacheKey = 'chatbot_response_' . md5($userQuery);
            $cachedResponse = $this->cache->load($cacheKey);
            if ($cachedResponse && !$conversation) {
                return $resultJson->setData([
                    'success' => true,
                    'message' => $cachedResponse,
                    'from_cache' => true
                ]);
            }
            
            // Get AI response
            $aiResponse = $this->getAiResponse($userQuery, $storeContext, $conversationHistory, $conversationSummary);
            
            // Record messages in conversation if available
            if ($conversation) {
                $conversation->addMessage($userQuery, true);
                $conversation->addMessage($aiResponse, false);
                $this->conversationRepository->save($conversation);
            }
            
            // Cache response if no conversation record
            if (!$conversation) {
                $this->cache->save($aiResponse, $cacheKey, [], 86400); // Cache for 24 hours
            }
            
            return $resultJson->setData([
                'success' => true,
                'message' => $aiResponse,
                'conversation_id' => $conversation ? $conversation->getId() : null
            ]);
            
        } catch (LocalizedException $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            $this->logger->critical('Chatbot error: ' . $e->getMessage());
            return $resultJson->setData([
                'success' => false,
                'message' => __('An error occurred while processing your request. Please try again: ' . $e->getMessage())
            ]);
        }
    }
    
    /**
     * Get AI response for a query
     * 
     * @param string $query
     * @param array $storeContext
     * @param array $conversationHistory
     * @param string $conversationSummary
     * @return string
     */
    public function getAiResponse($query, $storeContext, $conversationHistory, $conversationSummary = '')
    {
        $apiKey = $this->scopeConfig->getValue(
            'magentomcpai/general/api_key',
            ScopeInterface::SCOPE_STORE
        );
        
        if (!$apiKey) {
            return __('AI service is not configured properly. Please contact the store administrator.');
        }
        
        try {
            // First check if we have a cached response
            $cacheKey = 'chatbot_response_' . md5($query);
            $cachedResponse = $this->cache->load($cacheKey);
            if ($cachedResponse) {
                return $cachedResponse;
            }
            
            // Check if this is a product query and there's product info available
            $productResponse = $this->handleProductQuery($query, $storeContext);
            if ($productResponse) {
                // Cache the response for future use
                $this->cache->save($productResponse, $cacheKey, [], 86400); // Cache for 24 hours
                return $productResponse;
            }
            
            // Build system prompt
            $systemPrompt = $this->buildSystemPrompt($storeContext);
            
            // Add conversation summary if provided
            if (!empty($conversationSummary)) {
                $systemPrompt .= "\n\n" . $conversationSummary;
            }
            
            // Get FAQs
            $faqs = $this->getFaqs();
            
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt
                ]
            ];
            
            // Add FAQs to context if available
            if (!empty($faqs)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => "Here are some common questions and answers to reference:\n" . $faqs
                ];
            }
            
            // Add conversation history
            foreach ($conversationHistory as $history) {
                $messages[] = [
                    'role' => $history['role'],
                    'content' => $history['content']
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
            
            $response = $this->openAiService->sendChatRequest(
                $lastUserMessage,
                $messages,
                $maxTokens,
                $temperature
            );
            
            return $response['message'] ?? '';
        } catch (\Exception $e) {
            $this->logger->error('Chatbot error: ' . $e->getMessage());
            throw new \Exception('An error occurred while processing your request.: ' . $e->getMessage());
        }
    }
    
    /**
     * Build system prompt with store context
     * 
     * @param array $storeContext
     * @return string
     */
    private function buildSystemPrompt($storeContext)
    {
        $storeName = $storeContext['name'] ?? 'our store';
        $storePhone = $storeContext['phone'] ?? 'our customer service';
        $storeEmail = $storeContext['email'] ?? 'our customer service email';
        
        $prompt = "You are a helpful customer service assistant for {$storeName}. ";
        $prompt .= "Your job is to provide friendly, concise, and accurate responses to customer questions. ";
        $prompt .= "If a customer asks about contacting customer service, you can provide the phone number ({$storePhone}) ";
        $prompt .= "or email address ({$storeEmail}) if appropriate. ";
        $prompt .= "Keep your responses friendly, helpful, and to the point. ";
        $prompt .= "If you don't know the answer to a question, suggest that the customer contact customer service directly. ";
        $prompt .= "Do not make up information you don't have.";
        
        // Add customer chatbot specific AI rules if configured
        $aiRules = $this->scopeConfig->getValue(
            'magentomcpai/chatbot/ai_rules',
            ScopeInterface::SCOPE_STORE
        );
        
        if (!empty($aiRules)) {
            $prompt .= "\n\nFollow these specific rules when responding to customers:\n" . $aiRules;
        }
        
        // Add customer chatbot specific documentation if configured
        $documentation = $this->scopeConfig->getValue(
            'magentomcpai/chatbot/documentation',
            ScopeInterface::SCOPE_STORE
        );
        
        if (!empty($documentation)) {
            $prompt .= "\n\nHere is additional information about our products and services:\n" . $documentation;
        }
        
        return $prompt;
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
     * Handle product-related query
     *
     * @param string $query
     * @param array $storeContext
     * @return string|null
     */
    private function handleProductQuery($query, $storeContext)
    {
        $this->logger->info('Product Query Handler Called for: ' . $query);
        
        // Check if product questions feature is enabled
        $isProductQuestionsEnabled = $this->scopeConfig->isSetFlag(
            'magentomcpai/chatbot/enable_product_answers',
            ScopeInterface::SCOPE_STORE
        );
        
        if (!$isProductQuestionsEnabled) {
            $this->logger->info('Product questions feature is disabled in configuration');
            return null;
        }
        
        // Check if the 'current_product' key exists in store context
        if (!isset($storeContext['current_product']) || !is_array($storeContext['current_product'])) {
            $this->logger->warning('No product data available in store context', [
                'storeContext_keys' => array_keys($storeContext)
            ]);
            return null;
        }

        $productInfo = $storeContext['current_product'];
        $this->logger->info('Product data found', [
            'product_id' => $productInfo['id'] ?? $productInfo['entity_id'] ?? 'unknown',
            'product_name' => $productInfo['name'] ?? 'unknown',
            'attributes_count' => count($productInfo),
            'attributes_keys' => array_keys($productInfo)
        ]);
        
        // Log specific search for stone attributes
        $stoneRelatedKeys = [];
        foreach ($productInfo as $key => $value) {
            if (stripos($key, 'stone') !== false || 
                stripos($key, 'gem') !== false || 
                stripos($key, 'material') !== false) {
                $stoneRelatedKeys[$key] = $value;
            }
        }
        
        // Also check in nested attributes if they exist
        if (isset($productInfo['attributes']) && is_array($productInfo['attributes'])) {
            foreach ($productInfo['attributes'] as $key => $value) {
                if (stripos($key, 'stone') !== false || 
                    stripos($key, 'gem') !== false || 
                    stripos($key, 'material') !== false) {
                    $stoneRelatedKeys['attributes.' . $key] = $value;
                }
            }
        }
        
        if (!empty($stoneRelatedKeys)) {
            $this->logger->info('Stone-related attributes found:', $stoneRelatedKeys);
        } else {
            $this->logger->warning('No stone-related attributes found in product data');
        }
        
        // Process the query to see if it's about the product
        $lowercaseQuery = strtolower($query);
        $productRelevantKeywords = ['this product', 'the product', 'it', 'made of', 'material', 'color', 'weight', 'size', 'price', 'stone'];
        
        $isProductQuery = false;
        foreach ($productRelevantKeywords as $keyword) {
            if (strpos($lowercaseQuery, $keyword) !== false) {
                $isProductQuery = true;
                break;
            }
        }
        
        if (!$isProductQuery) {
            $this->logger->info('Query is not product-related, skipping product-specific handling');
            return null;
        }
        
        // Remove blacklisted attributes
        $blacklistedAttributes = $this->getBlacklistedAttributes();
        $this->logger->info('Using attribute blacklist', ['blacklist' => $blacklistedAttributes]);
        
        $filteredProductInfo = $this->filterProductAttributes($productInfo, $blacklistedAttributes);
        
        // Create a special system prompt for product questions
        $systemPrompt = "You are a helpful product assistant for an e-commerce store. " .
            "Answer the following question about this product based ONLY on the provided information. " .
            "If the question can't be answered with the given product information, politely say you don't have that specific information " .
            "and suggest the customer to check the full product description on the website or contact customer support. " .
            "IMPORTANT: Before responding, carefully check if the attributes contain the information asked for. " .
            "Here is the product information: " . json_encode($filteredProductInfo, JSON_PRETTY_PRINT);
        
        $this->logger->info('Sending product query to AI', [
            'query' => $query,
            'product_id' => $filteredProductInfo['id'] ?? $filteredProductInfo['entity_id'] ?? 'unknown'
        ]);
        
        // Add to conversation history and get response
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $query]
        ];
        
        try {
            $response = $this->openAiService->getChatCompletion($messages);
            $this->logger->info('Received AI response for product query', [
                'response_length' => strlen($response)
            ]);
            return $response;
        } catch (\Exception $e) {
            $this->logger->critical('Error getting product AI response: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return null;
        }
    }
    
    /**
     * Get blacklisted product attributes from configuration
     *
     * @return array
     */
    private function getBlacklistedAttributes()
    {
        $blacklist = $this->scopeConfig->getValue(
            'magentomcpai/chatbot/product_attribute_blacklist',
            ScopeInterface::SCOPE_STORE
        );
        
        if (!$blacklist) {
            // Default blacklist for sensitive attributes
            return ['cost', 'price_view', 'tier_price', 'special_price', 'wholesale_price', 
                    'msrp', 'tax_class_id', 'inventory_source', 'stock_data', 'supplier_code'];
        }
        
        return array_map('trim', explode("\n", $blacklist));
    }
    
    /**
     * Filter product attributes to remove blacklisted ones
     *
     * @param array $productInfo
     * @param array $blacklistedAttributes
     * @return array
     */
    private function filterProductAttributes($productInfo, $blacklistedAttributes)
    {
        // Create a copy to avoid modifying the original
        $filteredInfo = $productInfo;
        
        // Filter top-level attributes
        foreach ($blacklistedAttributes as $attribute) {
            if (isset($filteredInfo[$attribute])) {
                unset($filteredInfo[$attribute]);
            }
        }
        
        // Filter nested attributes if they exist
        if (isset($filteredInfo['attributes']) && is_array($filteredInfo['attributes'])) {
            foreach ($blacklistedAttributes as $attribute) {
                if (isset($filteredInfo['attributes'][$attribute])) {
                    unset($filteredInfo['attributes'][$attribute]);
                }
            }
        }
        
        return $filteredInfo;
    }
    
    /**
     * Securely retrieve order status
     *
     * @param string $orderNumber Order number/increment ID
     * @param string $customerIdentifier Email or phone associated with order
     * @param string $identifierType Type of identifier ('email' or 'phone')
     * @return array|false Order status information or false if validation fails
     */
    private function getSecureOrderStatus($orderNumber, $customerIdentifier, $identifierType = 'email')
    {
        try {
            $filters = [];
            
            // Filter by increment ID (order number)
            $filters[] = $this->filterBuilder
                ->setField('increment_id')
                ->setValue($orderNumber)
                ->setConditionType('eq')
                ->create();
            
            // Filter by customer email or phone
            if ($identifierType === 'email') {
                $filters[] = $this->filterBuilder
                    ->setField('customer_email')
                    ->setValue($customerIdentifier)
                    ->setConditionType('eq')
                    ->create();
            } else if ($identifierType === 'phone') {
                // This assumes you have a billing_telephone or similar field in your order
                // You may need to adjust this based on your specific database structure
                $filters[] = $this->filterBuilder
                    ->setField('billing_telephone')
                    ->setValue($customerIdentifier)
                    ->setConditionType('eq')
                    ->create();
                
                // Alternatively, you might need to join with the customer address table
                // This would require extending this method with more complex search criteria
            }
            
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilters($filters)
                ->create();
            
            $searchResult = $this->orderRepository->getList($searchCriteria);
            
            if ($searchResult->getTotalCount() > 0) {
                /** @var OrderInterface $order */
                $order = $searchResult->getItems()[array_key_first($searchResult->getItems())];
                
                // Get shipping information
                $shippingInfo = '';
                $shippingAddress = $order->getShippingAddress();
                if ($shippingAddress) {
                    $shippingInfo = implode(', ', array_filter([
                        $shippingAddress->getFirstname() . ' ' . $shippingAddress->getLastname(),
                        implode(', ', array_filter($shippingAddress->getStreet())),
                        $shippingAddress->getCity(),
                        $shippingAddress->getRegion(),
                        $shippingAddress->getPostcode(),
                        $shippingAddress->getCountryId()
                    ]));
                }
                
                // Get a summary of ordered items (limit to 3)
                $itemsCollection = $order->getItems();
                $itemsSummary = [];
                $itemCount = 0;
                
                foreach ($itemsCollection as $item) {
                    if ($itemCount < 3) {
                        $itemsSummary[] = $item->getName() . ' x ' . (int)$item->getQtyOrdered();
                        $itemCount++;
                    } else {
                        $remainingItems = count($itemsCollection) - 3;
                        if ($remainingItems > 0) {
                            $itemsSummary[] = "and {$remainingItems} more items";
                        }
                        break;
                    }
                }
                
                // Format dates for better readability
                $createdAt = new \DateTime($order->getCreatedAt());
                $formattedCreatedAt = $createdAt->format('F j, Y');
                
                // Return limited, safe information
                return [
                    'success' => true,
                    'order_number' => $orderNumber,
                    'status' => $order->getStatus(),
                    'status_label' => $order->getStatusLabel(),
                    'created_at' => $formattedCreatedAt,
                    'total_items' => count($itemsCollection),
                    'items_summary' => implode(', ', $itemsSummary),
                    'shipping_info' => $shippingInfo,
                    'grand_total' => $order->getGrandTotal(),
                    'currency' => $order->getOrderCurrencyCode()
                ];
            }
            
            return ['error' => 'order_not_found'];
        } catch (\Exception $e) {
            $this->logger->error('Chatbot secure order query error: ' . $e->getMessage());
            return ['error' => 'system_error'];
        }
    }
    
    /**
     * Handle order lookup query
     *
     * @param string $query
     * @param array $conversationHistory
     * @return string|null
     */
    private function handleOrderLookupQuery($query, $conversationHistory)
    {
        // First check if the feature is enabled in configuration
        $isOrderLookupEnabled = (bool)$this->scopeConfig->getValue(
            'magentomcpai/chatbot/enable_order_lookup',
            ScopeInterface::SCOPE_STORE
        );
        
        if (!$isOrderLookupEnabled) {
            return null;
        }
        
        // Expanded order-related keywords for better detection (including international keywords)
        $orderKeywords = [
            'order status', 'track order', 'my order', 'where is my order',
            'order number', 'order #', 'order no', 'order information',
            'track my order', 'order tracking', 'find my order'
        ];
        
        // Check if query appears to be order-related
        $isOrderQuery = false;
        foreach ($orderKeywords as $keyword) {
            if (strpos(strtolower($query), $keyword) !== false) {
                $isOrderQuery = true;
                break;
            }
        }
        
        // More flexible detection for direct order number mentions
        if (!$isOrderQuery && preg_match('/\b(order|#)?\s*(\d{5,10})\b/i', $query)) {
            $isOrderQuery = true;
        }
        
        if ($isOrderQuery) {
            // Extract order number from current query first, then from history
            $orderNumber = null;
            $customerEmail = null;
            $customerPhone = null;
            
            // Check current query for order number (with more flexible patterns)
            if (preg_match('/#?(\d{5,10})\b/', $query, $matches)) {
                $orderNumber = $matches[1];
            }
            
            // Check current query for email
            if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $query, $matches)) {
                $customerEmail = $matches[1];
            }
            
            // Check for phone number format (several common formats)
            if (preg_match('/(\+?\d{1,4}[\s\-]?)?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{4}/', $query, $matches)) {
                $customerPhone = preg_replace('/[^0-9]/', '', $matches[0]); // Strip non-numeric characters
            }
            
            // If not found in current query, check conversation history
            if (!$orderNumber || (!$customerEmail && !$customerPhone)) {
                foreach ($conversationHistory as $history) {
                    if ($history['role'] === 'user') {
                        $message = $history['content'];
                        
                        // Extract order number with more flexible pattern
                        if (!$orderNumber && preg_match('/#?(\d{5,10})\b/', $message, $matches)) {
                            $orderNumber = $matches[1];
                        }
                        
                        // Extract email
                        if (!$customerEmail && preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $message, $matches)) {
                            $customerEmail = $matches[1];
                        }
                        
                        // Extract phone
                        if (!$customerPhone && preg_match('/(\+?\d{1,4}[\s\-]?)?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{4}/', $message, $matches)) {
                            $customerPhone = preg_replace('/[^0-9]/', '', $matches[0]);
                        }
                    }
                }
            }
            
            // If we have an order number and email/phone, retrieve order status
            if ($orderNumber && ($customerEmail || $customerPhone)) {
                // Prioritize email for now, but we can extend getSecureOrderStatus to accept phone as well
                $identifier = $customerEmail ?: $customerPhone;
                $orderStatus = $this->getSecureOrderStatus($orderNumber, $identifier, $customerEmail ? 'email' : 'phone');
                if ($orderStatus) {
                    return $this->formatOrderStatusResponse($orderStatus);
                } else {
                    return "Sorry, I couldn't find order #{$orderNumber} in our system. Please verify your order number or contact customer service for assistance.";
                }
            } else {
                // Build a helpful response based on what information we're missing
                if (!$orderNumber && !$customerEmail && !$customerPhone) {
                    return "I can help you check your order status. Please provide your order number and the email address or phone number you used when placing the order. For example: \"My order number is 12345, and my email is example@email.com\".";
                } else if (!$orderNumber) {
                    return "To check your order status, I need your order number. Could you please provide it?";
                } else if (!$customerEmail && !$customerPhone) {
                    return "To check your order #{$orderNumber}, I need the email address or phone number you used when placing the order. Could you please provide this information?";
                }
            }
        }
        
        return null;
    }
    
    /**
     * Format order status response
     *
     * @param array $orderStatus
     * @return string
     */
    private function formatOrderStatusResponse($orderStatus)
    {
        if ($orderStatus['success']) {
            $response = "Your order #{$orderStatus['order_number']} is currently {$orderStatus['status_label']}. ";
            $response .= "It was placed on {$orderStatus['created_at']} and contains {$orderStatus['total_items']} items. ";
            $response .= "The order total is {$orderStatus['grand_total']} {$orderStatus['currency']}. ";
            if (!empty($orderStatus['shipping_info'])) {
                $response .= "The shipping information is: {$orderStatus['shipping_info']}.";
            }
            return $response;
        } else {
            return "Sorry, we couldn't find your order. Please verify your information or contact our customer service for assistance.";
        }
    }
}
