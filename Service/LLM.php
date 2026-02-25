<?php
namespace Genaker\MagentoMcpAi\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;

class LLM
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    private $apiKey;

    private $aiService;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        AIServiceInterface $aiService
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->aiService = $aiService;
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

        if(!$this->apiKey ){
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

        $result = $this->aiService->sendChatRequest(
            $messageString,
            $previousMessages,
            2000,
            $temperature
        );
        return $result;
    }

}
