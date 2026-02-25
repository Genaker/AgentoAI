<?php
/**
 * Bootstrap file for MagentoMcpAi tests
 */

define('TEST_ROOT', __DIR__);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Try multiple possible paths for vendor/autoload.php
$possiblePaths = [
    __DIR__ . '/../../../../vendor/autoload.php',           // From Test dir
    getcwd() . '/vendor/autoload.php',                      // From CWD
    dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php',  // Module root
];

$vendorAutoload = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $vendorAutoload = $path;
        break;
    }
}

if (!$vendorAutoload) {
    echo "Error: vendor/autoload.php not found. Tried:\n";
    foreach ($possiblePaths as $path) {
        echo "  - $path\n";
    }
    exit(1);
}

require_once $vendorAutoload;

// Try to load Magento bootstrap if available
$possibleMagentoPaths = [
    __DIR__ . '/../../../../app/bootstrap.php',
    getcwd() . '/app/bootstrap.php',
];

foreach ($possibleMagentoPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

$apiKey = getenv('OPENAI_API_KEY');
if ($apiKey) {
    echo "API key found\n";
}
