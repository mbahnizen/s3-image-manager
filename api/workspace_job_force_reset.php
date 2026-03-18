<?php
// api/workspace_job_force_reset.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();
AuthService::requireCsrf();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Job ID required']);
    exit;
}

$staleMinutes = defined('WORKSPACE_JOB_STALE_MINUTES') ? max(1, (int)WORKSPACE_JOB_STALE_MINUTES) : 15;

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT status, updated_at FROM workspace_jobs WHERE id = ?");
    $stmt->execute([$id]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        exit;
    }

    if ($job['status'] !== 'running') {
        http_response_code(409);
        echo json_encode(['error' => 'Job is not running']);
        exit;
    }

    $checkStmt = $db->prepare("SELECT COUNT(*) FROM workspace_jobs WHERE id = ? AND updated_at < datetime('now', ?)");
    $checkStmt->execute([$id, '-' . $staleMinutes . ' minutes']);
    $isStale = (int)$checkStmt->fetchColumn() > 0;
    if (!$isStale) {
        http_response_code(409);
        echo json_encode(['error' => 'Job is not stale yet']);
        exit;
    }

    $update = $db->prepare("UPDATE workspace_jobs SET status = 'failed', last_error = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $update->execute(['Marked stale by user', $id]);

    echo json_encode(['success' => true, 'job_id' => $id]);
} catch (\Exception $e) {
    Logger::error("Failed to force reset workspace job", ['error' => $e->getMessage(), 'job_id' => $id]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}