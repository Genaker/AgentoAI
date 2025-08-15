<?php
namespace Genaker\MagentoMcpAi\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ConversationActions extends Column
{
    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                $actions = $this->getData('action_list');
                if (!is_array($actions)) {
                    continue;
                }

                foreach ($actions as $key => $action) {
                    if (!$this->isValidAction($action)) {
                        continue;
                    }

                    $params = $this->prepareActionParams($action['params'], $item);

                    $actionData = [
                        'href' => $this->urlBuilder->getUrl($action['path'], $params),
                        'label' => $action['label'],
                        'hidden' => false,
                    ];

                    if (isset($action['confirm'])) {
                        $actionData['confirm'] = $action['confirm'];
                    }

                    $item[$this->getData('name')][$key] = $actionData;
                }
            }
        }

        return $dataSource;
    }


    /**
     * Validate action configuration
     *
     * @param array $action
     * @return bool
     */
    private function isValidAction(array $action): bool
    {
        return isset($action['path'], $action['label'], $action['params'])
            && is_array($action['params']);
    }

    /**
     * Prepare action parameters
     *
     * @param array $params
     * @param array $item
     * @return array
     */
    private function prepareActionParams(array $params, array $item): array
    {
        $preparedParams = [];
        foreach ($params as $field => $param) {
            if (isset($item[$param])) {
                $preparedParams[$field] = $item[$param];
            }
        }

        return $preparedParams;
    }
}
