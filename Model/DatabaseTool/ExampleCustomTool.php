<?php
namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Example Custom Tool
 * 
 * This is an example showing how to create a custom database tool.
 * Other modules can create similar tools and register them via di.xml
 */
class ExampleCustomTool implements DatabaseToolInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'show_tables';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List all tables in the Magento database. Useful for discovering available tables.';
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
                    'description' => 'Optional table name pattern (e.g., "customer%" to find customer-related tables)'
                ]
            ],
            'required' => []
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        // Example implementation - this would need ResourceConnection injected
        // For now, just return a placeholder
        return [
            'success' => true,
            'message' => 'This is an example custom tool. Implement your own logic here.',
            'data' => []
        ];
    }
}
