<?php
namespace Genaker\MagentoMcpAi\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Psr\Log\LoggerInterface;

class LLM
{
    private const LOG_PREFIX = '[MagentoMcpAi]';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    private $apiKey;

    private $aiService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param AIServiceInterface $aiService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        AIServiceInterface $aiService,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->aiService = $aiService;
        $this->logger = $logger;
    }

    /**
     * Get the OpenAI API key from config
     *
     * @return string
     * @throws LocalizedException
     */
    public function getApiKey(): string
    {
        if($this->apiKey){
            return $this->apiKey;
        }

        $this->apiKey = $this->scopeConfig->getValue(
            'magentomcpai/general/api_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if (!$this->apiKey) {
            $this->logger->error(self::LOG_PREFIX . ' API key not configured', [
                'config_path' => 'magentomcpai/general/api_key',
            ]);
            throw new LocalizedException(
                __('OpenAI API key is not set in the admin configuration')
            );
        }

        return $this->apiKey;
    }


    /**
     * Send a chat request to the OpenAI API
     *
     * @param string|array $query User query or array of messages
     * @param string $model Model to use
     * @param float $temperature Temperature for randomness
     * @return array Response from AI service
     * @throws LocalizedException
     */
    public function LLM($query, $model = 'gpt-5-nano', $temperature = 1): array
    {
        $messageString = '';
        $previousMessages = [];
        
        if (is_array($query)) {
            // If query is array of messages, extract the last user message
            $previousMessages = $query;
            foreach (array_reverse($query) as $msg) {
                if (isset($msg['role']) && $msg['role'] === 'user' && isset($msg['content'])) {
                    $messageString = $msg['content'];
                    break;
                }
            }
        } else {
            // If query is a string, use it as the message
            $messageString = (string)$query;
        }

        try {
            $result = $this->aiService->sendChatRequest(
                $messageString,
                $previousMessages,
                2000,
                $temperature
            );
            return $result;
        } catch (\Exception $e) {
            $this->logger->error(self::LOG_PREFIX . ' LLM request failed', [
                'error' => $e->getMessage(),
                'message_preview' => substr($messageString, 0, 200),
                'messages_count' => count($previousMessages),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

}
