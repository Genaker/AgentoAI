<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\Mcp\Server;

use Genaker\MagentoMcpAi\Api\Mcp\McpServerInterface;

/**
 * Mock MCP server — returns static test data.
 *
 * Purpose: integration testing and demonstrating the MCP tool-calling flow
 * without needing a real subprocess. Enable it in di.xml to verify that the
 * LLM correctly discovers and calls MCP tools.
 *
 * Exposed tools:
 *   mcp__mock__get_store_summary   — returns a fake store summary
 *   mcp__mock__get_top_products    — returns a static list of top products
 *   mcp__mock__echo_input          — echoes the 'message' argument back
 *
 * To enable, add this to di.xml (or a custom di.xml in your module):
 *
 *   <type name="Genaker\MagentoMcpAi\Model\Mcp\Registry">
 *       <arguments>
 *           <argument name="servers" xsi:type="array">
 *               <item name="mock" xsi:type="object">
 *                   Genaker\MagentoMcpAi\Model\Mcp\Server\MockDataMcpServer
 *               </item>
 *           </argument>
 *       </arguments>
 *   </type>
 *
 * Then run:
 *   bin/magento setup:di:compile && bin/magento genaker:agento:llm \
 *       "What does the mock MCP server tell us about the store?" --debug
 */
class MockDataMcpServer implements McpServerInterface
{
    private const SERVER_NAME = 'mock';

    private const TOOLS = [
        [
            'name'        => 'get_store_summary',
            'description' => 'Returns a static summary of the store including order count, revenue, and top category.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'get_top_products',
            'description' => 'Returns a static list of the top 3 best-selling products.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of products to return (default: 3)',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'echo_input',
            'description' => 'Echoes the provided message back. Useful for testing tool call round-trips.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'message' => [
                        'type'        => 'string',
                        'description' => 'The message to echo back',
                    ],
                ],
                'required'   => ['message'],
            ],
        ],
    ];

    // -------------------------------------------------------------------------
    // McpServerInterface
    // -------------------------------------------------------------------------

    public function getName(): string
    {
        return self::SERVER_NAME;
    }

    public function listTools(): array
    {
        return self::TOOLS;
    }

    public function callTool(string $toolName, array $arguments): array
    {
        return match ($toolName) {
            'get_store_summary' => $this->getStoreSummary(),
            'get_top_products'  => $this->getTopProducts((int)($arguments['limit'] ?? 3)),
            'echo_input'        => $this->echoInput($arguments['message'] ?? ''),
            default             => [
                'success' => false,
                'error'   => "MockDataMcpServer: unknown tool '$toolName'",
            ],
        };
    }

    public function isAvailable(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Tool implementations (static data)
    // -------------------------------------------------------------------------

    private function getStoreSummary(): array
    {
        $text = <<<TEXT
            Store Summary (MOCK DATA — for testing only)
            ─────────────────────────────────────────────
            Total orders:    12,450
            Total revenue:   $1,234,567.89
            Avg order value: $99.16
            Top category:    Electronics
            Active products: 3,200
            Customers:       8,750
            Last updated:    2026-01-15 (static test fixture)
            TEXT;

        return [
            'success' => true,
            'result'  => $text,
            'preview' => 'Mock store summary: 12,450 orders, $1.23M revenue',
        ];
    }

    private function getTopProducts(int $limit): array
    {
        $all = [
            ['rank' => 1, 'sku' => 'MOCK-001', 'name' => 'Wireless Headphones Pro',     'orders' => 842,  'revenue' => '$75,780'],
            ['rank' => 2, 'sku' => 'MOCK-002', 'name' => 'Smart Watch Ultra',            'orders' => 731,  'revenue' => '$146,200'],
            ['rank' => 3, 'sku' => 'MOCK-003', 'name' => 'Portable Bluetooth Speaker',   'orders' => 619,  'revenue' => '$37,140'],
            ['rank' => 4, 'sku' => 'MOCK-004', 'name' => 'USB-C Hub 7-in-1',             'orders' => 512,  'revenue' => '$25,600'],
            ['rank' => 5, 'sku' => 'MOCK-005', 'name' => 'Mechanical Keyboard RGB',      'orders' => 488,  'revenue' => '$68,320'],
        ];

        $rows = array_slice($all, 0, max(1, min($limit, 5)));

        $lines = ["Top {$limit} Best-Selling Products (MOCK DATA)", str_repeat('─', 50)];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%d %-30s SKU: %-10s Orders: %4d  Revenue: %s',
                $row['rank'], $row['name'], $row['sku'], $row['orders'], $row['revenue']
            );
        }

        $text = implode("\n", $lines);

        return [
            'success' => true,
            'result'  => $text,
            'preview' => "Top {$limit} products (mock): {$rows[0]['name']}, {$rows[1]['name']}...",
        ];
    }

    private function echoInput(string $message): array
    {
        if ($message === '') {
            return ['success' => false, 'error' => 'echo_input: "message" argument is required'];
        }

        return [
            'success' => true,
            'result'  => "Echo from MockDataMcpServer: $message",
            'preview' => "Echo: $message",
        ];
    }
}
