<?php
// api/workspace_jobs.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();

header('Content-Type: application/json');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = max(1, min(100, $limit));
$statusRaw = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$statusList = array_filter(array_map('trim', explode(',', $statusRaw)));
$hideOld = isset($_GET['hide_old']) ? (int)$_GET['hide_old'] : 1;

try {
    $db = Database::getInstance();
    $conditions = [];
    $params = [];
    if (!empty($statusList)) {
        $allowed = ['queued','running','failed','completed','canceled'];
        $filtered = array_values(array_filter($statusList, function ($s) use ($allowed) {
            return in_array($s, $allowed, true);
        }));
        if (!empty($filtered)) {
            $placeholders = implode(',', array_fill(0, count($filtered), '?'));
            $conditions[] = "j.status IN ($placeholders)";
            $params = array_merge($params, $filtered);
        }
    }
    if ($hideOld === 1) {
        $conditions[] = "NOT (j.status IN ('completed','canceled') AND j.updated_at < datetime('now', '-24 hours'))";
    }
    $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

    $stmt = $db->prepare("SELECT 
            j.id,
            j.workspace_id,
            j.type,
            j.status,
            j.progress,
            j.total,
            j.last_error,
            j.payload,
            j.created_at,
            j.updated_at,
            w.title AS workspace_title,
            (SELECT COUNT(*) FROM assets a WHERE a.workspace_id = j.workspace_id AND a.deleted_at IS NULL) AS asset_count
        FROM workspace_jobs j
        LEFT JOIN workspaces w ON w.id = j.workspace_id
        $where
        ORDER BY j.id DESC
        LIMIT ?");
    $params[] = $limit;
    $stmt->execute($params);
    $jobs = $stmt->fetchAll();

    echo json_encode([
        'jobs' => $jobs
    ]);
} catch (\Exception $e) {
    Logger::error("Failed to list workspace jobs", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
