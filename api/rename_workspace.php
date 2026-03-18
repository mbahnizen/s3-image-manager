<?php
// api/rename_workspace.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\AuthService;
use App\Database;
use App\StorageService;
use App\SlugService;
use App\Logger;

AuthService::requireAuth();
AuthService::requireCsrf();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['id'], $data['title'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Workspace ID and title required']);
    exit;
}

$id = (int)$data['id'];
$newTitle = trim($data['title']);
if ($newTitle === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid title']);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT id, title, slug FROM workspaces WHERE id = ?");
    $stmt->execute([$id]);
    $workspace = $stmt->fetch();

    if (!$workspace) {
        http_response_code(404);
        echo json_encode(['error' => 'Workspace not found']);
        exit;
    }

    $baseSlug = SlugService::generate($newTitle, false);
    $oldSlug = $workspace['slug'];
    if (preg_match('/^(\\d{4}\\/\\d{2}\\/\\d{2})\\//', $oldSlug, $m)) {
        $baseSlug = $m[1] . '/' . $baseSlug;
    }
    $newSlug = SlugService::ensureUniqueWorkspaceSlug($baseSlug, $db);

    if ($newSlug === $oldSlug && $newTitle === $workspace['title']) {
        echo json_encode(['success' => true, 'slug' => $oldSlug]);
        exit;
    }

    $threshold = defined('WORKSPACE_SYNC_THRESHOLD') ? max(0, (int)WORKSPACE_SYNC_THRESHOLD) : 20;
    $countStmt = $db->prepare("SELECT COUNT(*) FROM assets WHERE workspace_id = ? AND deleted_at IS NULL");
    $countStmt->execute([$id]);
    $assetCount = (int)$countStmt->fetchColumn();

    if ($assetCount <= $threshold) {
        try {
            $storage = new StorageService();
        } catch (\Exception $e) {
            Logger::error("Storage initialization failed", ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['error' => 'Internal Server Error']);
            exit;
        }

        $assetsStmt = $db->prepare("SELECT id, s3_key FROM assets WHERE workspace_id = ? AND deleted_at IS NULL");
        $assetsStmt->execute([$id]);
        $assets = $assetsStmt->fetchAll();

        $copiedKeys = [];
        foreach ($assets as $asset) {
            $oldKey = $asset['s3_key'];
            if (strpos($oldKey, $oldSlug . '/') !== 0) {
                continue;
            }
            $newKey = $newSlug . '/' . substr($oldKey, strlen($oldSlug) + 1);
            if ($newKey === $oldKey) {
                continue;
            }
            $copied = $storage->copy($oldKey, $newKey);
            if (!$copied) {
                foreach ($copiedKeys as $key) {
                    $storage->delete($key);
                }
                Logger::error("Failed to copy asset during workspace rename", [
                    'workspace_id' => $id,
                    'old_key' => $oldKey,
                    'new_key' => $newKey
                ]);
                http_response_code(500);
                echo json_encode(['error' => 'Failed to rename workspace assets']);
                exit;
            }
            $copiedKeys[] = $newKey;
        }

        $db->beginTransaction();
        $db->prepare("UPDATE workspaces SET title = ?, slug = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$newTitle, $newSlug, $id]);
        foreach ($assets as $asset) {
            $oldKey = $asset['s3_key'];
            if (strpos($oldKey, $oldSlug . '/') !== 0) {
                continue;
            }
            $newKey = $newSlug . '/' . substr($oldKey, strlen($oldSlug) + 1);
            if ($newKey === $oldKey) {
                continue;
            }
            $db->prepare("UPDATE assets SET s3_key = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$newKey, $asset['id']]);
        }
        $db->commit();

        foreach ($assets as $asset) {
            $oldKey = $asset['s3_key'];
            if (strpos($oldKey, $oldSlug . '/') !== 0) {
                continue;
            }
            $newKey = $newSlug . '/' . substr($oldKey, strlen($oldSlug) + 1);
            if ($newKey === $oldKey) {
                continue;
            }
            $deleted = $storage->delete($oldKey);
            if (!$deleted) {
                Logger::error("Failed to delete old asset after workspace rename", [
                    'workspace_id' => $id,
                    'old_key' => $oldKey,
                    'new_key' => $newKey
                ]);
            }
        }

        echo json_encode(['success' => true, 'slug' => $newSlug, 'title' => $newTitle]);
        exit;
    }

    $activeStmt = $db->prepare("SELECT id, status FROM workspace_jobs WHERE workspace_id = ? AND type = 'rename' AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
    $activeStmt->execute([$id]);
    $activeJob = $activeStmt->fetch();
    if ($activeJob && $activeJob['status'] === 'running') {
        http_response_code(409);
        echo json_encode(['error' => 'Rename job is currently running. Please wait.']);
        exit;
    }

    $payload = json_encode([
        'workspace_id' => $id,
        'old_slug' => $oldSlug,
        'new_slug' => $newSlug,
        'new_title' => $newTitle
    ]);

    if ($activeJob && $activeJob['status'] === 'queued') {
        $stmt = $db->prepare("UPDATE workspace_jobs SET payload = ?, progress = 0, total = 0, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$payload, $activeJob['id']]);
        $jobId = $activeJob['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO workspace_jobs (workspace_id, type, payload, status, created_at, updated_at) VALUES (?, 'rename', ?, 'queued', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([$id, $payload]);
        $jobId = $db->lastInsertId();
    }

    http_response_code(202);
    echo json_encode(['queued' => true, 'job_id' => $jobId, 'slug' => $newSlug, 'title' => $newTitle]);
} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    Logger::error("Failed to rename workspace", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
