<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Test\Unit\Model\DatabaseTool;

use Genaker\MagentoMcpAi\Model\DatabaseTool\GetMagentoInfo;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem\DirectoryList;
use PHPUnit\Framework\TestCase;

class GetMagentoInfoTest extends TestCase
{
    private ResourceConnection $resourceConnection;
    private ProductMetadataInterface $productMetadata;
    private DirectoryList $directoryList;
    private GetMagentoInfo $tool;

    protected function setUp(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturn('5');
        $connection->method('getTableName')->willReturnArgument(0);

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->productMetadata = $this->createMock(ProductMetadataInterface::class);
        $this->productMetadata->method('getVersion')->willReturn('2.4.7');
        $this->productMetadata->method('getEdition')->willReturn('Community');

        $this->directoryList = $this->createMock(DirectoryList::class);
        $this->directoryList->method('getRoot')->willReturn('/var/www/magento');

        $this->tool = new GetMagentoInfo(
            $this->resourceConnection,
            $this->productMetadata,
            $this->directoryList
        );
    }

    public function testGetName(): void
    {
        $this->assertEquals('get_magento_info', $this->tool->getName());
    }

    public function testGetParametersSchemaHasNoRequired(): void
    {
        $schema = $this->tool->getParametersSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertEmpty($schema['required']);
    }

    public function testExecuteSuccessIsTrue(): void
    {
        $result = $this->tool->execute([]);

        $this->assertTrue($result['success']);
    }

    public function testExecuteReturnsExpectedKeys(): void
    {
        $result = $this->tool->execute([]);

        $expectedKeys = [
            'success',
            'magento_version',
            'edition',
            'php_version',
            'magento_root',
            'module_count',
            'db_size_mb',
            'product_count',
            'customer_count',
            'order_count',
            'invalid_indexer_count',
            'cms_page_count',
            'cms_block_count',
            'preview',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: $key");
        }
    }

    public function testExecuteReturnsMagentoVersion(): void
    {
        $result = $this->tool->execute([]);

        $this->assertEquals('2.4.7', $result['magento_version']);
        $this->assertEquals('Community', $result['edition']);
    }

    public function testExecuteReturnsPhpVersion(): void
    {
        $result = $this->tool->execute([]);

        $this->assertEquals(phpversion(), $result['php_version']);
    }

    public function testExecuteReturnsPreviewString(): void
    {
        $result = $this->tool->execute([]);

        $this->assertIsString($result['preview']);
        $this->assertStringContainsString('2.4.7', $result['preview']);
    }
}
