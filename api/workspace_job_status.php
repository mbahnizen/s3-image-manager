<?php
// api/workspace_job_status.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Job ID required']);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT id, workspace_id, type, status, progress, total, last_error, created_at, updated_at FROM workspace_jobs WHERE id = ?");
    $stmt->execute([$id]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        exit;
    }

    echo json_encode([
        'id' => (int)$job['id'],
        'workspace_id' => (int)$job['workspace_id'],
        'type' => $job['type'],
        'status' => $job['status'],
        'progress' => (int)($job['progress'] ?? 0),
        'total' => (int)($job['total'] ?? 0),
        'error' => $job['last_error'] ?? null,
        'created_at' => $job['created_at'],
        'updated_at' => $job['updated_at']
    ]);
} catch (\Exception $e) {
    Logger::error("Failed to fetch workspace job status", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}