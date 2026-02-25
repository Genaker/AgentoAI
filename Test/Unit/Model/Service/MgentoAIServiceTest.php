<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Service;

use PHPUnit\Framework\TestCase;

/**
 * MgentoAIService Tests
 * 
 * Note: MgentoAIService has Magento framework type hints that cannot be mocked in unit tests.
 * For comprehensive testing, use integration tests or functional testing in production.
 * 
 * This test class verifies the basic structure and constants.
 */
class MgentoAIServiceTest extends TestCase
{
    /**
     * Test constants are defined
     */
    public function testConstantPathsAreDefined(): void
    {
        $this->assertEquals(
            '/v1/chat/completions',
            \Genaker\MagentoMcpAi\Model\Service\MgentoAIService::CHAT_COMPLETIONS_PATH
        );
        
        $this->assertEquals(
            '/v1/embeddings',
            \Genaker\MagentoMcpAi\Model\Service\MgentoAIService::EMBEDDINGS_PATH
        );
        
        $this->assertEquals(
            '/v1/images/generations',
            \Genaker\MagentoMcpAi\Model\Service\MgentoAIService::IMAGES_PATH
        );
        
        $this->assertEquals(
            '/v1/audio/transcriptions',
            \Genaker\MagentoMcpAi\Model\Service\MgentoAIService::AUDIO_TRANSCRIPTION_PATH
        );
        
        $this->assertEquals(
            '/v1/audio/speech',
            \Genaker\MagentoMcpAi\Model\Service\MgentoAIService::AUDIO_SPEECH_PATH
        );
    }

    /**
     * Test config constants are defined
     */
    public function testConfigConstantsAreDefined(): void
    {
        $this->assertEquals(
            'magentomcpai/general/ai_provider',
            \Genaker\MagentoMcpAi\Model\Service\MgentoAIService::CONFIG_AI_PROVIDER
        );
        
        $this->assertEquals(
            'magentomcpai/general/ai_model',
            \Genaker\MagentoMcpAi\Model\Service\MgentoAIService::CONFIG_AI_MODEL
        );
    }

    /**
     * Test class is properly namespaced
     */
    public function testClassIsProperlyNamespaced(): void
    {
        $reflection = new \ReflectionClass(\Genaker\MagentoMcpAi\Model\Service\MgentoAIService::class);
        
        $this->assertEquals(
            'Genaker\MagentoMcpAi\Model\Service',
            $reflection->getNamespaceName()
        );
    }

    /**
     * Test class has required public methods
     */
    public function testClassHasRequiredPublicMethods(): void
    {
        $reflection = new \ReflectionClass(\Genaker\MagentoMcpAi\Model\Service\MgentoAIService::class);
        
        $publicMethods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic()) {
                $publicMethods[] = $method->getName();
            }
        }
        
        // Check key methods exist
        $this->assertContains('sendChatRequest', $publicMethods);
        $this->assertContains('getChatCompletion', $publicMethods);
        $this->assertContains('getCompletion', $publicMethods);
        $this->assertContains('getApiKey', $publicMethods);
        $this->assertContains('generateEmbeddings', $publicMethods);
        $this->assertContains('generateImage', $publicMethods);
        $this->assertContains('transcribeAudio', $publicMethods);
        $this->assertContains('generateSpeech', $publicMethods);
        $this->assertContains('getAvailableProviders', $publicMethods);
        $this->assertContains('getPricing', $publicMethods);
    }

    /**
     * Test class has token tracking properties
     */
    public function testClassHasTokenTrackingProperties(): void
    {
        $reflection = new \ReflectionClass(\Genaker\MagentoMcpAi\Model\Service\MgentoAIService::class);
        
        $publicProperties = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $publicProperties[] = $prop->getName();
        }
        
        // Check token tracking properties
        $this->assertContains('prompt_tokens', $publicProperties);
        $this->assertContains('completion_tokens', $publicProperties);
        $this->assertContains('total_tokens', $publicProperties);
    }

    /**
     * Test class has private MultiLLMService dependency
     */
    public function testClassHasMultiLLMServiceDependency(): void
    {
        $reflection = new \ReflectionClass(\Genaker\MagentoMcpAi\Model\Service\MgentoAIService::class);
        
        // Check constructor has multiLLMService parameter
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        
        $paramNames = [];
        foreach ($constructor->getParameters() as $param) {
            $paramNames[] = $param->getName();
        }
        
        $this->assertContains('multiLLMService', $paramNames);
    }

    /**
     * Test class supports backwards compatibility
     */
    public function testClassSupportsBackwardsCompatibility(): void
    {
        $reflection = new \ReflectionClass(\Genaker\MagentoMcpAi\Model\Service\MgentoAIService::class);
        
        // Verify methods from OpenAiService are present
        $this->assertTrue($reflection->hasMethod('getApiKey'));
        $this->assertTrue($reflection->hasMethod('getApiDomain'));
        $this->assertTrue($reflection->hasMethod('sendChatRequest'));
        $this->assertTrue($reflection->hasMethod('getChatCompletion'));
        $this->assertTrue($reflection->hasMethod('getCompletion'));
        $this->assertTrue($reflection->hasMethod('sendFunctionCallingRequest'));
    }
}
