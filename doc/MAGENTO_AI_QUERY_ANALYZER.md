# Magento AI LLM CLI — `genaker:agento:llm`

A single command that works as both a **natural-language chat assistant** and an **autonomous installation analyzer**. The agent uses an iterative tool-calling loop (SQL queries, file search, Magento CLI) to investigate your store and produce structured reports.

---

## Modes

### 1. Chat / Query mode (default)

Ask any question in natural language. The agent calls tools as needed and streams the answer back.

```bash
# Single question
bin/magento genaker:agento:llm "How many customers do we have per group?"
bin/magento genaker:agento:llm "What are the top 10 SKUs by order count this year?"
bin/magento genaker:agento:llm "Show me all disabled cache types"

# Interactive chat (no argument — enter a REPL loop)
bin/magento genaker:agento:llm
```

Inside interactive mode:
- Type any question and press Enter
- `clear` / `clean` — reset conversation history
- `exit` / `quit` — leave

---

### 2. Analyzer mode (`--focus`)

Runs an autonomous ReAct agent that systematically investigates your Magento installation and outputs a structured Markdown report with findings rated **CRITICAL / HIGH / MEDIUM / LOW**.

```bash
bin/magento genaker:agento:llm --focus=all          # full audit
bin/magento genaker:agento:llm --focus=security     # injected code, admin accounts, HTTPS
bin/magento genaker:agento:llm --focus=performance  # indexers, caches, FPC, Redis/Varnish
bin/magento genaker:agento:llm --focus=config       # cron, email, base URLs, payment
bin/magento genaker:agento:llm --focus=db           # DB size, large tables, orphan data
```

Save the report to a file:
```bash
bin/magento genaker:agento:llm --focus=all --report=/tmp/magento-audit.txt
```

---

## Options

| Option | Short | Description |
|---|---|---|
| `--focus=VALUE` | `-f` | Trigger analyzer mode. Values: `security`, `performance`, `config`, `db`, `all` |
| `--report=FILE` | `-r` | Save final report to this file path (analyzer mode only) |
| `--allow-dangerous` | | Enable write SQL operations (INSERT/UPDATE/DELETE). **Use with caution.** |
| `--debug` | `-d` | Show verbose debug output (AI requests, tool args, token usage) |

---

## Agent tools

The agent has access to these tools in both modes:

| Tool | What it does |
|---|---|
| `get_magento_info` | Baseline snapshot: version, PHP, module count, DB size, product/order/customer counts, invalid indexers |
| `execute_sql_query` | Run SELECT/DESCRIBE/SHOW against the Magento DB. Auto-limited to 100 rows. Writes require `--allow-dangerous`. |
| `describe_table` | Get column structure for any table before querying |
| `grep_files` | Search the codebase for patterns (malicious JS, credentials, config values) |
| `read_file` | Read specific files relative to Magento root |
| `run_magento_cli` | Run whitelisted read-only CLI commands: `indexer:status`, `cache:status`, `module:status`, `cron:status`, `--version`, `info:adminuri` |
| `ask_user` | Pause analysis and ask the operator a question. Answer is fed back to the LLM. |

---

## How the agent loop works

```
User prompt / --focus prompt
        ↓
  LLM decides which tool to call
        ↓
  Tool executes → result added to conversation history
        ↓
  LLM decides to call another tool or answer
        ↓
  ... (up to 8 iterations for analyzer, 5 for chat)
        ↓
  LLM produces final text answer
        ↓
  If max iterations reached → forced final answer call (no tools)
        ↓
  Output / save to --report
```

**Infinite-loop guards:**
- After 3 consecutive tool calls → reflection prompt injected ("synthesize your findings now")
- Repeated identical tool call → immediate reflection
- Max iterations → forced final answer is always generated, so the operator always gets output

---

## Security

| Concern | How it's handled |
|---|---|
| SQL writes | Blocked by default. Require `--allow-dangerous`. |
| Shell commands | Only whitelisted `bin/magento` subcommands via `run_magento_cli`. |
| File reads | Path validation in `ReadFileTool` and `GrepTool`. |
| Result size | SQL results auto-limited to 100 rows; display limited to 20 rows. |

---

## Report format (analyzer mode)

```markdown
## Overview
Magento 2.4.7 Community | PHP 8.2 | 142 modules | DB 4.2 GB | 18,450 products | 12,340 orders

## Critical Issues (CRITICAL/HIGH)
- **[CRITICAL]** Injected JavaScript found in cms_block id=42 — contains `atob(...)` pattern
  Evidence: SELECT block_id, title FROM cms_block WHERE content LIKE '%atob%'
  Fix: Audit and clean the block content, rotate all API keys

## Warnings (MEDIUM)
- **[MEDIUM]** 3 indexers in "invalid" state: catalog_category_product, catalog_product_price, ...
  Fix: Run bin/magento indexer:reindex

## Recommendations (LOW)
- Full-page cache is enabled but Varnish is not configured — consider adding Varnish for high-traffic stores
- 142 modules installed — review for unused modules to reduce bootstrap overhead
```

---

## Examples

```bash
# Quick DB health check
bin/magento genaker:agento:llm --focus=db

# Security audit with report saved
bin/magento genaker:agento:llm --focus=security --report=/var/reports/security-$(date +%Y%m%d).txt

# Chat: customer analysis
bin/magento genaker:agento:llm "Show me customer registrations per month this year grouped by store"

# Chat: find large tables
bin/magento genaker:agento:llm "Which are the 10 largest tables in the database by size?"

# Full audit in debug mode (shows each tool call + token usage)
bin/magento genaker:agento:llm --focus=all --debug
```
