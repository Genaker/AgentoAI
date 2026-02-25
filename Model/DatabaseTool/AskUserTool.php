<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Genaker\MagentoMcpAi\Model\Service\UserInteractionContext;

/**
 * Tool that allows the LLM to ask the operator a clarifying question mid-analysis.
 *
 * When called in interactive (CLI) mode the question is shown to the user and their
 * answer is returned to the LLM so it can continue the analysis. In non-interactive
 * contexts (web, cron) a placeholder response is returned.
 */
class AskUserTool implements DatabaseToolInterface
{
    public function __construct(
        private readonly UserInteractionContext $context
    ) {
    }

    public function getName(): string
    {
        return 'ask_user';
    }

    public function getDescription(): string
    {
        return 'Ask the operator a clarifying question or request additional information '
            . 'needed to continue the analysis. Use this when you need human input before '
            . 'you can proceed. The answer will be returned to you.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'question' => [
                    'type'        => 'string',
                    'description' => 'The question to ask the operator.',
                ],
            ],
            'required'   => ['question'],
        ];
    }

    /**
     * @param array $arguments ['question' => string]
     * @param bool  $allowDangerous Unused; questions are never dangerous
     */
    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $question = trim($arguments['question'] ?? '');

        if ($question === '') {
            return ['success' => false, 'error' => 'No question provided.'];
        }

        $answer = $this->context->ask($question);

        return [
            'success'  => true,
            'question' => $question,
            'answer'   => $answer,
        ];
    }
}
