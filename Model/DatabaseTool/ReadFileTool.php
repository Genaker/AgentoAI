<?php
namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;

/**
 * Read File Tool - Read file contents
 */
class ReadFileTool implements DatabaseToolInterface
{
    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var File
     */
    private $fileIo;

    public function __construct(
        DirectoryList $directoryList,
        File $fileIo
    ) {
        $this->directoryList = $directoryList;
        $this->fileIo = $fileIo;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'read_file';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the contents of a file. Useful for examining code, configurations, or documentation. Returns file content with line numbers.';
    }

    /**
     * @inheritDoc
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Path to the file (relative to Magento root, e.g., "app/code/Vendor/Module/Model/Example.php")'
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => 'Start reading from this line number (optional, 1-indexed)'
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => 'End reading at this line number (optional, 1-indexed). If not specified, reads entire file or up to max_lines'
                ],
                'max_lines' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of lines to read (default: 500). Use to limit large files.'
                ]
            ],
            'required' => ['file_path']
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $filePath = $arguments['file_path'] ?? '';
        $startLine = isset($arguments['start_line']) ? (int)$arguments['start_line'] : null;
        $endLine = isset($arguments['end_line']) ? (int)$arguments['end_line'] : null;
        $maxLines = isset($arguments['max_lines']) ? (int)$arguments['max_lines'] : 500;

        if (empty($filePath)) {
            throw new LocalizedException(__('File path is required'));
        }

        try {
            $rootPath = $this->directoryList->getRoot();
            $fullPath = $rootPath . '/' . ltrim($filePath, '/');

            // Security: Ensure file is within Magento root
            $realRootPath = realpath($rootPath);
            $realFilePath = realpath($fullPath);
            
            if ($realFilePath === false || strpos($realFilePath, $realRootPath) !== 0) {
                throw new LocalizedException(__('File path is outside Magento root directory'));
            }

            if (!file_exists($fullPath)) {
                throw new LocalizedException(__('File not found: %1', $filePath));
            }

            if (!is_file($fullPath)) {
                throw new LocalizedException(__('Path is not a file: %1', $filePath));
            }

            if (!is_readable($fullPath)) {
                throw new LocalizedException(__('File is not readable: %1', $filePath));
            }

            $content = file_get_contents($fullPath);
            if ($content === false) {
                throw new LocalizedException(__('Failed to read file: %1', $filePath));
            }

            $lines = explode("\n", $content);
            $totalLines = count($lines);

            // Apply line range
            if ($startLine !== null) {
                $startLine = max(1, $startLine) - 1; // Convert to 0-indexed
            } else {
                $startLine = 0;
            }

            if ($endLine !== null) {
                $endLine = min($totalLines, $endLine);
            } else {
                $endLine = min($totalLines, $startLine + $maxLines);
            }

            $selectedLines = array_slice($lines, $startLine, $endLine - $startLine);
            $lineNumbers = range($startLine + 1, $endLine);

            // Format with line numbers
            $formattedLines = [];
            foreach ($selectedLines as $index => $line) {
                $formattedLines[] = [
                    'line' => $lineNumbers[$index],
                    'content' => $line
                ];
            }

            return [
                'success' => true,
                'file_path' => $filePath,
                'total_lines' => $totalLines,
                'lines_read' => count($formattedLines),
                'start_line' => $startLine + 1,
                'end_line' => $endLine,
                'content' => $formattedLines,
                'preview' => $this->formatContent($filePath, $formattedLines, $totalLines)
            ];
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to read file: %1', $e->getMessage()));
        }
    }

    /**
     * Format file content for AI consumption
     */
    private function formatContent(string $filePath, array $lines, int $totalLines): string
    {
        $formatted = [];
        $formatted[] = "File: $filePath (showing lines " . $lines[0]['line'] . "-" . end($lines)['line'] . " of $totalLines)";
        $formatted[] = str_repeat('-', 80);

        foreach ($lines as $line) {
            $formatted[] = sprintf("%4d | %s", $line['line'], $line['content']);
        }

        if ($totalLines > count($lines)) {
            $formatted[] = "\n... (" . ($totalLines - count($lines)) . " more lines)";
        }

        return implode("\n", $formatted);
    }
}
