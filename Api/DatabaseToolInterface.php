<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Api;

/**
 * Database Tool Interface
 * 
 * Implement this interface to create custom database tools that can be used
 * by the LlmAnalyzer CLI command (`genaker:agento:llm`) via tool calling.
 * 
 * @api
 */
interface DatabaseToolInterface
{
    /**
     * Get tool name (must be unique)
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get tool description for AI
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get tool parameters schema (OpenAPI-style JSON schema)
     *
     * @return array
     */
    public function getParametersSchema(): array;

    /**
     * Execute the tool
     *
     * @param array $arguments Tool arguments
     * @param bool $allowDangerous Whether dangerous operations are allowed
     * @return array Result array with 'success', 'data', 'preview', etc.
     * @throws \Exception
     */
    public function execute(array $arguments, bool $allowDangerous = false): array;
}
