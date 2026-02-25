<?php
namespace Genaker\MagentoMcpAi\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Exception\LocalizedException;
use Genaker\MagentoMcpAi\Api\McpAiInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Genaker\MagentoMcpAi\Model\Validator\QueryValidator;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Session\SessionManagerInterface;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Psr\Log\LoggerInterface;
use Zend_Db_Statement_Exception;

class McpAi implements McpAiInterface
{
    const XML_PATH_API_KEY = 'magentomcpai/general/api_key';
    //magentomcpai_general_mspi_api_key
    const XML_PATH_MSPI_API_KEY = 'magentomcpai/general/mspi_api_key';
    const XML_PATH_AI_RULES = 'magentomcpai/general/ai_rules';
    const XML_PATH_DOCUMENTATION = 'magentomcpai/general/documentation';
    const XML_PATH_USE_CUSTOM_DB = 'magentomcpai/database/use_custom_connection';
    const XML_PATH_DB_HOST = 'magentomcpai/database/db_host';
    const XML_PATH_DB_NAME = 'magentomcpai/database/db_name';
    const XML_PATH_DB_USER = 'magentomcpai/database/db_user';
    const XML_PATH_DB_PASSWORD = 'magentomcpai/database/db_password';
    const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const CACHE_KEY_PREFIX = 'mcpai_conversation_';
    const MAX_HISTORY_MESSAGES = 10;
    const SESSION_KEY = 'mcpai_session_id';
    const XML_PATH_SEND_MAGENTO_DATA_TO_AI = 'magentomcpai/general/send_magento_data_to_ai';

    protected $scopeConfig;
    protected $jsonHelper;
    protected $resourceConnection;
    protected $resultJsonFactory;
    protected $queryValidator;
    protected $cache;
    protected $customConnection = null;
    protected $deploymentConfig;
    protected $sessionManager;
    protected $aiService;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        JsonHelper $jsonHelper,
        ResourceConnection $resourceConnection,
        JsonFactory $resultJsonFactory,
        QueryValidator $queryValidator,
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        SessionManagerInterface $sessionManager,
        AIServiceInterface $aiService,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->jsonHelper = $jsonHelper;
        $this->resourceConnection = $resourceConnection;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->queryValidator = $queryValidator;
        $this->cache = $cache;
        $this->deploymentConfig = $deploymentConfig;
        $this->sessionManager = $sessionManager;
        $this->aiService = $aiService;
        $this->logger = $logger;
    }

    protected function getSessionId()
    {
        $sessionId = $this->sessionManager->getData(self::SESSION_KEY);
        
        if (!$sessionId) {
            $sessionId = (string)time();
            $this->sessionManager->setData(self::SESSION_KEY, $sessionId);
        }
        
        return $sessionId;
    }

    protected function getConversationHistory($sessionId)
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $sessionId;
        $history = $this->cache->load($cacheKey);
        return $history ? $this->jsonHelper->jsonDecode($history) : [];
    }

    protected function saveConversationHistory($sessionId, $history)
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $sessionId;
        // Keep only the last MAX_HISTORY_MESSAGES messages
        if (count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        }
        $this->cache->save(
            $this->jsonHelper->jsonEncode($history),
            $cacheKey,
            ['MCPAI_CONVERSATION'],
            3600 // Cache for 1 hour
        );
    }

    protected function addToHistory($sessionId, $role, $content)
    {
        $history = $this->getConversationHistory($sessionId);
        $history[] = [
            'role' => $role,
            'content' => $content
        ];
        $this->saveConversationHistory($sessionId, $history);
        return $history;
    }

    protected function getAiRules()
    {
        $rules = $this->scopeConfig->getValue(
            self::XML_PATH_AI_RULES,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $rules ?: [];
    }

    protected function getDocumentation()
    {
        $docs = $this->scopeConfig->getValue(
            self::XML_PATH_DOCUMENTATION,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $docs ?: [];
    }

    protected function buildSystemMessage()
    {
        $rules = $this->getAiRules();
        $documentation = $this->getDocumentation();
        
        $systemMessage = 'You are a SQL query generator for Magento 2 database. Your role is to assist with database queries while maintaining security. ';
        
        // Add configured rules
        if (!empty($rules)) {
            $systemMessage .= "\n Rules: " . $rules;
        } else {
            // Default rules
            $systemMessage .= "\n1. Generate only SELECT or DESCRIBE queries";
            $systemMessage .= "\n2. Validate each generated query";
            $systemMessage .= "\n3. Start responses with SQL in triple backticks: ```sql SELECT * FROM table; ```";
            $systemMessage .= "\n4. Reject any non-SELECT/DESCRIBE queries";
            $systemMessage .= "\n6. Provide clear and short explanations of query results";
            $systemMessage .= "\n7. It is Adobe Commerce Enterprise version use row_id vs entity_id to join tables";
            $systemMessage .= "\n8. Try to resolve any request as sql query to the magento MySQL database if it is not possible to resolve the request as sql query, response to provide more necessary information if you have any questions";
            $systemMessage .= "\n9. When asked about context return should whave data to  provide to this chat(you) to generate the same result ";
        }
        
        // Add documentation context
        if (!empty($documentation)) {
            $systemMessage .= "\n\nDocumentation Context:" . $documentation;
            
        }
        
        return $systemMessage;
    }

    protected function getCustomConnection()
    {
        if ($this->customConnection === null) {
            $aiDbConfig = $this->deploymentConfig->get('db/ai_connection');
            
            if ($aiDbConfig) {
                try {
                    $this->customConnection = new \Magento\Framework\DB\Adapter\Pdo\Mysql([
                        'host' => $aiDbConfig['host'],
                        'dbname' => $aiDbConfig['dbname'],
                        'username' => $aiDbConfig['username'],
                        'password' => $aiDbConfig['password'],
                        'active' => '1'
                    ]);
                } catch (\Exception $e) {
                    // Log error and fall back to default connection
                    $this->customConnection = false;
                }
            }
        }

        return $this->customConnection;
    }

    protected function getConnection()
    {
        $customConnection = $this->getCustomConnection();
        return $customConnection ?: $this->resourceConnection->getConnection();
    }

    public function generateQuery($prompt, $model = 'gpt-3.5-turbo', $mspiApiKey = null)
    {
        // Initialize result and content variables
        $result = [];
        $content = '';
        $tokenUsage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0
        ];
        
        try {
            // Get session ID from session storage or generate new one
            $sessionId = $this->getSessionId();

            // Validate MSPI API key
            if (!$this->validateMspiApiKey($mspiApiKey)) {
                return [
                    'success' => false,
                    'type' => 'error',
                    'content' => $content ?: 'Please provide your query.',
                    'result' => 'Invalid MSPI API key provided.',
                    'token_usage' => $tokenUsage
                ];
            }

            // Add user message to history with role
            $this->addToHistory($sessionId, 'user', $prompt);

            // Get conversation history
            $history = $this->getConversationHistory($sessionId);

            // Define system message with role
            $systemMessage = [
                'role' => 'system',
                'content' => $this->buildSystemMessage()
            ];

            // Prepare messages array for API with clear roles
            $messages = [$systemMessage];

            // Add conversation history with roles preserved
            foreach ($history as $msg) {
                if (!empty($msg['role']) && !empty($msg['content'])) {
                    $messages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    ];
                }
            }

            $apiKey = $this->getApiKey();
            if (!$apiKey) {
                return [
                    'success' => false,
                    'type' => 'error',
                    'content' => $content ?: 'Please provide your query.',
                    'result' => 'OpenAI API key is not configured',
                    'token_usage' => $tokenUsage
                ];
            }

            // Extract the last user message as the main message string
            // sendChatRequest expects: (string $message, array $messages = [], int $maxTokens = 2000, float $temperature = 0.7, array $tools = [])
            $lastUserMessage = $prompt;
            $maxTokens = 2000;
            $temperature = 0.7;

            // Use AIServiceInterface to make the API call
            $response = $this->aiService->sendChatRequest(
                $lastUserMessage,
                $messages,
                $maxTokens,
                $temperature
            );

            $content = $response['message'] ?? '';
            
            // Normalize token usage structure
            $tokens = $response['tokens'] ?? [];
            $tokenUsage = [
                'prompt_tokens' => $tokens['input'] ?? $tokens['prompt_tokens'] ?? 0,
                'completion_tokens' => $tokens['output'] ?? $tokens['completion_tokens'] ?? 0,
                'total_tokens' => $tokens['total'] ?? $tokens['total_tokens'] ?? 0
            ];

            // Add assistant response to history with role
            $this->addToHistory($sessionId, 'assistant', $content);

            // Extract query from markdown code block
            if (preg_match('/```sql(.+?)```/s', $content, $matches)) {
                $query = trim($matches[1]);
                
                try {
                    // Validate the query before execution
                    $this->queryValidator->validate($query);
                    
                    // Execute the query
                    $connection = $this->getConnection();
                    $result = $connection->fetchAll($query);
                    //dd($result);

                    // Check if sending Magento data to AI is enabled
                    $sendMagentoData = $this->scopeConfig->isSetFlag(
                        self::XML_PATH_SEND_MAGENTO_DATA_TO_AI,
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                    );
                    
                    // Add SQL data to CAG history with role user
                    if ($sendMagentoData) { // TODO: uncomment this when we have a way to store the SQL data or add to the config 
                        $this->addToHistory($sessionId, 'user', "SQL Resulr data: " . json_encode($result));
                    }
                    
                    return [
                        'success' => true,
                        'type' => 'query_result',
                        'content' => $content,
                        'result' => $result,
                        'token_usage' => $tokenUsage
                    ];
                } catch (Zend_Db_Statement_Exception $e) {
                    // Check if sending Magento data to AI is enabled
                    // TODO: probably need separate config for this
                    $sendMagentoData = $this->scopeConfig->isSetFlag(
                        self::XML_PATH_SEND_MAGENTO_DATA_TO_AI,
                        \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                    );
                    // Add SQL Error to CAG history with role user
                    if ($sendMagentoData) { // TODO: uncomment this when we have a way to store the SQL data or add to the config 
                        $this->addToHistory($sessionId, 'user', "SQL Error: " . $e->getMessage());
                    }
                    return [
                        'success' => false,
                        'type' => 'error',
                        'content' => $content ?: 'An error occurred while processing SQL query GENERATED BY ai.',
                        'result' => $e->getMessage(),
                        'token_usage' => $tokenUsage
                    ];
                }
            }
            
            // If no code block found, return the full response without result
            return [
                'success' => true,
                'type' => 'chat_response',
                'content' => $content,
                'result' => null,
                'token_usage' => $tokenUsage
            ];
        } catch (\Exception $e) {
            $this->logger->error('An error occurred while processing your query: ' . $e->getMessage());

            return [
                'success' => false,
                'type' => 'error',
                'content' => $content ?: 'An error occurred while processing your query.',
                'result' => $e->getMessage() . ' : ' .$e->getTraceAsString(),
                'token_usage' => $tokenUsage
            ];
        } 
    }

    public function executeQuery($query)
    {
        // Initialize result variable
        $result = [];
        $content = '';
        
        try {
            // Validate the query before execution
            $this->queryValidator->validate($query);
            
            $connection = $this->getConnection();
            $result = $connection->fetchAll($query);
            
            return [
                'success' => true,
                'type' => 'query_result',
                'content' => $content ?: 'Query executed successfully.',
                'result' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'error',
                'content' => $content ?: 'An error occurred while processing your query.',
                'result' => $e->getMessage(),
                'token_usage' => $tokenUsage
            ];
        }
    }

    public function clearConversationHistory($mspiApiKey)
    {
        try {
            // Validate MSPI API key
            if (!$this->validateMspiApiKey($mspiApiKey)) {
                return [
                    'success' => false,
                    'message' => 'Invalid MSPI API key provided.'
                ];
            }

            // Get session ID
            $sessionId = $this->getSessionId();
            
            // Clear conversation history from cache
            $cacheKey = self::CACHE_KEY_PREFIX . $sessionId;
            $this->cache->remove($cacheKey);
            
            // Clear session data
            $this->sessionManager->unsetData(self::SESSION_KEY);
            
            return [
                'success' => true,
                'message' => 'Conversation history cleared successfully.'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate MSPI API key
     *
     * @param string $providedKey
     * @return bool
     */
    public function validateMspiApiKey($providedKey)
    {
        // using Api key from config
        $storedKey = $this->getApiKey();

        return $providedKey === $storedKey;
    }

    protected function getApiKey()
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
} 