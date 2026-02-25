<?php
namespace Genaker\MagentoMcpAi\Test\Integration\Model\DatabaseTool;

use PHPUnit\Framework\TestCase;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;
use Genaker\MagentoMcpAi\Model\DatabaseTool\GrepTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Integration tests for GrepTool
 */
class GrepToolTest extends TestCase
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
     * @var GrepTool
     */
    private $tool;

    protected function setUp(): void
    {
        $this->directoryList = $this->createMock(DirectoryList::class);
        $this->fileIo = $this->createMock(File::class);

        $this->tool = new GrepTool($this->directoryList, $this->fileIo);
    }

    /**
     * Test tool can be instantiated
     */
    public function testToolCanBeInstantiated(): void
    {
        $this->assertInstanceOf(GrepTool::class, $this->tool);
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
        $this->assertEquals('grep_files', $this->tool->getName());
    }

    /**
     * Test tool description
     */
    public function testToolDescription(): void
    {
        $description = $this->tool->getDescription();
        $this->assertNotEmpty($description);
        $this->assertIsString($description);
        $this->assertStringContainsString('search', strtolower($description));
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
        $this->assertArrayHasKey('pattern', $schema['properties']);
        $this->assertArrayHasKey('file_path', $schema['properties']);
        $this->assertArrayHasKey('max_results', $schema['properties']);
        $this->assertContains('pattern', $schema['required']);
        $this->assertContains('file_path', $schema['required']);
    }

    /**
     * Test execute throws exception when pattern is missing
     */
    public function testExecuteThrowsExceptionWhenPatternMissing(): void
    {
        $arguments = [
            'file_path' => 'app/code'
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Search pattern is required');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute throws exception when file_path is missing
     */
    public function testExecuteThrowsExceptionWhenFilePathMissing(): void
    {
        $arguments = [
            'pattern' => 'test'
        ];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('File path is required');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute with simple string pattern
     */
    public function testExecuteWithSimpleStringPattern(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'pattern' => 'class ExecuteSqlQuery',
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_results' => 10
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('pattern', $result);
        $this->assertEquals('class ExecuteSqlQuery', $result['pattern']);
        $this->assertArrayHasKey('match_count', $result);
        $this->assertArrayHasKey('matches', $result);
        $this->assertArrayHasKey('preview', $result);
    }

    /**
     * Test execute respects max_results limit
     */
    public function testExecuteRespectsMaxResultsLimit(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'pattern' => 'function',
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_results' => 5
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertLessThanOrEqual(5, $result['match_count']);
    }

    /**
     * Test execute with regex pattern
     */
    public function testExecuteWithRegexPattern(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'pattern' => 'class\s+\w+',
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_results' => 10
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['matches']);
    }

    /**
     * Test execute with non-existent file path
     */
    public function testExecuteWithNonExistentFilePath(): void
    {
        $arguments = [
            'pattern' => 'test',
            'file_path' => 'app/code/Genaker/MagentoMcpAi/NonExistentFile.php'
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Grep search failed');

        $this->tool->execute($arguments, false);
    }

    /**
     * Test execute with empty results
     */
    public function testExecuteWithEmptyResults(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'pattern' => 'ThisPatternWillNeverExistInAnyFile12345',
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_results' => 10
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['match_count']);
        $this->assertEmpty($result['matches']);
        $this->assertStringContainsString('No matches found', $result['preview']);
    }

    /**
     * Test preview formatting
     */
    public function testPreviewFormatting(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'pattern' => 'class',
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_results' => 3
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        $result = $this->tool->execute($arguments, false);

        $this->assertArrayHasKey('preview', $result);
        $preview = $result['preview'];
        
        if ($result['match_count'] > 0) {
            $this->assertStringContainsString('Found', $preview);
            $this->assertStringContainsString('matches', $preview);
        }
    }

    /**
     * Test execute ignores allowDangerous flag (read-only operation)
     */
    public function testExecuteIgnoresAllowDangerousFlag(): void
    {
        $testFile = __DIR__ . '/../../../../Model/DatabaseTool/ExecuteSqlQuery.php';
        $arguments = [
            'pattern' => 'class',
            'file_path' => str_replace(dirname(__DIR__, 4) . '/', '', $testFile),
            'max_results' => 5
        ];

        $this->directoryList->method('getRoot')
            ->willReturn(dirname(__DIR__, 4));

        // Should work the same regardless of allowDangerous flag
        $result1 = $this->tool->execute($arguments, false);
        $result2 = $this->tool->execute($arguments, true);

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
    }
}
