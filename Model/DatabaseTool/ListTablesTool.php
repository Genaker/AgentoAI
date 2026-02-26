<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * List Tables Tool
 *
 * Discovers database tables matching a LIKE pattern and optionally returns
 * approximate row counts. Critical for self-correction when the LLM does not
 * know the exact table name (e.g., after a "Table not found" SQL error).
 */
class ListTablesTool implements DatabaseToolInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function getName(): string
    {
        return 'list_tables';
    }

    public function getDescription(): string
    {
        return 'List database tables matching a SQL LIKE pattern, with approximate row counts. '
            . 'Use this when you do not know the exact table name or want to discover related tables. '
            . 'Pattern examples: "sales_%" lists all sales tables, "%customer%" finds customer-related tables, '
            . '"%" lists every table in the database.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type'        => 'string',
                    'description' => 'SQL LIKE pattern to filter table names. Use % as wildcard (e.g., "sales_%", "%order%", "%"). Required.'
                ],
                'include_row_counts' => [
                    'type'        => 'boolean',
                    'description' => 'Include approximate row counts from information_schema (default: true). Set to false for faster results when listing many tables.'
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $pattern          = $arguments['pattern'] ?? '%';
        $includeRowCounts = $arguments['include_row_counts'] ?? true;

        if ($pattern === '') {
            $pattern = '%';
        }

        try {
            $connection = $this->resourceConnection->getConnection();

            $tables = $connection->fetchCol(
                $connection->quoteInto('SHOW TABLES LIKE ?', $pattern)
            );

            if (empty($tables)) {
                return [
                    'success' => true,
                    'tables'  => [],
                    'count'   => 0,
                    'preview' => "No tables found matching pattern: {$pattern}",
                ];
            }

            $result = [];

            // Row counts from information_schema are approximate but instant.
            // Skip when there are many tables to avoid a heavy IN() list.
            if ($includeRowCounts && count($tables) <= 200) {
                $tableList = implode(',', array_map(
                    fn($t) => $connection->quote($t),
                    $tables
                ));
                $rowCounts = $connection->fetchPairs(
                    "SELECT TABLE_NAME, TABLE_ROWS
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME IN ({$tableList})"
                );
                foreach ($tables as $table) {
                    $result[] = ['table' => $table, 'rows' => (int)($rowCounts[$table] ?? 0)];
                }
            } else {
                foreach ($tables as $table) {
                    $result[] = ['table' => $table];
                }
            }

            $lines = array_map(
                fn($r) => isset($r['rows'])
                    ? sprintf('%-50s (~%s rows)', $r['table'], number_format($r['rows']))
                    : $r['table'],
                $result
            );

            return [
                'success' => true,
                'tables'  => $result,
                'count'   => count($result),
                'preview' => "Tables matching '{$pattern}' (" . count($result) . " found):\n"
                    . implode("\n", $lines),
            ];
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to list tables: %1', $e->getMessage()));
        }
    }
}
