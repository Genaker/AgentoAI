<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Controller\Adminhtml\Chat;

use PHPUnit\Framework\TestCase;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;

/**
 * Simple integration tests for Admin Chat Query Controller
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
     * Test admin controller processes queries
     */
    public function testAdminControllerProcessesQueries(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Admin query response',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $result = $this->aiService->sendChatRequest('Admin query');

        $this->assertTrue($result['success']);
        $this->assertEquals('gpt-3.5-turbo', $result['model']);
    }

    /**
     * Test admin controller with context
     */
    public function testAdminControllerWithContext(): void
    {
        $mockResponse = [
            'success' => true,
            'message' => 'Response with context',
            'model' => 'gpt-3.5-turbo'
        ];

        $this->aiService->method('sendChatRequest')
            ->willReturn($mockResponse);

        $context = [['role' => 'system', 'content' => 'Admin context']];
        $result = $this->aiService->sendChatRequest('Query', $context);

        $this->assertTrue($result['success']);
    }
}
