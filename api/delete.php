<?php
// api/delete.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\StorageService;
use App\Database;
use App\AuthService;
use App\Logger;

header('Content-Type: application/json');

AuthService::requireAuth();
AuthService::requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request: ID required']);
    exit;
}

$id = $_POST['id'];
$db = Database::getInstance();
try {
    $storage = new StorageService();
} catch (\Exception $e) {
    Logger::error("Storage initialization failed", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
    exit;
}

$stmt = $db->prepare("SELECT s3_key FROM assets WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    http_response_code(404);
    echo json_encode(['error' => 'Asset not found']);
    exit;
}

$key = $asset['s3_key'];

try {
    $db->beginTransaction();
    $stmt = $db->prepare("UPDATE assets SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$id]);
    $db->commit();

    $s3Deleted = $storage->delete($key);
    if (!$s3Deleted) {
        $stmt = $db->prepare("UPDATE assets SET deleted_at = NULL WHERE id = ?");
        $stmt->execute([$id]);
        Logger::error("Failed to delete from S3", ['key' => $key]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete from S3']);
        exit;
    }

    $stmt = $db->prepare("DELETE FROM assets WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
} catch (\Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    Logger::error("Failed to delete asset", [
        'id' => $id,
        'key' => $key,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
