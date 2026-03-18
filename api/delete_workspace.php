<?php
// api/delete_workspace.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\AuthService;
use App\Database;
use App\StorageService;
use App\Logger;

AuthService::requireAuth();
AuthService::requireCsrf();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Workspace ID required']);
    exit;
}

$id = (int)$data['id'];

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

        foreach ($assets as $asset) {
            $deleted = $storage->delete($asset['s3_key']);
            if (!$deleted) {
                Logger::error("Failed to delete asset during workspace removal", [
                    'workspace_id' => $id,
                    'asset_id' => $asset['id'],
                    'key' => $asset['s3_key']
                ]);
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete workspace assets']);
                exit;
            }
        }

        $db->beginTransaction();
        $db->prepare("DELETE FROM assets WHERE workspace_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM workspaces WHERE id = ?")->execute([$id]);
        $db->commit();

        echo json_encode(['success' => true]);
        exit;
    }

    $activeStmt = $db->prepare("SELECT id, type, status FROM workspace_jobs WHERE workspace_id = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
    $activeStmt->execute([$id]);
    $activeJob = $activeStmt->fetch();
    if ($activeJob && $activeJob['status'] === 'running') {
        http_response_code(409);
        echo json_encode(['error' => 'A workspace job is currently running. Please wait.']);
        exit;
    }
    if ($activeJob && $activeJob['status'] === 'queued') {
        http_response_code(202);
        echo json_encode(['queued' => true, 'job_id' => $activeJob['id']]);
        exit;
    }

    $payload = json_encode([
        'workspace_id' => $id,
        'slug' => $workspace['slug']
    ]);

    $stmt = $db->prepare("INSERT INTO workspace_jobs (workspace_id, type, payload, status, created_at, updated_at) VALUES (?, 'delete', ?, 'queued', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute([$id, $payload]);
    $jobId = $db->lastInsertId();

    http_response_code(202);
    echo json_encode(['queued' => true, 'job_id' => $jobId]);
} catch (\Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    Logger::error("Failed to delete workspace", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
