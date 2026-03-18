<?php
// src/Logger.php

namespace App;

class Logger {
    public static function error($message, array $context = []) {
        $logEntry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => 'ERROR',
            'message' => $message,
            'context' => $context
        ];
        // Generate a trace ID if one isn't provided
        if (!isset($context['trace_id'])) {
            $logEntry['trace_id'] = substr(md5(uniqid('', true)), 0, 8);
        }
        error_log(json_encode($logEntry));
    }

    public static function info($message, array $context = []) {
        $logEntry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => 'INFO',
            'message' => $message,
            'context' => $context
        ];
        if (!isset($context['trace_id'])) {
            $logEntry['trace_id'] = substr(md5(uniqid('', true)), 0, 8);
        }
        error_log(json_encode($logEntry));
    }
}
