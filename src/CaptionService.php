<?php
// src/CaptionService.php

namespace App;

class CaptionService {
    /**
     * Generate a caption based on filename and workspace/article title.
     */
    public static function generate($originalName, $articleTitle = '', $index = null) {
        $cleanName = pathinfo($originalName, PATHINFO_FILENAME);
        $cleanName = str_replace(['-', '_'], ' ', $cleanName);
        $cleanName = ucfirst($cleanName);

        $numberPrefix = $index ? "Gambar {$index}: " : "";

        if ($articleTitle) {
            return "{$numberPrefix}Tampilan {$cleanName} pada artikel {$articleTitle}.";
        }

        return "{$numberPrefix}{$cleanName}.";
    }

    /**
     * Generate alt text (short and descriptive).
     */
    public static function generateAlt($originalName) {
        $cleanName = pathinfo($originalName, PATHINFO_FILENAME);
        $cleanName = str_replace(['-', '_'], ' ', $cleanName);
        return strtolower($cleanName);
    }
}
