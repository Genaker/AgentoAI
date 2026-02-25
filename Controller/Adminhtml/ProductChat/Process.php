<?php
namespace Genaker\MagentoMcpAi\Controller\Adminhtml\ProductChat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\LayoutFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Genaker\MagentoMcpAi\Model\MenuAIAPI;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Process
 */
class Process extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var MenuAIAPI
     */
    protected $menuAIAPI;

    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param MenuAIAPI $menuAIAPI
     */
    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param MenuAIAPI $menuAIAPI
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        MenuAIAPI $menuAIAPI,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->menuAIAPI = $menuAIAPI;
        $this->_scopeConfig = $scopeConfig;
    }

    /**
     * Process chat request
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $query = $this->getRequest()->getParam('query');
        if (!$query) {
            return $this->resultJsonFactory->create()->setData([
                'error' => 'No query provided'
            ]);
        }
        
        // Get conversation history if available
        $conversationHistoryJson = $this->getRequest()->getParam('conversation_history');
        $conversationHistory = [];
        if ($conversationHistoryJson) {
            try {
                $conversationHistory = json_decode($conversationHistoryJson, true);
                if (!is_array($conversationHistory)) {
                    $conversationHistory = [];
                }
            } catch (\Exception $e) {
                // If JSON decoding fails, continue with empty history
                $conversationHistory = [];
            }
        }

        try {
            // Get API key from configuration
            $apiKey = $this->_scopeConfig->getValue(
                'magentomcpai/general/api_key',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            if (!$apiKey) {
                throw new \Exception('API key not configured');
            }

           
            $systemContent = '1.You are a helpful product assistant for this online store selling gunsafes. 
            2.Provide concise and accurate information about products. 
            3.If not enought information from customer ask about more details
            4.Provide image links for products and url to the product page
            5.Respond with 5 products if you have relevant information
            5.1 if user ask showing more product show more products but not more than 10 
            6.Ask user when he want to buy 
            6.1 use MD formate for response inks in the ([url]) format
            6.2 image comes last in the product description
            6.3 product name first starts with ###
            6.4 add your thinking after the image about this safe as a sales person and expert in safes tell right away if it is cheap and not secure enough 
            7.Instruction to buy by phone call **911**';
            
            // Create messages array for OpenAI
            $messages = [
                ['role' => 'system', 'content' => $systemContent]
            ];
            
            // Add conversation history if available (limited to last 10 messages to avoid token limits)
            if (!empty($conversationHistory)) {
                $recentHistory = array_slice($conversationHistory, -10);
                foreach ($recentHistory as $message) {
                    if (isset($message['role']) && isset($message['content'])) {
                        // Skip the last user message as we'll add it separately
                        if ($message['role'] === 'user' && $message === end($recentHistory)) {
                            continue;
                        }
                        $messages[] = $message;
                    }
                }
            }
            
            // Add the current user query
            $messages[] = ['role' => 'user', 'content' => $query];

            // Process the request through MenuAIAPI
            $response = $this->menuAIAPI->sendRequestToChatGPT($query, $apiKey);
            // Extract the content from the response array
            $messageContent = is_array($response) && isset($response['content']) ? $response['content'] : $response;
            
            // Format the message content with HTML
            $formattedContent = $this->formatResponseContent($messageContent);
            
            // Include usage statistics if available
            $responseData = [
                'message' => $formattedContent,
                'hash' => md5($messageContent),
                'url' => null
            ];
            
            // Add usage data if available
            if (is_array($response) && isset($response['usage'])) {
                $responseData['usage'] = $response['usage'];
            }
            
            return $this->resultJsonFactory->create()->setData($responseData);
        } catch (\Exception $e) {
            return $this->resultJsonFactory->create()->setData([
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check permission via ACL resource
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Genaker_MagentoMcpAi::mcpai');
    }
    
    /**
     * Format the response content with HTML formatting
     *
     * @param string $content
     * @return string
     */
    private function formatResponseContent($content)
    {
        // Convert line breaks to <br> tags and prevent multiple consecutive breaks
        $content = preg_replace('/\n{2,}/', '\n', $content); // Replace 2+ consecutive breaks with 1
        $formatted = nl2br($content);
        // Handle markdown image syntax
        $formatted = preg_replace(
            '/!\[(.*?)\]\(([^\)]+)\)/',
            '<img src="$2" style="max-width:100%; max-height:300px; display:block; margin:10px auto;">',
            $formatted
        );
        
        // Handle product headings (###) for better styling (without trailing breaks)
        $formatted = preg_replace(
            '/###\s+(\d+\.)?(\s*)([^<\n]+)\s*(?=<br|$)/',
            '<h3 class="product-heading">$0</h3>',
            $formatted
        );
        
        // Remove line breaks after headers
        $formatted = preg_replace('/<\/h3><br \/>/', '</h3>', $formatted);
        
        // Make URLs clickable (excluding image URLs that were already processed)
        $formatted = preg_replace_callback(
            '/(\[([^\]]+)\]\(([^\)]+)\))/',
            function ($matches) {
                // Skip if it's an image URL
                if (strpos($matches[0], '![') === 0) {
                    return $matches[0];
                }
                return '<a href="' . $matches[3] . '" target="_blank">' . $matches[2] . '</a>';
            },
            $formatted
        );
        
        // Bold text between ** **
        $formatted = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $formatted);
        
        // Make "TEXT:" patterns bold (e.g., "Configuration:", "Status:", etc.)
        $formatted = preg_replace('/\b([A-Z][A-Za-z\s]*):/', '<strong>$1:</strong>', $formatted);
        
        // Handle numbered lists
        $formatted = preg_replace('/(^|\n)(\d+\.)\s/', '$1<strong>$2</strong> ', $formatted);
        
        // Clean up excessive <br> tags in the final output
        $formatted = preg_replace('/<br \/>(<br \/>)+/', '<br />', $formatted);
        
        // Replace any literal \n characters with proper breaks
        $formatted = str_replace('\n', '<br />', $formatted);
        return $formatted;
    }
}
