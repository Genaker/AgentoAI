<?php
namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Execute SQL Query Tool
 */
class ExecuteSqlQuery implements DatabaseToolInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'execute_sql_query';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Execute a SQL query against the Magento database. Supports SELECT, DESCRIBE, SHOW queries. Write operations (INSERT, UPDATE, DELETE) require dangerous mode.';
    }

    /**
     * @inheritDoc
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The SQL query to execute (SELECT, DESCRIBE, SHOW, etc.)'
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Why you need to execute this query'
                ]
            ],
            'required' => ['query', 'reason']
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $sqlQuery = $arguments['query'] ?? '';
        
        if (empty($sqlQuery)) {
            throw new LocalizedException(__('SQL query is required'));
        }

        // Strip trailing semicolon — Magento DB adapter rejects it as "multiple queries"
        $sqlQuery = rtrim($sqlQuery, " \t\n\r\0\x0B;");

        // Validate query permissions
        $this->validateQuery($sqlQuery, $allowDangerous);

        // Execute query
        try {
            $connection = $this->resourceConnection->getConnection();

            // Limit results to 100 rows for safety
            if (stripos($sqlQuery, 'LIMIT') === false && stripos($sqlQuery, 'SELECT') === 0) {
                $sqlQuery .= ' LIMIT 100';
            }

            $results = $connection->fetchAll($sqlQuery);
            
            return [
                'success' => true,
                'row_count' => count($results),
                'columns' => !empty($results) ? array_keys($results[0]) : [],
                'data' => $results,
                'preview' => $this->formatResults($results)
            ];
        } catch (\Exception $e) {
            throw new LocalizedException(__('Query execution failed: %1', $e->getMessage()));
        }
    }

    /**
     * Validate query permissions
     */
    private function validateQuery(string $sqlQuery, bool $allowDangerous): void
    {
        $sqlUpper = strtoupper(trim($sqlQuery));

        // Check for dangerous operations
        $dangerousOps = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER'];
        foreach ($dangerousOps as $op) {
            if (stripos($sqlUpper, $op) === 0) {
                if (!$allowDangerous) {
                    throw new LocalizedException(
                        __($op . ' operations not allowed. Use --allow-dangerous flag to enable.')
                    );
                }
            }
        }

        // Allow safe read operations: SELECT, DESCRIBE, SHOW, EXPLAIN
        $safeOps = ['SELECT', 'DESCRIBE', 'SHOW', 'EXPLAIN', 'DESC'];
        $isSafeOp = false;
        
        foreach ($safeOps as $op) {
            if (stripos($sqlUpper, $op) === 0) {
                $isSafeOp = true;
                break;
            }
        }

        // Enforce safe operations in safe mode
        if (!$allowDangerous && !$isSafeOp) {
            throw new LocalizedException(
                __('Only SELECT, DESCRIBE, SHOW, and EXPLAIN queries allowed in safe mode.')
            );
        }
    }

    /**
     * Format results for AI consumption
     */
    private function formatResults(array $results): string
    {
        if (empty($results)) {
            return "No results found.";
        }

        $formatted = [];
        $headers = array_keys($results[0]);
        $formatted[] = implode(' | ', $headers);
        $formatted[] = str_repeat('-', 80);

        // Limit to first 10 rows for AI context
        $previewRows = array_slice($results, 0, 10);
        foreach ($previewRows as $row) {
            $formatted[] = implode(' | ', array_values($row));
        }

        if (count($results) > 10) {
            $formatted[] = '... and ' . (count($results) - 10) . ' more rows';
        }

        return implode("\n", $formatted);
    }
}
