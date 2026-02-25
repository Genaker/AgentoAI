<?php

namespace Genaker\MagentoMcpAi\Model;

use Genaker\MagentoMcpAi\Api\MenuAIAPIInterface;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Backend\Model\UrlInterface;
use Magento\Backend\Helper\Data as Helper;
use Magento\Framework\Data\Form\FormKey;

class MenuAIAPI implements MenuAIAPIInterface
{
    protected $aiService;
    protected $directoryList;
    protected $scopeConfig;
    protected $logger;
    protected $session;
    protected $request;
    protected $urlBuilder;
    protected $helper;
    protected $formKey;

    const XML_PATH_API_KEY = 'magentomcpai/llm/openai_api_key';
    const XML_PATH_MENU_FILTER_ENABLED = 'magentomcpai/menu/filter_enabled';
    const MENU_MD_FILE = 'menu.md';

    public function __construct(
        AIServiceInterface $aiService,
        DirectoryList $directoryList,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        SessionManagerInterface $session,
        RequestInterface $request,
        UrlInterface  $urlBuilder,
        Helper $helper,
        FormKey $formKey
    ) {
        $this->aiService = $aiService;
        $this->directoryList = $directoryList;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->session = $session;
        $this->request = $request;
        $this->urlBuilder = $urlBuilder;
        $this->helper = $helper;
        $this->formKey = $formKey;
    }

    public function sendRequestToChatGPT($query, $apiKey)
    {
        try {
            $storedApiKey = $this->scopeConfig->getValue(
                self::XML_PATH_API_KEY,
                ScopeInterface::SCOPE_STORE
            );

            if (empty($apiKey) || $apiKey !== $storedApiKey) {
                throw new LocalizedException(__('Invalid API key.'));
            }

            // Determine the path to menu.md
            $moduleDir = dirname(__DIR__); // This gets the current module directory
            $menuFilePath = $moduleDir . '/' . self::MENU_MD_FILE; // Adjust the path as needed

            if (!file_exists($menuFilePath)) {
                throw new LocalizedException(__('Menu file not found. Please check the file path: ' . $menuFilePath . ' or run the menu.py script to generate the ' . $moduleDir . '/menu.md file'));
            }

            $menuContent = file_get_contents($menuFilePath);
            
            // Apply filtering only when enabled in config (default: disabled = full menu)
            $filterEnabled = (bool) $this->scopeConfig->getValue(
                self::XML_PATH_MENU_FILTER_ENABLED,
                ScopeInterface::SCOPE_STORE
            );
            $filteredMenuContent = $filterEnabled
                ? $this->filterMenuContentByQuery($menuContent, $query)
                : $menuContent;
            
            // Build the system message with clear structure
            $systemMessage = "You are a Magento admin interface assistant. Your role is to help users navigate and manage the Magento admin panel efficiently.

IMPORTANT: You have access to relevant Magento admin menu structure and system configuration below. Use this information to answer user questions about navigating the admin panel, finding settings, and accessing features.

CRITICAL INSTRUCTIONS FOR URL RESPONSES:
1. ALWAYS include a clickable URL link when answering questions about finding admin pages, settings, or features.
2. The URL format MUST be: [[{base_url}/path/to/page]]
3. Extract the URL from the menu documentation below - look for the URL: field in the relevant menu item.
4. IMPORTANT: When multiple menu items match the query, ALWAYS prioritize:
   - Standard Magento routes (e.g., /sales/order for orders, /customer/index for customers)
   - Main pages over configuration/settings pages
   - Core Magento functionality over third-party modules (avoid m2epro, amazon, ebay unless specifically asked)
5. Example: If user asks about customer orders or orders, use: [[{base_url}/sales/order]] (NOT m2epro/amazon_order or other third-party order pages)
6. Example: If user asks about customers, use: [[{base_url}/customer/index/index]] (standard customer grid)
7. ALWAYS wrap URLs in double square brackets: [[{base_url}/path]]

When responding to queries, follow these guidelines:
1. Use the menu documentation provided below to find relevant admin pages and settings
2. MANDATORY: When answering where to find X or how to access X questions, you MUST include the URL from the menu documentation in the format [[{base_url}/path]]. This is not optional.
3. If a specific section of the page is relevant, append the section ID using a # like: [[{base_url}/path#section-id]]
4. If the query cannot be directly addressed with a URL from the menu, ask additional questions about the query to find the correct answer.
5. Always aim to enhance the user's understanding of the Magento admin functionalities.
6. If the query is unclear or outside the scope of Magento admin tasks, politely ask for clarification or suggest consulting the official Magento documentation.
7. Maintain a professional and helpful tone in all responses.
8. Important: Respond only to what was asked, no additional information unless you can provide more relevant information about the question.

=== MAGENTO ADMIN MENU DOCUMENTATION ===
" . $filteredMenuContent . "

=== END OF MENU DOCUMENTATION ===

Remember: Use the menu structure above to help users navigate the Magento admin panel.";

            // Add the current query to the message history
            $messageHistory = []; // Disabling history 
            $this->addToMessageHistory($query);

            // Prepare messages array for API with clear roles
            $messages = [
                ['role' => 'system', 'content' => $systemMessage]
            ];

            // Add the last 5 user messages to the messages array
            foreach ($this->getMessageHistory() as $userMessage) {
                $messages[] = ['role' => 'user', 'content' => $userMessage];
            }

            // Extract the last user message as the main message string
            $lastUserMessage = $query; // Use the current query as the message
            if (!empty($this->getMessageHistory())) {
                $history = $this->getMessageHistory();
                $lastUserMessage = end($history);
            }

            $temperature = 0.7;
            $maxTokens = 5000;

            // Use AIServiceInterface to make the API call
            // sendChatRequest(string $message, array $messages = [], int $maxTokens = 2000, float $temperature = 0.7, array $tools = [])
            $response = $this->aiService->sendChatRequest(
                $lastUserMessage,
                $messages,
                $maxTokens,
                $temperature
            );

            // Extract URL from response content (response structure: ['message' => ...])
            $content = $response['message'] ?? '';
            preg_match('/\[\[(.*?)\]\]/', $content, $matches);
            $url = $matches[1] ?? null;
            
            // Remove any key parameter from extracted URL (Magento will generate fresh key)
            if ($url) {
                $url = preg_replace('#/key/[^/\]]+#', '', $url);
                $url = preg_replace('#\?.*key=[^&\]]+#', '', $url);
            }
            
            // If AI didn't provide URL, try to extract it from menu.md based on query
            if (!$url) {
                $url = $this->extractUrlFromMenuContent($filteredMenuContent, $query);
                // Remove key parameter from extracted URL
                if ($url) {
                    $url = preg_replace('#/key/[^/\]]+#', '', $url);
                    $url = preg_replace('#\?.*key=[^&\]]+#', '', $url);
                }
                // If we found a URL, inject it into the response
                if ($url) {
                    $urlPattern = '[[' . $url . ']]';
                    // Add URL to the end of the response if not already present
                    if (strpos($content, $urlPattern) === false) {
                        $content .= "\n\nYou can access this page directly: " . $urlPattern;
                    }
                }
            }

            // Transform {base_url} patterns to actual admin URLs in content using helper method
            $cleanContent = preg_replace_callback(
                '/\[\[{base_url}(\/.*?)\]\]/',
                function($matches) use ($filteredMenuContent) {
                    $path = $matches[1];
                    $generatedUrl = $this->generateAdminUrl($path);
                    $readableText = $this->generateReadableUrlText($path, $filteredMenuContent);
                    return '<a href="' . $generatedUrl . '" target="_blank" style="color: #3498db; text-decoration: none; font-weight: bold;">🔗 ' . $readableText . '</a>';
                },
                $content
            );
            $hash = null;
        
            // Process extracted URL for return value
            if ($url) {
                $hash = explode('#', $url)[1] ?? null;
                if($hash){
                    $url = explode('#', $url)[0];
                }
                // Generate proper admin URL from extracted URL
                $url = $this->generateAdminUrl($url);
                if ($hash) {
                    $url .= '#' . $hash;
                }
            }

            // Return as an associative array
            return [
                'message' => trim($cleanContent),
                'url' => $url,
                'hash' => $hash
            ];
        } catch (LocalizedException $e) {
            $this->logger->error('LocalizedException: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Exception: ' . $e->getMessage());
            throw new LocalizedException(__('An unexpected error occurred: ' . $e->getMessage()));
        }
    }

    private function addToMessageHistory($message)
    {
        // Generate a unique key for the current admin page
        $pageKey = $this->getPageKey();

        // Retrieve the current message history from the session
        $messageHistory = $this->session->getData($pageKey) ?? [];

        // Create structured message with metadata
        $structuredMessage = [
            'content' => $message,
            'timestamp' => time(),
            'type' => 'user_query',
            'page' => $this->request->getFullActionName()
        ];

        // Add the new message to the history
        $messageHistory[] = $structuredMessage;

        // Keep only the last 3 messages
        if (count($messageHistory) > 3) {
            array_shift($messageHistory);
        }

        // Save the updated message history back to the session
        $this->session->setData($pageKey, $messageHistory);
    }

    private function getMessageHistory()
    {
        // Generate a unique key for the current admin page
        $pageKey = $this->getPageKey();

        // Retrieve the message history from the session
        $rawHistory = $this->session->getData($pageKey) ?? [];
        
        // Convert structured messages to AI-friendly format
        $formattedHistory = [];
        foreach ($rawHistory as $index => $messageData) {
            // Handle both old string format and new structured format
            if (is_string($messageData)) {
                $content = $messageData;
                $type = 'legacy';
            } else {
                $content = $messageData['content'] ?? '';
                $type = $messageData['type'] ?? 'unknown';
                $timestamp = $messageData['timestamp'] ?? 0;
                $page = $messageData['page'] ?? 'unknown';
            }
            
            // Add context labeling for older messages (not the current one)
            $totalMessages = count($rawHistory);
            if ($index < $totalMessages - 1) {
                // This is context, not the current question
                $ageInMessages = $totalMessages - $index - 1;
                $contextLabel = "Previous context ({$ageInMessages} message" . ($ageInMessages > 1 ? 's' : '') . " ago)";
                $formattedHistory[] = $contextLabel . ': ' . $content;
            } else {
                // This is the current question
                $formattedHistory[] = $content;
            }
        }
        
        return $formattedHistory;
    }

    private function getPageKey()
    {
        // Use the current request path as a unique key for the admin page
        return 'message_history_' . md5($this->request->getFullActionName());
    }

    /**
     * Clear message history for current page (optional utility method)
     */
    public function clearMessageHistory()
    {
        $pageKey = $this->getPageKey();
        $this->session->unsetData($pageKey);
        return true;
    }

    /**
     * Get debug information about message history (optional utility method)
     */
    public function getHistoryDebugInfo()
    {
        $pageKey = $this->getPageKey();
        $rawHistory = $this->session->getData($pageKey) ?? [];
        
        return [
            'page_key' => $pageKey,
            'message_count' => count($rawHistory),
            'raw_messages' => $rawHistory,
            'formatted_messages' => $this->getMessageHistory()
        ];
    }

    /**
     * Filter menu content to include only relevant sections based on query
     * Returns all menu items that contain any of the query keywords
     * AI will decide which one to use
     *
     * @param string $menuContent Full menu.md content
     * @param string $query User query
     * @return string Filtered menu content
     */
    private function filterMenuContentByQuery(string $menuContent, string $query): string
    {
        // Maximum content size to prevent context window issues (approximately 50KB)
        $maxContentSize = 50000;
        
        // If content is already small enough, return as-is
        if (strlen($menuContent) <= $maxContentSize) {
            return $menuContent;
        }
        
        // Extract keywords from query (remove common words)
        $stopWords = ['where', 'to', 'find', 'can', 'i', 'how', 'do', 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'shall', 'can', 'about', 'with', 'for', 'from', 'in', 'on', 'at', 'by', 'of', 'to', 'as', 'into', 'through', 'during', 'including', 'against', 'among', 'throughout', 'despite', 'towards', 'upon', 'concerning', 'to', 'of', 'in', 'for', 'on', 'at', 'by', 'about', 'like', 'through', 'over', 'before', 'between', 'after', 'since', 'without', 'under', 'within', 'along', 'following', 'across', 'behind', 'beyond', 'plus', 'except', 'but', 'up', 'out', 'off', 'down', 'above', 'near'];
        $queryLower = strtolower($query);
        $words = preg_split('/\s+/', $queryLower);
        $keywords = array_filter($words, function($word) use ($stopWords) {
            $word = trim($word, '.,!?;:()[]{}"\'-');
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        
        // If no keywords found, return truncated content
        if (empty($keywords)) {
            return substr($menuContent, 0, $maxContentSize) . "\n\n[Note: Menu content truncated due to size. Please be more specific in your query for better results.]";
        }
        
        // Split menu content into lines
        $lines = explode("\n", $menuContent);
        $relevantLines = [];
        $currentMenuItem = [];
        $inMenuItem = false;
        
        // Find all menu items that contain any keyword
        foreach ($lines as $i => $line) {
            $lineLower = strtolower($line);
            
            // Check if this is a menu item line
            if (preg_match('/^-\s*\[(.+)\]/', $line, $matches)) {
                // If we were tracking a menu item, check if it matched keywords
                if ($inMenuItem && !empty($currentMenuItem)) {
                    $menuItemText = strtolower(implode(' ', $currentMenuItem));
                    $matchesKeyword = false;
                    foreach ($keywords as $keyword) {
                        if (strpos($menuItemText, $keyword) !== false) {
                            $matchesKeyword = true;
                            break;
                        }
                    }
                    if ($matchesKeyword) {
                        $relevantLines = array_merge($relevantLines, $currentMenuItem);
                    }
                }
                // Start tracking new menu item
                $currentMenuItem = [$line];
                $inMenuItem = true;
            } elseif ($inMenuItem) {
                // Add line to current menu item (include description, URL, etc.)
                $currentMenuItem[] = $line;
            } else {
                // Check if line contains any keyword (standalone lines)
                foreach ($keywords as $keyword) {
                    if (strpos($lineLower, $keyword) !== false) {
                        $relevantLines[] = $line;
                        break;
                    }
                }
            }
        }
        
        // Check last menu item if it was being tracked
        if ($inMenuItem && !empty($currentMenuItem)) {
            $menuItemText = strtolower(implode(' ', $currentMenuItem));
            $matchesKeyword = false;
            foreach ($keywords as $keyword) {
                if (strpos($menuItemText, $keyword) !== false) {
                    $matchesKeyword = true;
                    break;
                }
            }
            if ($matchesKeyword) {
                $relevantLines = array_merge($relevantLines, $currentMenuItem);
            }
        }
        
        // If we found relevant items, return them
        if (!empty($relevantLines)) {
            $filteredContent = implode("\n", $relevantLines);
            
            // If still too large, truncate
            if (strlen($filteredContent) > $maxContentSize) {
                $filteredContent = substr($filteredContent, 0, $maxContentSize);
                $filteredContent .= "\n\n[Note: Menu content filtered and truncated. Showing all matching items based on your query.]";
            }
            
            return $filteredContent;
        }
        
        // Fallback: return beginning of menu
        $truncated = substr($menuContent, 0, $maxContentSize);
        return $truncated . "\n\n[Note: Menu content truncated due to size. Please be more specific in your query for better results.]";
    }

    /**
     * Extract URL from menu content based on query keywords
     * Fallback method when AI doesn't provide URL in response
     * Returns first matching URL found (no scoring, AI should have chosen)
     *
     * @param string $menuContent Filtered menu content
     * @param string $query User query
     * @return string|null Extracted URL or null
     */
    private function extractUrlFromMenuContent(string $menuContent, string $query): ?string
    {
        // Extract keywords from query
        $stopWords = ['where', 'to', 'find', 'can', 'i', 'how', 'do', 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'shall', 'can', 'about', 'with', 'for', 'from', 'in', 'on', 'at', 'by', 'of', 'to', 'as', 'into', 'through', 'during', 'including', 'against', 'among', 'throughout', 'despite', 'towards', 'upon', 'concerning', 'to', 'of', 'in', 'for', 'on', 'at', 'by', 'about', 'like', 'through', 'over', 'before', 'between', 'after', 'since', 'without', 'under', 'within', 'along', 'following', 'across', 'behind', 'beyond', 'plus', 'except', 'but', 'up', 'out', 'off', 'down', 'above', 'near'];
        $queryLower = strtolower($query);
        $words = preg_split('/\s+/', $queryLower);
        $keywords = array_filter($words, function($word) use ($stopWords) {
            $word = trim($word, '.,!?;:()[]{}"\'-');
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        
        if (empty($keywords)) {
            return null;
        }
        
        // Split menu content into lines
        $lines = explode("\n", $menuContent);
        
        // Look for menu items that contain keywords and extract their URLs
        foreach ($lines as $i => $line) {
            $lineLower = strtolower($line);
            
            // Check if this line contains a menu item
            if (preg_match('/^-\s*\[(.+)\]/', $line, $menuMatches)) {
                $menuItem = strtolower($menuMatches[1]);
                
                // Check if menu item contains any keyword
                $matchesKeyword = false;
                foreach ($keywords as $keyword) {
                    if (strpos($menuItem, $keyword) !== false || strpos($lineLower, $keyword) !== false) {
                        $matchesKeyword = true;
                        break;
                    }
                }
                
                // If matches, look for URL in next few lines
                if ($matchesKeyword) {
                    for ($j = $i + 1; $j < min($i + 6, count($lines)); $j++) {
                        if (preg_match('/URL:\s*({base_url}[^\s]+)/', $lines[$j], $urlMatches)) {
                            return $urlMatches[1]; // Return first match found
                        }
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Generate proper Magento admin URL from path
     * Handles both adminhtml-prefixed and non-prefixed paths
     *
     * @param string $path Path from menu.md (e.g., "/sales/archive/orders" or "/adminhtml/system_config/edit/section/general")
     * @return string Generated admin URL
     */
    private function generateAdminUrl(string $path): string
    {
        // Remove {base_url} prefix if present
        $path = str_replace('{base_url}', '', $path);
        $path = trim($path, '/');
        
        // Remove any /admin/ prefix from path (will be added by urlBuilder)
        $path = preg_replace('#^admin/#', '', $path);
        
        // Remove any existing key parameter from path (Magento will generate fresh key)
        // Pattern: /key/xxxxx or key/xxxxx
        $path = preg_replace('#/key/[^/]+#', '', $path);
        $path = preg_replace('#^key/[^/]+/#', '', $path);
        
        // Extract hash fragment if present
        $hash = null;
        if (strpos($path, '#') !== false) {
            $parts = explode('#', $path, 2);
            $path = $parts[0];
            $hash = $parts[1];
        }
        
        // Split path into parts
        $pathParts = explode('/', $path);
        $pathParts = array_filter($pathParts, function($part) {
            return !empty($part) && $part !== 'key'; // Also filter out standalone 'key' parts
        });
        $pathParts = array_values($pathParts);
        
        $route = null;
        $params = [];
        
        // Handle adminhtml-prefixed paths specially
        // Paths like /adminhtml/system_config/edit/section/general should become route 'system_config/edit'
        if (isset($pathParts[0]) && $pathParts[0] === 'adminhtml') {
            array_shift($pathParts); // Remove 'adminhtml' prefix
        }
        
        // Extract route and parameters directly from path (use menu.md URLs as-is)
        if (count($pathParts) >= 3) {
            // Check if this is a system_config route with section parameter
            // Paths like /system_config/edit/section/general should be route 'system_config/edit' with param 'section' => 'general'
            // Also handle /adminhtml/system_config/edit/section/general (adminhtml already removed above)
            if ($pathParts[0] === 'system_config' && $pathParts[1] === 'edit' && isset($pathParts[2]) && $pathParts[2] === 'section') {
                // Route is system_config/edit/index (Magento UrlInterface expects full route format)
                $route = $pathParts[0] . '/' . $pathParts[1] . '/index';
                // Extract section parameter
                if (isset($pathParts[3])) {
                    $params['section'] = $pathParts[3];
                }
                // Extract any additional parameters (key/value pairs after section)
                for ($i = 4; $i < count($pathParts); $i += 2) {
                    if (isset($pathParts[$i + 1])) {
                        $paramKey = $pathParts[$i];
                        if ($paramKey !== 'key') {
                            $params[$paramKey] = $pathParts[$i + 1];
                        }
                    }
                }
            } else {
                // Standard module/controller/action format
                $route = implode('/', array_slice($pathParts, 0, 3));
                // Extract remaining parts as parameters (key/value pairs)
                // Skip 'key' parameter - Magento generates security keys automatically
                for ($i = 3; $i < count($pathParts); $i += 2) {
                    if (isset($pathParts[$i + 1])) {
                        $paramKey = $pathParts[$i];
                        if ($paramKey !== 'key') {
                            $params[$paramKey] = $pathParts[$i + 1];
                        }
                    }
                }
            }
        } elseif (count($pathParts) === 2) {
            // For paths like /customer/index/, use route 'customer/index/index'
            // Magento UrlInterface expects full route format: module/controller/action
            $route = $pathParts[0] . '/' . $pathParts[1] . '/index';
        } elseif (count($pathParts) === 1) {
            // Single part: module/index/index
            $route = $pathParts[0] . '/index/index';
        } else {
            // Fallback
            $route = 'index/index/index';
        }
        
        // Backend UrlBuilder expects routes WITHOUT adminhtml prefix
        // It automatically handles adminhtml area and adds /admin/ prefix
        // Example: 'customer/index/index' not 'adminhtml/customer/index/index'
        // (We already removed adminhtml from pathParts above, but double-check)
        if (str_starts_with($route, 'adminhtml/')) {
            $route = substr($route, strlen('adminhtml/'));
        }
        
        // Generate URL using Backend UrlBuilder (for admin area)
        // The urlBuilder automatically handles admin prefix and security key
        try {
            // Ensure secret key is enabled (forces fresh key generation) if method exists
            if (method_exists($this->urlBuilder, 'setUseSecretKey')) {
                $this->urlBuilder->setUseSecretKey(true);
            }
            
            // Generate URL - Magento will automatically add a fresh security key
            // Make sure params array doesn't contain 'key' to avoid conflicts
            unset($params['key']);
            
            $generatedUrl = $this->urlBuilder->getUrl($route, $params);
            
            // Clean up any duplicate /admin/ prefixes (shouldn't happen, but just in case)
            $generatedUrl = preg_replace('#(/admin)+/admin/#', '/admin/', $generatedUrl);
            
            // Decode URL-encoded hash fragments
            $generatedUrl = str_replace('%23', '#', $generatedUrl);
            
            // Add hash fragment if present
            if ($hash) {
                $generatedUrl .= '#' . $hash;
            }
            
            return $generatedUrl;
        } catch (\Exception $e) {
            $this->logger->error('Error generating admin URL: ' . $e->getMessage() . ' for path: ' . $path . ' route: ' . $route);
            
            // Fallback: use helper if urlBuilder fails
            try {
                $generatedUrl = $this->helper->getUrl($route, $params);
                if ($generatedUrl) {
                    // Remove any stale keys
                    $generatedUrl = preg_replace('#/key/[^/]+#', '', $generatedUrl ?? '');
                    $generatedUrl = preg_replace('#\?.*key=[^&]+#', '', $generatedUrl ?? '');
                    // Regenerate with helper
                    $generatedUrl = $this->helper->getUrl($route, $params);
                    if ($hash && $generatedUrl) {
                        $generatedUrl .= '#' . $hash;
                    }
                    if ($generatedUrl) {
                        return $generatedUrl;
                    }
                }
            } catch (\Exception $e2) {
                // Continue to manual construction
            }
            
            // Last resort: construct manually
            $baseUrl = $this->helper->getHomePageUrl();
            $fallbackUrl = $baseUrl ? rtrim($baseUrl, '/') . '/admin/' . ltrim($path, '/') : '/admin/' . ltrim($path, '/');
            if ($hash) {
                $fallbackUrl .= '#' . $hash;
            }
            return $fallbackUrl;
        }
    }

    /**
     * Generate human-readable text for URL link
     * Converts paths like "/sales/archive/orders" to "Admin > Sales > Orders"
     *
     * @param string $path URL path from menu.md
     * @param string $menuContent Menu content to extract actual menu item names
     * @return string Human-readable breadcrumb text
     */
    private function generateReadableUrlText(string $path, string $menuContent = ''): string
    {
        // Remove {base_url} prefix if present
        $path = str_replace('{base_url}', '', $path);
        $path = trim($path, '/');
        
        // Remove hash fragment if present
        if (strpos($path, '#') !== false) {
            $path = explode('#', $path)[0];
        }
        
        // Split path into parts
        $parts = explode('/', $path);
        $parts = array_filter($parts, function($part) {
            return !empty($part);
        });
        $parts = array_values($parts);
        
        // Remove 'adminhtml' if present (it's implied)
        if (isset($parts[0]) && $parts[0] === 'adminhtml') {
            array_shift($parts);
        }
        
        // Mapping for common module/controller names to readable labels
        $labelMapping = [
            'sales' => 'Sales',
            'order' => 'Orders',
            'orders' => 'Orders',
            'archive' => 'Archive',
            'invoice' => 'Invoices',
            'invoices' => 'Invoices',
            'shipment' => 'Shipments',
            'shipments' => 'Shipments',
            'creditmemo' => 'Credit Memos',
            'creditmemos' => 'Credit Memos',
            'customer' => 'Customers',
            'customers' => 'Customers',
            'group' => 'Customer Groups',
            'online' => 'Now Online',
            'catalog' => 'Catalog',
            'product' => 'Products',
            'products' => 'Products',
            'category' => 'Categories',
            'categories' => 'Categories',
            'system_config' => 'Configuration',
            'config' => 'Configuration',
            'system' => 'System',
            'adminhtml' => 'Admin',
            'index' => '', // Skip index actions
            'edit' => 'Edit',
            'view' => 'View',
            'new' => 'New',
            'tax' => 'Tax',
            'rate' => 'Rates',
            'importExport' => 'Import/Export',
            'url_rewrite' => 'URL Rewrites',
            'bulk' => 'Bulk Actions',
            'scheduled_operation' => 'Scheduled Import/Export',
            'notification' => 'Notifications',
            'import' => 'Import',
            'export' => 'Export',
            'history' => 'Import History',
            'events' => 'Events',
            'eventstatus' => 'Events Status',
            'reports' => 'Reports',
            'report' => 'Reports',
        ];
        
        // Build readable breadcrumb from path parts
        $breadcrumb = ['Admin'];
        
        foreach ($parts as $part) {
            $partLower = strtolower($part);
            
            // Skip empty parts and 'index' actions
            if (empty($part) || $partLower === 'index') {
                continue;
            }
            
            // Use mapping if available, otherwise capitalize and format
            if (isset($labelMapping[$partLower])) {
                $label = $labelMapping[$partLower];
                // Don't add empty labels
                if (!empty($label)) {
                    $breadcrumb[] = $label;
                }
            } else {
                // Capitalize first letter and replace underscores/hyphens with spaces
                $label = ucfirst(str_replace(['_', '-'], ' ', $part));
                $breadcrumb[] = $label;
            }
        }
        
        // Remove duplicates (can happen with mappings)
        $breadcrumb = array_unique($breadcrumb);
        $breadcrumb = array_values($breadcrumb);
        
        // Join with " > "
        return implode(' > ', $breadcrumb);
    }
}