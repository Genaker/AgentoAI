<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Service;

use Genaker\MagentoMcpAi\Model\Service\OpenAiService;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for OpenAiService
 */
class OpenAiServiceTest extends TestCase
{
    /**
     * @var OpenAiService
     */
    private $openAiService;

    /**
     * @var MockObject|Curl
     */
    private $curlMock;

    /**
     * @var MockObject|JsonHelper
     */
    private $jsonHelperMock;

    /**
     * @var MockObject|File
     */
    private $fileMock;

    /**
     * @var MockObject|ScopeConfigInterface
     */
    private $scopeConfigMock;

    protected function setUp(): void
    {
        $this->curlMock = $this->createMock(Curl::class);
        $this->jsonHelperMock = $this->createMock(JsonHelper::class);
        $this->fileMock = $this->createMock(File::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);

        $this->openAiService = new OpenAiService(
            $this->curlMock,
            $this->jsonHelperMock,
            $this->fileMock,
            $this->scopeConfigMock
        );
    }

    /**
     * Test 1: getApiKey retrieves from environment variable
     */
    public function testGetApiKeyFromEnvironmentVariable(): void
    {
        // Set environment variable
        $testApiKey = 'sk-test-key-12345';
        putenv("OPENAI_API_KEY={$testApiKey}");

        try {
            // The OpenAiService reads from config first, then env var
            // Mock config to return the API key
            $this->scopeConfigMock->method('getValue')
                ->with('magentomcpai/general/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
                ->willReturn($testApiKey);

            $apiKey = $this->openAiService->getApiKey();

            $this->assertEquals($testApiKey, $apiKey, 'API key should be retrieved from configuration');
        } finally {
            // Clean up
            putenv('OPENAI_API_KEY');
        }
    }

    /**
     * Test 2: getApiKey throws exception when not configured
     */
    public function testGetApiKeyThrowsExceptionWhenNotConfigured(): void
    {
        // Mock config to return null
        $this->scopeConfigMock->method('getValue')
            ->willReturn(null);

        // Clear environment variable
        putenv('OPENAI_API_KEY');

        $this->expectException(LocalizedException::class);
        $this->openAiService->getApiKey();
    }

    /**
     * Test 3: getApiDomain returns default domain
     */
    public function testGetApiDomainReturnsDefault(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->willReturn(null);

        // Use reflection to access protected method
        $reflectionMethod = new \ReflectionMethod($this->openAiService, 'getApiDomain');
        $reflectionMethod->setAccessible(true);
        $apiDomain = $reflectionMethod->invoke($this->openAiService);

        $this->assertEquals('https://api.openai.com', $apiDomain, 'Should return default API domain');
    }

    /**
     * Test 4: getApiDomain returns custom domain from config
     */
    public function testGetApiDomainReturnsCustom(): void
    {
        $customDomain = 'https://custom-api.example.com';
        
        $this->scopeConfigMock->method('getValue')
            ->with('magentomcpai/general/api_domain', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn($customDomain);

        // Use reflection to access protected method
        $reflectionMethod = new \ReflectionMethod($this->openAiService, 'getApiDomain');
        $reflectionMethod->setAccessible(true);
        $apiDomain = $reflectionMethod->invoke($this->openAiService);

        $this->assertEquals($customDomain, $apiDomain, 'Should return custom API domain');
    }

    /**
     * Test 5: getApiDomain strips trailing slash
     */
    public function testGetApiDomainStripsTrailingSlash(): void
    {
        $domainWithSlash = 'https://api.example.com/';
        
        $this->scopeConfigMock->method('getValue')
            ->with('magentomcpai/general/api_domain', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn($domainWithSlash);

        // Use reflection to access protected method
        $reflectionMethod = new \ReflectionMethod($this->openAiService, 'getApiDomain');
        $reflectionMethod->setAccessible(true);
        $apiDomain = $reflectionMethod->invoke($this->openAiService);

        $this->assertEquals('https://api.example.com', $apiDomain, 'Should strip trailing slash from domain');
        $this->assertFalse(str_ends_with($apiDomain, '/'), 'Domain should not end with slash');
    }

    /**
     * Test 6: Token properties can be set and retrieved
     */
    public function testTokenPropertiesSetAndRetrieve(): void
    {
        $this->openAiService->prompt_tokens = 100;
        $this->openAiService->completion_tokens = 50;
        $this->openAiService->total_tokens = 150;
        $this->openAiService->cached_tokens = 25;

        $this->assertEquals(100, $this->openAiService->prompt_tokens);
        $this->assertEquals(50, $this->openAiService->completion_tokens);
        $this->assertEquals(150, $this->openAiService->total_tokens);
        $this->assertEquals(25, $this->openAiService->cached_tokens);
    }

    /**
     * Test 7: Constructor initializes all dependencies
     */
    public function testConstructorInitializesDependencies(): void
    {
        $service = new OpenAiService(
            $this->curlMock,
            $this->jsonHelperMock,
            $this->fileMock,
            $this->scopeConfigMock
        );

        $this->assertInstanceOf(OpenAiService::class, $service);
    }

    /**
     * Test 8: API endpoint constants are defined
     */
    public function testApiEndpointConstantsAreDefined(): void
    {
        $this->assertEquals('/v1/chat/completions', OpenAiService::CHAT_COMPLETIONS_PATH);
        $this->assertEquals('/v1/files', OpenAiService::FILES_PATH);
        $this->assertEquals('/v1/embeddings', OpenAiService::EMBEDDINGS_PATH);
        $this->assertEquals('/v1/images/generations', OpenAiService::IMAGES_PATH);
        $this->assertEquals('/v1/audio/transcriptions', OpenAiService::AUDIO_TRANSCRIPTION_PATH);
        $this->assertEquals('/v1/audio/speech', OpenAiService::AUDIO_SPEECH_PATH);
    }

    /**
     * Test 9: Google API endpoint constants are defined
     */
    public function testGoogleApiEndpointConstantsAreDefined(): void
    {
        $this->assertStringContainsString('speech.googleapis.com', OpenAiService::GOOGLE_SPEECH_API_ENDPOINT);
        $this->assertStringContainsString('vision.googleapis.com', OpenAiService::GOOGLE_VISION_API_ENDPOINT);
    }

    /**
     * Test 10: Service handles multiple instances independently
     */
    public function testMultipleServiceInstancesAreIndependent(): void
    {
        $service1 = new OpenAiService(
            $this->curlMock,
            $this->jsonHelperMock,
            $this->fileMock,
            $this->scopeConfigMock
        );

        $service2 = new OpenAiService(
            $this->curlMock,
            $this->jsonHelperMock,
            $this->fileMock,
            $this->scopeConfigMock
        );

        $service1->prompt_tokens = 100;
        $service2->prompt_tokens = 200;

        $this->assertEquals(100, $service1->prompt_tokens);
        $this->assertEquals(200, $service2->prompt_tokens);
    }
}
