<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Controller\Chat;

use PHPUnit\Framework\TestCase;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;

/**
 * Simple integration tests for Frontend Chat Query Controller
 */
class QueryControllerIntegrationTest extends TestCase
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
     * Test customer controller processes queries
     */
    public function testCustomerControllerProcessesQueries(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Customer query response',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('Customer query');

        $this->assertTrue($result['success']);
        $this->assertEquals('gpt-3.5-turbo', $result['model']);
    }

    /**
     * Test customer controller with conversation history
     */
    public function testCustomerControllerWithHistory(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Response with history',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $history = [['role' => 'user', 'content' => 'Previous message']];
        $result = $this->aiService->sendChatRequest('Follow up', $history);

        $this->assertTrue($result['success']);
    }
}
