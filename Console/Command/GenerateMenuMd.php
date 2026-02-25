<?php
declare(strict_types=1);

namespace Genaker\MagentoMcpAi\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Backend\Model\UrlInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate menu.md file from Magento admin menu and system configuration
 */
class GenerateMenuMd extends Command
{
    private DirectoryList $directoryList;
    private State $appState;
    private UrlInterface $urlBuilder;

    public function __construct(
        DirectoryList $directoryList,
        State $appState,
        UrlInterface $urlBuilder
    ) {
        parent::__construct();
        $this->directoryList = $directoryList;
        $this->appState = $appState;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('genaker:menu:generate-md')
            ->setDescription('Generate menu.md file from Magento admin menu and system configuration');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Generating menu.md file...</info>');

        // Set area code to adminhtml for URL generation
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set
        }

        $modulePath = $this->directoryList->getPath(DirectoryList::APP) . '/code/Genaker/MagentoMcpAi';
        $outputFile = $modulePath . '/menu.md';

        $output->writeln("<info>Output file: {$outputFile}</info>");

        $content = "# Magento 2 Admin Menu and System Configuration\n\n";

        // Scan directories
        $directories = [
            $this->directoryList->getPath(DirectoryList::ROOT) . '/vendor',
            $this->directoryList->getPath(DirectoryList::APP) . '/code'
        ];

        // Extract admin menu items
        $content .= "## Admin Menu Items\n";
        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                $menuItems = $this->extractMenuItems($directory, $output);
                foreach ($menuItems as $item) {
                    $url = $this->generateMenuUrl($item['action']);
                    $content .= "- [{$item['title']}]\n";
                    $content .= "  - Description: Description of {$item['title']}\n";
                    $content .= "  - URL: {$url}\n";
                }
            }
        }

        // Extract system configuration items
        $content .= "\n## System Configuration Items\n";
        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                $configItems = $this->extractSystemConfigItems($directory, $output);
                foreach ($configItems as $section) {
                    $anchor = $this->generateAnchor($section['label']);
                    $content .= "- **[{$section['label']}](#{$anchor})**\n";
                    if (!empty($section['description'])) {
                        $content .= "  - Description: {$section['description']}\n";
                    }
                    $url = $this->generateSystemConfigUrl($section['id']);
                    $content .= "  - URL: {$url}\n";
                    
                    foreach ($section['groups'] as $group) {
                        $groupAnchor = $this->generateAnchor($group['label']);
                        $content .= "  - **[{$group['label']}](#{$groupAnchor})**\n";
                        if (!empty($group['description'])) {
                            $content .= "    - Description: {$group['description']}\n";
                        }
                        
                        foreach ($group['fields'] as $field) {
                            if (!empty($field['label'])) {
                                $fieldAnchor = $this->generateAnchor($field['label']);
                                $content .= "    - [{$field['label']}](#{$fieldAnchor}): {$field['description']}\n";
                            }
                        }
                    }
                }
            }
        }

        // Write file
        file_put_contents($outputFile, $content);

        $output->writeln('<info>menu.md file generated successfully!</info>');
        return Command::SUCCESS;
    }

    /**
     * Extract menu items from menu.xml files
     *
     * @param string $directory
     * @param OutputInterface $output
     * @return array
     */
    private function extractMenuItems(string $directory, OutputInterface $output): array
    {
        $menuItems = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'menu.xml') {
                $filePath = $file->getPathname();
                try {
                    $xml = simplexml_load_file($filePath);
                    if ($xml === false) {
                        continue;
                    }

                    // Register namespaces
                    $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

                    // Find all menu items
                    $items = $xml->xpath('//menu/add[@title and @action]');
                    foreach ($items as $item) {
                        $title = (string)$item['title'];
                        $action = (string)$item['action'];
                        if (!empty($title) && !empty($action)) {
                            $menuItems[] = [
                                'title' => $title,
                                'action' => $action
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    $output->writeln("<comment>Error parsing {$filePath}: {$e->getMessage()}</comment>");
                }
            }
        }

        return $menuItems;
    }

    /**
     * Extract system configuration items from system.xml files
     *
     * @param string $directory
     * @param OutputInterface $output
     * @return array
     */
    private function extractSystemConfigItems(string $directory, OutputInterface $output): array
    {
        $configItems = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'system.xml') {
                $filePath = $file->getPathname();
                try {
                    $xml = simplexml_load_file($filePath);
                    if ($xml === false) {
                        continue;
                    }

                    // Register namespaces
                    $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

                    // Find all sections
                    $sections = $xml->xpath('//config/system/section[@id]');
                    foreach ($sections as $section) {
                        $sectionId = (string)$section['id'];
                        $sectionLabel = (string)$section->label;
                        $sectionComment = (string)$section->comment;

                        if (empty($sectionId) || empty($sectionLabel)) {
                            continue;
                        }

                        $groups = [];
                        $groupNodes = $section->xpath('.//group[@id]');
                        foreach ($groupNodes as $group) {
                            $groupLabel = (string)$group->label;
                            $groupComment = (string)$group->comment;

                            if (empty($groupLabel)) {
                                continue;
                            }

                            $fields = [];
                            $fieldNodes = $group->xpath('.//field[@id]');
                            foreach ($fieldNodes as $field) {
                                $fieldLabel = (string)$field->label;
                                $fieldComment = (string)$field->comment;

                                if (!empty($fieldLabel)) {
                                    $fields[] = [
                                        'label' => $fieldLabel,
                                        'description' => $fieldComment
                                    ];
                                }
                            }

                            $groups[] = [
                                'label' => $groupLabel,
                                'description' => $groupComment,
                                'fields' => $fields
                            ];
                        }

                        $configItems[] = [
                            'label' => $sectionLabel,
                            'id' => $sectionId,
                            'description' => $sectionComment,
                            'groups' => $groups
                        ];
                    }
                } catch (\Exception $e) {
                    $output->writeln("<comment>Error parsing {$filePath}: {$e->getMessage()}</comment>");
                }
            }
        }

        return $configItems;
    }

    /**
     * Generate anchor from text
     *
     * @param string $text
     * @return string
     */
    private function generateAnchor(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/\s+/', '-', $text);
        $text = preg_replace('/[^a-z0-9-]/', '', $text);
        return $text;
    }

    /**
     * Generate menu URL from action
     * Converts adminhtml/module/controller/action to proper URL format
     *
     * @param string $action
     * @return string
     */
    private function generateMenuUrl(string $action): string
    {
        // Remove adminhtml/ prefix if present
        $route = str_replace('adminhtml/', '', $action);
        
        // Convert to {base_url} format for menu.md
        // This will be processed by MenuAIAPI to generate actual URLs
        return '{base_url}/' . $route;
    }

    /**
     * Generate system configuration URL
     *
     * @param string $sectionId
     * @return string
     */
    private function generateSystemConfigUrl(string $sectionId): string
    {
        // System config URLs use adminhtml/system_config/edit/section/{id}
        return '{base_url}/adminhtml/system_config/edit/section/' . $sectionId;
    }
}
