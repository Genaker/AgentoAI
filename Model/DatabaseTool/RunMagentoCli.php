<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\Filesystem\DirectoryList;

/**
 * Runs a whitelisted read-only bin/magento CLI subcommand and returns its output.
 *
 * Only commands in ALLOWED_COMMANDS are permitted. The full command string is never
 * passed to the shell directly — only the validated subcommand name (and optional
 * safe arguments) are appended, preventing command injection.
 */
class RunMagentoCli implements DatabaseToolInterface
{
    /**
     * Exact first token of the bin/magento subcommand that are permitted.
     * All others are rejected.
     */
    private const ALLOWED_COMMANDS = [
        'indexer:status',
        'cache:status',
        'module:status',
        'cron:status',
        'info:adminuri',
        '--version',
    ];

    public function __construct(
        private readonly DirectoryList $directoryList
    ) {
    }

    public function getName(): string
    {
        return 'run_magento_cli';
    }

    public function getDescription(): string
    {
        return 'Run a safe, read-only bin/magento CLI command and return its output. '
            . 'Allowed subcommands: ' . implode(', ', self::ALLOWED_COMMANDS) . '. '
            . 'Use this to check indexer status, cache status, enabled modules, cron health, etc.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'command' => [
                    'type'        => 'string',
                    'description' => 'The bin/magento subcommand to run (e.g. "indexer:status"). '
                        . 'Allowed: ' . implode(', ', self::ALLOWED_COMMANDS),
                ],
            ],
            'required'   => ['command'],
        ];
    }

    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $command = trim($arguments['command'] ?? '');

        if ($command === '') {
            return ['success' => false, 'error' => 'No command provided.'];
        }

        // Extract just the subcommand token (first word) for whitelist check
        $commandToken = explode(' ', $command)[0];

        if (!$this->isAllowedCommand($commandToken)) {
            return [
                'success' => false,
                'error'   => sprintf(
                    "Command '%s' is not in the allowed list. Allowed: %s",
                    $commandToken,
                    implode(', ', self::ALLOWED_COMMANDS)
                ),
            ];
        }

        try {
            $magentoRoot = $this->directoryList->getRoot();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Could not determine Magento root: ' . $e->getMessage()];
        }

        $binMagento = $magentoRoot . '/bin/magento';

        if (!is_file($binMagento)) {
            return ['success' => false, 'error' => 'bin/magento not found at: ' . $binMagento];
        }

        // Build the shell command using the validated token only; strip ANSI for clean output
        $fullCommand = sprintf(
            '%s %s %s --no-ansi 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($binMagento),
            // Only allow the whitelisted token — no arbitrary additional args
            escapeshellarg($commandToken)
        );

        $outputLines = [];
        $returnCode  = 0;

        if (!function_exists('exec')) {
            return ['success' => false, 'error' => 'exec() is disabled in this PHP environment.'];
        }

        exec($fullCommand, $outputLines, $returnCode);
        $output = implode("\n", $outputLines);

        return [
            'success'     => $returnCode === 0,
            'command'     => $commandToken,
            'output'      => $output,
            'return_code' => $returnCode,
            'preview'     => mb_substr($output, 0, 300) . (mb_strlen($output) > 300 ? '...' : ''),
        ];
    }

    /**
     * Check whether a subcommand token is in the allowed list.
     */
    public function isAllowedCommand(string $commandToken): bool
    {
        return in_array($commandToken, self::ALLOWED_COMMANDS, true);
    }

    /**
     * Return the list of allowed commands (for testing / display).
     *
     * @return string[]
     */
    public function getAllowedCommands(): array
    {
        return self::ALLOWED_COMMANDS;
    }
}
