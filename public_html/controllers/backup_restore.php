<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

define('BASE_PATH', dirname(__DIR__)); // vì file controller nằm trong /controllers

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/google-client.php';
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/config/activity_log.php';


auth_guard();
if (!is_admin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(0);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_ok($data = null)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => 1, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400, $extra = null)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['ok' => 0, 'error' => $msg];
    if ($extra !== null)
        $payload['extra'] = $extra;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Split SQL file into statements
 * - Handles comments
 * - Handles strings
 * - Handles DELIMITER blocks (triggers/procedures)
 */
function splitSqlStatements(string $sql): array
{
    // normalize line endings
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);

    $statements = [];
    $buffer = '';
    $delimiter = ';';

    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    // helper: check startswith at position
    $startsWithAt = function ($haystack, $needle, $pos) {
        return substr($haystack, $pos, strlen($needle)) === $needle;
    };

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        // --- handle line comments: -- or #
        if (!$inString) {
            // -- comment (must be followed by space or end)
            if ($startsWithAt($sql, "--", $i)) {
                $next = $sql[$i + 2] ?? '';
                if ($next === ' ' || $next === "\t" || $next === "\n" || $next === '') {
                    // skip until newline
                    while ($i < $len && $sql[$i] !== "\n")
                        $i++;
                    continue;
                }
            }
            // # comment
            if ($ch === '#') {
                while ($i < $len && $sql[$i] !== "\n")
                    $i++;
                continue;
            }
            // /* block comment */
            if ($startsWithAt($sql, "/*", $i)) {
                $i += 2;
                while ($i < $len && !$startsWithAt($sql, "*/", $i))
                    $i++;
                $i++; // will be incremented by loop
                continue;
            }
        }

        // --- detect DELIMITER change at line start
        if (!$inString) {
            // look back to check line start
            $prev = $sql[$i - 1] ?? "\n";
            if ($prev === "\n") {
                // trim from here
                if (preg_match('/\GDELIMITER\s+(.+)\s*\n/mi', $sql, $m, 0, $i)) {
                    $delimiter = trim($m[1]);
                    // jump cursor to end of that DELIMITER line
                    $i += strlen($m[0]) - 1;
                    continue;
                }
            }
        }

        // --- handle string entering/leaving
        if ($inString) {
            $buffer .= $ch;
            // escape sequence
            if ($ch === '\\') {
                $buffer .= ($sql[$i + 1] ?? '');
                $i++;
                continue;
            }
            if ($ch === $stringChar) {
                $inString = false;
                $stringChar = '';
            }
            continue;
        } else {
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = true;
                $stringChar = $ch;
                $buffer .= $ch;
                continue;
            }
        }

        // --- check delimiter end
        if ($delimiter !== '' && $startsWithAt($sql, $delimiter, $i)) {
            $stmt = trim($buffer);
            if ($stmt !== '')
                $statements[] = $stmt;
            $buffer = '';
            $i += strlen($delimiter) - 1;
            continue;
        }

        $buffer .= $ch;
    }

    $tail = trim($buffer);
    if ($tail !== '')
        $statements[] = $tail;

    return $statements;
}
// ====== GOOGLE DRIVE TEST (Shared Drive folder) ======
// gọi: controllers/backup.php?action=drive_ping&folder_id=...
if ($action === 'drive_ping') {
    $folderId = trim($_GET['folder_id'] ?? '');
    if ($folderId === '')
        json_err('Missing folder_id');

    try {
        $drive = getDriveService();

        $f = $drive->files->get($folderId, driveGetCreateOpts([
            'fields' => 'id,name,mimeType,driveId,parents',
        ]));

        json_ok([
            'service_account' => getServiceAccountEmail(),
            'folder' => [
                'id' => $f->getId(),
                'name' => $f->getName(),
                'mimeType' => $f->getMimeType(),
                'driveId' => $f->getDriveId(),
                'parents' => $f->getParents(),
            ]
        ]);
    } catch (Throwable $e) {
        json_err('Drive ping failed: ' . $e->getMessage(), 500);
    }
}

// gọi: controllers/backup.php?action=drive_upload_test&folder_id=...
if ($action === 'drive_upload_test') {
    $folderId = trim($_GET['folder_id'] ?? '');
    if ($folderId === '')
        json_err('Missing folder_id');

    try {
        $drive = getDriveService();

        $content = "Backup test " . date('Y-m-d H:i:s');
        $filename = 'backup_test_' . date('Ymd_His') . '.txt';

        $meta = new \Google\Service\Drive\DriveFile([
            'name' => $filename,
            'parents' => [$folderId],
        ]);

        $created = $drive->files->create($meta, driveGetCreateOpts([
            'data' => $content,
            'mimeType' => 'text/plain; charset=utf-8',
            'uploadType' => 'multipart',
            'fields' => 'id,name,webViewLink',
        ]));

        json_ok([
            'id' => $created->getId(),
            'name' => $created->getName(),
            'link' => $created->getWebViewLink(),
        ]);
    } catch (Throwable $e) {
        json_err('Drive upload failed: ' . $e->getMessage(), 500);
    }
}
function setting_get(PDO $pdo, string $k, $default = null)
{
    $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

function getDriveFolderId(PDO $pdo): string
{
    return trim((string) setting_get($pdo, 'gdrive_folder_id', ''));
}
function driveOpts(array $opt = []): array
{
    return $opt + ['supportsAllDrives' => true];
}

function ensureDriveSubfolder(PDO $pdo, string $parentFolderId, string $subName): string
{
    $drive = getDriveService();

    // Tìm folder con theo tên trong parentFolderId
    // Note: phải escape dấu ' trong tên
    $nameEsc = str_replace("'", "\\'", $subName);

    $q = "mimeType='application/vnd.google-apps.folder'
          and name='{$nameEsc}'
          and '{$parentFolderId}' in parents
          and trashed=false";

    $list = $drive->files->listFiles(driveOpts([
        'q' => $q,
        'pageSize' => 1,
        'fields' => 'files(id,name)',
    ]));

    $files = $list->getFiles();
    if (!empty($files)) {
        return $files[0]->getId();
    }

    // Chưa có -> tạo folder
    $meta = new \Google\Service\Drive\DriveFile([
        'name' => $subName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentFolderId],
    ]);

    $created = $drive->files->create($meta, driveOpts([
        'fields' => 'id,name',
    ]));

    return $created->getId();
}

/* ======================================================
   EXPORT DATABASE (DOWNLOAD .SQL)
   ====================================================== */
if ($action === 'export') {

    $filename = 'backup_' . date('dmY_His') . '.sql';

    // 1) Dump ra file tạm để vừa upload Drive vừa download
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

    $fh = fopen($tmpPath, 'wb');
    if (!$fh) {
        json_err('Không tạo được file tạm để backup', 500);
    }

    $w = function (string $s) use ($fh) {
        fwrite($fh, $s);
    };

    $w("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
    $w("SET time_zone = '+07:00';\n");
    $w("SET NAMES utf8mb4;\n");
    $w("SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);

    $views = $pdo->query("
        SELECT TABLE_NAME
        FROM information_schema.VIEWS
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($views as $view) {
        $w("DROP VIEW IF EXISTS `$view`;\n");
    }
    $w("\n");

    foreach ($tables as $table) {
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);

        $w("DROP TABLE IF EXISTS `$table`;\n");
        $w($row['Create Table'] . ";\n\n");

        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_map(fn($c) => $c['Field'], $cols);
        $colListSql = '`' . implode('`,`', $colNames) . '`';

        $stmt = $pdo->query("SELECT * FROM `$table`");
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values = [];
            foreach ($colNames as $c) {
                $val = $data[$c];
                $values[] = ($val === null) ? 'NULL' : $pdo->quote($val);
            }
            $w("INSERT INTO `$table` ($colListSql) VALUES (" . implode(',', $values) . ");\n");
        }
        $w("\n\n");
    }

    $viewDependencies = [
        'view_finance_summary' => ['finance_transactions', 'campaigns'],
    ];

    foreach ($views as $view) {
        if (isset($viewDependencies[$view])) {
            foreach ($viewDependencies[$view] as $depTable) {
                if (!tableExists($pdo, $depTable)) {
                    continue 2;
                }
            }
        }

        $row = $pdo->query("SHOW CREATE VIEW `$view`")->fetch(PDO::FETCH_ASSOC);
        $w("DROP VIEW IF EXISTS `$view`;\n");
        $w($row['Create View'] . ";\n\n");
    }

    $w("SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    // 2) Upload lên Google Drive (không làm fail download nếu upload lỗi)
    $driveLink = '';
    try {
        $folderId = getDriveFolderId($pdo);

        if ($rootFolderId !== '') {
            $backupFolderId = ensureDriveSubfolder($pdo, $rootFolderId, 'Backup');

            $drive = getDriveService();

            $meta = new \Google\Service\Drive\DriveFile([
                'name' => $filename,
                'parents' => [$backupFolderId], // ✅ đưa vào subfolder Backup
            ]);

            // NOTE: multipart sẽ đọc file lên RAM; DB lớn thì nên chuyển sang resumable chunk upload
            $content = file_get_contents($tmpPath);

            $created = $drive->files->create($meta, [
                'supportsAllDrives' => true,
                'data' => $content,
                'mimeType' => 'application/sql; charset=utf-8',
                'uploadType' => 'multipart',
                'fields' => 'id,name,webViewLink',
            ]);

            $driveLink = (string) $created->getWebViewLink();

            if (function_exists('log_activity')) {
                log_activity(
                    'backup_drive_upload',
                    'system',
                    'Sao lưu Drive',
                    null,
                    'Upload backup lên Google Drive: ' . $filename . ($driveLink ? ' | ' . $driveLink : '')
                );
            }
        }
    } catch (Throwable $e) {
        error_log('[BACKUP DRIVE UPLOAD FAIL] ' . $e->getMessage());
        if (function_exists('log_activity')) {
            log_activity(
                'backup_drive_upload_fail',
                'system',
                'Sao lưu Drive',
                null,
                'Upload Drive thất bại: ' . $e->getMessage()
            );
        }
    }

    // 3) Download về máy
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // để UI đọc được link Drive nếu muốn show toast
    if ($driveLink) {
        header('X-Drive-Backup-Link: ' . $driveLink);
        header('Access-Control-Expose-Headers: X-Drive-Backup-Link'); // nếu khác origin / có CORS
    }

    readfile($tmpPath);

    // 4) cleanup
    @unlink($tmpPath);

    if (function_exists('log_activity')) {
        log_activity('export', 'system', 'Sao lưu dữ liệu', null, 'Tải backup database: ' . $filename);
    }

    exit;
}


/* ======================================================
   IMPORT / RESTORE DATABASE (UPLOAD .SQL)
   ====================================================== */
if ($action === 'import') {

    if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        json_err('Không có file upload hợp lệ');
    }

    $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
    if (!$sql) {
        json_err('File SQL rỗng');
    }

    $filename = $_FILES['sql_file']['name'] ?? 'unknown.sql';

    $warnings = [];
    $executed = 0;
    $skipped = 0;

    try {
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

        $stmts = splitSqlStatements($sql);

        foreach ($stmts as $idx => $stmt) {
            $s = trim($stmt);
            if ($s === '')
                continue;

            try {
                $pdo->exec($s);
                $executed++;
            } catch (Throwable $e) {

                $msg = $e->getMessage();

                // ✅ Nếu lỗi CREATE VIEW do thiếu cột / bảng => skip để cứu data
                $isCreateView = preg_match('/^\s*CREATE\s+(ALGORITHM=.*\s+)?VIEW/i', $s);
                $isUnknownColumn = stripos($msg, 'Unknown column') !== false;
                $isTableNotFound = stripos($msg, 'Base table or view not found') !== false;

                if ($isCreateView && ($isUnknownColumn || $isTableNotFound)) {
                    $skipped++;
                    $warnings[] = [
                        'type' => 'skip_view',
                        'at' => $idx + 1,
                        'error' => $msg,
                        'sql_preview' => mb_substr($s, 0, 180) . (mb_strlen($s) > 180 ? '...' : '')
                    ];
                    continue;
                }

                // ❌ lỗi khác => dừng
                throw $e;
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        log_activity(
            'import',
            'system',
            'Phục hồi dữ liệu',
            null,
            'Phục hồi database từ file: ' . $filename
        );

        json_ok([
            'msg' => 'Phục hồi database xong',
            'executed' => $executed,
            'skipped' => $skipped,
            'warnings' => $warnings
        ]);
    } catch (Throwable $e) {
        json_err('Import lỗi: ' . $e->getMessage(), 500, [
            'executed' => $executed,
            'skipped' => $skipped,
            'warnings' => $warnings
        ]);
    }
}

/* ======================================================
   BAD ACTION – ALWAYS JSON
   ====================================================== */
json_err('Bad action', 400);
