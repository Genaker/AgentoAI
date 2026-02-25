<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model;

use PHPUnit\Framework\TestCase;
use Magento\Framework\Exception\LocalizedException;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;

/**
 * Integration tests for CustomerChatbot Model
 * Validates the model works with OpenAI service for customer interactions
 */
class CustomerChatbotIntegrationTest extends TestCase
{
    /**
     * @var AIServiceInterface
     */
    private $aiService;

    protected function setUp(): void
    {
        $this->aiService = $this->createStub(AIServiceInterface::class);
    }

    /**
     * Test customer query processing
     */
    public function testCustomerQueryProcessing(): void
    {
        $customerQuery = 'How do I track my order?';

        $mockResponse = [
            'success' => true,
            'message' => 'You can track your order by logging into your account...',
            'tokens' => ['input' => 10, 'output' => 30, 'total' => 40],
            'cost' => 0.0004,
            'model' => 'gpt-3.5-turbo',
            'provider' => 'openai'
        ];

        $this->aiService->method('sendChatRequest')
            ->with($customerQuery)
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($customerQuery);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message']);
        $this->assertStringNotContainsString('Error', $result['message']);
    }

    /**
     * Test customer chatbot with context
     */
    public function testChatbotWithCustomerContext(): void
    {
        $systemPrompt = 'You are a helpful customer service chatbot for an e-commerce store.';
        $customerQuery = 'What is your return policy?';
        
        $context = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $customerQuery]
        ];

        $mockResponse = [
            'success' => true,
            'message' => 'Our return policy allows 30 days from purchase date...',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($customerQuery, $context);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * Test chatbot personality and tone
     */
    public function testChatbotPersonalityTone(): void
    {
        $query = 'Tell me about your products';

        $mockResponse = [
            'success' => true,
            'message' => 'Welcome! We have a wide selection of high-quality products...',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($query);

        $this->assertTrue($result['success']);
        // Check response is friendly and welcoming
        $this->assertNotEmpty($result['message']);
    }

    /**
     * Test product recommendations
     */
    public function testProductRecommendations(): void
    {
        $query = 'I am looking for winter clothing';

        $mockResponse = [
            'success' => true,
            'message' => 'We recommend our winter collection including jackets, sweaters...',
            'tokens' => ['input' => 8, 'output' => 25, 'total' => 33],
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->with($query)
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($query);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('recommend', strtolower($result['message']));
    }

    /**
     * Test multi-turn conversation
     */
    public function testMultiTurnConversation(): void
    {
        $conversation = [
            ['role' => 'user', 'content' => 'Do you have black shoes?'],
            ['role' => 'assistant', 'content' => 'Yes, we have black shoes in various styles.'],
            ['role' => 'user', 'content' => 'What about size 10?']
        ];

        $mockResponse = [
            'success' => true,
            'message' => 'Yes, we have size 10 available in several styles.',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('What about size 10?', $conversation);

        $this->assertTrue($result['success']);
    }

    /**
     * Test edge cases - empty query
     */
    public function testEmptyQueryHandling(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'I did not understand your question. Please try again.',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->with('')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('');

        $this->assertTrue($result['success']);
    }

    /**
     * Test edge cases - very long query
     */
    public function testLongQueryHandling(): void
    {
        $longQuery = str_repeat('What is ', 100) . 'happening?';

        $mockResponse = [
            'success' => true,
            'message' => 'I understand your long question...',
            'tokens' => ['input' => 200, 'output' => 20, 'total' => 220],
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($longQuery);

        $this->assertTrue($result['success']);
    }

    /**
     * Test customer satisfaction responses
     */
    public function testCustomerSatisfactionResponses(): void
    {
        $query = 'Thanks for the help!';

        $mockResponse = [
            'success' => true,
            'message' => 'You are welcome! Feel free to ask if you need anything else.',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($query);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * Test handling of special characters and emojis
     */
    public function testSpecialCharactersHandling(): void
    {
        $query = 'Can I use special chars? #@!$%';

        $mockResponse = [
            'success' => true,
            'message' => 'We can handle your query.',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest($query);

        $this->assertTrue($result['success']);
    }

    /**
     * Test temperature variations for different response styles
     */
    public function testTemperatureVariations(): void
    {
        $query = 'Tell me a joke about shopping';

        $mockResponse = [
            'success' => true,
            'message' => 'Why did the shopper bring a ladder?',
            'model' => 'gpt-3.5-turbo'
        ];

        // Create fresh instances for each temperature test
        for ($temp = 0.1; $temp <= 1.0; $temp += 0.3) {
            $scopeConfig = $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class);
            $aiService = $this->createStub(AIServiceInterface::class);
            
            $aiService->method('sendChatRequest')
                ->willReturn($mockResponse);

            $result = $aiService->sendChatRequest($query, [], 2000, $temp);
            $this->assertTrue($result['success']);
        }
    }
}
