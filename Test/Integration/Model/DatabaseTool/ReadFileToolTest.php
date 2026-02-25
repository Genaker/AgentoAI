<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model\DatabaseTool;

use PHPUnit\Framework\TestCase;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;
use Genaker\MagentoMcpAi\Model\DatabaseTool\ReadFileTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Integration tests for ReadFileTool
 */
class ReadFileToolTest extends TestCase
{
    /**
     * @var DirectoryList|\PHPUnit\Framework\MockObject\MockObject
     */
    private $directoryList;

    /**
     * @var File|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fileIo;

    /**
     * @var ReadFileTool
     */
    private $tool;

    protected function setUp(): void
    {
        $this->directoryList = $this->createMock(DirectoryList::class);
        $this->fileIo = $this->createMock(File::class);

        $this->tool = new ReadFileTool($this->directoryList, $this->fileIo);
    }

    /**
     * Test tool can be instantiated
     */
    public function testToolCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ReadFileTool::class, $this->tool);
    }

    /**
     * Test tool implements DatabaseToolInterface
     */
    public function testToolImplementsInterface(): void
    {
        $this->assertInstanceOf(\Genaker\MagentoMcpAi\Api\DatabaseToolInterface::class, $this->tool);
    }

    /**
     * Test tool name
     */
    public function testToolName(): void
    {
        $this->assertEquals('read_file', $this->tool->getName());
    }

    /**
     * Test tool description
     */
    public function testToolDescription(): void
    {
        $description = $this->tool->getDescription();
        $this->assertNotEmpty($description);
        $this->assertIsString($description);
        $this->assertStringContainsString('read', strtolower($description));
    }

    /**
     * Test parameters schema
     */
    public function testParametersSchema(): void
    {
        $schema = $this->tool->getParametersSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('required', $schema);
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('file_path', $schema['properties']);
        $this->assertArrayHasKey('start_line', $schema['properties']);
        $this->assertArrayHasKey('end_line', $schema['properties']);
        $this->assertArrayHasKey('max_lines', $schema['properties']);
        $this->assertContains('file_path', $schema['required']);
    }

    /**
     * Test execute throws exception when file_path is missing
     */
    public function testExecuteThrowsExceptionWhenFilePathMissing(): void
    {
        $arguments = [];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('File path is required');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute with valid file
     */
    public function testExecuteWithValidFile(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile)
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('total_lines', $result);
        $this->assertArrayHasKey('lines_read', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('preview', $result);
        $this->assertGreaterThan(0, $result['total_lines']);
        $this->assertIsArray($result['content']);
    }

    /**
     * Test execute with start_line and end_line
     */
    public function testExecuteWithLineRange(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'start_line' => 1,
            'end_line' => 20
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['start_line']);
        $this->assertEquals(20, $result['end_line']);
        $this->assertLessThanOrEqual(20, $result['lines_read']);
    }

    /**
     * Test execute with max_lines limit
     */
    public function testExecuteWithMaxLinesLimit(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_lines' => 10
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertLessThanOrEqual(10, $result['lines_read']);
    }

    /**
     * Test execute throws exception when file not found
     */
    public function testExecuteThrowsExceptionWhenFileNotFound(): void
    {
        $arguments = [
            'file_path' => 'app/code/Genaker/MagentoMcpAi/NonExistentFile.php'
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Failed to read file');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute throws exception when path is directory
     */
    public function testExecuteThrowsExceptionWhenPathIsDirectory(): void
    {
        $arguments = [
            'file_path' => 'app/code/Genaker/MagentoMcpAi/Model/DatabaseTool'
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Failed to read file');

        try {
            $this->tool->execute($arguments, false);
        } catch (LocalizedException $e) {
            // Accept either "Path is not a file" or "Failed to read file" as both are valid
            $message = $e->getMessage();
            $this->assertTrue(
                strpos($message, 'Path is not a file') !== false || 
                strpos($message, 'Failed to read file') !== false,
                "Exception message should contain 'Path is not a file' or 'Failed to read file', got: $message"
            );
            throw $e;
        }
    }

    /**
     * Test preview formatting
     */
    public function testPreviewFormatting(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'start_line' => 1,
            'end_line' => 5
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('preview', $result);
        $preview = $result['preview'];
        
        $this->assertStringContainsString('File:', $preview);
        $this->assertStringContainsString('showing lines', $preview);
    }

    /**
     * Test execute ignores allowDangerous flag (read-only operation)
     */
    public function testExecuteIgnoresAllowDangerousFlag(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_lines' => 10
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        // Should work the same regardless of allowDangerous flag
        $result1 = $this->tool->execute($arguments, false);
        $result2 = $this->tool->execute($arguments, true);

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
    }

    /**
     * Test content format includes line numbers
     */
    public function testContentFormatIncludesLineNumbers(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'start_line' => 1,
            'end_line' => 5
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertIsArray($result['content']);
        foreach ($result['content'] as $line) {
            $this->assertArrayHasKey('line', $line);
            $this->assertArrayHasKey('content', $line);
            $this->assertIsInt($line['line']);
            $this->assertIsString($line['content']);
        }
    }
}
