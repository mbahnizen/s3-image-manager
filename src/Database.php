<?php
// src/Database.php

namespace App;

use PDO;
use PDOException;
use App\Logger;

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dbPath = __DIR__ . '/../data/image_manager.sqlite';
        try {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                Logger::error("Database driver missing", ['driver' => 'pdo_sqlite']);
                $this->renderDbError('SQLite driver is not enabled in PHP.');
            }
            $this->pdo = new PDO("sqlite:" . $dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec("PRAGMA foreign_keys = ON");
            $this->pdo->exec("PRAGMA busy_timeout = 5000");
            $this->initializeSchema();
        } catch (PDOException $e) {
            Logger::error("Database connection failed", ['error' => $e->getMessage()]);
            $this->renderDbError('Internal Server Error');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }

    private function initializeSchema() {
        $queries = [
            "CREATE TABLE IF NOT EXISTS workspaces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER,
                original_name TEXT,
                stored_name TEXT,
                slug TEXT,
                alt_text TEXT,
                caption TEXT,
                sort_order INTEGER DEFAULT 0,
                mime_type TEXT,
                size_bytes INTEGER,
                s3_key TEXT UNIQUE,
                public_url TEXT,
                deleted_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                attempted_at INTEGER NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS workspace_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workspace_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                payload TEXT,
                status TEXT NOT NULL,
                progress INTEGER DEFAULT 0,
                total INTEGER DEFAULT 0,
                last_error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE INDEX IF NOT EXISTS idx_assets_workspace ON assets(workspace_id)",
            "CREATE INDEX IF NOT EXISTS idx_workspaces_slug ON workspaces(slug)",
            "CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip_address, attempted_at)",
            "CREATE INDEX IF NOT EXISTS idx_workspace_jobs_status ON workspace_jobs(status, created_at)"
        ];

        foreach ($queries as $query) {
            $this->pdo->exec($query);
        }

        $this->ensureColumnExists('assets', 'deleted_at', 'DATETIME DEFAULT NULL');
    }

    private function ensureColumnExists($table, $column, $definition) {
        $stmt = $this->pdo->prepare("PRAGMA table_info($table)");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $columns, true)) {
            $this->pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }

    private function renderDbError($message) {
        http_response_code(500);
        if (!headers_sent()) {
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $isApi = function_exists('isApiRequest') && \isApiRequest();
            if ($isApi || strpos($accept, 'application/json') !== false) {
                header('Content-Type: application/json');
                die(json_encode(['error' => $message]));
            }
        }
        echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Server Error</title></head><body>";
        echo "<h1>Server Error</h1><p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
        echo "</body></html>";
        exit;
    }
}
