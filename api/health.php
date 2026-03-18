<?php
// api/health.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\AuthService;

AuthService::requireAuth();

header('Content-Type: application/json');

$checks = [
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'sqlite3' => extension_loaded('sqlite3'),
    'curl' => extension_loaded('curl'),
    'fileinfo' => extension_loaded('fileinfo'),
    'mbstring' => extension_loaded('mbstring'),
    'openssl' => extension_loaded('openssl'),
    'data_dir_writable' => is_writable(__DIR__ . '/../data'),
    'admin_password_set' => (getenv('ADMIN_PASSWORD') !== false && getenv('ADMIN_PASSWORD') !== ''),
    'jwt_secret_set' => (getenv('JWT_SECRET') !== false && getenv('JWT_SECRET') !== ''),
    's3_endpoint_set' => (getenv('S3_ENDPOINT') !== false && getenv('S3_ENDPOINT') !== ''),
    's3_bucket_set' => (getenv('S3_BUCKET') !== false && getenv('S3_BUCKET') !== ''),
    's3_access_key_set' => (getenv('S3_ACCESS_KEY') !== false && getenv('S3_ACCESS_KEY') !== ''),
    's3_secret_key_set' => (getenv('S3_SECRET_KEY') !== false && getenv('S3_SECRET_KEY') !== ''),
];

$ok = !in_array(false, $checks, true);

http_response_code($ok ? 200 : 500);
echo json_encode([
    'ok' => $ok,
    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    'checks' => $checks
]);
