<?php
namespace Genaker\MagentoMcpAi\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Genaker\MagentoMcpAi\Api\Service\AIServiceInterface;
use Genaker\MagentoMcpAi\Model\DatabaseTool\Registry as ToolRegistry;
use Genaker\MagentoMcpAi\Model\Mcp\Registry as McpRegistry;
use Genaker\MagentoMcpAi\Model\Service\CliChatWithToolsService;
use Genaker\MagentoMcpAi\Model\Service\UserInteractionContext;

class LlmAnalyzer extends Command
{
    const COMMAND_NAME = 'genaker:agento:llm';
    const CONFIG_PATH_MAX_ITERATIONS = 'magentomcpai/general/max_iterations';

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var AIServiceInterface
     */
    private $aiService;

    /**
     * @var ToolRegistry
     */
    private $toolRegistry;

    /**
     * @var McpRegistry
     */
    private $mcpRegistry;

    /**
     * @var CliChatWithToolsService
     */
    private $cliChatService;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var bool
     */
    private $allowDangerous = false;

    /**
     * @var UserInteractionContext
     */
    private $userInteractionContext;

    /**
     * @var bool  True when running in --focus (structured analysis) mode
     */
    private $isAnalyzeMode = false;

    /**
     * @var string[] Parsed options from last AI message (for expanding "1", "2" etc)
     */
    private $lastParsedOptions = [];

    public function __construct(
        ResourceConnection $resourceConnection,
        AIServiceInterface $aiService,
        ToolRegistry $toolRegistry,
        CliChatWithToolsService $cliChatService,
        ScopeConfigInterface $scopeConfig,
        UserInteractionContext $userInteractionContext,
        McpRegistry $mcpRegistry = null
    ) {
        parent::__construct();
        $this->resourceConnection = $resourceConnection;
        $this->aiService = $aiService;
        $this->toolRegistry = $toolRegistry;
        $this->cliChatService = $cliChatService;
        $this->scopeConfig = $scopeConfig;
        $this->userInteractionContext = $userInteractionContext;
        $this->mcpRegistry = $mcpRegistry;
    }

    protected function configure()
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription(
                'AI-powered CLI assistant for Magento. Two modes: '
                . '(1) Chat/query mode — ask questions in natural language; '
                . '(2) Analyzer mode (--focus) — autonomous agent that investigates your installation '
                . 'using SQL, file search, and Magento CLI tools, then produces a structured report.'
            )
            ->addArgument(
                'query',
                InputArgument::OPTIONAL,
                'Natural language query or request. If omitted, enters interactive chat mode.'
            )
            ->addOption(
                'focus',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Run structured Magento analysis: security | performance | config | db | all'
            )
            ->addOption(
                'report',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Save final analysis report to this file path (only used with --focus)'
            )
            ->addOption(
                'allow-dangerous',
                null,
                InputOption::VALUE_NONE,
                'DANGEROUS: Allow write operations (INSERT, UPDATE, DELETE). Use with caution!'
            )
            ->addOption(
                'debug',
                'd',
                InputOption::VALUE_NONE,
                'Enable debug mode (equivalent to -vvv verbosity)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $userQuery  = $input->getArgument('query');
        $focus      = $input->getOption('focus');
        $reportFile = $input->getOption('report');
        $this->allowDangerous = $input->getOption('allow-dangerous');

        if ($input->getOption('debug')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        if ($this->allowDangerous) {
            $output->writeln('<error>WARNING: DANGEROUS MODE ENABLED - Write operations allowed!</error>');
            $output->writeln('');
        }

        $this->cliChatService->setAllowDangerous($this->allowDangerous);
        $this->debug($output, 'Verbosity level: ' . $this->getVerbosityName($output->getVerbosity()), OutputInterface::VERBOSITY_VERBOSE);

        // Always wire up UserInteractionContext so ask_user tool works in every mode
        $helper = new QuestionHelper();
        $this->userInteractionContext->setAskCallback(
            function (string $question) use ($input, $output, $helper): string {
                $output->writeln('');
                $parsed = $this->parseQuestionOptions($question);
                if (count($parsed['options']) >= 2) {
                    $output->writeln('<question> AI asks: ' . $parsed['preamble'] . ' </question>');
                    foreach ($parsed['options'] as $i => $opt) {
                        $output->writeln('  <info>' . ($i + 1) . ')</info> ' . trim($opt));
                    }
                    $output->writeln('');
                    $q = new Question('<fg=cyan>Your answer (1-' . count($parsed['options']) . ') or type freely: </>');
                    $answer = (string)($helper->ask($input, $output, $q) ?? '');
                    $num = trim($answer);
                    if (preg_match('/^\d+$/', $num) && (int)$num >= 1 && (int)$num <= count($parsed['options'])) {
                        return trim($parsed['options'][(int)$num - 1]);
                    }
                    return $answer;
                }
                $output->writeln('<question> AI asks: ' . $question . ' </question>');
                $q = new Question('<fg=cyan>Your answer: </>');
                return (string)($helper->ask($input, $output, $q) ?? '');
            }
        );

        // ── Analyze mode: --focus triggers the structured Magento installation analysis ──
        if ($focus !== null) {
            $this->isAnalyzeMode = true;
            $this->cliChatService->setCustomSystemMessage($this->buildSystemMessage($this->allowDangerous));

            $output->writeln('');
            $output->writeln('<info>╔══════════════════════════════════════════════╗</info>');
            $output->writeln('<info>║     Magento AI Analyzer — ' . strtoupper($focus) . ' mode' . str_repeat(' ', max(0, 19 - strlen($focus))) . '║</info>');
            $output->writeln('<info>╚══════════════════════════════════════════════╝</info>');
            $output->writeln('');
            $output->writeln('<comment>The agent will use tools to investigate your Magento installation.</comment>');
            $output->writeln('<comment>Type answers when asked. Ctrl+C to abort.</comment>');
            $output->writeln('');

            $tools               = $this->getMergedTools();
            $conversationHistory = [];
            $analysisPrompt      = $this->buildAnalysisPrompt($focus);

            $result = $this->processQueryWithService($output, $analysisPrompt, $conversationHistory, $tools, false);

            if ($reportFile && ($result['status'] === 'success') && !empty($result['message'])) {
                $this->saveReport($reportFile, $result['message'], $output);
            }

            return ($result['status'] === 'error') ? Command::FAILURE : Command::SUCCESS;
        }

        // ── Chat / single-query mode (existing behaviour) ──
        if (empty($userQuery)) {
            return $this->interactiveMode($input, $output);
        }

        $output->writeln('<info>Query: ' . $userQuery . '</info>');
        $output->writeln('');

        return $this->processQuery($output, $userQuery);
    }

    /**
     * Interactive chat mode - continuous conversation
     */
    protected function interactiveMode(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Magento AI Assistant - Interactive Mode</info>');
        $output->writeln('<comment>Type your questions. "clear" or "clean" to reset history. "exit" or "quit" to end.</comment>');
        $output->writeln('');

        $helper = new QuestionHelper();
        $conversationHistory = [];
        $tools = $this->getMergedTools();

        while (true) {
            try {
                // Get user input
                $question = new Question('<fg=cyan>> </>');
                $question->setTrimmable(true);
                $userQuery = $helper->ask($input, $output, $question);

                // Check for exit commands
                if (empty($userQuery) || in_array(strtolower(trim($userQuery)), ['exit', 'quit', 'q'])) {
                    $output->writeln('<info>Goodbye!</info>');
                    break;
                }

                // Clear history locally (no AI call)
                if (in_array(strtolower(trim($userQuery)), ['clear', 'clean'])) {
                    $conversationHistory = [];
                    $this->lastParsedOptions = [];
                    $output->writeln('<info>History cleared.</info>');
                    $output->writeln('');
                    continue;
                }

                // Expand "1", "2" etc to full option text when previous AI message had options
                $queryToSend = $userQuery;
                if (preg_match('/^\s*(\d+)\s*$/', trim($userQuery), $m)
                    && !empty($this->lastParsedOptions)
                    && (int)$m[1] >= 1
                    && (int)$m[1] <= count($this->lastParsedOptions)
                ) {
                    $selected = $this->lastParsedOptions[(int)$m[1] - 1];
                    $queryToSend = "I select option " . $m[1] . ": " . $selected;
                }

                $output->writeln(''); // Empty line for readability

                // Process the query using service
                $result = $this->processQueryWithService($output, $queryToSend, $conversationHistory, $tools, true);
                
                // Check if there was an error
                if ($result['status'] === 'error') {
                    $output->writeln('<error>' . $result['message'] . '</error>');
                    $output->writeln('<comment>Query failed. You can try again or type "exit" to quit.</comment>');
                }
                
                $output->writeln(''); // Empty line after response

            } catch (\Exception $e) {
                if ($e->getMessage() === 'Interrupted' || strpos($e->getMessage(), 'Interrupted') !== false) {
                    $output->writeln('');
                    $output->writeln('<info>Goodbye!</info>');
                    break;
                }
                $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
                $output->writeln('<comment>Stack trace: ' . $e->getTraceAsString() . '</comment>');
                $output->writeln('');
            }
        }

        return 0;
    }

    /**
     * Process a single query (non-interactive mode)
     */
    protected function processQuery(OutputInterface $output, string $userQuery): int
    {
        try {
            $tools = $this->getMergedTools();
            $conversationHistory = [];

            $result = $this->processQueryWithService($output, $userQuery, $conversationHistory, $tools, false);
            
            if ($result['status'] === 'error') {
                return 1;
            }
            
            return 0;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    /**
     * Process query using CliChatWithToolsService
     */
    protected function processQueryWithService(
        OutputInterface $output,
        string $userQuery,
        array &$conversationHistory,
        array $tools,
        bool $isInteractive = true
    ): array {
        $maxIterations = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_MAX_ITERATIONS,
            ScopeInterface::SCOPE_STORE
        ) ?: ($this->isAnalyzeMode ? 8 : 5);
        $iteration = 0;
        $currentQuery = $userQuery;
        // Reflection / infinite-loop guard
        $consecutiveToolCalls = 0;
        $lastToolSignature    = '';
        // Session totals (tokens + cost across all requests in loop)
        $sessionTotals = ['input' => 0, 'output' => 0, 'total' => 0, 'cost' => 0.0, 'calls' => 0];

        while ($iteration < $maxIterations) {
            $iteration++;
            
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln('<fg=cyan>[DEBUG]</> <fg=yellow>=== Iteration ' . $iteration . '/' . $maxIterations . ' ===</>');
            }
            $this->debug($output, "Current query: $currentQuery", OutputInterface::VERBOSITY_VERY_VERBOSE);
            
            // Debug: Show request details before calling service
            $messages = [];
            foreach ($conversationHistory as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
            $this->debugAiRequest($output, $currentQuery, $messages, $tools);
            
            // Call the service to process the query (single iteration)
            $this->debug($output, 'Calling CliChatWithToolsService...', OutputInterface::VERBOSITY_VERBOSE);
            $result = $this->cliChatService->processQueryWithTools(
                $currentQuery,
                $conversationHistory,
                $tools
            );
            
            // Update conversation history from service result
            $conversationHistory = $result['conversation_history'] ?? $conversationHistory;
            
            // Debug output
            $this->debug($output, "Service result status: " . $result['status'], OutputInterface::VERBOSITY_VERBOSE);
            
            // Debug response if available
            if (isset($result['response'])) {
                $this->debugAiResponse($output, $result['response']);
                $this->accumulateSessionTotals($result['response'], $sessionTotals);
                $this->debugSessionTotals($output, $sessionTotals);
            }
            
            if ($result['status'] === 'tool_called') {
                // Tool was called - display results and continue loop
                $functionName = $result['tool_name'];
                $toolResult = $result['tool_result'];
                
                $this->debug($output, "Tool call detected: $functionName", OutputInterface::VERBOSITY_VERBOSE);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->debug($output, 'Tool arguments: ' . json_encode($result['tool_arguments']), OutputInterface::VERBOSITY_VERY_VERBOSE);
                    if ($functionName === 'execute_sql_query' && isset($result['tool_arguments']['query'])) {
                        $this->debug($output, 'SQL executed: ' . $result['tool_arguments']['query'], OutputInterface::VERBOSITY_VERY_VERBOSE);
                    }
                }
                
                if ($isInteractive) {
                    $output->write('<fg=yellow>Using tool: ' . $functionName . '...</>');
                    $output->writeln('');
                } else {
                    $output->writeln('<fg=yellow>AI requested tool...</>');
                    $output->writeln('');
                    $output->writeln('<comment>Tool: ' . $functionName . '</comment>');
                }
                
                $this->debug($output, "Executing tool: $functionName", OutputInterface::VERBOSITY_VERBOSE);
                $this->debug($output, "Tool execution completed", OutputInterface::VERBOSITY_VERBOSE);
                
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $resultPreview = is_array($toolResult) ? json_encode($toolResult) : (string)$toolResult;
                    $resultPreview = strlen($resultPreview) > 200 ? substr($resultPreview, 0, 200) . '...' : $resultPreview;
                    $this->debug($output, "Tool result preview: $resultPreview", OutputInterface::VERBOSITY_VERY_VERBOSE);
                }
                
                // Display tool results
                $this->displayToolResults($output, $functionName, $toolResult);

                // --- Reflection / infinite-loop guard ---
                $toolSignature = $functionName . ':' . json_encode($result['tool_arguments'] ?? []);
                $isRepeatCall  = ($toolSignature === $lastToolSignature);
                $lastToolSignature = $toolSignature;
                $consecutiveToolCalls++;

                if ($isRepeatCall || $consecutiveToolCalls >= 3) {
                    $this->debug(
                        $output,
                        sprintf(
                            'Reflection triggered (%d consecutive tool calls%s)',
                            $consecutiveToolCalls,
                            $isRepeatCall ? ', repeated call' : ''
                        ),
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                    $currentQuery = sprintf(
                        "You have now used %d tools and gathered significant information.\n"
                        . "Please synthesize your findings and provide your best answer to the original question: %s\n"
                        . "If you still need one critical piece of data you may use one more tool, "
                        . "otherwise provide your final answer now.",
                        $consecutiveToolCalls,
                        $userQuery
                    );
                    $consecutiveToolCalls = 0;
                } else {
                    $currentQuery = $result['next_query'];
                }
                $this->debug($output, "Updated query for next iteration: $currentQuery", OutputInterface::VERBOSITY_VERY_VERBOSE);
                continue;
            } elseif ($result['status'] === 'success') {
                // Final answer received — reset consecutive counter
                $consecutiveToolCalls = 0;
                $this->debug($output, 'AI provided final answer (no tool call)', OutputInterface::VERBOSITY_VERBOSE);
                $aiMessage = $result['message'];
                
                if (empty($aiMessage)) {
                    $output->writeln('<warning>AI response is empty. This might indicate an issue with the AI service.</warning>');
                    $this->debug($output, 'Empty message detected in final answer branch', OutputInterface::VERBOSITY_VERBOSE);
                    if (!$isInteractive) {
                        return ['status' => 'error', 'message' => 'Empty response'];
                    }
                } else {
                    $this->debug($output, 'Outputting AI response (length: ' . strlen($aiMessage) . ' chars)', OutputInterface::VERBOSITY_VERBOSE);
                    $displayText = $this->formatOptionsForDisplay($aiMessage);
                    if ($isInteractive) {
                        $output->writeln('<info>Answer:</info>');
                        $this->streamOutput($output, $displayText);
                    } elseif ($this->isAnalyzeMode) {
                        $output->writeln('');
                        $output->writeln('<info>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</info>');
                        $output->writeln('<info>Analysis Report</info>');
                        $output->writeln('<info>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</info>');
                        $output->writeln('');
                        $output->writeln($displayText);
                        $output->writeln('');
                    } else {
                        $output->writeln('<info>Answer:</info>');
                        $output->writeln($displayText);
                    }
                }

                return $result;
            } elseif ($result['status'] === 'error') {
                // Error occurred
                return $result;
            }
        }
        
        // Max iterations reached — attempt one final forced-answer call (no tools)
        $output->writeln('<warning>Maximum iterations reached. Generating final answer from gathered data...</warning>');
        $this->debug($output, "Reached max iterations ($maxIterations). Forcing final answer.", OutputInterface::VERBOSITY_VERBOSE);

        $forcedQuery  = "The iteration limit ($maxIterations tool calls) was reached. You MUST respond with:\n"
            . " Your best answer from the data gathered so far (or 'No data gathered yet' if none).\n"
            . " A brief explanation: 'The iteration limit was reached before completion.'\n"
            . " Ask the user to continue: suggest they refine the question or break it into smaller steps.\n"
            . "Original question: " . $userQuery . "\n"
            . "Do NOT call any more tools. Be concise.";
        $forcedResult = $this->cliChatService->processQueryWithTools(
            $forcedQuery,
            $conversationHistory,
            [] // No tools — force a text response
        );

        if ($forcedResult['status'] === 'success' && !empty($forcedResult['message'])) {
            if (isset($forcedResult['response'])) {
                $this->accumulateSessionTotals($forcedResult['response'], $sessionTotals);
                $this->debugSessionTotals($output, $sessionTotals);
            }
            $conversationHistory = $forcedResult['conversation_history'];
            $displayText = $this->formatOptionsForDisplay($forcedResult['message']);
            if ($isInteractive) {
                $output->writeln('<info>Answer:</info>');
                $this->streamOutput($output, $displayText);
            } elseif ($this->isAnalyzeMode) {
                $output->writeln('<info>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</info>');
                $output->writeln('<info>Analysis Report (iteration limit reached)</info>');
                $output->writeln('<info>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</info>');
                $output->writeln('');
                $output->writeln($displayText);
                $output->writeln('');
            } else {
                $output->writeln('<info>Answer:</info>');
                $output->writeln($displayText);
            }
            return $forcedResult;
        }

        // Forced answer failed (empty or error) — explain and ask user to continue
        $output->writeln('');
        $output->writeln('<comment>The iteration limit (' . $maxIterations . ') was reached before a complete answer could be generated.</comment>');
        $output->writeln('<comment>Please try again with a more specific question, or break your request into smaller steps.</comment>');
        $output->writeln('');
        return ['status' => 'max_iterations', 'message' => 'Maximum iterations reached'];
    }

    /**
     * Stream output word by word for typing effect (like ollama)
     */
    protected function streamOutput(OutputInterface $output, string $text): void
    {
        if (empty($text)) {
            $output->writeln('<warning>Empty response from AI</warning>');
            return;
        }
        
        // Split text into words while preserving spaces
        $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        foreach ($words as $word) {
            if (trim($word) === '') {
                // Preserve spaces/newlines
                $output->write($word);
            } else {
                // Output word with small delay for typing effect
                $output->write($word);
                usleep(15000); // 15ms delay per word for smooth typing effect
            }
        }
        
        $output->writeln(''); // New line at end
    }

    /**
     * Old implementation - removed, logic moved to CliChatWithToolsService
     * @deprecated This method has been removed. Use CliChatWithToolsService instead.
     */
    private function _removed_processQueryInteractiveOld(
        OutputInterface $output,
        string $userQuery,
        array &$conversationHistory,
        array $tools,
        bool $isInteractive = true
    ): int {
        $maxIterations = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_MAX_ITERATIONS,
            ScopeInterface::SCOPE_STORE
        ) ?: 5;
        $iteration = 0;
        $currentQuery = $userQuery;

        while ($iteration < $maxIterations) {
            $iteration++;
            
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln('<fg=cyan>[DEBUG]</> <fg=yellow>=== Iteration ' . $iteration . '/' . $maxIterations . ' ===</>');
            }
            $this->debug($output, "Current query: $currentQuery", OutputInterface::VERBOSITY_VERY_VERBOSE);
            
            // Check if this is a final answer request (after tool execution)
            $isFinalAnswer = strpos($currentQuery, 'Based on the tool results') !== false || 
                            strpos($currentQuery, 'provide a final answer') !== false ||
                            strpos($currentQuery, 'provide a clear and helpful answer') !== false;
            
            // Build system message with tool instructions (only if not final answer)
            $systemMessage = $isFinalAnswer ? '' : $this->buildSystemMessage($tools);
            
            // Convert conversation history to messages format
            $messages = [];
            // Add system message first if we have tools and not asking for final answer
            if (!$isFinalAnswer && !empty($tools) && !empty($systemMessage)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $systemMessage
                ];
            }
            foreach ($conversationHistory as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
            
            // Build the full prompt with user query
            $fullPrompt = $this->buildPromptWithTools($currentQuery, $tools, $isFinalAnswer);
            
            // Debug: Show request details
            $this->debugAiRequest($output, $currentQuery, $messages, $tools);
            
            // Show what's actually being sent to the AI service
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $this->debug($output, '--- Actual Request to AI Service ---', OutputInterface::VERBOSITY_DEBUG);
                $actualRequest = [
                    'message' => $fullPrompt,
                    'messages_history' => $messages,
                    'maxTokens' => 2000,
                    'temperature' => 0.1,
                    'tools' => $tools
                ];
                $output->writeln(json_encode($actualRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            
            // Send request with tools - use the full prompt as the message
            // For final answer requests, don't pass tools to allow text response
            try {
                $this->debug($output, 'Sending request to AI service...', OutputInterface::VERBOSITY_VERBOSE);
                $toolsToSend = $isFinalAnswer ? [] : $tools; // Don't send tools when asking for final answer
                if ($isFinalAnswer) {
                    $this->debug($output, 'Final answer mode: tools disabled', OutputInterface::VERBOSITY_VERBOSE);
                }
                $response = $this->aiService->sendChatRequest(
                    $fullPrompt,  // Use full prompt instead of just the query
                    $messages,
                    4000,  // Higher limit for GPT-5 reasoning models
                    0.1,
                    $toolsToSend
                );
                $this->debug($output, 'Received response from AI service', OutputInterface::VERBOSITY_VERBOSE);
            } catch (\Exception $e) {
                $output->writeln('<error>AI Service Error: ' . $e->getMessage() . '</error>');
                $this->debug($output, 'Exception: ' . $e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->debug($output, 'Stack trace: ' . $e->getTraceAsString(), OutputInterface::VERBOSITY_VERY_VERBOSE);
                }
                if ($isInteractive) {
                    $output->writeln('<comment>Try again or type "exit" to quit.</comment>');
                }
                return 1;
            }

            // Debug: Show response details
            $this->debugAiResponse($output, $response);

            // Check response structure
            if (empty($response)) {
                $output->writeln('<error>Empty response from AI service</error>');
                $this->debug($output, 'Response is completely empty', OutputInterface::VERBOSITY_VERBOSE);
                return 1;
            }

            $aiMessage = $response['message'] ?? '';
            $toolCalls = $response['tool_calls'] ?? null;
            
            // Debug: log if message is empty
            if (empty($aiMessage)) {
                if (!empty($toolCalls)) {
                    $this->debug($output, 'Response contains tool calls instead of text', OutputInterface::VERBOSITY_VERBOSE);
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                        $this->debug($output, 'Tool calls: ' . json_encode($toolCalls, JSON_PRETTY_PRINT), OutputInterface::VERBOSITY_VERY_VERBOSE);
                    }
                } else {
                    $output->writeln('<warning>Warning: AI response message is empty</warning>');
                    $this->debug($output, 'Response structure: ' . json_encode(array_keys($response)), OutputInterface::VERBOSITY_VERBOSE);
                    // Try alternative response keys
                    $aiMessage = $response['text'] ?? $response['content'] ?? '';
                    if (!empty($aiMessage)) {
                        $this->debug($output, 'Found message in alternative key (text/content)', OutputInterface::VERBOSITY_VERBOSE);
                    }
                }
            }
            
            // If still empty and no tool calls, show error
            if (empty($aiMessage) && empty($toolCalls)) {
                $output->writeln('<error>No message content or tool calls in AI response</error>');
                $this->debug($output, 'Full response: ' . json_encode($response, JSON_PRETTY_PRINT), OutputInterface::VERBOSITY_VERY_VERBOSE);
                return 1;
            }
            
            // Check if AI wants to call a tool (parse JSON from response)
            $toolCall = $this->parseToolCallFromResponse($aiMessage);
            
            if ($toolCall) {
                $functionName = $toolCall['tool'] ?? $toolCall['function'] ?? '';
                $arguments = $toolCall['arguments'] ?? [];
                
                $this->debug($output, "Tool call detected: $functionName", OutputInterface::VERBOSITY_VERBOSE);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->debug($output, 'Tool arguments: ' . json_encode($arguments), OutputInterface::VERBOSITY_VERY_VERBOSE);
                    if ($functionName === 'execute_sql_query' && isset($arguments['query'])) {
                        $this->debug($output, 'SQL executed: ' . $arguments['query'], OutputInterface::VERBOSITY_VERY_VERBOSE);
                    }
                }
                
                if ($isInteractive) {
                    $output->write('<fg=yellow>Using tool: ' . $functionName . '...</>');
                    $output->writeln('');
                } else {
                    $output->writeln('<fg=yellow>AI requested tool...</>');
                    $output->writeln('');
                    $output->writeln('<comment>Tool: ' . $functionName . '</comment>');
                }
                
                // Get tool from registry and execute
                $tool = $this->toolRegistry->getTool($functionName);
                if (!$tool) {
                    $output->writeln('<error>Unknown tool: ' . $functionName . '</error>');
                    $this->debug($output, 'Available tools: ' . implode(', ', array_map(function($t) {
                        return $t['function']['name'] ?? $t['name'] ?? 'unknown';
                    }, $tools)), OutputInterface::VERBOSITY_VERBOSE);
                    break;
                }
                
                try {
                    $this->debug($output, "Executing tool: $functionName", OutputInterface::VERBOSITY_VERBOSE);
                    $toolResult = $tool->execute($arguments, $this->allowDangerous);
                    $this->debug($output, "Tool execution completed", OutputInterface::VERBOSITY_VERBOSE);
                    
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                        $resultPreview = is_array($toolResult) ? json_encode($toolResult) : (string)$toolResult;
                        $resultPreview = strlen($resultPreview) > 200 ? substr($resultPreview, 0, 200) . '...' : $resultPreview;
                        $this->debug($output, "Tool result preview: $resultPreview", OutputInterface::VERBOSITY_VERY_VERBOSE);
                    }
                    
                    // Display tool results
                    $this->displayToolResults($output, $functionName, $toolResult);
                } catch (\Exception $e) {
                    $output->writeln('<error>Tool execution error: ' . $e->getMessage() . '</error>');
                    $this->debug($output, 'Tool exception: ' . $e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                        $this->debug($output, 'Stack trace: ' . $e->getTraceAsString(), OutputInterface::VERBOSITY_VERY_VERBOSE);
                    }
                    $toolResult = ['error' => $e->getMessage()];
                }
                
                // Add to conversation history
                $conversationHistory[] = [
                    'role' => 'user',
                    'content' => $currentQuery
                ];
                $conversationHistory[] = [
                    'role' => 'assistant',
                    'content' => "Tool call: $functionName"
                ];
                $conversationHistory[] = [
                    'role' => 'user',
                    'content' => "Tool result: " . json_encode($toolResult)
                ];
                
                // Update user query for next iteration - ask for final answer WITHOUT tools
                $currentQuery = "Based on the tool results provided above, provide a clear and helpful answer to the user's original question: " . $userQuery . "\n\nDo NOT use any tools. Provide a natural language response explaining the results.";
                $this->debug($output, "Updated query for next iteration: $currentQuery", OutputInterface::VERBOSITY_VERY_VERBOSE);
            } else {
                // AI provided final answer
                $this->debug($output, 'AI provided final answer (no tool call)', OutputInterface::VERBOSITY_VERBOSE);
                if (empty($aiMessage)) {
                    $output->writeln('<warning>AI response is empty. This might indicate an issue with the AI service.</warning>');
                    $this->debug($output, 'Empty message detected in final answer branch', OutputInterface::VERBOSITY_VERBOSE);
                    if (!$isInteractive) {
                        return 1;
                    }
                } else {
                    $this->debug($output, 'Outputting AI response (length: ' . strlen($aiMessage) . ' chars)', OutputInterface::VERBOSITY_VERBOSE);
                    $displayText = $this->formatOptionsForDisplay($aiMessage);
                    if ($isInteractive) {
                        $output->writeln('<info>Answer:</info>');
                        $this->streamOutput($output, $displayText);
                    } else {
                        $output->writeln('<info>Answer:</info>');
                        $output->writeln($displayText);
                    }
                }
                
                // Add to conversation history
                $conversationHistory[] = [
                    'role' => 'user',
                    'content' => $userQuery
                ];
                $conversationHistory[] = [
                    'role' => 'assistant',
                    'content' => $aiMessage ?: '(Empty response)'
                ];
                
                break;
            }
        }

        if ($iteration >= $maxIterations) {
            $output->writeln('<warning>Maximum iterations reached. Stopping.</warning>');
            $this->debug($output, "Reached max iterations ($maxIterations)", OutputInterface::VERBOSITY_VERBOSE);
            $this->debug($output, 'Last query: ' . $currentQuery, OutputInterface::VERBOSITY_VERBOSE);
            $this->debug($output, 'Last response: ' . ($aiMessage ?? 'empty'), OutputInterface::VERBOSITY_VERBOSE);
        }

        $this->debug($output, "=== End of query processing (iterations: $iteration) ===", OutputInterface::VERBOSITY_VERBOSE);
        return 0;
    }

    /**
     * Translate natural language to SQL using AI
     */
    protected function translateToSQL($userQuery)
    {
        if ($this->allowDangerous) {
            $mode = 'read-write';
            $allowedOps = "SELECT, INSERT, UPDATE, DELETE, DESCRIBE, SHOW (for inspecting table structure and relations)";
            $examples = "Examples: 'DESCRIBE customer_entity', 'SHOW TABLES', 'SELECT * FROM customer_entity', 'UPDATE customer_entity SET firstname=\"John\" WHERE id=1'";
        } else {
            $mode = 'read-only';
            $allowedOps = "SELECT, DESCRIBE, SHOW (for inspecting table structure and relations)";
            $examples = "Examples: 'DESCRIBE customer_entity', 'SHOW TABLES', 'SELECT * FROM customer_entity'";
        }
        
        $prompt = "You are a Magento 2 database expert in $mode mode.\n\n";
        $prompt .= "Translate this natural language query to SQL:\n";
        $prompt .= "Query: " . $userQuery . "\n\n";
        $prompt .= "Magento tables reference:\n";
        $prompt .= "- customer_entity: id, email, firstname, lastname, created_at\n";
        $prompt .= "- sales_order: entity_id, customer_id, status, created_at, total_paid\n";
        $prompt .= "- catalog_product_entity: entity_id, sku, attribute_set_id, type_id, created_at\n";
        $prompt .= "- sales_order_item: item_id, order_id, product_id, sku, qty_ordered, price\n\n";
        $prompt .= "Allowed queries: " . $allowedOps . "\n";
        $prompt .= $examples . "\n\n";
        $prompt .= "Return ONLY the SQL query, nothing else. No explanation.";

        $response = $this->aiService->sendChatRequest($prompt, [], 500, 0.1);
        $sql = trim($response['message'] ?? '');
        
        // Clean up the response
        $sql = str_replace(['```sql', '```', '`'], '', $sql);
        $sql = trim($sql);
        
        return $sql;
    }

    /**
     * Validate query permissions before execution
     */
    protected function validateQuery($sqlQuery, OutputInterface $output)
    {
        $sqlUpper = strtoupper(trim($sqlQuery));

        // Check for dangerous operations
        $dangerousOps = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER'];
        foreach ($dangerousOps as $op) {
            if (stripos($sqlUpper, $op) === 0) {
                if (!$this->allowDangerous) {
                    throw new \Exception(
                        $op . ' operations not allowed. Use --allow-dangerous flag to enable (use with caution!)'
                    );
                }
                    $output->writeln('<error>WARNING: ' . $op . ' operation detected!</error>');
            }
        }

        // Allow safe read operations: SELECT, DESCRIBE, SHOW, EXPLAIN
        $safeOps = ['SELECT', 'DESCRIBE', 'SHOW', 'EXPLAIN', 'DESC'];
        $isSafeOp = false;
        
        foreach ($safeOps as $op) {
            if (stripos($sqlUpper, $op) === 0) {
                $isSafeOp = true;
                break;
            }
        }

        // Enforce safe operations in safe mode
        if (!$this->allowDangerous && !$isSafeOp) {
            throw new \Exception('Only SELECT, DESCRIBE, SHOW, and EXPLAIN queries allowed in safe mode. Use --allow-dangerous to enable write operations.');
        }
    }

    /**
     * Check if query is a schema inspection query (DESCRIBE, SHOW, etc.)
     */
    protected function isSchemaQuery($sqlQuery)
    {
        $sqlUpper = strtoupper(trim($sqlQuery));
        $schemaOps = ['DESCRIBE', 'SHOW', 'DESC', 'EXPLAIN'];
        
        foreach ($schemaOps as $op) {
            if (stripos($sqlUpper, $op) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate final query by sending schema info back to AI with original question
     */
    protected function generateFinalQuery($userQuery, $schemaResults)
    {
        // Format schema results as readable text
        $schemaInfo = $this->formatSchemaForAI($schemaResults);
        
        $mode = $this->allowDangerous ? 'read-write' : 'read-only (SELECT queries)';
        
        $prompt = "You are a Magento 2 database expert in $mode mode.\n\n";
        $prompt .= "User's original question: " . $userQuery . "\n\n";
        $prompt .= "Here is the database schema information:\n";
        $prompt .= $schemaInfo . "\n\n";
        $prompt .= "Based on this schema information, generate a SQL query to answer the user's question.\n";
        $prompt .= "Return ONLY the SQL query, nothing else. No explanation.";
        
        $response = $this->aiService->sendChatRequest($prompt, [], 500, 0.1);
        $sql = trim($response['message'] ?? '');
        
        // Clean up the response
        $sql = str_replace(['```sql', '```', '`'], '', $sql);
        $sql = trim($sql);
        
        return $sql;
    }

    /**
     * Format schema results for AI consumption
     */
    protected function formatSchemaForAI($results)
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

    /**
     * Execute the generated SQL query
     */
    protected function executeQuery($sqlQuery)
    {
        try {
            $connection = $this->resourceConnection->getConnection();

            // Strip trailing semicolon — Magento DB adapter rejects it as "multiple queries"
            $sqlQuery = rtrim($sqlQuery, " \t\n\r\0\x0B;");

            // Limit results to 100 rows for safety
            if (stripos($sqlQuery, 'LIMIT') === false && stripos($sqlQuery, 'SELECT') === 0) {
                $sqlQuery .= ' LIMIT 100';
            }

            $results = $connection->fetchAll($sqlQuery);
            return $results;

        } catch (\Exception $e) {
            throw new \Exception('Query execution failed: ' . $e->getMessage());
        }
    }


    /**
     * Display tool execution results
     */
    protected function displayToolResults(OutputInterface $output, string $toolName, array $result): void
    {
        if (isset($result['error'])) {
            $output->writeln('<error>Error: ' . $result['error'] . '</error>');
            return;
        }

        if ($toolName === 'execute_sql_query') {
            if (isset($result['data'])) {
                $this->displayResults($output, $result['data']);
            }
        } elseif ($toolName === 'describe_table') {
            if (isset($result['columns'])) {
                $this->displayResults($output, $result['columns']);
            }
        } elseif ($toolName === 'grep_files') {
            if (isset($result['matches'])) {
                $output->writeln('<info>Found ' . $result['match_count'] . ' matches:</info>');
                $output->writeln('');
                foreach ($result['matches'] as $match) {
                    $output->writeln(sprintf(
                        '<comment>%s:%d</comment> - %s',
                        $match['file'],
                        $match['line'],
                        $match['content']
                    ));
                }
            }
        } elseif ($toolName === 'read_file') {
            if (isset($result['content'])) {
                $output->writeln('<info>File: ' . $result['file_path'] . '</info>');
                $output->writeln('<info>Lines ' . $result['start_line'] . '-' . $result['end_line'] . ' of ' . $result['total_lines'] . ':</info>');
                $output->writeln('');
                foreach ($result['content'] as $line) {
                    $output->writeln(sprintf(
                        '<comment>%4d</comment> | %s',
                        $line['line'],
                        $line['content']
                    ));
                }
            }
        } elseif ($toolName === 'get_magento_info') {
            if (isset($result['preview'])) {
                $output->writeln('  <comment>' . $result['preview'] . '</comment>');
            }
        } elseif ($toolName === 'run_magento_cli') {
            if (isset($result['output'])) {
                $lines = array_filter(explode("\n", $result['output']), 'strlen');
                foreach (array_slice($lines, 0, 30) as $line) {
                    $output->writeln('  ' . $line);
                }
            }
        } elseif ($toolName === 'ask_user') {
            $output->writeln('<fg=magenta>User answered: ' . ($result['answer'] ?? '') . '</>');
        } else {
            // Generic display for other tools
            if (isset($result['preview'])) {
                $output->writeln('<info>Result:</info>');
                $output->writeln($result['preview']);
            }
        }
    }


    private const MAX_DISPLAY_ROWS = 20;
    private const MAX_CELL_LENGTH = 80;

    /**
     * Display query results in table format
     */
    protected function displayResults(OutputInterface $output, $results)
    {
        if (empty($results)) {
            $output->writeln('<warning>No results found</warning>');
            return;
        }

        $totalRows = count($results);
        $displayCount = min(self::MAX_DISPLAY_ROWS, $totalRows);
        $truncated = $totalRows > self::MAX_DISPLAY_ROWS;

        $rowLabel = $totalRows === 1 ? '1 row' : $totalRows . ' rows';
        $output->writeln('<info>SQL results (' . $rowLabel . '):</info>');
        $output->writeln('');

        $table = new Table($output);
        $headers = array_keys($results[0]);
        $table->setHeaders($headers);

        for ($i = 0; $i < $displayCount; $i++) {
            $row = [];
            foreach ($results[$i] as $val) {
                $str = $val === null ? '' : (string) $val;
                if (strlen($str) > self::MAX_CELL_LENGTH) {
                    $str = substr($str, 0, self::MAX_CELL_LENGTH - 3) . '...';
                    $truncated = true;
                }
                $row[] = $str;
            }
            $table->addRow($row);
        }

        $table->render();

        if ($truncated) {
            $output->writeln('');
            $output->writeln('<comment>Data is too long - partially printed.</comment>');
            if ($totalRows > self::MAX_DISPLAY_ROWS) {
                $output->writeln('<comment>Showing first ' . self::MAX_DISPLAY_ROWS . ' of ' . $totalRows . ' rows.</comment>');
            }
        }
    }

    /**
     * Output debug message based on verbosity level
     *
     * @param OutputInterface $output
     * @param string $message
     * @param int $minVerbosity Minimum verbosity level (OutputInterface::VERBOSITY_*)
     */
    protected function debug(OutputInterface $output, string $message, int $minVerbosity = OutputInterface::VERBOSITY_VERBOSE): void
    {
        if ($output->getVerbosity() >= $minVerbosity) {
            $output->writeln('<fg=cyan>[DEBUG]</> ' . $message);
        }
    }

    /**
     * Get verbosity level name for display
     *
     * @param int $verbosity
     * @return string
     */
    protected function getVerbosityName(int $verbosity): string
    {
        return match($verbosity) {
            OutputInterface::VERBOSITY_QUIET => 'QUIET',
            OutputInterface::VERBOSITY_NORMAL => 'NORMAL',
            OutputInterface::VERBOSITY_VERBOSE => 'VERBOSE (-v)',
            OutputInterface::VERBOSITY_VERY_VERBOSE => 'VERY_VERBOSE (-vv)',
            OutputInterface::VERBOSITY_DEBUG => 'DEBUG (-vvv or --debug)',
            default => 'UNKNOWN'
        };
    }

    /**
     * Output debug info about AI request
     *
     * @param OutputInterface $output
     * @param string $query
     * @param array $messages
     * @param array $tools
     */
    protected function debugAiRequest(OutputInterface $output, string $query, array $messages, array $tools): void
    {
        if ($output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
            return;
        }
        $this->debug($output, '=== AI Request Debug ===', OutputInterface::VERBOSITY_VERBOSE);
        $this->debug($output, 'Current Query: ' . $query, OutputInterface::VERBOSITY_VERBOSE);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->debug($output, '--- Full Conversation History (' . count($messages) . ' messages) ---', OutputInterface::VERBOSITY_VERY_VERBOSE);
            if (empty($messages)) {
                $this->debug($output, '  (No conversation history)', OutputInterface::VERBOSITY_VERY_VERBOSE);
            } else {
                foreach ($messages as $idx => $msg) {
                    $role = $msg['role'] ?? 'unknown';
                    $roleColor = $role === 'user' ? '<fg=green>' : ($role === 'assistant' ? '<fg=magenta>' : ($role === 'system' ? '<fg=cyan>' : ''));
                    $roleTag = $roleColor ? $roleColor . $role . '</>' : $role;
                    $content = $msg['content'] ?? '';
                    $contentPreview = is_string($content) ? $content : json_encode($content);
                    $contentLength = strlen($contentPreview);
                    $preview = $contentLength > 500 ? substr($contentPreview, 0, 500) . '...' : $contentPreview;
                    $this->debug($output, "  [$idx] Role: $roleTag (length: $contentLength chars)", OutputInterface::VERBOSITY_VERY_VERBOSE);
                    $this->debug($output, "      Content: $preview", OutputInterface::VERBOSITY_VERY_VERBOSE);
                    $output->writeln('');
                }
            }
            if (!empty($tools)) {
                $toolNames = array_map(
                    fn($t) => $t['function']['name'] ?? $t['name'] ?? '?',
                    $tools
                );
                $this->debug($output, '--- Tools (' . count($tools) . '): ' . implode(', ', $toolNames), OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
        } else {
            $this->debug($output, 'Messages: ' . count($messages) . ', Tools: ' . count($tools), OutputInterface::VERBOSITY_VERBOSE);
        }
        
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $this->debug($output, '--- Full Request Structure (JSON) ---', OutputInterface::VERBOSITY_DEBUG);
            $requestStructure = [
                'message' => $query,
                'messages' => $messages,
                'tools' => $tools,
                'maxTokens' => 2000,
                'temperature' => 0.1
            ];
            $output->writeln(json_encode($requestStructure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $output->writeln('');
        }
    }

    /**
     * Output debug info about AI response
     *
     * @param OutputInterface $output
     * @param array $response
     */
    protected function debugAiResponse(OutputInterface $output, array $response): void
    {
        $this->debug($output, '=== AI Response Debug ===', OutputInterface::VERBOSITY_VERBOSE);
        $this->debug($output, 'Response keys: ' . implode(', ', array_keys($response)), OutputInterface::VERBOSITY_VERBOSE);
        
        if (isset($response['message'])) {
            $msgLength = strlen($response['message']);
            $this->debug($output, "Message length: $msgLength chars", OutputInterface::VERBOSITY_VERBOSE);
            if ($msgLength > 0) {
                $preview = substr($response['message'], 0, 100);
                $this->debug($output, "Message preview: $preview...", OutputInterface::VERBOSITY_VERBOSE);
            }
        }
        
        if (isset($response['tokens'])) {
            $tokens = $response['tokens'];
            $input = $tokens['input'] ?? $tokens['prompt_tokens'] ?? 0;
            $output_tokens = $tokens['output'] ?? $tokens['completion_tokens'] ?? 0;
            $total = $tokens['total'] ?? $tokens['total_tokens'] ?? 0;
            $this->debug($output, "Tokens - Input: $input, Output: $output_tokens, Total: $total", OutputInterface::VERBOSITY_VERBOSE);
        }
        
        if (isset($response['cost'])) {
            $this->debug($output, 'Price: $' . number_format($response['cost'], 6), OutputInterface::VERBOSITY_VERBOSE);
        }
        
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            if (isset($response['model'])) {
                $this->debug($output, 'Model: ' . $response['model'], OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
            if (isset($response['provider'])) {
                $this->debug($output, 'Provider: ' . $response['provider'], OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
            if (isset($response['finish_reason'])) {
                $this->debug($output, 'Finish reason: ' . $response['finish_reason'], OutputInterface::VERBOSITY_VERY_VERBOSE);
            }
        }
        
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $this->debug($output, 'Full response:', OutputInterface::VERBOSITY_DEBUG);
            $output->writeln(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Accumulate tokens and cost from a single response into session totals.
     */
    protected function accumulateSessionTotals(array $response, array &$sessionTotals): void
    {
        $sessionTotals['calls']++;
        if (isset($response['tokens'])) {
            $t = $response['tokens'];
            $sessionTotals['input'] += $t['input'] ?? $t['prompt_tokens'] ?? 0;
            $sessionTotals['output'] += $t['output'] ?? $t['completion_tokens'] ?? 0;
            $sessionTotals['total'] += $t['total'] ?? $t['total_tokens'] ?? 0;
        }
        if (isset($response['cost'])) {
            $sessionTotals['cost'] += (float) $response['cost'];
        }
    }

    /**
     * Output session totals (cumulative across all requests in loop).
     */
    protected function debugSessionTotals(OutputInterface $output, array $sessionTotals): void
    {
        if ($sessionTotals['calls'] > 0) {
            $this->debug(
                $output,
                sprintf(
                    'Session total - Tokens: Input %d, Output %d, Total %d; Price: $%s; LLM calls: %d',
                    $sessionTotals['input'],
                    $sessionTotals['output'],
                    $sessionTotals['total'],
                    number_format($sessionTotals['cost'], 6),
                    $sessionTotals['calls']
                ),
                OutputInterface::VERBOSITY_VERBOSE
            );
        }
    }

    // =========================================================================
    // Analyze mode helpers (used when --focus is provided)
    // =========================================================================

    /**
     * Format text for display: convert bullet options (- ) to numbered (1) 2) 3)).
     * Only adds select hint when preamble looks like a question (would you like, choose, etc.).
     *
     * @return string Formatted text for display
     */
    private function formatOptionsForDisplay(string $text): string
    {
        $parsed = $this->parseQuestionOptions($text);
        if (count($parsed['options']) < 2) {
            $this->lastParsedOptions = [];
            return $text;
        }
        $isQuestion = (bool) preg_match(
            '/\b(would you like|choose|select|which|pick|if you\'d like|or\s*$|want me to)\b/i',
            $parsed['preamble']
        );
        if (!$isQuestion) {
            $this->lastParsedOptions = [];
            return $text;
        }
        $this->lastParsedOptions = $parsed['options'];
        $numbered = '';
        foreach ($parsed['options'] as $i => $opt) {
            $numbered .= ($i > 0 ? "\n" : '') . ($i + 1) . ') ' . trim($opt);
        }
        $n = count($parsed['options']);
        $hint = "\n\n<comment>(Type 1-{$n} to select, or type your answer)</comment>";
        return trim($parsed['preamble']) . "\n\n" . $numbered . $hint;
    }

    /**
     * Parse options: only from a clear "Response options:" or "Choose:" section to avoid mixing
     * clarification questions (1) What field...) with selection options (1) Proceed...).
     *
     * @return array{preamble: string, options: string[]}
     */
    private function parseQuestionOptions(string $question): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $question);
        $preamble = '';
        $options = [];
        $inChoiceSection = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(Response options|Choose one?|Pick one?|Please pick|Select):\s*$/i', $line)) {
                $inChoiceSection = true;
                $preamble .= ($preamble !== '' ? "\n" : '') . $line;
                continue;
            }
            if ($inChoiceSection) {
                if (preg_match('/^\s*-\s+(.+)$/', $line, $m)) {
                    $options[] = $m[1];
                } elseif (preg_match('/^\s*(\d+)\)\s*(.+)$/', $line, $m)) {
                    $options[] = $m[2];
                } elseif (!empty($options) && trim($line) !== '' && preg_match('/^\s{2,}/', $line)) {
                    $options[array_key_last($options)] .= "\n" . trim($line);
                } elseif (trim($line) === '' || !preg_match('/^\s*[\d\-]/', $line)) {
                    $inChoiceSection = false;
                    if (trim($line) !== '') {
                        $preamble .= ($preamble !== '' ? "\n" : '') . $line;
                    }
                }
            } else {
                $preamble .= ($preamble !== '' ? "\n" : '') . $line;
            }
        }

        return [
            'preamble' => trim($preamble),
            'options'  => array_map('trim', $options),
        ];
    }

    /**
     * Return the merged list of built-in PHP tools and MCP server tools in OpenAI format.
     */
    private function getMergedTools(): array
    {
        $tools = $this->toolRegistry->getToolsForAI($this->allowDangerous);

        if ($this->mcpRegistry !== null) {
            $mcpTools = $this->mcpRegistry->getToolsForAI();
            if (!empty($mcpTools)) {
                $tools = array_merge($tools, $mcpTools);
            }
        }

        return $tools;
    }

    /**
     * Magento-expert system message injected when running in analyze mode.
     */
    private function buildSystemMessage(bool $allowDangerous): string
    {
        $mode = $allowDangerous ? 'read-write' : 'read-only';

        return <<<SYSTEM
You are an expert Magento 2 architect and security engineer performing a live installation audit in {$mode} mode.

TOOLS AVAILABLE:
- get_magento_info: Start here. Returns version, PHP version, module count, DB size, key table counts, invalid indexers.
- execute_sql_query: Run SQL against the Magento database. Pass ONLY the SQL statement (no explanations). Always add LIMIT to SELECT queries.
- describe_table: Get a table's column structure before querying it.
- grep_files: Search files in the Magento codebase for patterns (code, config, credentials).
- read_file: Read specific files. Paths are relative to Magento root.
- run_magento_cli: Run safe read-only bin/magento subcommands (indexer:status, cache:status, module:status, cron:status, --version, info:adminuri).
- ask_user: Ask the operator a clarifying question when you need human input.

When offering choices: use "Response options:" or "Choose one:" followed by 1) 2) 3) 4) etc. When the user selects, proceed—do not ask again. If you have data from a tool, present it—do not ask. Ask only when the request is unclear.

INVESTIGATION APPROACH:
1. Always start with get_magento_info to establish a baseline.
2. Investigate each area systematically using the appropriate tools.
3. Ask the operator via ask_user when a finding needs clarification.
4. Use execute_sql_query to verify configuration values in core_config_data.
5. Use grep_files to check for suspicious code patterns in CMS content or PHP files.
6. Use run_magento_cli to check operational health (indexers, caches, cron).

IMPORTANT MAGENTO TABLES:
- core_config_data (config_id, scope, scope_id, path, value) — system configuration
- cms_page (page_id, title, content, is_active) — CMS pages
- cms_block (block_id, title, content, is_active) — CMS blocks
- admin_user (user_id, username, email, is_active, logdate) — admin accounts
- indexer_state (indexer_id, status, updated) — indexer health
- cron_schedule (schedule_id, job_code, status, created_at, executed_at) — cron health
- setup_module (module, schema_version, data_version) — installed modules

FINAL REPORT FORMAT:
## Overview
Brief summary of the installation (version, scale, overall health score)

## Critical Issues (CRITICAL/HIGH)
- Each finding with: description, evidence (table/file/value), recommended fix

## Warnings (MEDIUM)
- Each warning with: description and recommendation

## Recommendations (LOW/Informational)
- Performance, maintenance, best-practice suggestions

Rate findings: CRITICAL | HIGH | MEDIUM | LOW
SYSTEM;
    }

    /**
     * Build the initial user prompt for the chosen analysis focus.
     */
    private function buildAnalysisPrompt(string $focus): string
    {
        $focusInstructions = match ($focus) {
            'security'    => "FOCUS ON SECURITY:\n"
                . "- Check core_config_data and cms_block/cms_page for injected malicious JavaScript (eval, atob, fromCharCode, base64 patterns, external script tags)\n"
                . "- Inspect admin user accounts: how many, any suspicious accounts, last login dates\n"
                . "- Check if admin URL is set to a non-default path\n"
                . "- Verify HTTPS is configured for store and admin\n"
                . "- Check for exposed sensitive config (payment keys, API credentials) in CMS content\n"
                . "- Search for suspicious PHP files recently modified in app/code and pub/media",

            'performance' => "FOCUS ON PERFORMANCE:\n"
                . "- Run indexer:status via run_magento_cli — report any invalid/suspended indexers\n"
                . "- Run cache:status — check which caches are disabled\n"
                . "- Count total installed modules (many modules slow Magento significantly)\n"
                . "- Check full-page cache configuration in core_config_data\n"
                . "- Report product/category/CMS counts vs. typical thresholds\n"
                . "- Check if Varnish or Redis is configured (look for cache_backend in core_config_data)\n"
                . "- Report DB size and largest tables",

            'config'      => "FOCUS ON CONFIGURATION HEALTH:\n"
                . "- Check cron_schedule for recent job execution and any stuck/missed jobs\n"
                . "- Verify email sender configuration (trans_email, smtp settings)\n"
                . "- Check base URL configuration (secure/unsecure base URLs, should match production domain)\n"
                . "- Verify payment method configurations are active and complete\n"
                . "- Check tax configuration\n"
                . "- Verify shipping method configuration\n"
                . "- Check store locale and currency settings",

            'db'          => "FOCUS ON DATABASE HEALTH:\n"
                . "- Report DB size per major table group (catalog, sales, customer, EAV, search)\n"
                . "- Check for unusually large tables that may need archiving\n"
                . "- Verify referential integrity in CMS content (cms_block, cms_page)\n"
                . "- Check for suspicious patterns in core_config_data values\n"
                . "- Report orphaned records or data anomalies\n"
                . "- Count active vs. total records in key tables",

            default       => "PERFORM A COMPREHENSIVE ANALYSIS covering ALL of the following areas:\n"
                . "1. SECURITY: Check for injected malicious code in CMS/config, admin accounts, HTTPS, admin URL\n"
                . "2. PERFORMANCE: Indexer status, cache configuration, module count, FPC, Redis/Varnish\n"
                . "3. CONFIGURATION: Cron health, email settings, base URLs, payment/shipping setup\n"
                . "4. DATABASE: Table sizes, content integrity, suspicious values\n"
                . "Be thorough. Use ask_user if you find something that needs clarification.",
        };

        return "Please analyze this Magento 2 installation and provide a comprehensive report.\n\n"
            . $focusInstructions . "\n\n"
            . "Start with get_magento_info to establish a baseline, then investigate systematically.";
    }

    /**
     * Save the final analysis report to a file.
     */
    private function saveReport(string $filePath, string $content, OutputInterface $output): void
    {
        try {
            file_put_contents($filePath, $content);
            $output->writeln('<info>Report saved to: ' . $filePath . '</info>');
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to save report: ' . $e->getMessage() . '</error>');
        }
    }
}

