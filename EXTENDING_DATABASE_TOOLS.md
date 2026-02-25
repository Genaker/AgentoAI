# Extending Database Tools

The `LlmAnalyzer` CLI command (`genaker:agento:llm`) supports extensible tools via Magento's Dependency Injection system. You can add custom tools by implementing the `DatabaseToolInterface` and registering them in `di.xml`.

## Creating a Custom Tool

### Step 1: Implement DatabaseToolInterface

Create a new class that implements `Genaker\MagentoMcpAi\Api\DatabaseToolInterface`:

```php
<?php
namespace YourVendor\YourModule\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\Exception\LocalizedException;

class YourCustomTool implements DatabaseToolInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'your_tool_name';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Description of what your tool does for the AI';
    }

    /**
     * @inheritDoc
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'param1' => [
                    'type' => 'string',
                    'description' => 'Description of parameter'
                ]
            ],
            'required' => ['param1']
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        // Your tool logic here
        return [
            'success' => true,
            'data' => [],
            'preview' => 'Formatted preview for AI'
        ];
    }
}
```

### Step 2: Register in di.xml

Add your tool to the registry in your module's `etc/di.xml`:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    
    <!-- Register your custom tool -->
    <type name="Genaker\MagentoMcpAi\Model\DatabaseTool\Registry">
        <arguments>
            <argument name="tools" xsi:type="array">
                <item name="your_tool_name" xsi:type="object">YourVendor\YourModule\Model\DatabaseTool\YourCustomTool</item>
            </argument>
        </arguments>
    </type>
    
    <!-- Configure your tool's dependencies -->
    <type name="YourVendor\YourModule\Model\DatabaseTool\YourCustomTool">
        <arguments>
            <argument name="dependency" xsi:type="object">Magento\Framework\Some\Dependency</argument>
        </arguments>
    </type>
</config>
```

## Example: Show Tables Tool

```php
<?php
namespace YourVendor\YourModule\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class ShowTablesTool implements DatabaseToolInterface
{
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function getName(): string
    {
        return 'show_tables';
    }

    public function getDescription(): string
    {
        return 'List all tables in the database, optionally filtered by pattern';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Table name pattern (e.g., "customer%")'
                ]
            ],
            'required' => []
        ];
    }

    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $pattern = $arguments['pattern'] ?? '';
        $connection = $this->resourceConnection->getConnection();
        
        $sql = "SHOW TABLES";
        if ($pattern) {
            $sql .= " LIKE " . $connection->quote($pattern);
        }
        
        $results = $connection->fetchAll($sql);
        
        return [
            'success' => true,
            'tables' => array_column($results, 'Tables_in_' . $connection->getSchemaName()),
            'preview' => implode("\n", array_column($results, 'Tables_in_' . $connection->getSchemaName()))
        ];
    }
}
```

## Tool Naming Conventions

- Use lowercase with underscores: `your_tool_name`
- Be descriptive: `show_tables`, `analyze_query`, `export_data`
- Dangerous tools (write operations) should include keywords like `insert`, `update`, `delete` in the name so they're automatically filtered in safe mode

## Tool Return Format

Your tool's `execute()` method should return:

```php
[
    'success' => true,           // boolean
    'data' => [...],             // array of results
    'preview' => '...',          // string formatted for AI
    'row_count' => 10,           // optional: number of rows
    'columns' => [...],          // optional: column names
    // ... any other metadata
]
```

On error, throw a `LocalizedException` or return:

```php
['error' => 'Error message']
```

## Default Tools

The module includes two default tools:

1. **execute_sql_query** - Execute SQL queries (SELECT, DESCRIBE, SHOW, etc.)
2. **describe_table** - Get table structure

These are registered automatically and can be extended or replaced.
