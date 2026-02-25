<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Api\DatabaseToolInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\DirectoryList;

/**
 * Returns a concise snapshot of the Magento installation without requiring the
 * LLM to know table names or write SQL. Ideal as the first tool called in any
 * Magento analysis session.
 */
class GetMagentoInfo implements DatabaseToolInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function getName(): string
    {
        return 'get_magento_info';
    }

    public function getDescription(): string
    {
        return 'Get a comprehensive snapshot of the Magento installation: version, edition, '
            . 'PHP version, Magento root path, installed module count, database size, '
            . 'product/customer/order counts, invalid indexer count, and CMS content counts. '
            . 'Use this as the first step of any Magento analysis.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(), // no required parameters
            'required'   => [],
        ];
    }

    public function execute(array $arguments, bool $allowDangerous = false): array
    {
        $connection = $this->resourceConnection->getConnection();

        try {
            $moduleCount = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM setup_module WHERE schema_version IS NOT NULL'
            );
        } catch (\Throwable $e) {
            $moduleCount = null;
        }

        try {
            $dbSizeMb = (float)$connection->fetchOne(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()"
            );
        } catch (\Throwable $e) {
            $dbSizeMb = null;
        }

        try {
            $productCount = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $this->resourceConnection->getTableName('catalog_product_entity')
            );
        } catch (\Throwable $e) {
            $productCount = null;
        }

        try {
            $customerCount = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $this->resourceConnection->getTableName('customer_entity')
            );
        } catch (\Throwable $e) {
            $customerCount = null;
        }

        try {
            $orderCount = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $this->resourceConnection->getTableName('sales_order')
            );
        } catch (\Throwable $e) {
            $orderCount = null;
        }

        try {
            $invalidIndexerCount = (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM " . $this->resourceConnection->getTableName('indexer_state')
                . " WHERE status != 'valid'"
            );
        } catch (\Throwable $e) {
            $invalidIndexerCount = null;
        }

        try {
            $cmsPageCount = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $this->resourceConnection->getTableName('cms_page')
            );
            $cmsBlockCount = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $this->resourceConnection->getTableName('cms_block')
            );
        } catch (\Throwable $e) {
            $cmsPageCount = $cmsBlockCount = null;
        }

        try {
            $magentoRoot = $this->directoryList->getRoot();
        } catch (\Throwable $e) {
            $magentoRoot = null;
        }

        return [
            'success'              => true,
            'magento_version'      => $this->productMetadata->getVersion(),
            'edition'              => $this->productMetadata->getEdition(),
            'php_version'          => phpversion(),
            'magento_root'         => $magentoRoot,
            'module_count'         => $moduleCount,
            'db_size_mb'           => $dbSizeMb,
            'product_count'        => $productCount,
            'customer_count'       => $customerCount,
            'order_count'          => $orderCount,
            'invalid_indexer_count'=> $invalidIndexerCount,
            'cms_page_count'       => $cmsPageCount,
            'cms_block_count'      => $cmsBlockCount,
            'preview'              => sprintf(
                'Magento %s %s | PHP %s | %d modules | DB %.1f MB | %d products | %d orders | %d invalid indexers',
                $this->productMetadata->getEdition(),
                $this->productMetadata->getVersion(),
                phpversion(),
                $moduleCount ?? 0,
                $dbSizeMb ?? 0,
                $productCount ?? 0,
                $orderCount ?? 0,
                $invalidIndexerCount ?? 0
            ),
        ];
    }
}
