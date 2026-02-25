<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\Service;

use Genaker\MagentoMcpAi\Model\Service\UserInteractionContext;
use PHPUnit\Framework\TestCase;

class UserInteractionContextTest extends TestCase
{
    private UserInteractionContext $context;

    protected function setUp(): void
    {
        $this->context = new UserInteractionContext();
    }

    public function testIsInteractiveReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->context->isInteractive());
    }

    public function testIsInteractiveTrueAfterSettingCallback(): void
    {
        $this->context->setAskCallback(fn($q) => 'yes');
        $this->assertTrue($this->context->isInteractive());
    }

    public function testAskCallsCallbackWithQuestion(): void
    {
        $received = null;
        $this->context->setAskCallback(function (string $question) use (&$received): string {
            $received = $question;
            return 'answer';
        });

        $result = $this->context->ask('Are you sure?');

        $this->assertEquals('Are you sure?', $received);
        $this->assertEquals('answer', $result);
    }

    public function testAskReturnsNonInteractiveStringWhenNoCallback(): void
    {
        $result = $this->context->ask('Any question?');
        $this->assertStringContainsString('non-interactive', $result);
    }

    public function testCallbackCanBeReplacedAfterFirstSet(): void
    {
        $this->context->setAskCallback(fn($q) => 'first');
        $this->context->setAskCallback(fn($q) => 'second');

        $this->assertEquals('second', $this->context->ask('question'));
    }
}
