<?php
// api/rename_asset.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\AuthService;
use App\StorageService;
use App\SlugService;
use App\Logger;

AuthService::requireAuth();
AuthService::requireCsrf();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['id'], $data['new_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Asset ID and new name are required']);
    exit;
}

$id = $data['id'];
$newNameRaw = trim($data['new_name']);
if ($newNameRaw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid filename']);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT s3_key, stored_name FROM assets WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $asset = $stmt->fetch();

    if (!$asset) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found']);
        exit;
    }

    $normalized = SlugService::normalizeFilename($newNameRaw);
    $info = pathinfo($normalized);
    $existingExt = strtolower(pathinfo($asset['stored_name'] ?? $asset['s3_key'], PATHINFO_EXTENSION));
    if (empty($info['extension']) && $existingExt) {
        $normalized = $info['filename'] . '.' . $existingExt;
        $info = pathinfo($normalized);
    }
    if (empty($info['filename'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid filename']);
        exit;
    }

    $oldKey = $asset['s3_key'];
    $dir = pathinfo($oldKey, PATHINFO_DIRNAME);
    $dir = ($dir === '.' ? '' : trim($dir, '/'));

    $unique = null;
    if (preg_match('/_([a-f0-9]{8})\.[^.]+$/', $oldKey, $m)) {
        $unique = $m[1];
    }
    if (!$unique) {
        $unique = bin2hex(random_bytes(4));
    }

    $base = $info['filename'];
    $ext = $info['extension'] ?? '';
    $newKey = ($dir ? $dir . '/' : '') . $base . '_' . $unique . ($ext ? '.' . $ext : '');

    if ($newKey === $oldKey) {
        echo json_encode(['success' => true, 'url' => PUBLIC_URL_BASE . ltrim($newKey, '/')]);
        exit;
    }

    $storage = new StorageService();
    $copied = $storage->copy($oldKey, $newKey);
    if (!$copied) {
        Logger::error("Failed to copy asset", ['old_key' => $oldKey, 'new_key' => $newKey]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to rename asset']);
        exit;
    }

    $deleted = $storage->delete($oldKey);
    if (!$deleted) {
        $storage->delete($newKey);
        Logger::error("Failed to delete original asset after copy", ['old_key' => $oldKey, 'new_key' => $newKey]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to rename asset']);
        exit;
    }

    $stmt = $db->prepare("UPDATE assets SET stored_name = ?, original_name = ?, s3_key = ?, slug = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$normalized, $normalized, $newKey, $base, $id]);

    echo json_encode([
        'success' => true,
        'url' => PUBLIC_URL_BASE . ltrim($newKey, '/'),
        'stored_name' => $normalized,
        's3_key' => $newKey
    ]);
} catch (\Exception $e) {
    Logger::error("Failed to rename asset", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
