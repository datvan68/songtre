<?php
// Default fallback
$base_url = 'https://songtre.namsaigon.edu.vn/';

// Load .env variables if file exists
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (in_array($name, ['BASE_URL', 'APP_URL'])) {
                $base_url = $value;
                break;
            }
        }
    }
} else {
    // If no .env, fallback to host-based logic
    if (isset($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
        if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            // Dynamically construct based on actual port
            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $host . "/";
        }
    }
}

define('BASE_URL', rtrim($base_url, '/') . '/');
