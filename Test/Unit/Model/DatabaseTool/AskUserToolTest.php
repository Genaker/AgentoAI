<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Model\DatabaseTool\AskUserTool;
use Genaker\MagentoMcpAi\Model\Service\UserInteractionContext;
use PHPUnit\Framework\TestCase;

class AskUserToolTest extends TestCase
{
    private UserInteractionContext $context;
    private AskUserTool $tool;

    protected function setUp(): void
    {
        $this->context = new UserInteractionContext();
        $this->tool    = new AskUserTool($this->context);
    }

    public function testGetName(): void
    {
        $this->assertEquals('ask_user', $this->tool->getName());
    }

    public function testGetParametersSchemaHasRequiredQuestion(): void
    {
        $schema = $this->tool->getParametersSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('question', $schema['properties']);
        $this->assertContains('question', $schema['required']);
    }

    public function testExecuteCallsContextAndReturnsAnswer(): void
    {
        $this->context->setAskCallback(fn($q) => 'yes, proceed');

        $result = $this->tool->execute(['question' => 'Should I continue?']);

        $this->assertTrue($result['success']);
        $this->assertEquals('Should I continue?', $result['question']);
        $this->assertEquals('yes, proceed', $result['answer']);
    }

    public function testExecuteInNonInteractiveMode(): void
    {
        // No callback set — non-interactive fallback
        $result = $this->tool->execute(['question' => 'What environment is this?']);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('non-interactive', $result['answer']);
    }

    public function testExecuteReturnsErrorWhenNoQuestion(): void
    {
        $result = $this->tool->execute([]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetDescriptionMentionsAnalysis(): void
    {
        $this->assertStringContainsString('analysis', strtolower($this->tool->getDescription()));
    }
}
