<?php
// config.php

define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: 'https://s3-id-jkt-1.kilatstorage.id');
define('S3_BUCKET', getenv('S3_BUCKET') ?: 'knowledgebase');
define('S3_ACCESS_KEY', getenv('S3_ACCESS_KEY') ?: '');
define('S3_SECRET_KEY', getenv('S3_SECRET_KEY') ?: '');
define('S3_REGION', getenv('S3_REGION') ?: 'id-jkt-1');
define('S3_ACL', getenv('S3_ACL') ?: 'public-read');

// Path publik (jika bucket Anda public dan bisa diakses langsung):
define('PUBLIC_URL_BASE', getenv('PUBLIC_URL_BASE') ?: 'https://s3-id-jkt-1.kilatstorage.id/knowledgebase/');

$sessionLifetimeEnv = getenv('SESSION_LIFETIME_SECONDS');
define(
    'SESSION_LIFETIME_SECONDS',
    ($sessionLifetimeEnv !== false && $sessionLifetimeEnv !== '') ? (int)$sessionLifetimeEnv : 43200
);
$sessionIdleEnv = getenv('SESSION_IDLE_TIMEOUT_SECONDS');
define(
    'SESSION_IDLE_TIMEOUT_SECONDS',
    ($sessionIdleEnv !== false && $sessionIdleEnv !== '') ? (int)$sessionIdleEnv : 7200
);
$workspaceSyncEnv = getenv('WORKSPACE_SYNC_THRESHOLD');
define(
    'WORKSPACE_SYNC_THRESHOLD',
    ($workspaceSyncEnv !== false && $workspaceSyncEnv !== '') ? (int)$workspaceSyncEnv : 20
);
$jobStaleEnv = getenv('WORKSPACE_JOB_STALE_MINUTES');
define(
    'WORKSPACE_JOB_STALE_MINUTES',
    ($jobStaleEnv !== false && $jobStaleEnv !== '') ? (int)$jobStaleEnv : 15
);

function isApiRequest() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($script, '/api/') !== false) || (strpos($uri, '/api/') !== false);
}

function criticalConfigError($message) {
    if (php_sapi_name() !== 'cli') {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (isApiRequest() || strpos($accept, 'application/json') !== false) {
            header('Content-Type: application/json');
            http_response_code(500);
            die(json_encode(['error' => $message]));
        }
    }
    die($message);
}

if (!getenv('ADMIN_PASSWORD')) {
    criticalConfigError('CRITICAL CONFIG ERROR: ADMIN_PASSWORD must be set in .env');
}

define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD'));
