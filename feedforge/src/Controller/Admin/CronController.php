<?php

declare(strict_types=1);

namespace FeedForge\Controller\Admin;

use FeedForge\Service\SyncEngine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cron/worker endpoint for batch synchronization.
 *
 * Extends AbstractController (not FrameworkBundleAdminController) because
 * cron must be accessible without admin session — it uses its own token auth.
 *
 * Usage: curl "https://shop.com/admin-path/feedforge/cron/run?token=YOUR_CRON_TOKEN"
 */
class CronController extends AbstractController
{
    private const LOCK_FILE = 'feedforge_sync.lock';

    public function __construct(
        private readonly SyncEngine $syncEngine,
    ) {
    }

    /**
     * Run sync worker.
     *
     * Validates cron token, acquires lock, runs sync engine.
     */
    public function runAction(Request $request): JsonResponse
    {
        // Validate cron token (timing-safe comparison)
        $token = $request->query->get('token', '');
        $expectedToken = \Configuration::get('FEEDFORGE_CRON_TOKEN');

        if (empty($token) || !hash_equals($expectedToken ?: '', $token)) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid cron token',
            ], 403);
        }

        // Acquire lock to prevent concurrent runs
        $lockFile = $this->getLockFilePath();
        $lockHandle = $this->acquireLock($lockFile);

        if ($lockHandle === null) {
            return $this->json([
                'success' => false,
                'error' => 'Sync already running (lock file exists)',
            ], 409);
        }

        try {
            $shopId = (int) (\Context::getContext()->shop->id ?? 1);

            // Extend PHP time limit for long sync operations
            $maxExecution = (int) (\Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: 300);
            if (function_exists('set_time_limit')) {
                set_time_limit($maxExecution + 30);
            }

            // Run automatic sync (determines type: full, delta, or skip)
            $result = $this->syncEngine->autoSync($shopId);

            // Log if debug enabled
            if (\Configuration::get('FEEDFORGE_DEBUG_LOGGING') && $result['stats']) {
                \PrestaShopLogger::addLog(
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

            return $this->json([
                'success' => true,
                'syncType' => $result['syncType'],
                'logId' => $result['logId'],
                'data' => $result['stats'] ?? [
                    'message' => 'Sync skipped — interval not reached or no pending items',
                ],
            ]);
        } catch (\Exception $e) {
            if (\Configuration::get('FEEDFORGE_DEBUG_LOGGING')) {
                \PrestaShopLogger::addLog(
                    'FeedForge cron error: ' . $e->getMessage(),
                    3,
                    null,
                    'FeedForge',
                    0
                );
            }

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            $this->releaseLock($lockHandle, $lockFile);
        }
    }

    /**
     * Get lock file path in PS var/ directory.
     */
    private function getLockFilePath(): string
    {
        $varDir = _PS_ROOT_DIR_ . '/var/';
        if (!is_dir($varDir)) {
            $varDir = _PS_ROOT_DIR_ . '/';
        }

        return $varDir . self::LOCK_FILE;
    }

    /**
     * Try to acquire an exclusive lock.
     *
     * @return resource|null Lock file handle or null if already locked
     */
    private function acquireLock(string $lockFile): mixed
    {
        // Check for stale lock (older than max execution time + buffer)
        if (file_exists($lockFile)) {
            $lockAge = time() - filemtime($lockFile);
            $maxAge = (int) (\Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: 300) + 60;

            if ($lockAge > $maxAge) {
                @unlink($lockFile);
            }
        }

        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        // Write PID and timestamp for debugging
        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'pid' => getmypid(),
            'started_at' => date('Y-m-d H:i:s'),
        ]));
        fflush($handle);

        return $handle;
    }

    /**
     * Release the lock and clean up.
     *
     * @param resource|null $handle
     */
    private function releaseLock(mixed $handle, string $lockFile): void
    {
        if ($handle !== null) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        @unlink($lockFile);
    }
}
