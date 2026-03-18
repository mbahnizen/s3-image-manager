<?php
// api/login.php

require_once __DIR__ . '/../src/bootstrap.php';

use App\AuthService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$password = $data['password'] ?? '';

$loginResult = AuthService::login($password);

if ($loginResult === true) {
    echo json_encode([
        'success' => true, 
        'csrf_token' => AuthService::getCsrfToken()
    ]);
} elseif ($loginResult === 'locked') {
    http_response_code(429);
    echo json_encode(['error' => 'Too many failed login attempts. Please try again in 15 minutes.']);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid password']);
}
