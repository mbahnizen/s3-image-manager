<?php
// api/workspace_job_retry.php

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

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("UPDATE workspace_jobs SET status = 'queued', progress = 0, total = 0, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'failed'");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Job not in failed state']);
        exit;
    }

    echo json_encode(['success' => true, 'job_id' => $id]);
} catch (\Exception $e) {
    Logger::error("Failed to requeue workspace job", ['error' => $e->getMessage(), 'job_id' => $id]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}