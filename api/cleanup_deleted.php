<?php
// api/cleanup_deleted.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\Logger;

header('Content-Type: application/json');

AuthService::requireAuth();
AuthService::requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$days = isset($data['older_than_days']) ? (int)$data['older_than_days'] : 30;
$days = max(1, min(3650, $days));

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("DELETE FROM assets WHERE deleted_at IS NOT NULL AND deleted_at < datetime('now', ?)");
    $stmt->execute(['-' . $days . ' days']);
    $deleted = $stmt->rowCount();

    Logger::info("Cleanup deleted assets", ['deleted' => $deleted, 'older_than_days' => $days]);
    echo json_encode(['success' => true, 'deleted' => $deleted, 'older_than_days' => $days]);
} catch (\Exception $e) {
    Logger::error("Cleanup failed", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
