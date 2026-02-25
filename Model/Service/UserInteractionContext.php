<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Service;

/**
 * Shared mutable context that bridges CLI I/O to tools needing user interaction.
 *
 * The CLI command sets a callback via setAskCallback(); tools (e.g. AskUserTool)
 * call ask() to prompt the operator and receive a response. When no callback is
 * registered (non-interactive / web context) ask() returns a placeholder string.
 */
class UserInteractionContext
{
    /**
     * @var callable|null
     */
    private $askCallback = null;

    /**
     * Register the callback used to ask the operator a question.
     *
     * @param callable $callback fn(string $question): string
     */
    public function setAskCallback(callable $callback): void
    {
        $this->askCallback = $callback;
    }

    /**
     * Whether an interactive callback has been registered.
     */
    public function isInteractive(): bool
    {
        return $this->askCallback !== null;
    }

    /**
     * Ask the operator a question and return their answer.
     *
     * @param string $question
     * @return string User answer, or '[non-interactive]' when no callback is set
     */
    public function ask(string $question): string
    {
        if ($this->askCallback !== null) {
            return (string)($this->askCallback)($question);
        }

        return '[non-interactive: no user available to answer]';
    }
}
