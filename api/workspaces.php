<?php
// api/workspaces.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\WorkspaceService;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();

header('Content-Type: application/json');

$service = new WorkspaceService();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['workspaces' => $service->listWorkspaces()]);
} elseif ($method === 'POST') {
    AuthService::requireCsrf();
    
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['title'])) {
        echo json_encode(['error' => 'Title is required']);
        exit;
    }
    
    try {
        $id = $service->createWorkspace($data['title'], $data['description'] ?? '');
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (\Exception $e) {
        Logger::error("Failed to create workspace", ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
}
