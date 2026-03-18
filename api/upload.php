<?php
// api/upload.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\StorageService;
use App\Database;
use App\SlugService;
use App\CaptionService;
use App\AuthService;
use App\Logger;

AuthService::requireAuth();
AuthService::requireCsrf();

header('Content-Type: application/json');

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Basic validation
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error: ' . $file['error']]);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
    http_response_code(413);
    echo json_encode(['error' => 'File too large (max 10MB)']);
    exit;
}

// MIME validation using finfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!in_array($mimeType, $allowedMimeTypes)) {
    http_response_code(415);
    echo json_encode(['error' => 'Invalid file type: ' . $mimeType]);
    exit;
}

if (strpos($mimeType, 'image/') === 0) {
    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo) {
        Logger::error("Invalid image content", ['filename' => $file['name'], 'mime' => $mimeType]);
        http_response_code(422);
        echo json_encode(['error' => 'Invalid image content or corrupted file']);
        exit;
    }
    $width = (int)($imageInfo[0] ?? 0);
    $height = (int)($imageInfo[1] ?? 0);
    $maxPixels = 12000000; // 12 MP limit
    $maxDimension = 8000;
    if ($width <= 0 || $height <= 0 || ($width * $height) > $maxPixels || $width > $maxDimension || $height > $maxDimension) {
        Logger::error("Image dimensions too large", [
            'filename' => $file['name'],
            'mime' => $mimeType,
            'width' => $width,
            'height' => $height
        ]);
        http_response_code(413);
        echo json_encode(['error' => 'Image dimensions too large']);
        exit;
    }
}

$workspace_id = $_POST['workspace_id'] ?? 1;
$originalName = basename($file['name']);
$requestedName = trim($_POST['filename'] ?? '');
if ($requestedName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Filename is required']);
    exit;
}
$filename = SlugService::normalizeFilename($requestedName);
$nameInfo = pathinfo($filename);
if (empty($nameInfo['extension'])) {
    $origExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($origExt) {
        $filename = $nameInfo['filename'] . '.' . $origExt;
        $nameInfo = pathinfo($filename);
    }
}
if (empty($nameInfo['filename'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid filename']);
    exit;
}

    try {
        $storage = new StorageService();
    } catch (\Exception $e) {
        Logger::error("Storage initialization failed", ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
        exit;
    }
$db = Database::getInstance();

$stmt = $db->prepare("SELECT title, slug FROM workspaces WHERE id = ?");
$stmt->execute([$workspace_id]);
$wsData = $stmt->fetch();

if (!$wsData) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid workspace']);
    exit;
}

$targetPath = $wsData['slug'];
$result = $storage->upload($file['tmp_name'], $filename, $mimeType, $targetPath);

if ($result['success']) {
    $caption = trim($_POST['caption'] ?? '');
    $alt = trim($_POST['alt_text'] ?? '');
    if ($caption === '') {
        $caption = CaptionService::generate($filename, $wsData['title']);
    }
    if ($alt === '') {
        $alt = CaptionService::generateAlt($filename);
    }

    try {
        $stmt = $db->prepare("INSERT INTO assets (workspace_id, original_name, stored_name, mime_type, size_bytes, s3_key, caption, alt_text, slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $workspace_id,
            $originalName,
            $filename,
            $mimeType,
            $file['size'],
            $result['key'],
            $caption,
            $alt,
            $nameInfo['filename']
        ]);
        
        echo json_encode([
            'success' => true,
            'url' => PUBLIC_URL_BASE . ltrim($result['key'], '/'),
            'name' => $filename,
            'key' => $result['key'],
            'caption' => $caption,
            'alt_text' => $alt
        ]);
    } catch (\Exception $e) {
        // Atomic cleanup: remove from S3 if DB fails
        $storage->delete($result['key']);
        Logger::error("Failed to save to database after S3 upload", [
            'key' => $result['key'],
            'error' => $e->getMessage()
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
} else {
    Logger::error("S3 upload failed", [
        'error' => $result['error'] ?? 'Unknown error'
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
