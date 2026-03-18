<?php
// src/SnippetService.php

namespace App;

class SnippetService {
    public static function generateMarkdown($url, $alt = '', $caption = '', $number = null) {
        $markdown = "![{$alt}]({$url})";
        if ($caption) {
            $prefix = $number ? "Gambar {$number}: " : "";
            $markdown .= "\n*{$prefix}{$caption}*";
        }
        return $markdown;
    }

    public static function generateHtml($url, $alt = '', $caption = '', $number = null) {
        $prefix = $number ? "Gambar {$number}: " : "";
        $html = "<figure>\n";
        $html .= "  <img src=\"{$url}\" alt=\"{$alt}\">\n";
        if ($caption) {
            $html .= "  <figcaption>{$prefix}{$caption}</figcaption>\n";
        }
        $html .= "</figure>";
        return $html;
    }
}
