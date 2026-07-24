<?php
/**
 * Feed Forge — Standalone cron entry point.
 *
 * Call via HTTP:
 *   curl "https://shop.com/modules/feedforge/cron.php?token=YOUR_CRON_TOKEN"
 *
 * Call via CLI:
 *   php /path/to/modules/feedforge/cron.php --token=YOUR_CRON_TOKEN
 */

// Detect CLI vs HTTP mode
$isCli = (php_sapi_name() === 'cli');

// Boot PrestaShop
define('_PS_ADMIN_DIR_', dirname(__FILE__, 3) . '/admin-dev');
require_once dirname(__FILE__, 3) . '/config/config.inc.php';

// Get token from CLI args or HTTP query
if ($isCli) {
    $token = '';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--token=')) {
            $token = substr($arg, 8);
        }
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    $token = Tools::getValue('token', '');
}

// Validate cron token
$expectedToken = Configuration::get('FEEDFORGE_CRON_TOKEN');
if (empty($token) || !hash_equals($expectedToken ?: '', $token)) {
    if ($isCli) {
        fwrite(STDERR, "Error: Invalid cron token\n");
        exit(1);
    }
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid cron token']);
    exit;
}

// Boot Symfony AdminKernel for module services
try {
    require_once _PS_ROOT_DIR_ . '/app/AdminKernel.php';
    $kernel = new AdminKernel('prod', false);
    $kernel->boot();
    $container = $kernel->getContainer();
    $syncEngine = $container->get('FeedForge\Service\SyncEngine');
} catch (\Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "Kernel boot error: " . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Kernel boot: ' . $e->getMessage()]);
    exit;
}

// Acquire lock
$lockFile = _PS_ROOT_DIR_ . '/var/feedforge_sync.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    $maxAge = (int) (Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: 300) + 60;
    if ($lockAge > $maxAge) {
        @unlink($lockFile);
    }
}

$lockHandle = @fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if ($lockHandle) {
        fclose($lockHandle);
    }
    $msg = 'Sync already running (lock file exists)';
    if ($isCli) {
        fwrite(STDERR, "Error: $msg\n");
        exit(1);
    }
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, json_encode(['pid' => getmypid(), 'started_at' => date('Y-m-d H:i:s')]));
fflush($lockHandle);

try {
    $shopId = (int) (Context::getContext()->shop->id ?? 1);

    $maxExecution = (int) (Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: 300);
    if (function_exists('set_time_limit')) {
        set_time_limit($maxExecution + 30);
    }

    $result = $syncEngine->autoSync($shopId);

    if (Configuration::get('FEEDFORGE_DEBUG_LOGGING') && ($result['stats'] ?? null)) {
        PrestaShopLogger::addLog(
            sprintf(
                'FeedForge cron: %s sync — processed: %d, inserted: %d, updated: %d, deleted: %d, failed: %d',
                $result['syncType'],
                $result['stats']['products_processed'] ?? 0,
                $result['stats']['products_created'] ?? 0,
                $result['stats']['products_updated'] ?? 0,
                $result['stats']['products_deleted'] ?? 0,
                $result['stats']['products_failed'] ?? 0
            ),
            1,
            null,
            'FeedForge',
            0
        );
    }

    $output = [
        'success' => true,
        'syncType' => $result['syncType'],
        'logId' => $result['logId'],
        'data' => $result['stats'] ?? ['message' => 'Sync skipped — interval not reached or no pending items'],
    ];

    if ($isCli) {
        echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode($output);
    }
} catch (\Throwable $e) {
    if (Configuration::get('FEEDFORGE_DEBUG_LOGGING')) {
        PrestaShopLogger::addLog('FeedForge cron error: ' . $e->getMessage(), 3, null, 'FeedForge', 0);
    }

    if ($isCli) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
}
