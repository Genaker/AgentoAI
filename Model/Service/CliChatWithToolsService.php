<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Service;

use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Model\DatabaseTool\Registry as ToolRegistry;
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
     * @param AIServiceInterface $aiService
     * @param ToolRegistry $toolRegistry
     * @param LoggerInterface $logger
     * @param bool $allowDangerous
     */
    public function __construct(
        AIServiceInterface $aiService,
        ToolRegistry $toolRegistry,
        LoggerInterface $logger,
        bool $allowDangerous = false
    ) {
        $this->aiService      = $aiService;
        $this->toolRegistry   = $toolRegistry;
        $this->logger         = $logger;
        $this->allowDangerous = $allowDangerous;
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
            $this->logger->error('AI Service Error: ' . $e->getMessage());
            return [
                'status'               => 'error',
                'message'              => 'AI Service Error: ' . $e->getMessage(),
                'conversation_history' => $conversationHistory,
                'tool_calls'           => null,
            ];
        }

        if (empty($response)) {
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
            return $this->handleToolCall($toolCallData, $userQuery, $conversationHistory);
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
        array $conversationHistory
    ): array {
        $functionName = $toolCallData['tool'] ?? $toolCallData['function'] ?? '';
        $arguments    = $toolCallData['arguments'] ?? [];

        $tool = $this->toolRegistry->getTool($functionName);
        if (!$tool) {
            return [
                'status'               => 'error',
                'message'              => 'Unknown tool: ' . $functionName,
                'conversation_history' => $conversationHistory,
                'tool_calls'           => null,
            ];
        }

        try {
            $toolResult = $tool->execute($arguments, $this->allowDangerous);
        } catch (\Exception $e) {
            $this->logger->error('Tool execution error: ' . $e->getMessage());
            $toolResult = ['error' => $e->getMessage()];
        }

        // Record interaction in history
        $conversationHistory[] = ['role' => 'user',      'content' => $userQuery];
        $conversationHistory[] = ['role' => 'assistant', 'content' => "Tool call: $functionName(" . json_encode($arguments) . ")"];
        $conversationHistory[] = ['role' => 'user',      'content' => 'Tool result: ' . json_encode($toolResult)];

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
            . "(customers, orders, products, counts, etc.).\n"
            . "For greetings or non-data questions respond with natural language — do NOT use tools.\n\n"
            . "Common Magento tables: customer_entity, sales_order, catalog_product_entity, "
            . "sales_order_item, cms_page, cms_block, core_config_data\n";
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
     * Extract the first "real" user query from conversation history,
     * skipping injected messages like tool results or reflection prompts.
     */
    protected function extractOriginalQuery(array $conversationHistory): string
    {
        foreach ($conversationHistory as $msg) {
            if ($msg['role'] !== 'user') {
                continue;
            }
            $content = $msg['content'];
            if (
                strpos($content, 'Tool result:') === false &&
                strpos($content, 'Based on the tool results') === false &&
                strpos($content, 'You have now used') === false
            ) {
                return $content;
            }
        }
        return '';
    }
}
