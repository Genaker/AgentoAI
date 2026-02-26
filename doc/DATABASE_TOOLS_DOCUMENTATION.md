# Database Tools Documentation

## Overview

The `LlmAnalyzer` CLI command (`genaker:agento:llm`) uses an extensible tool-calling architecture that allows AI to interact with your Magento database, search files, read code, and perform various operations. Tools are registered via Magento's Dependency Injection system, making it easy to add custom operations.

## Architecture

```
User Query
    ↓
AI analyzes query and selects tool(s)
    ↓
Tool executes (SQL, DESCRIBE, custom operations)
    ↓
Results returned to AI
    ↓
AI provides final answer
```

## Default Tools

The module includes four default tools:

### 1. `execute_sql_query`

Executes SQL queries against the Magento database.

**Parameters:**
- `query` (string, required) - SQL query to execute
- `reason` (string, required) - Why this query is needed

**Example:**
```json
{
  "tool": "execute_sql_query",
  "arguments": {
    "query": "SELECT * FROM customer_entity WHERE email LIKE '%test%' LIMIT 10",
    "reason": "Find customers with test emails"
  }
}
```

**Returns:**
```php
[
    'success' => true,
    'row_count' => 10,
    'columns' => ['entity_id', 'email', 'firstname'],
    'data' => [...],
    'preview' => 'Formatted preview for AI'
]
```

### 2. `describe_table`

Gets the structure of a database table.

**Parameters:**
- `table_name` (string, required) - Name of the table

**Example:**
```json
{
  "tool": "describe_table",
  "arguments": {
    "table_name": "sales_order"
  }
}
```

**Returns:**
```php
[
    'success' => true,
    'table' => 'sales_order',
    'columns' => [
        ['Field' => 'entity_id', 'Type' => 'int', 'Key' => 'PRI'],
        ['Field' => 'customer_id', 'Type' => 'int', 'Key' => 'MUL']
    ],
    'preview' => 'Field: entity_id | Type: int | Key: PRI'
]
```

### 3. `grep_files`

Searches for text patterns in files. Useful for finding code, configurations, or specific content across the codebase.

**Parameters:**
- `pattern` (string, required) - The search pattern (supports regex)
- `file_path` (string, required) - File path or directory to search in (relative to Magento root). Use * for wildcard (e.g., "app/code/**/*.php")
- `max_results` (integer, optional) - Maximum number of results to return (default: 50)

**Example:**
```json
{
  "tool": "grep_files",
  "arguments": {
    "pattern": "class Customer",
    "file_path": "app/code/**/*.php",
    "max_results": 20
  }
}
```

**Returns:**
```php
[
    'success' => true,
    'pattern' => 'class Customer',
    'file_path' => 'app/code/**/*.php',
    'match_count' => 5,
    'matches' => [
        ['file' => 'app/code/Vendor/Module/Model/Customer.php', 'line' => 10, 'content' => 'class Customer extends AbstractModel'],
        ...
    ],
    'preview' => 'Found 5 matches:\napp/code/Vendor/Module/Model/Customer.php:10 - class Customer extends AbstractModel'
]
```

### 4. `read_file`

Reads the contents of a file. Useful for examining code, configurations, or documentation.

**Parameters:**
- `file_path` (string, required) - Path to the file (relative to Magento root)
- `start_line` (integer, optional) - Start reading from this line number (1-indexed)
- `end_line` (integer, optional) - End reading at this line number (1-indexed)
- `max_lines` (integer, optional) - Maximum number of lines to read (default: 500)

**Example:**
```json
{
  "tool": "read_file",
  "arguments": {
    "file_path": "app/code/Vendor/Module/Model/Customer.php",
    "start_line": 1,
    "end_line": 50
  }
}
```

**Returns:**
```php
[
    'success' => true,
    'file_path' => 'app/code/Vendor/Module/Model/Customer.php',
    'total_lines' => 200,
    'lines_read' => 50,
    'start_line' => 1,
    'end_line' => 50,
    'content' => [
        ['line' => 1, 'content' => '<?php'],
        ['line' => 2, 'content' => 'namespace Vendor\\Module\\Model;'],
        ...
    ],
    'preview' => 'File: app/code/Vendor/Module/Model/Customer.php (showing lines 1-50 of 200)\n...'
]
```

## Creating Custom Tools

### Step 1: Implement DatabaseToolInterface

Create a class implementing `Genaker\MagentoMcpAi\Api\DatabaseToolInterface`:

```php
<?php
namespace YourVendor\YourModule\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ResourceConnection;

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
        return 'List all tables in the database, optionally filtered by pattern. Useful for discovering available tables.';
    }

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

    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $pattern = $arguments['pattern'] ?? '';
        $connection = $this->resourceConnection->getConnection();
        
        $sql = "SHOW TABLES";
        if ($pattern) {
            $sql .= " LIKE " . $connection->quote($pattern);
        }
        
        $results = $connection->fetchAll($sql);
        $tables = array_column($results, 'Tables_in_' . $connection->getSchemaName());
        
        return [
            'success' => true,
            'tables' => $tables,
            'count' => count($tables),
            'preview' => implode("\n", array_slice($tables, 0, 20))
        ];
    }
}
```

### Step 2: Register Tool in di.xml

Add your tool to the registry in your module's `etc/di.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    
    <!-- Register your custom tool -->
    <type name="Genaker\MagentoMcpAi\Model\DatabaseTool\Registry">
        <arguments>
            <argument name="tools" xsi:type="array">
                <item name="show_tables" xsi:type="object">YourVendor\YourModule\Model\DatabaseTool\ShowTablesTool</item>
            </argument>
        </arguments>
    </type>
    
    <!-- Configure your tool's dependencies -->
    <type name="YourVendor\YourModule\Model\DatabaseTool\ShowTablesTool">
        <arguments>
            <argument name="resourceConnection" xsi:type="object">Magento\Framework\App\ResourceConnection</argument>
        </arguments>
    </type>
</config>
```

### Step 3: Clear Cache

After registering your tool:

```bash
bin/magento cache:clean config
rm -rf generated/code/*
```

## Tool Interface Reference

### DatabaseToolInterface

```php
interface DatabaseToolInterface
{
    /**
     * Get unique tool name (lowercase with underscores)
     * @return string
     */
    public function getName(): string;

    /**
     * Get tool description for AI
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get OpenAPI-style JSON schema for parameters
     * @return array
     */
    public function getParametersSchema(): array;

    /**
     * Execute the tool
     * 
     * @param array $arguments Tool arguments from AI
     * @param bool $allowDangerous Whether dangerous operations are allowed
     * @return array Result with 'success', 'data', 'preview', etc.
     * @throws \Exception
     */
    public function execute(array $arguments, bool $allowDangerous = false): array;
}
```

## Tool Return Format

Your tool's `execute()` method should return:

```php
[
    'success' => true,           // boolean - required
    'data' => [...],             // array - your actual results
    'preview' => '...',          // string - formatted for AI consumption
    'row_count' => 10,           // int - optional: number of rows
    'columns' => [...],          // array - optional: column names
    // ... any other metadata
]
```

**On Error:**
Throw a `LocalizedException` or return:
```php
['error' => 'Error message']
```

## Dangerous Tools

Tools that perform write operations should include dangerous keywords in their name:
- `insert_*`
- `update_*`
- `delete_*`
- `drop_*`
- `truncate_*`
- `alter_*`

These tools are automatically filtered out in safe mode (default). They only appear when `--allow-dangerous` flag is used.

**Example Dangerous Tool:**
```php
public function getName(): string
{
    return 'update_customer_email'; // Contains "update" - will be filtered in safe mode
}
```

## Examples

### Example 1: Show Tables Tool

```php
<?php
namespace YourVendor\YourModule\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ResourceConnection;

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
        return 'List all database tables, optionally filtered by pattern';
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
        $schemaName = $connection->getSchemaName();
        $tables = array_column($results, 'Tables_in_' . $schemaName);
        
        return [
            'success' => true,
            'tables' => $tables,
            'count' => count($tables),
            'preview' => implode("\n", array_slice($tables, 0, 20))
        ];
    }
}
```

### Example 2: Analyze Query Performance Tool

```php
<?php
namespace YourVendor\YourModule\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ResourceConnection;

class ExplainQueryTool implements DatabaseToolInterface
{
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function getName(): string
    {
        return 'explain_query';
    }

    public function getDescription(): string
    {
        return 'Analyze SQL query execution plan to understand performance';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'SQL query to analyze'
                ]
            ],
            'required' => ['query']
        ];
    }

    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $query = $arguments['query'] ?? '';
        if (empty($query)) {
            throw new \Exception('Query is required');
        }

        $connection = $this->resourceConnection->getConnection();
        $explainQuery = "EXPLAIN " . $query;
        $results = $connection->fetchAll($explainQuery);
        
        return [
            'success' => true,
            'execution_plan' => $results,
            'preview' => $this->formatExplainResults($results)
        ];
    }

    private function formatExplainResults(array $results): string
    {
        // Format EXPLAIN results for AI
        $formatted = [];
        foreach ($results as $row) {
            $formatted[] = sprintf(
                "Table: %s | Type: %s | Rows: %s | Key: %s",
                $row['table'] ?? '',
                $row['type'] ?? '',
                $row['rows'] ?? '',
                $row['key'] ?? ''
            );
        }
        return implode("\n", $formatted);
    }
}
```

### Example 3: Dangerous Tool (Write Operation)

```php
<?php
namespace YourVendor\YourModule\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class UpdateCustomerTool implements DatabaseToolInterface
{
    private $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function getName(): string
    {
        return 'update_customer'; // Contains "update" - dangerous tool
    }

    public function getDescription(): string
    {
        return 'Update customer information (REQUIRES --allow-dangerous flag)';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Customer ID'
                ],
                'field' => [
                    'type' => 'string',
                    'description' => 'Field to update'
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'New value'
                ]
            ],
            'required' => ['customer_id', 'field', 'value']
        ];
    }

    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        if (!$allowDangerous) {
            throw new LocalizedException(
                __('This tool requires --allow-dangerous flag')
            );
        }

        $customerId = $arguments['customer_id'] ?? null;
        $field = $arguments['field'] ?? '';
        $value = $arguments['value'] ?? '';

        if (!$customerId || !$field) {
            throw new LocalizedException(__('customer_id and field are required'));
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName = $connection->getTableName('customer_entity');
        
        $connection->update(
            $tableName,
            [$field => $value],
            ['entity_id = ?' => $customerId]
        );

        return [
            'success' => true,
            'message' => "Updated customer $customerId field $field",
            'affected_rows' => 1
        ];
    }
}
```

## Tool Registration in di.xml

### Basic Registration

```xml
<type name="Genaker\MagentoMcpAi\Model\DatabaseTool\Registry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="your_tool_name" xsi:type="object">YourVendor\YourModule\Model\DatabaseTool\YourTool</item>
        </argument>
    </arguments>
</type>
```

### Multiple Tools from Same Module

```xml
<type name="Genaker\MagentoMcpAi\Model\DatabaseTool\Registry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="show_tables" xsi:type="object">YourVendor\YourModule\Model\DatabaseTool\ShowTablesTool</item>
            <item name="explain_query" xsi:type="object">YourVendor\YourModule\Model\DatabaseTool\ExplainQueryTool</item>
            <item name="analyze_indexes" xsi:type="object">YourVendor\YourModule\Model\DatabaseTool\AnalyzeIndexesTool</item>
        </argument>
    </arguments>
</type>
```

### Tool with Dependencies

```xml
<!-- Register tool -->
<type name="Genaker\MagentoMcpAi\Model\DatabaseTool\Registry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="custom_tool" xsi:type="object">YourVendor\YourModule\Model\DatabaseTool\CustomTool</item>
        </argument>
    </arguments>
</type>

<!-- Configure tool dependencies -->
<type name="YourVendor\YourModule\Model\DatabaseTool\CustomTool">
    <arguments>
        <argument name="resourceConnection" xsi:type="object">Magento\Framework\App\ResourceConnection</argument>
        <argument name="logger" xsi:type="object">Psr\Log\LoggerInterface</argument>
        <argument name="customService" xsi:type="object">YourVendor\YourModule\Service\CustomService</argument>
    </arguments>
</type>
```

## Tool Naming Conventions

- Use lowercase with underscores: `show_tables`, `explain_query`
- Be descriptive: `analyze_query_performance`, `export_table_data`
- Dangerous tools: Include keywords (`insert`, `update`, `delete`, etc.) in name

## Best Practices

1. **Validate Input**: Always validate and sanitize tool arguments
2. **Error Handling**: Throw `LocalizedException` with clear error messages
3. **Security**: Check `$allowDangerous` flag for write operations
4. **Preview Format**: Provide a concise preview string for AI context
5. **Limit Results**: For large datasets, limit results in preview (full data still returned)
6. **Documentation**: Write clear descriptions - AI uses these to decide which tool to call

## Testing Tools

Test your custom tool:

```php
<?php
namespace YourVendor\YourModule\Test\Integration\Model\DatabaseTool;

use PHPUnit\Framework\TestCase;
use YourVendor\YourModule\Model\DatabaseTool\YourTool;

class YourToolTest extends TestCase
{
    public function testToolExecution(): void
    {
        $tool = $this->createMock(YourTool::class);
        // Test your tool logic
    }
}
```

## Usage Examples

### CLI Usage

```bash
# Basic query (uses tools automatically)
bin/magento genaker:agento:llm "show me all customers"

# With dangerous operations
bin/magento genaker:agento:llm "update customer email" --allow-dangerous
```

### How AI Uses Tools

1. User asks: "What tables contain customer data?"
2. AI calls: `describe_table("customer_entity")`
3. Tool returns: Table structure
4. AI calls: `show_tables({"pattern": "customer%"})`
5. Tool returns: List of customer-related tables
6. AI provides: Final answer with table names

## Troubleshooting

### Tool Not Appearing

1. Check `di.xml` registration
2. Clear cache: `bin/magento cache:clean config`
3. Clear generated code: `rm -rf generated/code/*`
4. Verify tool implements `DatabaseToolInterface`

### Tool Not Executing

1. Check tool name matches exactly
2. Verify parameters match schema
3. Check tool's `execute()` method for exceptions
4. Review command output for error messages

### Dangerous Tool Not Available

- Ensure `--allow-dangerous` flag is used
- Check tool name contains dangerous keyword
- Verify `isDangerousTool()` logic in Registry

## API Reference

### Registry Methods

```php
// Get all tools formatted for AI
$tools = $registry->getToolsForAI($allowDangerous);

// Get specific tool
$tool = $registry->getTool('tool_name');

// Get all registered tools
$allTools = $registry->getTools();
```

### Tool Execution Flow

1. AI receives tool definitions via `getToolsForAI()`
2. AI decides to call a tool
3. Command parses tool call from AI response
4. Command gets tool from registry: `getTool($name)`
5. Command executes: `$tool->execute($arguments, $allowDangerous)`
6. Results displayed and sent back to AI
7. AI uses results to provide final answer

## See Also

- `EXTENDING_DATABASE_TOOLS.md` - Quick start guide
- `MAGENTO_AI_QUERY_ANALYZER.md` - CLI command documentation
- Default tool implementations in `Model/DatabaseTool/`
