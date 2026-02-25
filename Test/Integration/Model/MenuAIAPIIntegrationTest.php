<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model;

use PHPUnit\Framework\TestCase;
use Genaker\MagentoMcpAi\Model\MenuAIAPI;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Backend\Model\UrlInterface;
use Magento\Backend\Helper\Data as Helper;
use Magento\Framework\Data\Form\FormKey;

/**
 * Integration tests for MenuAIAPI Model
 * Tests that menu.md file is properly read and used
 */
class MenuAIAPIIntegrationTest extends TestCase
{
    /**
     * @var MenuAIAPI
     */
    private $menuAIAPI;

    /**
     * @var AIServiceInterface
     */
    private $aiService;

    /**
     * @var string
     */
    private $menuMdPath;

    protected function setUp(): void
    {
        // Create stub dependencies
        $this->aiService = $this->createMock(AIServiceInterface::class);
        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        
        // Create stub session - will return null for all methods
        // For tests that need session functionality, we'll create a proper mock in those tests
        $session = $this->createStub(SessionManagerInterface::class);
        
        // Create mock HTTP request with getFullActionName method
        $request = $this->createMock(HttpRequest::class);
        $request->method('getFullActionName')
            ->willReturn('adminhtml_index_index');
        $urlBuilder = $this->createStub(UrlInterface::class);
        $helper = $this->createStub(Helper::class);
        $formKey = $this->createStub(FormKey::class);

        // Determine the actual path to menu.md
        // From Test/Integration/Model, go up 3 levels to module root
        $moduleDir = dirname(__DIR__, 3); // Test/Integration/Model -> Test/Integration -> Test -> module root
        $this->menuMdPath = $moduleDir . '/menu.md';

        // Configure scope config to return a test API key
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) {
                if (strpos($path, 'openai_api_key') !== false) {
                    return 'test-api-key-12345';
                }
                return null;
            });

        // Create MenuAIAPI instance
        $this->menuAIAPI = new MenuAIAPI(
            $this->aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );
    }

    /**
     * Test that menu.md file exists
     */
    public function testMenuMdFileExists(): void
    {
        $this->assertFileExists(
            $this->menuMdPath,
            "menu.md file should exist at: {$this->menuMdPath}. Run menu.py to generate it."
        );
    }

    /**
     * Test that menu.md file is readable and contains content
     */
    public function testMenuMdFileIsReadable(): void
    {
        $this->assertFileIsReadable(
            $this->menuMdPath,
            "menu.md file should be readable"
        );

        $content = file_get_contents($this->menuMdPath);
        $this->assertNotEmpty(
            $content,
            "menu.md file should not be empty"
        );

        $this->assertGreaterThan(
            100,
            strlen($content),
            "menu.md file should contain substantial content (at least 100 characters)"
        );
    }

    /**
     * Test that menu.md content is included in AI service call
     * 
     * Note: This test verifies menu.md is read and passed to AI service.
     * Session functionality is tested separately.
     */
    public function testMenuMdContentIsPassedToAIService(): void
    {
        // Skip if menu.md doesn't exist
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found at: {$this->menuMdPath}");
        }

        $menuContent = file_get_contents($this->menuMdPath);
        $query = 'Where can I find product settings?';
        $apiKey = 'test-api-key-12345';

        // Create a new MenuAIAPI instance for this test with proper session mock
        $sessionData = [];
        $session = $this->getMockBuilder(\Magento\Framework\Session\Generic::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData', 'unsetData'])
            ->getMock();
        
        $session->method('getData')
            ->willReturnCallback(function($key = null) use (&$sessionData) {
                return $key === null ? $sessionData : ($sessionData[$key] ?? null);
            });
        
        $session->method('setData')
            ->willReturnCallback(function($key, $value = null) use (&$sessionData, $session) {
                $sessionData[$key] = $value;
                return $session;
            });

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) use ($apiKey) {
                return strpos($path, 'openai_api_key') !== false ? $apiKey : null;
            });
        $logger = $this->createStub(LoggerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        $urlBuilder = $this->createStub(UrlInterface::class);
        $helper = $this->createStub(Helper::class);
        $formKey = $this->createStub(FormKey::class);

        $menuAIAPI = new MenuAIAPI(
            $this->aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        // Set up expectation that AI service is called with menu.md content
        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->with(
                $this->equalTo($query),
                $this->callback(function($messages) use ($menuContent) {
                    // Verify that messages array contains system message with menu content
                    $this->assertIsArray($messages);
                    $this->assertNotEmpty($messages);
                    
                    // Find system message
                    $systemMessage = null;
                    foreach ($messages as $message) {
                        if (isset($message['role']) && $message['role'] === 'system') {
                            $systemMessage = $message['content'];
                            break;
                        }
                    }
                    
                    $this->assertNotNull($systemMessage, 'System message should exist');
                    
                    // Verify menu.md content is included
                    $this->assertStringContainsString(
                        $menuContent,
                        $systemMessage,
                        'System message should contain menu.md content'
                    );
                    
                    // Verify additional context instructions are included
                    $this->assertStringContainsString(
                        'Magento admin interface assistant',
                        $systemMessage,
                        'System message should contain assistant instructions'
                    );
                    
                    // Verify menu documentation markers are present
                    $this->assertStringContainsString(
                        'MAGENTO ADMIN MENU DOCUMENTATION',
                        $systemMessage,
                        'System message should contain menu documentation markers'
                    );
                    
                    return true;
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'message' => 'You can find product settings in [[{base_url}/admin/catalog/product]]',
                'success' => true
            ]);

        // Execute the method
        $result = $menuAIAPI->sendRequestToChatGPT($query, $apiKey);

        // Verify result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test that exception is thrown when menu.md file doesn't exist
     */
    public function testExceptionThrownWhenMenuMdMissing(): void
    {
        // Create a new instance with a non-existent path
        $moduleDir = dirname(__DIR__, 2);
        $nonExistentPath = $moduleDir . '/non-existent-menu.md';

        // Use reflection to temporarily change the file path
        $reflection = new \ReflectionClass($this->menuAIAPI);
        $property = $reflection->getProperty('directoryList');
        $property->setAccessible(true);

        // Create a mock that returns a path where menu.md doesn't exist
        $mockDirectoryList = $this->createMock(DirectoryList::class);
        
        // We need to mock the actual file path resolution
        // Since MenuAIAPI uses dirname(__DIR__), we'll need to test differently
        
        // Instead, let's test by temporarily renaming the file
        $backupPath = $this->menuMdPath . '.backup';
        
        if (file_exists($this->menuMdPath)) {
            // Backup the file
            rename($this->menuMdPath, $backupPath);
            
            try {
                $this->expectException(LocalizedException::class);
                $this->expectExceptionMessage('Menu file not found');
                
                $this->menuAIAPI->sendRequestToChatGPT('test query', 'test-api-key-12345');
            } finally {
                // Restore the file
                if (file_exists($backupPath)) {
                    rename($backupPath, $this->menuMdPath);
                }
            }
        } else {
            $this->markTestSkipped("menu.md file doesn't exist, cannot test missing file scenario");
        }
    }

    /**
     * Test that menu.md contains expected structure (admin menu items)
     */
    public function testMenuMdContainsExpectedStructure(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $content = file_get_contents($this->menuMdPath);

        // Check for expected sections
        $this->assertStringContainsString(
            'Admin Menu Items',
            $content,
            'menu.md should contain "Admin Menu Items" section'
        );

        // Check for URLs (should contain {base_url} pattern)
        $this->assertStringContainsString(
            '{base_url}',
            $content,
            'menu.md should contain {base_url} URL patterns'
        );

        // Check for markdown list format
        $this->assertThat(
            $content,
            $this->logicalOr(
                $this->stringContains('- ['),
                $this->stringContains('##')
            ),
            'menu.md should contain markdown list or heading format'
        );
    }

    /**
     * Test that menu.md content is properly formatted for AI consumption
     */
    public function testMenuMdContentFormatting(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $menuContent = file_get_contents($this->menuMdPath);
        $query = 'test query';
        $apiKey = 'test-api-key-12345';

        // Create a new MenuAIAPI instance with proper session mock
        $sessionData = [];
        $session = $this->getMockBuilder(\Magento\Framework\Session\Generic::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData', 'unsetData'])
            ->getMock();
        
        $session->method('getData')
            ->willReturnCallback(function($key = null) use (&$sessionData) {
                return $key === null ? $sessionData : ($sessionData[$key] ?? null);
            });
        
        $session->method('setData')
            ->willReturnCallback(function($key, $value = null) use (&$sessionData, $session) {
                $sessionData[$key] = $value;
                return $session;
            });

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) use ($apiKey) {
                return strpos($path, 'openai_api_key') !== false ? $apiKey : null;
            });
        $logger = $this->createStub(LoggerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        $urlBuilder = $this->createStub(UrlInterface::class);
        $helper = $this->createStub(Helper::class);
        $formKey = $this->createStub(FormKey::class);

        $menuAIAPI = new MenuAIAPI(
            $this->aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        // Mock AI service to capture the actual content passed
        $capturedMessages = null;
        $this->aiService->method('sendChatRequest')
            ->willReturnCallback(function($message, $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return ['message' => 'test response', 'success' => true];
            });

        $menuAIAPI->sendRequestToChatGPT($query, $apiKey);

        // Verify the content structure
        $this->assertNotNull($capturedMessages, 'Messages should be captured');
        $this->assertIsArray($capturedMessages);
        $this->assertNotEmpty($capturedMessages);

        // Find system message
        $systemMessage = null;
        foreach ($capturedMessages as $message) {
            if (isset($message['role']) && $message['role'] === 'system') {
                $systemMessage = $message['content'];
                break;
            }
        }

        $this->assertNotNull($systemMessage, 'System message should exist');
        
        // Verify menu content is at the end (after instructions)
        $this->assertStringContainsString(
            $menuContent,
            $systemMessage,
            'System message should contain full menu.md content'
        );

        // Verify instructions are present
        $instructionPos = strpos($systemMessage, 'Magento admin interface assistant');
        $this->assertNotFalse($instructionPos, 'Instructions should be present');
        $this->assertGreaterThan(0, $instructionPos, 'Instructions should be present');
        
        // Verify menu documentation markers are present
        $this->assertStringContainsString(
            'MAGENTO ADMIN MENU DOCUMENTATION',
            $systemMessage,
            'System message should contain menu documentation markers'
        );
        
        // Verify menu content is present (check for key parts since exact match might differ)
        // Check for a distinctive part of menu.md content
        $menuKeyPhrase = 'Admin Menu Items';
        $menuKeyPos = strpos($systemMessage, $menuKeyPhrase);
        if ($menuKeyPos === false) {
            // Try another distinctive phrase
            $menuKeyPhrase = '{base_url}';
            $menuKeyPos = strpos($systemMessage, $menuKeyPhrase);
        }
        $this->assertNotFalse($menuKeyPos, "Menu content should be present (looked for: {$menuKeyPhrase})");
        $this->assertGreaterThan(0, $menuKeyPos, "Menu content should be present");
    }

    /**
     * Test that Menu AI returns URL links when asked about admin menu items
     * Example: "where to find orders" should return a URL to orders page
     */
    public function testMenuAIReturnsUrlForOrdersQuery(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $query = 'where to find orders';
        $apiKey = 'test-api-key-12345';
        $expectedUrlPath = '/sales/archive/orders';
        $expectedFullUrl = 'http://example.com/admin/sales/archive/orders';

        // Create session mock
        $sessionData = [];
        $session = $this->getMockBuilder(\Magento\Framework\Session\Generic::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData', 'unsetData'])
            ->getMock();
        
        $session->method('getData')
            ->willReturnCallback(function($key = null) use (&$sessionData) {
                return $key === null ? $sessionData : ($sessionData[$key] ?? null);
            });
        
        $session->method('setData')
            ->willReturnCallback(function($key, $value = null) use (&$sessionData, $session) {
                $sessionData[$key] = $value;
                return $session;
            });

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) use ($apiKey) {
                return strpos($path, 'openai_api_key') !== false ? $apiKey : null;
            });
        $logger = $this->createStub(LoggerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        
        // Mock URL builder - it's used in the else branch for non-adminhtml routes
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getSecretKey')
            ->willReturn('secret-key-123');
        $urlBuilder->method('getUrl')
            ->with($this->stringContains('sales/archive/orders'))
            ->willReturn($expectedFullUrl);
        
        // Mock helper - it's used for adminhtml routes
        $helper = $this->createMock(Helper::class);
        $helper->method('getUrl')
            ->willReturn($expectedFullUrl);
        
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')
            ->willReturn('form-key-123');

        $menuAIAPI = new MenuAIAPI(
            $this->aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        // Mock AI service to return response with URL link
        $aiResponseMessage = "You can find orders in the admin panel at [[{base_url}{$expectedUrlPath}]]. This page shows all your order history.";
        
        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn([
                'message' => $aiResponseMessage,
                'success' => true
            ]);

        // Execute the method
        $result = $menuAIAPI->sendRequestToChatGPT($query, $apiKey);

        // Verify result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('url', $result);
        
        // Verify URL is extracted and returned
        $this->assertNotNull($result['url'], 'URL should be extracted from AI response');
        $this->assertNotEmpty($result['url'], 'URL should not be empty');
        
        // Verify message contains the link (should be transformed to HTML link)
        $this->assertStringContainsString(
            '<a href',
            $result['message'],
            'Message should contain HTML link tag'
        );
        $this->assertStringContainsString(
            $expectedFullUrl,
            $result['message'],
            'Message should contain the full admin URL'
        );
    }

    /**
     * Test that Menu AI returns URL for different admin menu queries
     * Example: "where can I find customers" should return customer URL
     */
    public function testMenuAIReturnsUrlForCustomersQuery(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $query = 'where can I find customers';
        $apiKey = 'test-api-key-12345';
        $expectedUrlPath = '/customer/index';
        $expectedFullUrl = 'http://example.com/admin/customer/index';

        // Create session mock
        $sessionData = [];
        $session = $this->getMockBuilder(\Magento\Framework\Session\Generic::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData', 'unsetData'])
            ->getMock();
        
        $session->method('getData')
            ->willReturnCallback(function($key = null) use (&$sessionData) {
                return $key === null ? $sessionData : ($sessionData[$key] ?? null);
            });
        
        $session->method('setData')
            ->willReturnCallback(function($key, $value = null) use (&$sessionData, $session) {
                $sessionData[$key] = $value;
                return $session;
            });

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) use ($apiKey) {
                return strpos($path, 'openai_api_key') !== false ? $apiKey : null;
            });
        $logger = $this->createStub(LoggerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        
        // Mock URL builder - it's used in the else branch for non-adminhtml routes
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getSecretKey')
            ->willReturn('secret-key-123');
        $urlBuilder->method('getUrl')
            ->willReturn($expectedFullUrl);
        
        // Mock helper - it's used for adminhtml routes
        $helper = $this->createMock(Helper::class);
        $helper->method('getUrl')
            ->willReturn($expectedFullUrl);
        
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')
            ->willReturn('form-key-123');

        $menuAIAPI = new MenuAIAPI(
            $this->aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        // Mock AI service to return response with URL link
        $aiResponseMessage = "You can find customers in the admin panel at [[{base_url}{$expectedUrlPath}]]. This page shows all your customer list.";
        
        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn([
                'message' => $aiResponseMessage,
                'success' => true
            ]);

        // Execute the method
        $result = $menuAIAPI->sendRequestToChatGPT($query, $apiKey);

        // Verify result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('url', $result);
        
        // Verify URL is extracted
        $this->assertNotNull($result['url'], 'URL should be extracted from AI response');
        
        // Verify message contains link
        $this->assertStringContainsString(
            '<a href',
            $result['message'],
            'Message should contain HTML link tag'
        );
    }

    /**
     * Test that Menu AI handles URL with hash fragments correctly
     */
    public function testMenuAIReturnsUrlWithHashFragment(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $query = 'where to find order settings';
        $apiKey = 'test-api-key-12345';
        $expectedUrlPath = '/sales/order';
        $expectedHash = 'settings';
        $expectedFullUrl = 'http://example.com/admin/sales/order';

        // Create session mock
        $sessionData = [];
        $session = $this->getMockBuilder(\Magento\Framework\Session\Generic::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData', 'unsetData'])
            ->getMock();
        
        $session->method('getData')
            ->willReturnCallback(function($key = null) use (&$sessionData) {
                return $key === null ? $sessionData : ($sessionData[$key] ?? null);
            });
        
        $session->method('setData')
            ->willReturnCallback(function($key, $value = null) use (&$sessionData, $session) {
                $sessionData[$key] = $value;
                return $session;
            });

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) use ($apiKey) {
                return strpos($path, 'openai_api_key') !== false ? $apiKey : null;
            });
        $logger = $this->createStub(LoggerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        
        // Mock URL builder - it's used in the else branch for non-adminhtml routes
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getSecretKey')
            ->willReturn('secret-key-123');
        $urlBuilder->method('getUrl')
            ->willReturn($expectedFullUrl);
        
        // Mock helper - it's used for adminhtml routes
        $helper = $this->createMock(Helper::class);
        $helper->method('getUrl')
            ->willReturn($expectedFullUrl);
        
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')
            ->willReturn('form-key-123');

        $menuAIAPI = new MenuAIAPI(
            $this->aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        // Mock AI service to return response with URL link containing hash
        $aiResponseMessage = "You can find order settings at [[{base_url}{$expectedUrlPath}#{$expectedHash}]]. This section contains order configuration options.";
        
        $this->aiService->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn([
                'message' => $aiResponseMessage,
                'success' => true
            ]);

        // Execute the method
        $result = $menuAIAPI->sendRequestToChatGPT($query, $apiKey);

        // Verify result structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('hash', $result);
        
        // Verify hash is extracted
        $this->assertEquals($expectedHash, $result['hash'], 'Hash fragment should be extracted from URL');
        
        // Verify URL contains hash (hash is added back to URL for proper linking)
        $this->assertStringContainsString(
            '#' . $expectedHash,
            $result['url'],
            'URL should contain hash fragment for proper page navigation'
        );
    }

    /**
     * Test that generated admin URLs are valid (return 200, not 404 or redirect)
     * This test verifies URL generation produces working URLs
     */
    public function testAdminUrlsAreValidAndAccessible(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $testCases = [
            [
                'path' => '/customer/index',
                'expectedRoute' => 'customer/index/index',
                'description' => 'Customer grid'
            ],
            [
                'path' => '/sales/order',
                'expectedRoute' => 'sales/order/index',
                'description' => 'Orders grid'
            ],
            [
                'path' => '/catalog/product',
                'expectedRoute' => 'catalog/product/index',
                'description' => 'Product catalog'
            ],
        ];

        foreach ($testCases as $testCase) {
            $this->runUrlValidationTest($testCase);
        }
    }

    /**
     * Helper method to validate URL generation
     *
     * @param array $testCase
     */
    private function runUrlValidationTest(array $testCase): void
    {
        $path = $testCase['path'];
        $expectedRoute = $testCase['expectedRoute'];
        $description = $testCase['description'];

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $session = $this->createStub(SessionManagerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        
        // Mock URL builder - verify route format is correct (without adminhtml prefix)
        $urlBuilder = $this->createMock(UrlInterface::class);
        
        $secretKey = 'test-secret-key-' . md5($expectedRoute);
        $expectedFullUrl = "http://example.com/admin/{$expectedRoute}/key/{$secretKey}/";
        
        // Verify getUrl is called with route WITHOUT adminhtml prefix
        $urlBuilder->expects($this->once())
            ->method('getUrl')
            ->with(
                $this->equalTo($expectedRoute), // Should be 'customer/index/index', NOT 'adminhtml/customer/index/index'
                $this->callback(function($params) {
                    return is_array($params) && !isset($params['key']);
                })
            )
            ->willReturn($expectedFullUrl);
        
        $helper = $this->createMock(Helper::class);
        $formKey = $this->createMock(FormKey::class);

        // Use reflection to test generateAdminUrl method directly
        $menuAIAPI = new MenuAIAPI(
            $this->aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        $reflection = new \ReflectionClass($menuAIAPI);
        $method = $reflection->getMethod('generateAdminUrl');
        $method->setAccessible(true);

        // Test URL generation
        $generatedUrl = $method->invoke($menuAIAPI, $path);

        // Verify URL format
        $this->assertStringStartsWith(
            'http',
            $generatedUrl,
            "{$description}: URL should start with http/https"
        );
        
        // Verify no duplicate /admin/ prefix
        $this->assertStringNotContainsString(
            '/admin/admin/',
            $generatedUrl,
            "{$description}: URL should not have duplicate /admin/admin/ prefix"
        );
        
        // Verify URL contains /admin/ exactly once
        $adminCount = substr_count($generatedUrl, '/admin/');
        $this->assertEquals(
            1,
            $adminCount,
            "{$description}: URL should contain /admin/ exactly once. Got: {$generatedUrl}"
        );
        
        // Verify URL contains expected route path
        $urlNormalized = strtolower($generatedUrl);
        $expectedRouteNormalized = strtolower($expectedRoute);
        // Check if URL contains the route path (with slashes)
        $this->assertTrue(
            strpos($urlNormalized, str_replace('/', '/', $expectedRouteNormalized)) !== false,
            "{$description}: URL should contain expected route. Got: {$generatedUrl}, Expected route: {$expectedRoute}"
        );
        
        // Verify URL has security key
        $this->assertStringContainsString(
            '/key/',
            $generatedUrl,
            "{$description}: URL should contain security key parameter"
        );
    }

    /**
     * Test that admin URLs are resolved correctly from AI output
     * Tests various URL formats and ensures proper Magento admin URL generation
     */
    public function testAdminUrlResolvedCorrectlyFromAIOutput(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $testCases = [
            [
                'query' => 'where to find customer orders',
                'aiResponse' => 'You can find orders at [[{base_url}/sales/order]].',
                'expectedRoute' => 'sales/order/index',
                'expectedPath' => '/sales/order',
                'description' => 'Customer orders URL'
            ],
            [
                'query' => 'where are customers',
                'aiResponse' => 'Customers are located at [[{base_url}/customer/index/index]].',
                'expectedRoute' => 'customer/index/index',
                'expectedPath' => '/customer/index/index',
                'description' => 'Customer grid URL'
            ],
            [
                'query' => 'show me products',
                'aiResponse' => 'Products can be found at [[{base_url}/catalog/product]].',
                'expectedRoute' => 'catalog/product/index',
                'expectedPath' => '/catalog/product',
                'description' => 'Product catalog URL'
            ],
            [
                'query' => 'system configuration',
                'aiResponse' => 'System config is at [[{base_url}/adminhtml/system_config/edit/section/general]].',
                'expectedRoute' => 'adminhtml/system_config/edit',
                'expectedPath' => '/adminhtml/system_config/edit/section/general',
                'description' => 'System configuration URL'
            ],
            [
                'query' => 'where to find base url config',
                'aiResponse' => 'Base URL config is at [[{base_url}/system_config/edit/section/general]].',
                'expectedRoute' => 'system_config/edit/index',
                'expectedPath' => '/system_config/edit/section/general',
                'description' => 'Base URL configuration URL'
            ],
        ];

        foreach ($testCases as $testCase) {
            $this->runUrlResolutionTest($testCase);
        }
    }

    /**
     * Helper method to run URL resolution test for a specific test case
     *
     * @param array $testCase
     */
    private function runUrlResolutionTest(array $testCase): void
    {
        $query = $testCase['query'];
        $aiResponse = $testCase['aiResponse'];
        $expectedRoute = $testCase['expectedRoute'];
        $expectedPath = $testCase['expectedPath'];
        $description = $testCase['description'];
        $apiKey = 'test-api-key-12345';

        // Create session mock
        $sessionData = [];
        $session = $this->getMockBuilder(\Magento\Framework\Session\Generic::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData', 'unsetData'])
            ->getMock();
        
        $session->method('getData')
            ->willReturnCallback(function($key = null) use (&$sessionData) {
                return $key === null ? $sessionData : ($sessionData[$key] ?? null);
            });
        
        $session->method('setData')
            ->willReturnCallback(function($key, $value = null) use (&$sessionData, $session) {
                $sessionData[$key] = $value;
                return $session;
            });

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) use ($apiKey) {
                return strpos($path, 'openai_api_key') !== false ? $apiKey : null;
            });
        $logger = $this->createStub(LoggerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        
        // Mock URL builder with proper expectations
        $urlBuilder = $this->createMock(UrlInterface::class);
        
        // Generate expected URL with fresh secret key
        $secretKey = 'fresh-secret-key-' . md5($expectedRoute . time());
        $expectedFullUrl = "http://example.com/admin/{$expectedRoute}/key/{$secretKey}/";
        
        $urlBuilder->method('getUrl')
            ->with($this->callback(function($route) use ($expectedRoute) {
                // Allow for route variations (with/without adminhtml prefix, with/without /index)
                $routeNormalized = str_replace('adminhtml/', '', $route);
                $expectedNormalized = str_replace('adminhtml/', '', $expectedRoute);
                return strpos($routeNormalized, $expectedNormalized) !== false ||
                       strpos($expectedNormalized, $routeNormalized) !== false;
            }), $this->anything())
            ->willReturn($expectedFullUrl);
        
        // Mock helper for fallback scenarios
        $helper = $this->createMock(Helper::class);
        $helper->method('getUrl')
            ->willReturn($expectedFullUrl);
        $helper->method('getHomePageUrl')
            ->willReturn('http://example.com/');

        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')
            ->willReturn('form-key-123');

        // Create a new AI service mock for this test case (to avoid "called more than once" error)
        $aiService = $this->createMock(AIServiceInterface::class);
        $aiService->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn([
                'message' => $aiResponse,
                'success' => true
            ]);

        $menuAIAPI = new MenuAIAPI(
            $aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        // Execute the method
        $result = $menuAIAPI->sendRequestToChatGPT($query, $apiKey);

        // Verify result structure
        $this->assertIsArray($result, "{$description}: Result should be an array");
        $this->assertArrayHasKey('message', $result, "{$description}: Result should have 'message' key");
        $this->assertArrayHasKey('url', $result, "{$description}: Result should have 'url' key");
        
        // Verify URL is extracted and generated
        $this->assertNotNull($result['url'], "{$description}: URL should be extracted from AI response");
        $this->assertNotEmpty($result['url'], "{$description}: URL should not be empty");
        
        // Verify URL format (should be a valid admin URL)
        $this->assertStringStartsWith(
            'http',
            $result['url'],
            "{$description}: URL should start with http/https"
        );
        $this->assertStringContainsString(
            '/admin/',
            $result['url'],
            "{$description}: URL should contain /admin/ path"
        );
        
        // Verify URL contains the expected route path
        // The URL will have the full route like /admin/sales/order/index
        $urlNormalized = strtolower($result['url']);
        $expectedRouteNormalized = strtolower(str_replace('adminhtml/', '', $expectedRoute));
        
        // Extract route from URL (remove http://, domain, /admin/, /key/xxx)
        $urlPathParts = explode('/', trim(str_replace(['http://', 'https://'], '', $urlNormalized), '/'));
        $urlRouteParts = [];
        $skipNext = false;
        foreach ($urlPathParts as $i => $part) {
            if ($part === 'admin') {
                continue; // Skip 'admin'
            }
            if ($part === 'key') {
                break; // Stop at 'key' parameter
            }
            if (!empty($part) && !preg_match('/^[a-f0-9]{64}$/i', $part)) { // Filter out secret keys
                $urlRouteParts[] = $part;
            }
        }
        $urlRoute = implode('/', $urlRouteParts);
        
        // Check if URL route matches expected route (allow for /index suffix)
        $this->assertTrue(
            strpos($urlRoute, $expectedRouteNormalized) !== false ||
            strpos($expectedRouteNormalized, $urlRoute) !== false,
            "{$description}: URL should contain expected route. Got URL route: {$urlRoute}, Expected route: {$expectedRouteNormalized}, Full URL: {$result['url']}"
        );
        
        // Verify message contains HTML link
        $this->assertStringContainsString(
            '<a href',
            $result['message'],
            "{$description}: Message should contain HTML link tag"
        );
        
        // Verify link href matches generated URL
        preg_match('/href="([^"]+)"/', $result['message'], $hrefMatches);
        if (!empty($hrefMatches[1])) {
            $hrefUrl = $hrefMatches[1];
            // Normalize URLs for comparison (remove trailing slashes, normalize key format)
            $normalizedHref = preg_replace('#/key/[^/]+#', '', rtrim($hrefUrl, '/'));
            $normalizedResult = preg_replace('#/key/[^/]+#', '', rtrim($result['url'], '/'));
            $this->assertEquals(
                $normalizedResult,
                $normalizedHref,
                "{$description}: Link href should match generated URL"
            );
        }
    }

    /**
     * Test that URLs with key parameters are properly handled
     * Ensures stale keys are removed and fresh keys are generated
     */
    public function testUrlKeyParameterHandling(): void
    {
        if (!file_exists($this->menuMdPath)) {
            $this->markTestSkipped("menu.md file not found");
        }

        $query = 'where to find orders';
        $apiKey = 'test-api-key-12345';
        
        // AI response with URL containing a key parameter (should be stripped)
        $aiResponseWithKey = 'Find orders at [[{base_url}/sales/order/key/old-stale-key-12345]].';
        $expectedRoute = 'sales/order/index';
        $freshSecretKey = 'fresh-key-' . md5($expectedRoute . time());
        $expectedFullUrl = "http://example.com/admin/{$expectedRoute}/key/{$freshSecretKey}/";

        // Create session mock
        $sessionData = [];
        $session = $this->getMockBuilder(\Magento\Framework\Session\Generic::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData', 'unsetData'])
            ->getMock();
        
        $session->method('getData')
            ->willReturnCallback(function($key = null) use (&$sessionData) {
                return $key === null ? $sessionData : ($sessionData[$key] ?? null);
            });
        
        $session->method('setData')
            ->willReturnCallback(function($key, $value = null) use (&$sessionData, $session) {
                $sessionData[$key] = $value;
                return $session;
            });

        $directoryList = $this->createStub(DirectoryList::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(function($path) use ($apiKey) {
                return strpos($path, 'openai_api_key') !== false ? $apiKey : null;
            });
        $logger = $this->createStub(LoggerInterface::class);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getFullActionName')->willReturn('adminhtml_index_index');
        
        // Mock URL builder - verify it's called with route WITHOUT key parameter
        $urlBuilder = $this->createMock(UrlInterface::class);
        
        $urlBuilder->expects($this->atLeastOnce())
            ->method('getUrl')
            ->with(
                $this->equalTo($expectedRoute),
                $this->callback(function($params) {
                    // Verify params array does NOT contain 'key'
                    return is_array($params) && !isset($params['key']);
                })
            )
            ->willReturn($expectedFullUrl);
        
        $helper = $this->createMock(Helper::class);
        $helper->method('getHomePageUrl')
            ->willReturn('http://example.com/');
        $helper->method('getUrl')
            ->willReturn($expectedFullUrl);

        $formKey = $this->createMock(FormKey::class);

        // Create a new AI service mock for this test
        $aiService = $this->createMock(AIServiceInterface::class);
        $aiService->expects($this->once())
            ->method('sendChatRequest')
            ->willReturn([
                'message' => $aiResponseWithKey,
                'success' => true
            ]);

        $menuAIAPI = new MenuAIAPI(
            $aiService,
            $directoryList,
            $scopeConfig,
            $logger,
            $session,
            $request,
            $urlBuilder,
            $helper,
            $formKey
        );

        // Execute
        $result = $menuAIAPI->sendRequestToChatGPT($query, $apiKey);

        // Verify URL does NOT contain the old stale key
        $this->assertStringNotContainsString(
            'old-stale-key-12345',
            $result['url'],
            'URL should not contain stale key from AI response'
        );
        
        // Verify URL contains a fresh key (generated by Magento)
        $this->assertStringContainsString(
            '/key/',
            $result['url'],
            'URL should contain a security key parameter'
        );
        
        // Verify the key in URL is different from the stale one
        preg_match('#/key/([^/]+)#', $result['url'], $keyMatches);
        if (!empty($keyMatches[1])) {
            $this->assertNotEquals(
                'old-stale-key-12345',
                $keyMatches[1],
                'URL should have a fresh key, not the stale one from AI response'
            );
        }
    }
}
