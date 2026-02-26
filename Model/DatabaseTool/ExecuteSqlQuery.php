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
    /** Valid SHOW subcommands (MySQL/MariaDB) */
    private const SHOW_VALID = 'TABLES|COLUMNS|CREATE|INDEX|INDEXES|STATUS|VARIABLES|DATABASES|DATABASE|ENGINES|GRANTS|PROCESSLIST|TRIGGERS|WARNINGS|ERRORS|TABLE\s+STATUS|FULL\s+COLUMNS';

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
        return 'Execute a single SQL statement against the Magento database. '
            . 'Pass ONLY the SQL (SELECT, DESCRIBE, SHOW). Do NOT pass explanations, examples, or documentation.';
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
                    'description' => 'Exactly one SQL statement starting with SELECT, DESCRIBE, or SHOW. No explanations or examples.'
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

        // Reject non-SQL input; if SQL is embedded in text, extract it
        $trimmed = trim($sqlQuery);
        if (preg_match('/^show\s+\d/i', $trimmed)) {
            return [
                'success' => false,
                'error' => 'Not a valid SQL statement. "show" must be followed by TABLES, COLUMNS, etc.—not numbers or descriptions.',
            ];
        }
        $looksLikeSql = (bool) preg_match('/^(SELECT|DESCRIBE|DESC|EXPLAIN)\s/i', $trimmed)
            || preg_match('/^SHOW\s+(' . self::SHOW_VALID . ')\b/i', $trimmed)
            || preg_match('/^(INSERT|UPDATE|DELETE|DROP)\s/i', $trimmed);
        $looksLikeDoc = (bool) preg_match(
            '/\b(Option [AB]|Example SQL|What you|Notes? and|columns? returned|per-customer|Sort by|Filter by|A subset|several with|total revenue)\b|'
            . '\n\s*\d+\)\s|\d+\s+order|\bshow\s+\d/i',
            $sqlQuery
        );

        if (!$looksLikeSql || $looksLikeDoc) {
            $extracted = $this->extractSqlFromText($sqlQuery, $allowDangerous);
            if ($extracted !== null) {
                $sqlQuery = $extracted;
            } else {
                return [
                    'success' => false,
                    'error' => 'Not a valid SQL statement. The query parameter must start with SELECT, DESCRIBE, or SHOW. '
                        . 'You passed explanations or data—do not use execute_sql_query for that. '
                        . 'Respond to the user in natural language instead. Do not call this tool again.',
                ];
            }
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
            $msg = $e->getMessage();
            return [
                'success' => false,
                'error' => $msg . ' Fix the query (use describe_table to verify column names) and try again.',
            ];
        }
    }

    /**
     * Extract first SQL statement from text that may contain explanations/examples.
     */
    private function extractSqlFromText(string $text, bool $allowDangerous): ?string
    {
        $keywords = ['SELECT', 'DESCRIBE', 'DESC', 'SHOW', 'EXPLAIN'];
        if ($allowDangerous) {
            $keywords = array_merge($keywords, ['INSERT', 'UPDATE', 'DELETE']);
        }
        // Match from SQL keyword until ; or \n\n (next section) or end
        if (preg_match('/\b(' . implode('|', $keywords) . ')\s+[\s\S]+?(?=;|\n\n|$)/i', $text, $m)) {
            $sql = trim($m[0], " \t\n\r;");
            $rejectPattern = '/\b(Option [AB]|Example SQL|What you|per-customer|Sort by|Filter by|A subset|several with|total revenue)\b|\n\s*\d+\)\s|\d+\s+order|\bshow\s+\d/i';
            if (strlen($sql) <= 2000 && !preg_match($rejectPattern, $sql)) {
                if (preg_match('/^SHOW\s/i', $sql) && !preg_match('/^SHOW\s+(' . self::SHOW_VALID . ')\b/i', $sql)) {
                    return null;
                }
                return $sql;
            }
        }
        return null;
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
