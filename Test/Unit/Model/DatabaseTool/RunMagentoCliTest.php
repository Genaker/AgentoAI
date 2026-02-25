<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Model\DatabaseTool\RunMagentoCli;
use Magento\Framework\Filesystem\DirectoryList;
use PHPUnit\Framework\TestCase;

class RunMagentoCliTest extends TestCase
{
    private RunMagentoCli $tool;

    protected function setUp(): void
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn('/var/www/magento');

        $this->tool = new RunMagentoCli($directoryList);
    }

    public function testGetName(): void
    {
        $this->assertEquals('run_magento_cli', $this->tool->getName());
    }

    public function testGetParametersSchemaHasRequiredCommand(): void
    {
        $schema = $this->tool->getParametersSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('command', $schema['properties']);
        $this->assertContains('command', $schema['required']);
    }

    public function testAllowedCommandsAreRecognised(): void
    {
        $allowedCommands = [
            'indexer:status',
            'cache:status',
            'module:status',
            'cron:status',
            'info:adminuri',
            '--version',
        ];

        foreach ($allowedCommands as $cmd) {
            $this->assertTrue(
                $this->tool->isAllowedCommand($cmd),
                "Expected '$cmd' to be in the allowed list"
            );
        }
    }

    public function testDisallowedCommandReturnsError(): void
    {
        $result = $this->tool->execute(['command' => 'rm -rf /']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not in the allowed list', $result['error']);
    }

    public function testDisallowedCommandCacheClear(): void
    {
        $result = $this->tool->execute(['command' => 'cache:flush']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testEmptyCommandReturnsError(): void
    {
        $result = $this->tool->execute(['command' => '']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetAllowedCommandsReturnsNonEmptyArray(): void
    {
        $allowed = $this->tool->getAllowedCommands();

        $this->assertIsArray($allowed);
        $this->assertNotEmpty($allowed);
        $this->assertContains('indexer:status', $allowed);
        $this->assertContains('cache:status', $allowed);
    }

    public function testDescriptionListsAllowedCommands(): void
    {
        $description = $this->tool->getDescription();

        $this->assertStringContainsString('indexer:status', $description);
        $this->assertStringContainsString('cache:status', $description);
    }
}
