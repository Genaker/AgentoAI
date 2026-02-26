<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Service;

use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Model\DatabaseTool\Registry as ToolRegistry;
use Genaker\MagentoMcpAi\Model\Mcp\Registry as McpRegistry;
use Psr\Log\LoggerInterface;

/**
 * Service for handling CLI chat with AI tools (ReAct agent loop)
 *
 * Each call to processQueryWithTools() is one agent iteration:
 *  - LLM decides to call a tool → status 'tool_called', result returned to caller
 *  - LLM provides a text answer (no tool call) → status 'success'
 *
 * The caller (LlmAnalyzer / MagentoAnalyzer) runs the loop and applies the
 * reflection / force-answer mechanism once consecutive tool calls exceed a threshold.
 *
 * Self-correction (inner monologue):
 *  When a tool call returns an error, a focused no-tools API call is made asking
 *  the LLM to diagnose the failure and plan a correction. The resulting reasoning
 *  is injected into conversation history as an assistant "[Self-correction]" message
 *  so the next iteration can use it to make a better tool call or provide a final answer.
 */
class CliChatWithToolsService
{
    /**
     * @var AIServiceInterface
     */
    private $aiService;

    /**
     * @var ToolRegistry
     */
    private $toolRegistry;

    /**
     * @var McpRegistry|null
     */
    private ?McpRegistry $mcpRegistry;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $allowDangerous;

    /**
     * Override the default system message (used by MagentoAnalyzer for expert persona).
     * Empty string = use built-in default.
     *
     * @var string
     */
    private string $customSystemMessage = '';

    /**
     * When true, a focused no-tools API call is made whenever a tool returns an error.
     * The LLM's diagnosis is injected into conversation history as a "[Self-correction]"
     * assistant message so the next iteration benefits from the analysis.
     *
     * @var bool
     */
    private bool $selfCorrectionEnabled = true;

    /**
     * @param AIServiceInterface $aiService
     * @param ToolRegistry       $toolRegistry
     * @param LoggerInterface    $logger
     * @param bool               $allowDangerous
     * @param McpRegistry|null   $mcpRegistry    Optional MCP server registry
     */
    public function __construct(
        AIServiceInterface $aiService,
        ToolRegistry $toolRegistry,
        LoggerInterface $logger,
        bool $allowDangerous = false,
        ?McpRegistry $mcpRegistry = null
    ) {
        $this->aiService      = $aiService;
        $this->toolRegistry   = $toolRegistry;
        $this->logger         = $logger;
        $this->allowDangerous = $allowDangerous;
        $this->mcpRegistry    = $mcpRegistry;
    }

    // -------------------------------------------------------------------------
    // Public setters
    // -------------------------------------------------------------------------

    /**
     * Override the system message sent to the LLM.
     * Pass an empty string to revert to the built-in default.
     */
    public function setCustomSystemMessage(string $message): void
    {
        $this->customSystemMessage = $message;
    }

    /**
     * Set allow dangerous operations
     */
    public function setAllowDangerous(bool $allowDangerous): void
    {
        $this->allowDangerous = $allowDangerous;
    }

    /**
     * Enable or disable self-correction inner monologue on tool errors.
     * Disable for unit tests or when the calling code has its own error-recovery logic.
     */
    public function setSelfCorrectionEnabled(bool $enabled): void
    {
        $this->selfCorrectionEnabled = $enabled;
    }

    // -------------------------------------------------------------------------
    // Core agent iteration
    // -------------------------------------------------------------------------

    /**
     * Process a single iteration of the ReAct agent loop.
     *
     * Returns one of:
     *   ['status' => 'tool_called', 'tool_name' => ..., 'tool_arguments' => ...,
     *    'tool_result' => ..., 'conversation_history' => ..., 'next_query' => ...]
     *   ['status' => 'success', 'message' => ..., 'conversation_history' => ...]
     *   ['status' => 'error',   'message' => ..., 'conversation_history' => ...]
     *
     * @param string $userQuery      Current iteration query (may be a reflection prompt)
     * @param array  $conversationHistory
     * @param array  $tools          OpenAI-format tool definitions
     */
    public function processQueryWithTools(
        string $userQuery,
        array $conversationHistory = [],
        array $tools = []
    ): array {
        // Build system message
        $systemMessage = $this->buildSystemMessage($tools);

        // Build messages array: [system?, ...history]
        $messages = [];
        if (!empty($systemMessage) && !empty($tools)) {
            $messages[] = ['role' => 'system', 'content' => $systemMessage];
        }
        foreach ($conversationHistory as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        // Build the user-facing prompt for this iteration
        $fullPrompt = $this->buildPromptWithTools($userQuery, $tools);

        // Call the AI service — always pass tools so the LLM can decide when to stop
        try {
            $response = $this->aiService->sendChatRequest(
                $fullPrompt,
                $messages,
                4000,
                0.1,
                $tools
            );
        } catch (\Exception $e) {
            $this->logger->error('[MagentoMcpAi] AI Service Error in chat loop', [
                'error' => $e->getMessage(),
                'user_query_preview' => substr($userQuery ?? '', 0, 200),
                'messages_count' => count($messages ?? []),
                'tools_count' => count($tools ?? []),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'status'               => 'error',
                'message'              => 'AI Service Error: ' . $e->getMessage(),
                'conversation_history' => $conversationHistory,
                'tool_calls'           => null,
            ];
        }

        if (empty($response)) {
            $this->logger->warning('[MagentoMcpAi] Empty response from AI service', [
                'user_query_preview' => substr($userQuery ?? '', 0, 200),
                'messages_count' => count($messages ?? []),
            ]);
            return [
                'status'               => 'error',
                'message'              => 'Empty response from AI service',
                'conversation_history' => $conversationHistory,
                'tool_calls'           => null,
            ];
        }

        $aiMessage = $response['message'] ?? $response['text'] ?? '';
        $nativeToolCalls = $response['tool_calls'] ?? null;

        if (empty($aiMessage) && empty($nativeToolCalls)) {
            $this->logger->warning('[MagentoMcpAi] No message content or tool calls in AI response', [
                'user_query_preview' => substr($userQuery ?? '', 0, 200),
                'response_keys' => array_keys($response),
            ]);
            return [
                'status'               => 'error',
                'message'              => 'No message content or tool calls in AI response',
                'conversation_history' => $conversationHistory,
                'tool_calls'           => null,
                'response'             => $response,
            ];
        }

        // Resolve which tool to call:
        //   1. Prefer native API tool calls (structured, reliable)
        //   2. Fall back to text-JSON parsing (older text-completion style)
        $toolCallData = $this->resolveToolCall($nativeToolCalls, $aiMessage);

        if ($toolCallData !== null) {
            return $this->handleToolCall($toolCallData, $userQuery, $conversationHistory, $response);
        }

        // No tool call → LLM provided a final text answer
        return $this->handleFinalAnswer($aiMessage, $userQuery, $conversationHistory, $response);
    }

    // -------------------------------------------------------------------------
    // Tool call handling
    // -------------------------------------------------------------------------

    /**
     * Resolve which tool to invoke.
     *
     * Prefers native API tool calls returned by the LLM provider; falls back to
     * text-JSON parsing for providers / scenarios where native calling is unavailable.
     *
     * @param array|null $nativeToolCalls Tool calls extracted from the API response
     * @param string     $aiMessage       Raw text response from the LLM
     * @return array|null Normalised tool call ['tool'=>name, 'function'=>name, 'arguments'=>[]]
     */
    protected function resolveToolCall(?array $nativeToolCalls, string $aiMessage): ?array
    {
        // --- Native tool calls (preferred) ---
        if (!empty($nativeToolCalls) && is_array($nativeToolCalls)) {
            $first = $nativeToolCalls[0];
            $name  = $first['name'] ?? ($first['function']['name'] ?? '');
            $args  = $first['arguments'] ?? ($first['function']['arguments'] ?? []);

            if (is_string($args)) {
                $args = json_decode($args, true) ?? [];
            }

            if ($name !== '') {
                return [
                    'tool'      => $name,
                    'function'  => $name,
                    'arguments' => $args,
                ];
            }
        }

        // --- Text-JSON fallback ---
        return $this->parseToolCallFromResponse($aiMessage);
    }

    /**
     * Execute a resolved tool call and return the 'tool_called' result array.
     */
    private function handleToolCall(
        array $toolCallData,
        string $userQuery,
        array $conversationHistory,
        array $response = []
    ): array {
        $functionName = $toolCallData['tool'] ?? $toolCallData['function'] ?? '';
        $arguments    = $toolCallData['arguments'] ?? [];

        // Check built-in ToolRegistry first, then fall back to McpRegistry
        $tool = $this->toolRegistry->getTool($functionName);
        if (!$tool && $this->mcpRegistry !== null) {
            $tool = $this->mcpRegistry->getTool($functionName);
        }

        if (!$tool) {
            $this->logger->warning('[MagentoMcpAi] Unknown tool requested by AI', [
                'tool' => $functionName,
                'arguments' => $arguments,
                'user_query_preview' => substr($userQuery ?? '', 0, 200),
            ]);
            return [
                'status'               => 'error',
                'message'              => 'Unknown tool: ' . $functionName,
                'conversation_history' => $conversationHistory,
                'tool_calls'           => null,
                'response'             => $response,
            ];
        }

        try {
            $toolResult = $tool->execute($arguments, $this->allowDangerous);
        } catch (\Exception $e) {
            $this->logger->error('[MagentoMcpAi] Tool execution error', [
                'tool' => $functionName,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'user_query_preview' => substr($userQuery ?? '', 0, 200),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            $toolResult = ['error' => $e->getMessage()];
        }

        // Record interaction in history — omit tool name to avoid prompting LLM to reuse same tool
        $historyContent = $this->formatToolResultForHistory($functionName, $toolResult);
        $conversationHistory[] = ['role' => 'user',      'content' => $userQuery];
        $conversationHistory[] = ['role' => 'assistant', 'content' => $historyContent['assistant']];
        $conversationHistory[] = ['role' => 'user',      'content' => $historyContent['user']];

        // Self-correction inner monologue: when the tool returned an error, ask the LLM
        // (without any tools) to diagnose the failure and plan a fix. The resulting reasoning
        // is injected as an assistant message so the next iteration can act on it.
        $isToolError = isset($toolResult['error']) || ($toolResult['success'] ?? true) === false;
        if ($isToolError && $this->selfCorrectionEnabled) {
            $correction = $this->runSelfCorrection($functionName, $arguments, $toolResult, $conversationHistory);
            if ($correction !== null) {
                $conversationHistory[] = [
                    'role'    => 'assistant',
                    'content' => "[Self-correction]:\n{$correction}",
                ];
            }
        }

        // Next iteration: use the current question (userQuery) so the AI answers it with the new tool result.
        // Do NOT use extractOriginalQuery — it returns the first user message in history, which can be
        // a different question (e.g. "how many orders" when the current question is "what is the biggest order").
        $nextQuery = $userQuery;

        return [
            'status'               => 'tool_called',
            'tool_name'            => $functionName,
            'tool_arguments'       => $arguments,
            'tool_result'          => $toolResult,
            'conversation_history' => $conversationHistory,
            'next_query'           => $nextQuery,
            'response'             => $response,
        ];
    }

    /**
     * Record and return a successful final text answer.
     */
    private function handleFinalAnswer(
        string $aiMessage,
        string $userQuery,
        array $conversationHistory,
        array $response = []
    ): array {
        // Use current userQuery for history consistency (same fix as handleToolCall)
        $conversationHistory[] = ['role' => 'user',      'content' => $userQuery];
        $conversationHistory[] = ['role' => 'assistant', 'content' => $aiMessage ?: '(Empty response)'];

        return [
            'status'               => 'success',
            'message'              => $aiMessage,
            'conversation_history' => $conversationHistory,
            'tool_calls'           => null,
            'response'             => $response,
        ];
    }

    // -------------------------------------------------------------------------
    // System / prompt builders
    // -------------------------------------------------------------------------

    /**
     * Build the system message.
     * Returns the custom message when set; otherwise falls back to the built-in default.
     */
    protected function buildSystemMessage(array $tools): string
    {
        if ($this->customSystemMessage !== '') {
            return $this->customSystemMessage;
        }

        if (empty($tools)) {
            return '';
        }

        $mode = $this->allowDangerous ? 'read-write' : 'read-only';

        return "You are a Magento 2 AI assistant in $mode mode.\n\n"
            . "Use execute_sql_query when the question requires database data "
            . "(customers, orders, products, counts, etc.). "
            . "Pass ONLY the SQL statement in the query parameter—never explanations, examples, or documentation.\n"
            . "Before calling execute_sql_query, validate: the query must be valid SQL (SELECT..., DESCRIBE..., SHOW TABLES, etc.). Never pass options, descriptions, or choices (e.g. '1) Sort by' or 'Show full breakdown')—only executable SQL.\n"
            . "Call execute_sql_query only when you need to fetch data. Do NOT call it for: explaining SQL, proposing queries, or when you can answer without running a query—respond in natural language instead.\n"
            . "When offering choices, use a single clear block. Start with 'Response options:' or 'Choose one:', then list 1) 2) 3) 4) etc. as needed. "
            . "For details use bullet points (-). For selectable options use numbers only.\n"
            . "When the user selects an option (e.g. 'I select option 3: ...' or text matching your option), proceed with that choice. Do not ask them to choose again.\n"
            . "If you have data from a tool, present it—do not ask questions. Ask only when the request is unclear and you have no data.\n"
            . "For greetings or non-data questions respond with natural language — do NOT use tools.\n\n"
            . "Common Magento tables: customer_entity, sales_order, catalog_product_entity, "
            . "sales_order_item, catalog_product_entity_varchar, catalog_product_entity_int, "
            . "catalog_product_entity_decimal, eav_attribute, eav_entity_type, "
            . "cms_page, cms_block, core_config_data, quote, quote_item\n\n"
            . "SELF-CORRECTION & DISCOVERY WORKFLOW — follow this strictly when errors occur:\n"
            . "1. Table not found / unknown table: call list_tables with a pattern like '%keyword%' "
            . "   to discover the correct table name, then retry the query.\n"
            . "2. Unknown column / field list error: call describe_table with include_keys=true "
            . "   to see exact column names and indexes, fix the column reference, then retry.\n"
            . "3. Need to JOIN or understand relations: call describe_table with include_keys=true "
            . "   to inspect foreign keys before writing the JOIN.\n"
            . "4. Not sure which tables exist for a topic (e.g., EAV attributes, prices): "
            . "   call list_tables with a broad pattern first (e.g., 'catalog_%'), then describe "
            . "   the relevant tables before querying.\n"
            . "5. To understand Magento PHP model-to-table mappings: use grep_files with a pattern "
            . "   like 'getMainTable\\|_init' in app/code or vendor/magento to find resource models.\n"
            . "Never guess column names — always verify with describe_table after an error.\n";
    }

    /**
     * Build the user-facing prompt for this iteration.
     */
    protected function buildPromptWithTools(string $userQuery, array $tools): string
    {
        $prompt = "User question: $userQuery\n\n";

        if (!empty($tools)) {
            $prompt .= "You have tools available. Use them when you need data to answer. "
                . "When you have enough information, respond with a clear natural language answer "
                . "without calling any more tools.\n";
        }

        return $prompt;
    }

    // -------------------------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a tool call from a text LLM response (fallback for non-native tool calling).
     *
     * Looks for JSON of the form {"tool": "name", "arguments": {...}} embedded in the text,
     * or falls back to detecting bare SQL statements.
     */
    protected function parseToolCallFromResponse(string $response): ?array
    {
        // Try to extract JSON from response (handle nested JSON)
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $response, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json['tool'])) {
                return [
                    'tool'      => $json['tool'],
                    'function'  => $json['tool'],
                    'arguments' => $json['arguments'] ?? [],
                ];
            }
        }

        // Check if response contains SQL directly (last-resort fallback)
        if (preg_match('/(SELECT|DESCRIBE|SHOW|INSERT|UPDATE|DELETE)\s+/i', $response)) {
            $sql = trim($response);
            $sql = preg_replace('/```sql?\s*/i', '', $sql);
            $sql = preg_replace('/```\s*/', '', $sql);
            $sql = trim($sql);

            return [
                'tool'      => 'execute_sql_query',
                'function'  => 'execute_sql_query',
                'arguments' => [
                    'query'  => $sql,
                    'reason' => 'AI generated SQL query',
                ],
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Format tool result for history — omit tool name, show only data to avoid prompting LLM to reuse same tool.
     */
    protected function formatToolResultForHistory(string $functionName, array $toolResult): array
    {
        $assistant = 'Retrieved data.';
        $user = 'Data received: ';
        if (isset($toolResult['error'])) {
            $user .= $toolResult['error'];
        } elseif ($functionName === 'execute_sql_query' && isset($toolResult['preview'])) {
            $user .= 'Rows: ' . ($toolResult['row_count'] ?? 0) . "\n" . $toolResult['preview'];
        } elseif (isset($toolResult['preview'])) {
            $user .= $toolResult['preview'];
        } else {
            $user .= json_encode($toolResult);
        }
        return ['assistant' => $assistant, 'user' => $user];
    }

    protected function extractOriginalQuery(array $conversationHistory): string
    {
        foreach ($conversationHistory as $msg) {
            if ($msg['role'] !== 'user') {
                continue;
            }
            $content = $msg['content'];
            if (
                strpos($content, 'Tool result:') === false &&
                strpos($content, 'Data received:') === false &&
                strpos($content, 'Based on the tool results') === false &&
                strpos($content, 'You have now used') === false
            ) {
                return $content;
            }
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Self-correction inner monologue
    // -------------------------------------------------------------------------

    /**
     * Ask the LLM (without tools) to diagnose a tool error and produce a corrected plan.
     *
     * The response is returned as a plain string and will be injected into conversation
     * history as an assistant "[Self-correction]" message so the next ReAct iteration
     * can use the analysis to make a better tool call or provide a final answer.
     *
     * Returns null when the LLM call fails or returns nothing.
     *
     * @param string $toolName           Name of the tool that returned an error
     * @param array  $arguments          Arguments passed to the failed tool call
     * @param array  $toolResult         The error result from the tool
     * @param array  $conversationHistory Current conversation history for context
     */
    private function runSelfCorrection(
        string $toolName,
        array $arguments,
        array $toolResult,
        array $conversationHistory
    ): ?string {
        $errorMsg  = $toolResult['error'] ?? $toolResult['message'] ?? json_encode($toolResult);
        $argsJson  = json_encode($arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Detect common Magento DB error patterns and add targeted recovery hints
        $hint = $this->buildSelfCorrectionHint($toolName, $arguments, $errorMsg);

        $diagnosticPrompt =
            "The tool `{$toolName}` was called with these arguments:\n"
            . "```json\n{$argsJson}\n```\n\n"
            . "It returned the following error:\n\"{$errorMsg}\"\n\n"
            . $hint
            . "Think step by step:\n"
            . "1. What is the root cause of this error?\n"
            . "2. What correction is needed to the arguments or approach?\n"
            . "3. What is the precise corrected action to take next to answer the user's question?\n\n"
            . "Provide a concise analysis and your corrected plan as the final response.";

        // System message for the inner monologue — no tool definitions to avoid recursion
        $systemMsg = "You are a Magento 2 AI assistant performing self-correction analysis. "
            . "Analyse tool errors step by step and output a concise, actionable correction plan. "
            . "Do not call any tools — reason only in plain text.";

        $messages = [['role' => 'system', 'content' => $systemMsg]];

        // Include up to the last 6 history messages for context (avoid huge payloads)
        foreach (array_slice($conversationHistory, -6) as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        try {
            $response = $this->aiService->sendChatRequest(
                $diagnosticPrompt,
                $messages,
                1000,  // short — analysis only
                0.1,   // low temperature for deterministic reasoning
                []     // NO tools — pure inner monologue
            );

            $text = $response['message'] ?? $response['text'] ?? null;

            if (!empty($text)) {
                $this->logger->debug('[MagentoMcpAi] Self-correction completed', [
                    'tool'     => $toolName,
                    'preview'  => substr($text, 0, 200),
                ]);
            }

            return $text ?: null;
        } catch (\Exception $e) {
            $this->logger->warning('[MagentoMcpAi] Self-correction call failed', [
                'tool'  => $toolName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build a targeted recovery hint based on the error message and tool used.
     *
     * Matches common Magento / MySQL error patterns and returns plain-text guidance
     * that is injected into the self-correction diagnostic prompt so the LLM knows
     * exactly which tool to call next.
     */
    private function buildSelfCorrectionHint(string $toolName, array $arguments, string $errorMsg): string
    {
        $lower = strtolower($errorMsg);

        // "Unknown column 'foo' in 'field list'" → describe the table for exact column names
        if (str_contains($lower, 'unknown column')) {
            // Try to extract the table from the SQL argument
            $sql        = $arguments['query'] ?? '';
            $tableHint  = '';
            if (preg_match('/FROM\s+`?(\w+)`?/i', $sql, $m)) {
                $tableHint = " (start with describe_table for '{$m[1]}')";
            }
            return "RECOVERY HINT: An 'Unknown column' error means the column name is wrong. "
                . "Call describe_table with include_keys=true{$tableHint} to see the exact column names, "
                . "then retry the query with the correct name.\n\n";
        }

        // "Table 'db.foo' doesn't exist" → discover the real table name
        if (str_contains($lower, "doesn't exist") || str_contains($lower, 'table not found')) {
            // Try to extract the guessed table name from the error or SQL
            $pattern = '';
            if (preg_match("/table '[\w.]*?\.(\w+)'/i", $errorMsg, $m)) {
                // Turn 'sales_order_foo' into 'sales_%' or '%order_foo%' as a suggestion
                $parts   = explode('_', $m[1]);
                $pattern = $parts[0] . '_%';
            }
            $patternHint = $pattern ? " Try pattern '{$pattern}' or '%{$m[1]}%'." : '';
            return "RECOVERY HINT: The table does not exist under that name. "
                . "Call list_tables with a LIKE pattern to discover the correct table name.{$patternHint} "
                . "Magento often prefixes tables (e.g., 'sales_order' not 'order').\n\n";
        }

        // "You have an error in your SQL syntax" → give syntax guidance
        if (str_contains($lower, 'sql syntax') || str_contains($lower, 'syntax error')) {
            return "RECOVERY HINT: SQL syntax is invalid. Common Magento pitfalls: "
                . "use backtick-quoted table names, avoid reserved words as column aliases, "
                . "ensure GROUP BY includes all non-aggregate SELECT columns (MySQL strict mode). "
                . "Review the query carefully and rewrite it.\n\n";
        }

        // "Access denied" or permission errors
        if (str_contains($lower, 'access denied') || str_contains($lower, 'permission')) {
            return "RECOVERY HINT: The database user lacks permission for this operation. "
                . "Switch to a read-only approach or inform the user that this action is restricted.\n\n";
        }

        // EAV / attribute not found patterns
        if (
            $toolName === 'execute_sql_query'
            && (str_contains($lower, 'eav') || str_contains($lower, 'attribute'))
        ) {
            return "RECOVERY HINT: For EAV attribute queries, remember that attribute values are split "
                . "across multiple tables by type: catalog_product_entity_varchar (text), "
                . "catalog_product_entity_int (int/boolean), catalog_product_entity_decimal (price/weight), "
                . "catalog_product_entity_datetime (dates). "
                . "Use describe_table or list_tables with 'catalog_product_entity_%' to find the right one.\n\n";
        }

        return '';
    }
}
