<?php
// api/workspace_jobs_active.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT id, workspace_id, type, status, updated_at FROM workspace_jobs WHERE status IN ('queued','running','failed') ORDER BY updated_at DESC");
    $stmt->execute();
    $jobs = $stmt->fetchAll();

    echo json_encode(['jobs' => $jobs]);
} catch (\Exception $e) {
    Logger::error("Failed to list active workspace jobs", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}