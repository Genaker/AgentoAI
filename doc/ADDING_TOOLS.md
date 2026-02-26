# Adding Tools to the Magento AI Agent

This guide explains how to create a new tool, how the LLM calls it, and what the
full data-flow looks like from user query to answer.

---

## How the LLM Uses Tools — Full Data Flow

```
User command
    │
    ▼
LlmAnalyzer::execute()
    │  Builds tool list (ToolRegistry::getToolsForAI)
    │  Sets system message
    │
    ▼
LlmAnalyzer::processQueryWithService()   ← ReAct loop (up to 8 iterations)
    │
    ▼
CliChatWithToolsService::processQueryWithTools()   ← one iteration
    │
    ├─► MgentoAIService::sendChatRequest()
    │       │  Converts tool definitions to AIAccess format
    │       │  Sends prompt + conversation history + tools → OpenAI Responses API
    │       │
    │       ▼
    │   MultiLLMService::query()
    │       │  Calls AIAccess OpenAI client  →  /v1/responses
    │       │  Extracts tool_calls from response output[] array
    │       │  (function_call items at top level, or tool_use inside content blocks)
    │       │
    │       └─► returns { text, tool_calls, tokens, cost, finish_reason, ... }
    │
    ├─► resolveToolCall()
    │       1. Prefer native tool_calls array from API
    │       2. Fallback: parse JSON {"tool":...,"arguments":...} from text
    │
    ├─► handleToolCall()
    │       │  Looks up tool in ToolRegistry
    │       │  Calls tool->execute($arguments, $allowDangerous)
    │       │  Appends to conversationHistory:
    │       │      user:      "<current query>"
    │       │      assistant: "Tool call: toolName({args})"
    │       │      user:      "Tool result: {json}"
    │       │
    │       └─► returns { status: 'tool_called', tool_name, tool_result, next_query, ... }
    │
    └─► handleFinalAnswer()
            LLM returned text with no tool call → done
            returns { status: 'success', message: "..." }
```

### Infinite-Loop Guards

| Guard | Threshold | What Happens |
|---|---|---|
| Consecutive tool calls | 3 | "Reflection" prompt: synthesize now |
| Repeated identical call | 1 | Immediate reflection |
| Max iterations reached | 5 (chat) / 8 (analyze) | Forced final answer with tools stripped |

---

## Creating a New Tool — Step by Step

### Step 1 — Implement `DatabaseToolInterface`

Create `app/code/Genaker/MagentoMcpAi/Model/DatabaseTool/MyNewTool.php`:

```php
<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;

class MyNewTool implements DatabaseToolInterface
{
    /**
     * Unique identifier — must match the name the LLM will call.
     * Use snake_case, no spaces.
     */
    public function getName(): string
    {
        return 'my_new_tool';
    }

    /**
     * One-sentence description shown to the LLM so it knows when to use the tool.
     * Be precise: the LLM reads this to decide whether to call the tool.
     */
    public function getDescription(): string
    {
        return 'Returns something useful from the Magento installation.';
    }

    /**
     * JSON Schema for the arguments the LLM must pass.
     * Only declare what you actually use — the LLM fills these in.
     */
    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'some_param' => [
                    'type'        => 'string',
                    'description' => 'What this parameter is for',
                ],
            ],
            'required' => ['some_param'],
        ];
    }

    /**
     * Execute the tool.
     *
     * @param  array $arguments   Decoded JSON arguments from the LLM call
     * @param  bool  $allowDangerous  True when --allow-dangerous flag is set
     * @return array Must always return an array. Use 'error' key for failures:
     *               ['error' => 'message'] — displayed in red to the user
     *               ['success' => true, ...]  — passed back to the LLM
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $param = $arguments['some_param'] ?? '';

        try {
            // ... your logic ...
            return [
                'success' => true,
                'result'  => 'Some result for: ' . $param,
                'preview' => 'Short summary shown in CLI output',
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
```

**Interface contract** ([Api/DatabaseToolInterface.php](../Api/DatabaseToolInterface.php)):

| Method | Return type | Purpose |
|---|---|---|
| `getName()` | `string` | Tool identifier — must be unique |
| `getDescription()` | `string` | Shown to the LLM; governs when it calls the tool |
| `getParametersSchema()` | `array` | JSON Schema object the API validates against |
| `execute(array, bool)` | `array` | Run the tool; return structured data |

---

### Step 2 — Register in `di.xml`

Open [`etc/di.xml`](../etc/di.xml) and add two blocks:

**a) Register as a named tool in the registry:**
```xml
<!-- Inside the ToolRegistry virtualType arguments → tools array -->
<item name="my_new_tool" xsi:type="object">
    Genaker\MagentoMcpAi\Model\DatabaseTool\MyNewTool
</item>
```

**b) Inject dependencies (if your tool has constructor arguments):**
```xml
<type name="Genaker\MagentoMcpAi\Model\DatabaseTool\MyNewTool">
    <arguments>
        <argument name="resourceConnection" xsi:type="object">
            Magento\Framework\App\ResourceConnection
        </argument>
    </arguments>
</type>
```

For tools with no constructor arguments beyond what Magento auto-wires, step (b) is optional.

---

### Step 3 — Flush DI Compile Cache

```bash
bin/magento setup:di:compile
bin/magento cache:flush
```

After this the tool appears automatically in the agent — no other changes needed.

---

### Step 4 — Add Display Support (optional)

If your tool returns structured data you want to display in the CLI table view,
add a branch in `LlmAnalyzer::displayToolResults()`:

```php
} elseif ($toolName === 'my_new_tool') {
    if (isset($result['result'])) {
        $output->writeln('<comment>' . $result['result'] . '</comment>');
    }
}
```

---

### Step 5 — Write a Unit Test

Create `Test/Unit/Model/DatabaseTool/MyNewToolTest.php`:

```php
<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Model\DatabaseTool\MyNewTool;
use PHPUnit\Framework\TestCase;

class MyNewToolTest extends TestCase
{
    private MyNewTool $tool;

    protected function setUp(): void
    {
        $this->tool = new MyNewTool(/* inject mocks */);
    }

    public function testGetName(): void
    {
        $this->assertEquals('my_new_tool', $this->tool->getName());
    }

    public function testGetParametersSchema(): void
    {
        $schema = $this->tool->getParametersSchema();
        $this->assertArrayHasKey('some_param', $schema['properties']);
        $this->assertContains('some_param', $schema['required']);
    }

    public function testExecuteReturnsSuccessResult(): void
    {
        $result = $this->tool->execute(['some_param' => 'test'], false);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('result', $result);
    }
}
```

Run with:
```bash
php vendor/bin/phpunit app/code/Genaker/MagentoMcpAi/Test/Unit/ --testdox
```

---

## Tool Return Value Conventions

The `execute()` return array is passed verbatim to the LLM as the tool result.
Follow these conventions so the LLM can understand the output:

| Key | Type | Meaning |
|---|---|---|
| `success` | `bool` | Whether the tool ran without errors |
| `error` | `string` | Error message — triggers red CLI output; LLM sees the failure |
| `preview` | `string` | Short human-readable summary shown in CLI output |
| `data` | `array` | Tabular result rows (displayed as a table in the CLI) |
| `output` | `string` | Free-form text output (e.g. CLI command output) |

You can add any other keys — the full array is JSON-encoded and sent to the LLM.
Keep it concise; the LLM has a token budget.

---

## How Tool Definitions Reach the LLM

`ToolRegistry::getToolsForAI()` converts each registered tool into OpenAI function-calling format:

```json
{
  "type": "function",
  "function": {
    "name": "my_new_tool",
    "description": "Returns something useful from the Magento installation.",
    "parameters": {
      "type": "object",
      "properties": {
        "some_param": { "type": "string", "description": "What this parameter is for" }
      },
      "required": ["some_param"]
    }
  }
}
```

This array is sent in `$options['tools']` to the OpenAI Responses API.
The model reads the descriptions and decides autonomously which tool(s) to call.

---

## Existing Tools Reference

| Tool name | File | Purpose |
|---|---|---|
| `execute_sql_query` | `ExecuteSqlQuery.php` | Run SELECT/DESCRIBE/SHOW against Magento DB |
| `describe_table` | `DescribeTable.php` | Get column structure of any table |
| `grep_files` | `GrepFiles.php` | Search codebase for patterns |
| `read_file` | `ReadFile.php` | Read specific files relative to Magento root |
| `get_magento_info` | `GetMagentoInfo.php` | Baseline snapshot (version, counts, indexers) |
| `run_magento_cli` | `RunMagentoCli.php` | Run whitelisted read-only `bin/magento` commands |
| `ask_user` | `AskUserTool.php` | Pause analysis and ask operator a question |

---

## Security Checklist for New Tools

- [ ] Validate all `$arguments` input before use
- [ ] Respect `$allowDangerous` for any write/destructive operations
- [ ] Use absolute paths only; validate with `realpath()` for file tools
- [ ] Never expose raw stack traces in the return array (the LLM will repeat them)
- [ ] Limit result size — large arrays cost tokens; truncate to ≤ 100 rows / 50 items
