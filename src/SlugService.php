<?php
// src/SlugService.php

namespace App;

class SlugService {
    /**
     * Generate a slug from a string.
     */
    public static function generate($text, $prefixDate = true) {
        // Handle non-ASCII characters by URL encoding or mapping (simple approach for now)
        // If it's a filename, we might want to keep some characters or transliterate.
        // For now, let's at least ensure it's not empty.
        
        $text = mb_strtolower($text, 'UTF-8');
        
        // Remove non-alphanumeric (except for -)
        $text = preg_replace('/[^a-z0-9\s-]/u', '', $text);
        
        // Replace spaces and multiple dashes with a single dash
        $text = preg_replace('/[\s-]+/', '-', $text);
        
        // Trim dashes from both ends
        $text = trim($text, '-');
        
        // Fallback if empty
        if (empty($text)) {
            $text = 'content-' . bin2hex(random_bytes(4));
        }
        
        if ($prefixDate) {
            $date = date('Y/m/d');
            return "$date/$text";
        }
        
        return $text;
    }

    /**
     * Normalize filename for storage.
     */
    public static function normalizeFilename($filename) {
        $info = pathinfo($filename);
        $name = self::generate($info['filename'], false);
        $ext = isset($info['extension']) ? strtolower($info['extension']) : '';
        
        // Ensure name is safe for S3 and not just a bunch of dots
        $name = ltrim($name, '.');
        if (empty($name)) {
            $name = 'file_' . time();
        }
        
        return $ext ? "$name.$ext" : $name;
    }

    /**
     * Ensure workspace slug is unique in the database.
     */
    public static function ensureUniqueWorkspaceSlug($slug, \PDO $db) {
        $originalSlug = $slug;
        $counter = 1;
        
        while (true) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM workspaces WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn() == 0) {
                return $slug;
            }
            $slug = $originalSlug . '-' . $counter++;
        }
    }
}
