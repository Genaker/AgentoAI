# MCP Server Integration

The Magento AI agent supports **Model Context Protocol (MCP) servers** as a second source
of tools alongside the built-in PHP tools. Any MCP-compatible server can be plugged in via
`di.xml` — no PHP code changes required.

---

## What is MCP?

The [Model Context Protocol](https://modelcontextprotocol.io/) is an open standard (by
Anthropic) for connecting AI models to external tools and data sources. An MCP server is a
process that exposes a list of callable **tools** over JSON-RPC 2.0. Any tool the server
exposes automatically becomes available to the LLM agent.

---

## Architecture

```
genaker:agento:llm
  └── LlmAnalyzer
        ├── ToolRegistry  (built-in PHP tools) ──┐
        └── McpRegistry   (MCP server tools)  ───┤── merged tool list → LLM
                                                 │
              CliChatWithToolsService ◄───────────┘
                ├── LLM calls built-in tool → ToolRegistry → PHP class
                └── LLM calls MCP tool     → McpRegistry → McpToolAdapter
                                                            └── StdioMcpServer
                                                                 └── JSON-RPC → subprocess
```

**Tool naming convention:**  `mcp__{serverName}__{toolName}`

Examples:
- `mcp__filesystem__read_file`
- `mcp__git__git_log`
- `mcp__postgres__query`

Double underscores (`__`) are the unambiguous separator and never collide with built-in
tool names (which contain single underscores only).

---

## Registering an MCP Server (di.xml)

### Step 1 — Define the server via a `virtualType`

```xml
<virtualType name="MyFilesystemMcpServer"
             type="Genaker\MagentoMcpAi\Model\Mcp\Server\StdioMcpServer">
    <arguments>
        <!-- Unique server name — used as tool prefix: mcp__filesystem__* -->
        <argument name="name"    xsi:type="string">filesystem</argument>

        <!-- Command to launch the MCP server subprocess -->
        <argument name="command" xsi:type="string">
            npx -y @modelcontextprotocol/server-filesystem /var/www/html/pub
        </argument>

        <!-- Optional: additional environment variables for the subprocess -->
        <!-- <argument name="env" xsi:type="array">
            <item name="MY_VAR" xsi:type="string">value</item>
        </argument> -->
    </arguments>
</virtualType>
```

### Step 2 — Add it to the MCP registry

```xml
<type name="Genaker\MagentoMcpAi\Model\Mcp\Registry">
    <arguments>
        <argument name="servers" xsi:type="array">
            <item name="filesystem" xsi:type="object">MyFilesystemMcpServer</item>
        </argument>
    </arguments>
</type>
```

### Step 3 — Flush DI cache

```bash
bin/magento setup:di:compile
bin/magento cache:clean
```

That's it. The next `genaker:agento:llm` invocation will connect to the server, discover
its tools, and make them available to the LLM automatically.

---

## `StdioMcpServer` Configuration Reference

| Argument  | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `name`    | string | Yes      | Server identifier (alphanumeric + underscores). Used as tool prefix. |
| `command` | string | Yes      | Shell command to launch the MCP server. The subprocess must speak MCP stdio transport. |
| `env`     | array  | No       | Additional environment variables (key/value strings) for the subprocess. |

The server process is started lazily (only when the first tool call or tool-list request
arrives) and kept alive for the duration of the PHP process. It is cleanly terminated in
`__destruct()`.

---

## Worked Example: Filesystem MCP Server

The official `@modelcontextprotocol/server-filesystem` server exposes tools to read, write,
list, and search files in a given directory.

### Install the server

```bash
npm install -g @modelcontextprotocol/server-filesystem
# or use npx (no install required, shown in examples below)
```

### Register in di.xml

```xml
<virtualType name="MagentoPubFilesystemMcpServer"
             type="Genaker\MagentoMcpAi\Model\Mcp\Server\StdioMcpServer">
    <arguments>
        <argument name="name"    xsi:type="string">filesystem</argument>
        <argument name="command" xsi:type="string">
            npx -y @modelcontextprotocol/server-filesystem /var/www/html/pub
        </argument>
    </arguments>
</virtualType>

<type name="Genaker\MagentoMcpAi\Model\Mcp\Registry">
    <arguments>
        <argument name="servers" xsi:type="array">
            <item name="filesystem" xsi:type="object">MagentoPubFilesystemMcpServer</item>
        </argument>
    </arguments>
</type>
```

### What the LLM can now do

After `setup:di:compile`, the agent gains tools like:
- `mcp__filesystem__read_file` — read a file in `/var/www/html/pub`
- `mcp__filesystem__list_directory` — list a directory
- `mcp__filesystem__search_files` — grep for a pattern

```bash
bin/magento genaker:agento:llm "List all image files in pub/media/catalog/product"
```

---

## Worked Example: Custom Python MCP Server

You can write a custom MCP server in any language. Here's a minimal Python server that
exposes a single tool `get_store_status`:

```python
#!/usr/bin/env python3
# file: mcp_store_status.py
import json, sys

def handle(method, params, id_):
    if method == "initialize":
        return {"protocolVersion": "2024-11-05", "capabilities": {"tools": {}},
                "serverInfo": {"name": "store-status", "version": "1.0"}}
    if method == "tools/list":
        return {"tools": [{"name": "get_store_status", "description": "Returns Magento store health.",
                           "inputSchema": {"type": "object", "properties": {}}}]}
    if method == "tools/call":
        return {"content": [{"type": "text", "text": "Store is UP. Orders: 1200."}], "isError": False}
    return {}

for line in sys.stdin:
    req = json.loads(line.strip())
    result = handle(req.get("method", ""), req.get("params", {}), req.get("id"))
    if req.get("id") is not None:
        print(json.dumps({"jsonrpc": "2.0", "id": req["id"], "result": result}), flush=True)
```

```xml
<virtualType name="StoreStatusMcpServer"
             type="Genaker\MagentoMcpAi\Model\Mcp\Server\StdioMcpServer">
    <arguments>
        <argument name="name"    xsi:type="string">store_status</argument>
        <argument name="command" xsi:type="string">python3 /opt/mcp/mcp_store_status.py</argument>
    </arguments>
</virtualType>

<type name="Genaker\MagentoMcpAi\Model\Mcp\Registry">
    <arguments>
        <argument name="servers" xsi:type="array">
            <item name="store_status" xsi:type="object">StoreStatusMcpServer</item>
        </argument>
    </arguments>
</type>
```

---

## Multiple Servers

Register as many servers as needed:

```xml
<type name="Genaker\MagentoMcpAi\Model\Mcp\Registry">
    <arguments>
        <argument name="servers" xsi:type="array">
            <item name="filesystem"   xsi:type="object">FilesystemMcpServer</item>
            <item name="git"          xsi:type="object">GitMcpServer</item>
            <item name="store_status" xsi:type="object">StoreStatusMcpServer</item>
        </argument>
    </arguments>
</type>
```

Each server's tools are prefixed with its name. The LLM sees them all side-by-side with
the built-in PHP tools.

---

## Popular Open-Source MCP Servers

| Server | npm package / repo | Tools exposed |
|--------|--------------------|---------------|
| Filesystem | `@modelcontextprotocol/server-filesystem` | read, write, list, search files |
| Git | `@modelcontextprotocol/server-git` | git log, diff, status, commit |
| GitHub | `@modelcontextprotocol/server-github` | issues, PRs, repos, search code |
| PostgreSQL | `@modelcontextprotocol/server-postgres` | query, schema introspection |
| SQLite | `@modelcontextprotocol/server-sqlite` | query SQLite databases |
| Fetch/HTTP | `@modelcontextprotocol/server-fetch` | fetch URLs, extract content |
| Brave Search | `@modelcontextprotocol/server-brave-search` | web search via Brave API |
| Memory | `@modelcontextprotocol/server-memory` | knowledge graph persistence |

Install any of them with `npx -y <package>` (no global install required).

---

## Graceful Degradation

- If an MCP server **cannot be started** (command not found, exits immediately), its tools
  are omitted and a warning is written to `var/mcpai.log`. The agent continues with
  built-in tools only.
- If a server **crashes mid-session**, the next tool call returns an error result and the
  LLM sees it in history. It can then use other tools or give a partial answer.
- If `listTools()` **returns no tools**, the server is simply ignored (zero overhead).

---

## Debugging

Enable verbose output to see MCP tools in the tool list:

```bash
bin/magento genaker:agento:llm "your question" --debug
```

The debug output shows:
```
[DEBUG] --- Tools (10): execute_sql_query, describe_table, ..., mcp__filesystem__read_file, mcp__filesystem__list_directory
```

MCP tool calls are logged in `var/mcpai.log`:
```
[DEBUG] MCP → server {"server":"filesystem","method":"tools/call"}
[DEBUG] MCP ← server {"server":"filesystem","method":"tools/call","has_result":true}
```

---

## Extending: Custom Server Classes

For advanced use cases (HTTP transport, authentication, custom retry logic), extend
`AbstractMcpServer` directly:

```php
class MyHttpMcpServer extends \Genaker\MagentoMcpAi\Model\Mcp\AbstractMcpServer
{
    protected function initializeConnection(): void
    {
        // e.g. verify the remote URL is reachable
    }

    protected function sendRpcRequest(string $method, array $params, int $id): array
    {
        // POST JSON-RPC to your HTTP endpoint and return parsed response
    }

    public function isAvailable(): bool
    {
        return true; // implement health check
    }
}
```

Register it in di.xml exactly like `StdioMcpServer`.

---

---

## Built-in Test Server (`MockDataMcpServer`)

A `MockDataMcpServer` is included and **enabled by default** in `di.xml`. It is a pure-PHP
implementation (no subprocess) that exposes three static-data tools for immediate testing:

| Tool | Description |
|------|-------------|
| `mcp__mock__get_store_summary` | Returns fake order count, revenue, top category |
| `mcp__mock__get_top_products` | Returns a static top-5 products list (accepts `limit` param) |
| `mcp__mock__echo_input` | Echoes the `message` argument back (round-trip test) |

### Test it immediately

After `setup:di:compile`:

```bash
# Ask about the store — agent should call mcp__mock__get_store_summary
bin/magento genaker:agento:llm "What does the mock MCP server tell us about the store?"

# Ask for top products
bin/magento genaker:agento:llm "Show me the top 3 products from the MCP mock server"

# Echo test
bin/magento genaker:agento:llm "Use the echo_input MCP tool to echo 'hello world'"

# Debug mode — shows tool list and each tool call
bin/magento genaker:agento:llm "What store data is available via MCP?" --debug
```

The debug output will show `mcp__mock__*` in the tools list alongside all built-in tools:
```
[DEBUG] --- Tools (10): execute_sql_query, ..., mcp__mock__get_store_summary, mcp__mock__get_top_products, mcp__mock__echo_input
```

### Remove in production

Comment out or remove the `mock` item in `di.xml` when deploying to production:
```xml
<!-- <item name="mock" xsi:type="object">Genaker\MagentoMcpAi\Model\Mcp\Server\MockDataMcpServer</item> -->
```

---

## Related Documentation

- [ADDING_TOOLS.md](ADDING_TOOLS.md) — How to add built-in PHP tools
- [TOOLS_AND_LLM.md](TOOLS_AND_LLM.md) — How tools integrate with the LLM ReAct loop
- [MAGENTO_AI_QUERY_ANALYZER.md](MAGENTO_AI_QUERY_ANALYZER.md) — CLI command reference
