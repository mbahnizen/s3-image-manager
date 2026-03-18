<?php
// api/update_asset.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();
// For pre-flight or regular JSON POST, CSRF check might need to be adjusted or headers used
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

if (!isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Asset ID required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Fetch current data to allow partial updates
    $stmt = $db->prepare("SELECT alt_text, caption, sort_order FROM assets WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$data['id']]);
    $current = $stmt->fetch();
    
    if (!$current) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found']);
        exit;
    }

    $alt_text = $data['alt_text'] ?? $current['alt_text'];
    $caption = $data['caption'] ?? $current['caption'];
    $sort_order = $data['sort_order'] ?? $current['sort_order'];

    $stmt = $db->prepare("UPDATE assets SET alt_text = ?, caption = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([
        $alt_text,
        $caption,
        $sort_order,
        $data['id']
    ]);
    
    echo json_encode(['success' => true]);
} catch (\Exception $e) {
    Logger::error("Failed to update asset", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
