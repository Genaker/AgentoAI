# Tools and LLM — How the AI Agent Works

This document describes how the Magento MCP AI module integrates Large Language Models (LLMs) with executable tools, enabling the AI to query databases, search files, run CLI commands, and produce answers from real Magento data.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [The ReAct Agent Loop](#the-react-agent-loop)
3. [How Tools Are Exposed to the LLM](#how-tools-are-exposed-to-the-llm)
4. [Tool Call Resolution](#tool-call-resolution)
5. [Conversation Flow](#conversation-flow)
6. [All Available Tools](#all-available-tools)
7. [System Prompts and Instructions](#system-prompts-and-instructions)
8. [Security and Guards](#security-and-guards)
9. [Extending with Custom Tools](#extending-with-custom-tools)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         User / CLI / Web                                 │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  LlmAnalyzer / MagentoAnalyzer (CLI)                                     │
│  - Manages iteration loop (max 5 chat / 8 analyzer)                      │
│  - Handles reflection / forced-answer when limit reached                │
│  - Displays tool results and final answer                                │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  CliChatWithToolsService                                                 │
│  - Single iteration: build prompt, call AI, parse response              │
│  - If tool call → execute tool, append result to history, return         │
│  - If text answer → return success with message                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
              ┌─────────────────────┼─────────────────────┐
              ▼                     ▼                     ▼
┌──────────────────┐  ┌──────────────────────┐  ┌─────────────────────────┐
│  AIService       │  │  Tool Registry       │  │  MgentoAIService        │
│  (Interface)     │  │  getToolsForAI()     │  │  sendChatRequest()      │
└──────────────────┘  └──────────────────────┘  └─────────────────────────┘
              │                     │                     │
              ▼                     ▼                     ▼
┌──────────────────┐  ┌──────────────────────┐  ┌─────────────────────────┐
│  MultiLLMService │  │  ExecuteSqlQuery     │  │  OpenAI / Claude /      │
│  (ai-access)     │  │  DescribeTable       │  │  Gemini / etc.          │
└──────────────────┘  │  GrepTool            │  └─────────────────────────┘
                      │  ReadFileTool        │
                      │  GetMagentoInfo      │
                      │  RunMagentoCli       │
                      │  AskUserTool         │
                      └──────────────────────┘
```

---

## The ReAct Agent Loop

The agent uses a **ReAct** (Reasoning + Acting) pattern: the LLM reasons about what to do, acts by calling tools, observes results, and either calls more tools or produces a final answer.

### Iteration Flow

1. **User asks a question** (e.g., "How many customers do we have?")
2. **LlmAnalyzer** passes the question and conversation history to **CliChatWithToolsService**
3. **CliChatWithToolsService** builds:
   - System message (tool instructions, Magento context)
   - Conversation history (prior turns + tool results)
   - User prompt (current question)
4. **AI service** sends the request to the LLM with tool definitions (OpenAI function-calling format)
5. **LLM responds** with either:
   - **Tool call**: `{"tool": "execute_sql_query", "arguments": {"query": "SELECT COUNT(*) FROM customer_entity", "reason": "..."}}`
   - **Final answer**: Plain text (e.g., "You have 105,993 customers.")
6. If tool call:
   - Tool is executed (e.g., SQL runs, results returned)
   - Result is appended to conversation history as `user` message: `Tool result: {...}`
   - Loop continues with the **same user question** (so the LLM sees the new data and can answer or call more tools)
7. If final answer:
   - Message is displayed to the user
   - Loop ends

### Max Iterations and Reflection

- **Chat mode**: 5 iterations (configurable via `magentomcpai/general/max_iterations`)
- **Analyzer mode**: 8 iterations

**Guards against infinite loops:**

- **Reflection prompt**: After 3 consecutive tool calls (or a repeated identical call), the agent injects: *"You have now used N tools. Please synthesize your findings and provide your best answer. If you still need one critical piece of data you may use one more tool, otherwise provide your final answer now."*
- **Max iterations**: When the limit is reached, a forced final-answer request is sent (no tools) so the operator always gets output.

---

## How Tools Are Exposed to the LLM

Tools are converted to **OpenAI function-calling format** by `Registry::getToolsForAI()`:

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'execute_sql_query',
        'description' => 'Execute a SQL query against the Magento database...',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => '...'],
                'reason' => ['type' => 'string', 'description' => '...']
            ],
            'required' => ['query', 'reason']
        ]
    ]
]
```

The LLM receives:

- **System message**: Instructions on when to use tools (e.g., "Use execute_sql_query when the question requires database data")
- **Conversation history**: Prior user/assistant messages and tool results
- **Tool definitions**: Array of function schemas
- **User prompt**: Current question

The LLM then either returns a tool call (structured or JSON in text) or a natural-language answer.

---

## Tool Call Resolution

The service supports two ways the LLM can request a tool:

### 1. Native API Tool Calls

When the LLM provider supports structured tool calls (e.g., OpenAI `tool_calls`), the response includes:

```json
{
  "tool_calls": [
    {
      "name": "execute_sql_query",
      "arguments": {"query": "SELECT COUNT(*) FROM customer_entity", "reason": "Count customers"}
    }
  ]
}
```

### 2. Text-JSON Fallback

For providers that return plain text, the service parses JSON from the response:

```json
{"tool": "execute_sql_query", "arguments": {"query": "SELECT COUNT(*) FROM customer_entity", "reason": "Count customers"}}
```

If no JSON is found, it falls back to detecting bare SQL statements (e.g., `SELECT ...`) and wraps them in `execute_sql_query`.

---

## Conversation Flow

### Example: "How many customers do we have?"

| Step | Role       | Content |
|------|------------|---------|
| 1    | system     | You are a Magento 2 AI assistant in read-only mode. Use execute_sql_query when... |
| 2    | user       | How many customers do we have? |
| 3    | assistant  | Tool call: execute_sql_query({"query":"SELECT COUNT(*) AS cnt FROM customer_entity","reason":"..."}) |
| 4    | user       | Tool result: {"success":true,"row_count":1,"data":[{"cnt":"105993"}],...} |
| 5    | (user prompt again) | How many customers do we have? |
| 6    | assistant  | You have 105,993 customers. |

The key point: after a tool call, the **same user question** is sent again so the LLM sees the tool result and can answer. The conversation history is updated with:

- `user`: original question
- `assistant`: tool call
- `user`: tool result

---

## All Available Tools

### 1. `execute_sql_query`

Executes SQL against the Magento database.

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `query`   | string | Yes      | SQL query (SELECT, DESCRIBE, SHOW). Trailing semicolons are stripped. |
| `reason`  | string | Yes      | Why this query is needed |

**When to use:** Questions about customers, orders, products, counts, config values, etc.

**Returns:** `success`, `row_count`, `columns`, `data`, `preview`

**Safety:** Only SELECT/DESCRIBE/SHOW by default. INSERT/UPDATE/DELETE require `--allow-dangerous`. Auto-adds LIMIT 100 to SELECT queries.

---

### 2. `describe_table`

Returns the structure of a database table.

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `table_name` | string | Yes      | Table name (e.g., `sales_order`) |

**When to use:** Before writing SQL to discover column names and types.

**Returns:** `success`, `table`, `columns`, `preview`

---

### 3. `grep_files`

Searches for text patterns in files.

| Parameter    | Type    | Required | Description |
|--------------|---------|----------|-------------|
| `pattern`    | string  | Yes      | Search pattern (regex supported) |
| `file_path`  | string  | Yes      | Path or glob (e.g., `app/code/**/*.php`) |
| `max_results`| integer | No       | Max matches (default: 50) |

**When to use:** Finding code, config values, malicious patterns, credentials.

**Returns:** `success`, `match_count`, `matches` (file, line, content), `preview`

---

### 4. `read_file`

Reads file contents with optional line range.

| Parameter   | Type    | Required | Description |
|-------------|---------|----------|-------------|
| `file_path` | string  | Yes      | Path relative to Magento root |
| `start_line`| integer | No       | Start line (1-indexed) |
| `end_line`  | integer | No       | End line (1-indexed) |
| `max_lines` | integer | No       | Max lines to read (default: 500) |

**When to use:** Examining code, config files, documentation.

**Returns:** `success`, `file_path`, `content`, `start_line`, `end_line`, `total_lines`, `preview`

---

### 5. `get_magento_info`

Returns a snapshot of the Magento installation (no parameters).

**When to use:** First step of any analysis — version, PHP, module count, DB size, product/order/customer counts, invalid indexers, CMS counts.

**Returns:** `success`, `magento_version`, `edition`, `php_version`, `module_count`, `db_size_mb`, `product_count`, `customer_count`, `order_count`, `invalid_indexer_count`, `cms_page_count`, `cms_block_count`, `preview`

---

### 6. `run_magento_cli`

Runs whitelisted read-only `bin/magento` commands.

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `command` | string | Yes      | Subcommand name (e.g., `indexer:status`) |

**Allowed commands:** `indexer:status`, `cache:status`, `module:status`, `cron:status`, `info:adminuri`, `--version`

**When to use:** Checking indexer status, cache status, enabled modules, cron health.

**Returns:** `success`, `command`, `output`, `return_code`, `preview`

---

### 7. `ask_user`

Asks the operator a question. In interactive CLI mode, the answer is returned to the LLM.

| Parameter  | Type   | Required | Description |
|------------|--------|----------|-------------|
| `question` | string | Yes      | Question to ask |

**When to use:** When the AI needs human input (e.g., "Do you want to include disabled products?").

**Returns:** `success`, `question`, `answer`

---

## System Prompts and Instructions

The system message informs the LLM:

- **Mode:** "You are a Magento 2 AI assistant in read-only mode" (or read-write with `--allow-dangerous`)
- **Tool usage:** "Use execute_sql_query when the question requires database data (customers, orders, products, counts, etc.)"
- **No tool for greetings:** "For greetings or non-data questions respond with natural language — do NOT use tools"
- **Common tables:** `customer_entity`, `sales_order`, `catalog_product_entity`, `sales_order_item`, `cms_page`, `cms_block`, `core_config_data`

The user prompt adds: "You have tools available. Use them when you need data to answer. When you have enough information, respond with a clear natural language answer without calling any more tools."

---

## Security and Guards

| Concern | Mitigation |
|---------|------------|
| SQL writes | Blocked by default; require `--allow-dangerous` |
| SQL injection | Parameterized queries; only whitelisted operations |
| Multiple queries | Trailing semicolons stripped; single query per call |
| Shell commands | Only whitelisted `bin/magento` subcommands via `run_magento_cli` |
| File reads | Path validation in `ReadFileTool` and `GrepTool` |
| Result size | SQL auto-limited to 100 rows; display limited to 20 rows |
| Dangerous tools | Tools with `insert`, `update`, `delete`, etc. in name are filtered in safe mode |

---

## Extending with Custom Tools

1. Implement `DatabaseToolInterface` (name, description, parameters schema, execute).
2. Register in `di.xml` under `Genaker\MagentoMcpAi\Model\DatabaseTool\Registry` → `tools` argument.
3. Return `['success' => true, 'data' => ..., 'preview' => ...]` or `['error' => '...']`.

See [DATABASE_TOOLS_DOCUMENTATION.md](DATABASE_TOOLS_DOCUMENTATION.md) for full examples and the interface reference.

---

## Related Documentation

- [MAGENTO_AI_QUERY_ANALYZER.md](MAGENTO_AI_QUERY_ANALYZER.md) — CLI modes, analyzer focus, report format
- [DATABASE_TOOLS_DOCUMENTATION.md](DATABASE_TOOLS_DOCUMENTATION.md) — Tool API reference, custom tools, examples
