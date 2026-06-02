<?php
function rate_limit(string $key, int $max, int $seconds) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = time();

    $_SESSION['rate'] ??= [];
    $_SESSION['rate'][$key] ??= [];

    if (!isset($_SESSION['rate'][$key][$ip])) {
        $_SESSION['rate'][$key][$ip] = [
            'count' => 1,
            'time' => $now
        ];
        return;
    }

    $r = &$_SESSION['rate'][$key][$ip];

    if ($now - $r['time'] > $seconds) {
        $r = ['count' => 1, 'time' => $now];
        return;
    }

    $r['count']++;

    if ($r['count'] > $max) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => 0,
            'error' => 'Too many requests'
        ]);
        exit;
    }
}
