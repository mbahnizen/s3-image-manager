<?php
// src/WorkspaceService.php

namespace App;

use App\Database;
use App\SlugService;

class WorkspaceService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function createWorkspace($title, $description = '') {
        $slug = SlugService::generate($title);
        $uniqueSlug = SlugService::ensureUniqueWorkspaceSlug($slug, $this->db);
        $stmt = $this->db->prepare("INSERT INTO workspaces (title, slug, description) VALUES (?, ?, ?)");
        $stmt->execute([$title, $uniqueSlug, $description]);
        return $this->db->lastInsertId();
    }

    public function listWorkspaces() {
        $sql = "SELECT 
                    w.*,
                    COUNT(a.id) AS asset_count,
                    COALESCE(SUM(a.size_bytes), 0) AS total_bytes,
                    MAX(a.created_at) AS last_asset_at
                FROM workspaces w
                LEFT JOIN assets a 
                    ON a.workspace_id = w.id AND a.deleted_at IS NULL
                GROUP BY w.id
                ORDER BY w.created_at DESC";
        return $this->db->query($sql)->fetchAll();
    }

    public function getWorkspace($id) {
        $stmt = $this->db->prepare("SELECT * FROM workspaces WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}
