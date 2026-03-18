<?php
// api/list_assets.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();

header('Content-Type: application/json');

$workspace_id = $_GET['workspace_id'] ?? 1;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

try {
    $db = Database::getInstance();
    
    $stmt = $db->prepare("SELECT * FROM assets WHERE workspace_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC, created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$workspace_id, $limit, $offset]);
    $assets = $stmt->fetchAll();
    
    // Generate stable public URLs for each asset
    foreach ($assets as &$asset) {
        $asset['public_url'] = PUBLIC_URL_BASE . ltrim($asset['s3_key'], '/');
    }

    $stmtTotal = $db->prepare("SELECT COUNT(*) FROM assets WHERE workspace_id = ? AND deleted_at IS NULL");
    $stmtTotal->execute([$workspace_id]);
    $total = $stmtTotal->fetchColumn();
    
    echo json_encode([
        'assets' => $assets,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
} catch (\Exception $e) {
    Logger::error("Failed to list assets", ['workspace_id' => $workspace_id, 'error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
