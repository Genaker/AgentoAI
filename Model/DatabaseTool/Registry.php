<?php
namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;

/**
 * Database Tool Registry
 * 
 * Collects and manages database tools registered via DI
 */
class Registry
{
    /**
     * @var DatabaseToolInterface[]
     */
    private $tools = [];

    /**
     * @param array $tools Array of DatabaseToolInterface instances
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            if ($tool instanceof DatabaseToolInterface) {
                $this->tools[$tool->getName()] = $tool;
            }
        }
    }

    /**
     * Get all registered tools
     *
     * @return DatabaseToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Get tool by name
     *
     * @param string $name
     * @return DatabaseToolInterface|null
     */
    public function getTool(string $name): ?DatabaseToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Get tools formatted for AI (OpenAI function calling format)
     *
     * @param bool $allowDangerous Whether dangerous operations are allowed
     * @return array
     */
    public function getToolsForAI(bool $allowDangerous = false): array
    {
        $tools = [];
        
        foreach ($this->tools as $tool) {
            // Skip dangerous tools if not allowed
            if (!$allowDangerous && $this->isDangerousTool($tool)) {
                continue;
            }
            
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParametersSchema()
                ]
            ];
        }
        
        return $tools;
    }

    /**
     * Check if tool is dangerous (contains write operations)
     *
     * @param DatabaseToolInterface $tool
     * @return bool
     */
    private function isDangerousTool(DatabaseToolInterface $tool): bool
    {
        $dangerousNames = ['insert', 'update', 'delete', 'drop', 'truncate', 'alter'];
        $toolName = strtolower($tool->getName());
        
        foreach ($dangerousNames as $dangerous) {
            if (strpos($toolName, $dangerous) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
