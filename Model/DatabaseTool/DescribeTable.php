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
        return 'Get the structure of a database table (columns, types, keys, etc.). Useful for understanding table schema before writing queries.';
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
                    'type' => 'string',
                    'description' => 'Name of the table to describe (e.g., customer_entity, sales_order)'
                ]
            ],
            'required' => ['table_name']
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $tableName = $arguments['table_name'] ?? '';
        
        if (empty($tableName)) {
            throw new LocalizedException(__('Table name is required'));
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $sqlQuery = "DESCRIBE " . $connection->quoteIdentifier($tableName);
            $results = $connection->fetchAll($sqlQuery);
            
            return [
                'success' => true,
                'table' => $tableName,
                'columns' => $results,
                'preview' => $this->formatSchema($results)
            ];
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to describe table: %1', $e->getMessage()));
        }
    }

    /**
     * Format schema for AI consumption
     */
    private function formatSchema(array $results): string
    {
        if (empty($results)) {
            return "No schema information available.";
        }
        
        $formatted = [];
        
        foreach ($results as $row) {
            $rowStr = '';
            foreach ($row as $key => $value) {
                if ($rowStr) {
                    $rowStr .= ' | ';
                }
                $rowStr .= $key . ': ' . $value;
            }
            $formatted[] = $rowStr;
        }
        
        return implode("\n", $formatted);
    }
}
