<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

auth_guard();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim((string) $action);

$IS_EXPORT = ($action === 'export_scoring_summary');

// Bắt cả fatal/parse để không "response rỗng"
register_shutdown_function(function () use ($IS_EXPORT) {
    $e = error_get_last();
    if (!$e)
        return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($e['type'], $fatalTypes, true))
        return;

    http_response_code(500);

    if ($IS_EXPORT) {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Fatal error: " . ($e['message'] ?? 'unknown') . "\n"
            . "File: " . ($e['file'] ?? '') . "\n"
            . "Line: " . ($e['line'] ?? 0) . "\n";
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Fatal error: ' . ($e['message'] ?? 'unknown'),
        'file' => $e['file'] ?? '',
        'line' => $e['line'] ?? 0,
    ], JSON_UNESCAPED_UNICODE);
});

// Include helpers
require_once __DIR__ . '/scoring/helpers.php';

try {
    // Include modular routing files
    require_once __DIR__ . '/scoring/options.php';
    require_once __DIR__ . '/scoring/config.php';
    require_once __DIR__ . '/scoring/calculate.php';
    require_once __DIR__ . '/scoring/save.php';
    require_once __DIR__ . '/scoring/saved_crud.php';
    require_once __DIR__ . '/scoring/export.php';

    // If no action matched above, return unknown action error
    json_err('Unknown action', 400, ['action' => $action]);

} catch (Throwable $e) {
    json_err($e->getMessage(), 500, [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
