<?php
namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;

/**
 * Grep Tool - Search for patterns in files
 */
class GrepTool implements DatabaseToolInterface
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
        return 'grep_files';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search for text patterns in files. Useful for finding code, configurations, or specific content across the codebase. Supports regex patterns.';
    }

    /**
     * @inheritDoc
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The search pattern (supports regex)'
                ],
                'file_path' => [
                    'type' => 'string',
                    'description' => 'File path or directory to search in (relative to Magento root). Use * for wildcard (e.g., "app/code/**/*.php")'
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default: 50)'
                ]
            ],
            'required' => ['pattern', 'file_path']
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $pattern = $arguments['pattern'] ?? '';
        $filePath = $arguments['file_path'] ?? '';
        $maxResults = (int)($arguments['max_results'] ?? 50);

        if (empty($pattern)) {
            throw new LocalizedException(__('Search pattern is required'));
        }

        if (empty($filePath)) {
            throw new LocalizedException(__('File path is required'));
        }

        try {
            $rootPath = $this->directoryList->getRoot();
            $fullPath = $rootPath . '/' . ltrim($filePath, '/');

            $results = $this->searchFiles($fullPath, $pattern, $maxResults);

            return [
                'success' => true,
                'pattern' => $pattern,
                'file_path' => $filePath,
                'match_count' => count($results),
                'matches' => $results,
                'preview' => $this->formatResults($results)
            ];
        } catch (\Exception $e) {
            throw new LocalizedException(__('Grep search failed: %1', $e->getMessage()));
        }
    }

    /**
     * Search files for pattern
     */
    private function searchFiles(string $path, string $pattern, int $maxResults): array
    {
        $results = [];
        $rootPath = $this->directoryList->getRoot();

        // Handle wildcard patterns
        if (strpos($path, '*') !== false) {
            $files = $this->globFiles($path);
        } elseif (is_file($path)) {
            $files = [$path];
        } elseif (is_dir($path)) {
            $files = $this->getFilesInDirectory($path);
        } else {
            throw new LocalizedException(__('Path not found: %1', $path));
        }

        foreach ($files as $file) {
            if (count($results) >= $maxResults) {
                break;
            }

            if (!is_readable($file) || !is_file($file)) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Use regex if pattern contains special regex characters, otherwise simple search
            $isRegex = $this->isRegexPattern($pattern);
            
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                $matched = false;
                if ($isRegex) {
                    if (@preg_match('/' . $pattern . '/i', $line)) {
                        $matched = true;
                    }
                } else {
                    if (stripos($line, $pattern) !== false) {
                        $matched = true;
                    }
                }
                
                if ($matched) {
                    $relativePath = str_replace($rootPath . '/', '', $file);
                    $results[] = [
                        'file' => $relativePath,
                        'line' => $lineNum + 1,
                        'content' => trim($line)
                    ];
                    if (count($results) >= $maxResults) {
                        break 2;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check if pattern is regex
     */
    private function isRegexPattern(string $pattern): bool
    {
        // Simple check for common regex characters
        $regexChars = ['^', '$', '.', '*', '+', '?', '[', ']', '(', ')', '{', '}', '|', '\\'];
        foreach ($regexChars as $char) {
            if (strpos($pattern, $char) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Glob files matching pattern
     */
    private function globFiles(string $pattern): array
    {
        $files = [];
        
        // Convert ** to recursive glob
        if (strpos($pattern, '**') !== false) {
            $pattern = str_replace('**', '*', $pattern);
        }
        
        $matches = glob($pattern);
        if ($matches) {
            foreach ($matches as $match) {
                if (is_file($match)) {
                    $files[] = $match;
                }
            }
        }
        
        return $files;
    }

    /**
     * Get all files in directory recursively
     */
    private function getFilesInDirectory(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Format results for AI consumption
     */
    private function formatResults(array $results): string
    {
        if (empty($results)) {
            return "No matches found.";
        }

        $formatted = [];
        $formatted[] = "Found " . count($results) . " matches:\n";

        foreach ($results as $result) {
            $formatted[] = sprintf(
                "%s:%d - %s",
                $result['file'],
                $result['line'],
                $result['content']
            );
        }

        return implode("\n", $formatted);
    }
}
