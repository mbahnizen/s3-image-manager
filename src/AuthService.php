<?php
// src/AuthService.php

namespace App;

class AuthService {
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            // Secure session cookie settings with long-lived lifetime
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                        $_SERVER['SERVER_PORT'] == 443 ||
                        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $lifetime = defined('SESSION_LIFETIME_SECONDS') ? SESSION_LIFETIME_SECONDS : 43200;
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.gc_maxlifetime', (string)$lifetime);
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isSecure
            ]);

            session_start();
        }
    }

    public static function isLockedOut() {
        $db = Database::getInstance();
        $ip = $_SERVER['REMOTE_ADDR'];
        $timeWindow = time() - (15 * 60); // 15 minutes
        
        // Purge old attempts to avoid unbounded growth
        $db->prepare("DELETE FROM login_attempts WHERE attempted_at <= ?")
           ->execute([$timeWindow]);

        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > ?");
        $stmt->execute([$ip, $timeWindow]);
        $attempts = $stmt->fetchColumn();
        
        return $attempts >= 5; // Lockout after 5 attempts
    }

    public static function recordLoginAttempt() {
        $db = Database::getInstance();
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, ?)");
        $stmt->execute([$ip, time()]);
    }

    public static function clearLoginAttempts() {
        $db = Database::getInstance();
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
    }

    public static function login($password) {
        self::startSession();
        
        if (self::isLockedOut()) {
            return 'locked';
        }

        if ($password === ADMIN_PASSWORD) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
            $_SESSION['last_activity'] = time();
            self::clearLoginAttempts();
            return true;
        }

        self::recordLoginAttempt();
        return false;
    }

    public static function logout() {
        self::startSession();
        unset($_SESSION['admin_logged_in']);
        unset($_SESSION['csrf_token']);
        unset($_SESSION['login_ip']);
        unset($_SESSION['last_activity']);
        session_destroy();
    }

    public static function isLoggedIn() {
        self::startSession();
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            return false;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!isset($_SESSION['login_ip']) || $_SESSION['login_ip'] !== $ip) {
            self::logout();
            return false;
        }
        $idleTimeout = defined('SESSION_IDLE_TIMEOUT_SECONDS') ? SESSION_IDLE_TIMEOUT_SECONDS : 7200;
        if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $idleTimeout) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function getCsrfToken() {
        self::startSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrfToken($token) {
        self::startSession();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function requireAuth() {
        if (!self::isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    public static function requireCsrf() {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!self::validateCsrfToken($token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }
}
