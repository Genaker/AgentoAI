<?php

namespace Genaker\MagentoMcpAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class AIAssistant extends Template
{
    const XML_PATH_API_KEY = 'magentomcpai/general/api_key';

    protected $scopeConfig;

    protected $authorization;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->scopeConfig = $scopeConfig;
        $this->authorization = $authorization;
    }

    public function getFormAction()
    {
        return $this->getUrl('adminhtml/aiassistant/send');
    }

    public function getApiKey()
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isAllowed(): bool
    {
        return $this->authorization->isAllowed('Genaker_MagentoMcpAi::mcpai_dashboard_page');
    }
}
