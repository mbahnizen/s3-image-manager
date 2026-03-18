<?php
// scripts/workspace_worker.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\StorageService;
use App\Logger;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function updateJob(PDO $db, int $jobId, array $fields): void {
    $sets = [];
    $values = [];
    foreach ($fields as $key => $value) {
        $sets[] = "$key = ?";
        $values[] = $value;
    }
    $sets[] = "updated_at = CURRENT_TIMESTAMP";
    $values[] = $jobId;
    $sql = "UPDATE workspace_jobs SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
}

function retry(callable $fn, int $retries = 3, int $delayMs = 250): bool {
    $attempt = 0;
    while ($attempt < $retries) {
        $attempt++;
        try {
            if ($fn()) {
                return true;
            }
        } catch (Exception $e) {
        }
        usleep($delayMs * 1000);
    }
    return false;
}

try {
    $db = Database::getInstance();

    $staleMinutes = defined('WORKSPACE_JOB_STALE_MINUTES') ? max(1, (int)WORKSPACE_JOB_STALE_MINUTES) : 15;
    $db->prepare("UPDATE workspace_jobs SET status = 'failed', last_error = 'Stale running job', updated_at = CURRENT_TIMESTAMP WHERE status = 'running' AND updated_at < datetime('now', ?)")
       ->execute(['-' . $staleMinutes . ' minutes']);

    $job = $db->query("SELECT * FROM workspace_jobs WHERE status = 'queued' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$job) {
        echo "No queued jobs\n";
        exit(0);
    }

    $lockStmt = $db->prepare("UPDATE workspace_jobs SET status = 'running', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'queued'");
    $lockStmt->execute([$job['id']]);
    if ($lockStmt->rowCount() === 0) {
        echo "Job already picked by another worker\n";
        exit(0);
    }

    $jobId = (int)$job['id'];
    $payload = json_decode($job['payload'] ?? '', true);
    if (!is_array($payload)) {
        $payload = [];
    }

    try {
        $storage = new StorageService();
    } catch (Exception $e) {
        updateJob($db, $jobId, [
            'status' => 'failed',
            'last_error' => 'Storage initialization failed'
        ]);
        Logger::error("Workspace job failed: storage init", ['job_id' => $jobId, 'error' => $e->getMessage()]);
        exit(1);
    }

    if ($job['type'] === 'rename') {
        $workspaceId = (int)($payload['workspace_id'] ?? $job['workspace_id'] ?? 0);
        $newSlug = $payload['new_slug'] ?? '';
        $newTitle = $payload['new_title'] ?? '';

        $stmt = $db->prepare("SELECT id, title, slug FROM workspaces WHERE id = ?");
        $stmt->execute([$workspaceId]);
        $workspace = $stmt->fetch();
        if (!$workspace || $newSlug === '') {
            updateJob($db, $jobId, ['status' => 'failed', 'last_error' => 'Workspace not found or invalid slug']);
            exit(1);
        }
        $oldSlug = $workspace['slug'];
        if ($newTitle === '') {
            $newTitle = $workspace['title'];
        }
        if ($workspace['slug'] === $newSlug && $workspace['title'] === $newTitle) {
            updateJob($db, $jobId, [
                'status' => 'completed',
                'progress' => 0,
                'total' => 0,
                'last_error' => null
            ]);
            echo "Rename job no-op: $jobId\n";
            exit(0);
        }

        $assetsStmt = $db->prepare("SELECT id, s3_key FROM assets WHERE workspace_id = ? AND deleted_at IS NULL");
        $assetsStmt->execute([$workspaceId]);
        $assets = $assetsStmt->fetchAll();

        $toMove = [];
        foreach ($assets as $asset) {
            $oldKey = $asset['s3_key'];
            if (strpos($oldKey, $oldSlug . '/') !== 0) {
                continue;
            }
            $newKey = $newSlug . '/' . substr($oldKey, strlen($oldSlug) + 1);
            if ($newKey === $oldKey) {
                continue;
            }
            $toMove[] = ['id' => $asset['id'], 'old' => $oldKey, 'new' => $newKey];
        }

        $total = count($toMove);
        updateJob($db, $jobId, ['total' => $total, 'progress' => 0]);

        $progress = 0;
        foreach ($toMove as $item) {
            $copied = retry(function () use ($storage, $item) {
                return $storage->copy($item['old'], $item['new']);
            });
            if (!$copied) {
                updateJob($db, $jobId, ['status' => 'failed', 'last_error' => 'Failed to copy asset: ' . $item['old']]);
                Logger::error("Workspace rename failed during copy", ['job_id' => $jobId, 'old_key' => $item['old'], 'new_key' => $item['new']]);
                exit(1);
            }
            $progress++;
            updateJob($db, $jobId, ['progress' => $progress]);
        }

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE workspaces SET title = ?, slug = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$newTitle, $newSlug, $workspaceId]);
            foreach ($toMove as $item) {
                $db->prepare("UPDATE assets SET s3_key = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([$item['new'], $item['id']]);
            }
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            updateJob($db, $jobId, ['status' => 'failed', 'last_error' => 'Database update failed']);
            Logger::error("Workspace rename failed during DB update", ['job_id' => $jobId, 'error' => $e->getMessage()]);
            exit(1);
        }

        $deleteWarnings = [];
        foreach ($toMove as $item) {
            $deleted = retry(function () use ($storage, $item) {
                return $storage->delete($item['old']);
            });
            if (!$deleted) {
                $deleteWarnings[] = $item['old'];
            }
        }

        $warning = $deleteWarnings ? ('Failed to delete old keys: ' . implode(', ', $deleteWarnings)) : null;
        updateJob($db, $jobId, [
            'status' => 'completed',
            'progress' => $total,
            'total' => $total,
            'last_error' => $warning
        ]);

        echo "Rename job completed: $jobId\n";
        exit(0);
    }

    if ($job['type'] === 'delete') {
        $workspaceId = (int)($payload['workspace_id'] ?? $job['workspace_id'] ?? 0);

        $stmt = $db->prepare("SELECT id, title, slug FROM workspaces WHERE id = ?");
        $stmt->execute([$workspaceId]);
        $workspace = $stmt->fetch();
        if (!$workspace) {
            updateJob($db, $jobId, ['status' => 'failed', 'last_error' => 'Workspace not found']);
            exit(1);
        }

        $assetsStmt = $db->prepare("SELECT id, s3_key FROM assets WHERE workspace_id = ? AND deleted_at IS NULL");
        $assetsStmt->execute([$workspaceId]);
        $assets = $assetsStmt->fetchAll();

        $total = count($assets);
        updateJob($db, $jobId, ['total' => $total, 'progress' => 0]);

        $progress = 0;
        foreach ($assets as $asset) {
            $deleted = retry(function () use ($storage, $asset) {
                return $storage->delete($asset['s3_key']);
            });
            if (!$deleted) {
                updateJob($db, $jobId, ['status' => 'failed', 'last_error' => 'Failed to delete asset: ' . $asset['s3_key']]);
                Logger::error("Workspace delete failed during S3 delete", ['job_id' => $jobId, 'key' => $asset['s3_key']]);
                exit(1);
            }
            $progress++;
            updateJob($db, $jobId, ['progress' => $progress]);
        }

        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM assets WHERE workspace_id = ?")->execute([$workspaceId]);
            $db->prepare("DELETE FROM workspaces WHERE id = ?")->execute([$workspaceId]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            updateJob($db, $jobId, ['status' => 'failed', 'last_error' => 'Database delete failed']);
            Logger::error("Workspace delete failed during DB delete", ['job_id' => $jobId, 'error' => $e->getMessage()]);
            exit(1);
        }

        updateJob($db, $jobId, [
            'status' => 'completed',
            'progress' => $total,
            'total' => $total,
            'last_error' => null
        ]);

        echo "Delete job completed: $jobId\n";
        exit(0);
    }

    updateJob($db, $jobId, ['status' => 'failed', 'last_error' => 'Unknown job type']);
    exit(1);
} catch (Exception $e) {
    Logger::error("Workspace worker crashed", ['error' => $e->getMessage()]);
    echo "Worker error\n";
    exit(1);
}