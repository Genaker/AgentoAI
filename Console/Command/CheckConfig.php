<?php
namespace Genaker\MagentoMcpAi\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class CheckConfig extends Command
{
    const XML_PATH_MSPI_API_KEY = 'magentomcpai/general/mspi_api_key';

    protected $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ?string $name = null
    ) {
        parent::__construct($name);
        $this->scopeConfig = $scopeConfig;
    }

    protected function configure()
    {
        $this->setName('genaker:mcpai:check-config')
            ->setDescription('Check MCP AI configuration values');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $key = $this->scopeConfig->getValue(
            self::XML_PATH_MSPI_API_KEY,
            ScopeInterface::SCOPE_STORE
        );

        $output->writeln('MSPI API Key Configuration:');
        $output->writeln('Path: ' . self::XML_PATH_MSPI_API_KEY);
        $output->writeln('Value: ' . ($key ? $key : 'not set'));

        return 0;
    }
}
