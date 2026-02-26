<?php
namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Describe Table Tool
 */
class DescribeTable implements DatabaseToolInterface
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
        return 'describe_table';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Get the structure of a database table (columns, types, nullability, defaults, keys). '
            . 'Use include_keys=true to also retrieve index definitions and foreign key relationships — '
            . 'essential for writing correct JOINs and understanding data relations.';
    }

    /**
     * @inheritDoc
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table_name' => [
                    'type'        => 'string',
                    'description' => 'Name of the table to describe (e.g., customer_entity, sales_order)',
                ],
                'include_keys' => [
                    'type'        => 'boolean',
                    'description' => 'Also return index definitions and foreign key relationships (default: false). '
                        . 'Set to true when you need to understand JOIN columns or data relations.',
                ],
            ],
            'required' => ['table_name'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $tableName   = $arguments['table_name'] ?? '';
        $includeKeys = (bool)($arguments['include_keys'] ?? false);

        if (empty($tableName)) {
            throw new LocalizedException(__('Table name is required'));
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $columns    = $connection->fetchAll('DESCRIBE ' . $connection->quoteIdentifier($tableName));

            $result = [
                'success' => true,
                'table'   => $tableName,
                'columns' => $columns,
                'preview' => $this->formatSchema($columns),
            ];

            if ($includeKeys) {
                $indexes = $connection->fetchAll(
                    'SHOW INDEX FROM ' . $connection->quoteIdentifier($tableName)
                );

                $foreignKeys = $connection->fetchAll(
                    'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ' . $connection->quote($tableName) . '
                       AND REFERENCED_TABLE_NAME IS NOT NULL'
                );

                $result['indexes']      = $indexes;
                $result['foreign_keys'] = $foreignKeys;
                $result['preview']     .= "\n\n" . $this->formatKeys($indexes, $foreignKeys);
            }

            return $result;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to describe table: %1', $e->getMessage()));
        }
    }

    /**
     * Format column schema for AI consumption.
     */
    private function formatSchema(array $results): string
    {
        if (empty($results)) {
            return 'No schema information available.';
        }

        $formatted = [];
        foreach ($results as $row) {
            $parts = [];
            foreach ($row as $key => $value) {
                $parts[] = $key . ': ' . $value;
            }
            $formatted[] = implode(' | ', $parts);
        }

        return implode("\n", $formatted);
    }

    /**
     * Format index and foreign key information for AI consumption.
     */
    private function formatKeys(array $indexes, array $foreignKeys): string
    {
        $lines = [];

        if (!empty($indexes)) {
            // Group index columns by key name for compact display
            $grouped = [];
            foreach ($indexes as $row) {
                $keyName  = $row['Key_name']   ?? '';
                $column   = $row['Column_name'] ?? '';
                $unique   = (($row['Non_unique'] ?? 1) == 0) ? 'UNIQUE' : '';
                $grouped[$keyName]['unique'] = $unique;
                $grouped[$keyName]['cols'][] = $column;
            }
            $lines[] = 'Indexes:';
            foreach ($grouped as $name => $info) {
                $type    = $name === 'PRIMARY' ? 'PRIMARY KEY' : ($info['unique'] ? 'UNIQUE INDEX' : 'INDEX');
                $lines[] = "  {$type} {$name} (" . implode(', ', $info['cols']) . ')';
            }
        }

        if (!empty($foreignKeys)) {
            $lines[] = '';
            $lines[] = 'Foreign Keys:';
            foreach ($foreignKeys as $fk) {
                $lines[] = sprintf(
                    '  %s: %s → %s.%s',
                    $fk['CONSTRAINT_NAME']       ?? '',
                    $fk['COLUMN_NAME']            ?? '',
                    $fk['REFERENCED_TABLE_NAME']  ?? '',
                    $fk['REFERENCED_COLUMN_NAME'] ?? ''
                );
            }
        }

        return $lines ? implode("\n", $lines) : '';
    }
}
