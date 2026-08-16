<?php
// Clear OPcache via web request
header('Content-Type: text/plain; charset=utf-8');

if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo "OPcache reset: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
} else {
    echo "OPcache not available\n";
}

// Also clear specific files
$files = [
    __DIR__ . '/../helpers/ShoumenniujingHelper.php',
    __DIR__ . '/../daemons/ActionRouter.php',
    __DIR__ . '/../commands/give.php',
    __DIR__ . '/../commands/go.php',
];

foreach ($files as $file) {
    if (function_exists('opcache_invalidate')) {
        $invalidated = opcache_invalidate($file, true);
        echo "Invalidated: $file => " . ($invalidated ? 'YES' : 'NO') . "\n";
    }
}

// Show OPcache status
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status) {
        echo "\nOPcache enabled: " . ($status['opcache_enabled'] ? 'YES' : 'NO') . "\n";
    }
}
