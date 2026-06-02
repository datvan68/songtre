<?php
require_once __DIR__ . '/../config/db.php';
// ⚠️ KHÔNG require auth.php nếu db.php đã include rồi.
// Nếu bạn chắc chắn db.php KHÔNG include auth.php thì dùng require_once:
if (file_exists(__DIR__ . '/../config/auth.php')) {
    require_once __DIR__ . '/../config/auth.php';
}

require __DIR__ . '/../config/google-client.php';
require_once __DIR__ . '/../config/activity_log.php';

use Google\Service\Drive;

// ✅ chạy ổn cả PHP 7.4 và 8.2: không để warning "deprecated" làm bể API
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ✅ API controller: tắt display_errors để không làm bể JSON
ini_set('display_errors', '0');

// ✅ Bắt warning/notice thành exception để trả JSON (NHƯNG bỏ qua deprecated)
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return true; // nuốt deprecated, không throw
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});


auth_guard();

$uid = (int) ($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$GLOBALS['_ACH_ACTION'] = $action;

$isExport = in_array($action, ['export_pdf', 'export_xlsx'], true);
$isStream = in_array($action, ['download_file', 'download_local'], true);

if (!$isExport && !$isStream) {
    header('Content-Type: application/json; charset=utf-8');
}

/* =====================================================
   RESP HELPERS
===================================================== */
function notify_insert(PDO $pdo, string $message, ?int $userId, string $link): void
{
    // userId = NULL => broadcast (giống nominations)
    $pdo->prepare("INSERT INTO notifications (message, user_id, link) VALUES (?, ?, ?)")
        ->execute([$message, $userId, $link]);
}

function ach_link(string $tab = 'list'): string
{
    // nhớ đúng route page của bạn
    return "/index.php?p=achievements&tab=" . urlencode($tab);
}

function ok($data = [])
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function err($msg = 'Error', $code = 400)
{
    $action = $GLOBALS['_ACH_ACTION'] ?? '';
    http_response_code($code);

    // export/download: trả plain text để không bể file stream
    if (in_array($action, ['export_pdf', 'export_xlsx', 'download_file', 'download_local'], true)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }

    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}


set_exception_handler(function ($e) {
    err("Server error: " . $e->getMessage(), 500);
});

/* =====================================================
   PERMISSIONS
===================================================== */
if (!can('achievements', 'view'))
    err('Forbidden', 403);

$canCreate = can('achievements', 'create');
$canUpdate = can('achievements', 'update');
$canDelete = can('achievements', 'delete');
$canReview = can('achievements', 'review');
$canPrint = can('achievements', 'print');

/* =====================================================
   FILE RULES
===================================================== */
const ACH_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xlsx'];
const ACH_ALLOWED_MIME = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];
const ACH_MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

function sanitize_filename($name): string
{
    $name = preg_replace('/[^\pL\pN\.\-\_\s]+/u', '', (string) $name);
    $name = preg_replace('/\s+/', '_', $name);
    return trim($name, '_');
}

function safe_ext(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    return $ext ?: 'bin';
}

function extract_drive_file_id(string $url): string
{
    $url = trim((string) $url);
    if ($url === '')
        return '';

    if (preg_match('~\/d\/([a-zA-Z0-9_-]{10,})\/~', $url, $m))
        return $m[1];
    if (preg_match('~[?&]id=([a-zA-Z0-9_-]{10,})~', $url, $m))
        return $m[1];

    return '';
}

/* =====================================================
   SCHEMA DETECT (tự detect cột drive_file_id)
===================================================== */
function table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $k = strtolower($table . '.' . $column);
    if (isset($cache[$k]))
        return $cache[$k];

    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    $cache[$k] = ((int) $st->fetchColumn() > 0);
    return $cache[$k];
}
function resolve_member_for_logged_user(PDO $pdo, int $uid): array
{
    if ($uid <= 0)
        return ['user_fullname' => '', 'member' => null];

    // 1) lấy user
    $u = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $u->execute([$uid]);
    $user = $u->fetch(PDO::FETCH_ASSOC) ?: null;

    $userFullname = trim((string) ($user['fullname'] ?? ''));
    if (!$user)
        return ['user_fullname' => $userFullname, 'member' => null];

    $member = null;

    // 2) ưu tiên users.member_id (nếu có cột)
    if (table_has_column($pdo, 'users', 'member_id') && !empty($user['member_id'])) {
        $mid = (int) $user['member_id'];
        $st = $pdo->prepare("
            SELECT m.id, m.fullname, m.mssv,
                   COALESCE(m.class_name, c.name) AS class_text
            FROM members m
            LEFT JOIN classes c ON c.id = m.class_id
            WHERE m.id=? AND (m.stop_follow=0 OR m.stop_follow IS NULL)
            LIMIT 1
        ");
        $st->execute([$mid]);
        $member = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // 3) fallback: members.user_id (nếu có cột)
    if (!$member && table_has_column($pdo, 'members', 'user_id')) {
        $st = $pdo->prepare("
            SELECT m.id, m.fullname, m.mssv,
                   COALESCE(m.class_name, c.name) AS class_text
            FROM members m
            LEFT JOIN classes c ON c.id = m.class_id
            WHERE m.user_id=? AND (m.stop_follow=0 OR m.stop_follow IS NULL)
            LIMIT 1
        ");
        $st->execute([$uid]);
        $member = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // 4) fallback: match mssv theo users.mssv/username/email (nếu có)
    if (!$member) {
        $guess = '';
        foreach (['mssv', 'username', 'email'] as $k) {
            if (!empty($user[$k]) && is_string($user[$k])) {
                $guess = trim($user[$k]);
                break;
            }
        }
        if ($guess !== '') {
            $st = $pdo->prepare("
                SELECT m.id, m.fullname, m.mssv,
                       COALESCE(m.class_name, c.name) AS class_text
                FROM members m
                LEFT JOIN classes c ON c.id = m.class_id
                WHERE m.mssv=? AND (m.stop_follow=0 OR m.stop_follow IS NULL)
                LIMIT 1
            ");
            $st->execute([$guess]);
            $member = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    // 5) fallback cuối: match theo fullname
    if (!$member && $userFullname !== '') {
        $like = '%' . $userFullname . '%';
        $st = $pdo->prepare("
            SELECT m.id, m.fullname, m.mssv,
                   COALESCE(m.class_name, c.name) AS class_text
            FROM members m
            LEFT JOIN classes c ON c.id = m.class_id
            WHERE m.fullname LIKE ?
              AND (m.stop_follow=0 OR m.stop_follow IS NULL)
            ORDER BY (m.fullname = ?) DESC, m.id DESC
            LIMIT 1
        ");
        $st->execute([$like, $userFullname]);
        $member = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return ['user_fullname' => $userFullname, 'member' => $member];
}
function build_achievements_spreadsheet(PDO $pdo, array $rows): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $titleLine1 = "DANH SÁCH THÀNH TÍCH";
    $titleLine2 = "KHO THÀNH TÍCH";

    $place = "Quận 8";
    $dateLine = $place . ", ngày " . date('j') . " tháng " . date('n') . " năm " . date('Y');

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Danh sach');
    $spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(12);

    $lastColLetter = 'M';

    // ===== helper: map status vi =====
    $visibility_vi = function (?string $s): string {
        $s = strtolower(trim((string) $s));
        $map = [
            'public' => 'Công khai',
            'private' => 'Riêng tư',
            'hidden' => 'Ẩn',
            'show' => 'Hiển thị',
            'display' => 'Hiển thị',
            'on' => 'Hiển thị',
            'off' => 'Ẩn',
            '1' => 'Hiển thị',
            '0' => 'Ẩn',
            'yes' => 'Hiển thị',
            'no' => 'Ẩn',
        ];
        if ($s === '')
            return '';
        return $map[$s] ?? (string) $s;
    };

    $status_vi = function (?string $s): string {
        $s = strtolower(trim((string) $s));
        $map = [
            'pending' => 'Chờ duyệt',
            'approved' => 'Đã duyệt',
            'rejected' => 'Từ chối',
            'draft' => 'Nháp',
            'submitted' => 'Đã gửi duyệt',
            'archived' => 'Lưu trữ',
            'deleted' => 'Đã xóa',
            'active' => 'Đang áp dụng',
            'inactive' => 'Ngừng áp dụng',
        ];
        if ($s === '')
            return '';
        return $map[$s] ?? (string) $s;
    };


    // header
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";

    $sheet->setCellValue("A1", $orgLeft);
    $sheet->mergeCells("A1:F4");
    $sheet->getStyle("A1:F4")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    $sheet->setCellValue("H1", $orgRight);
    $sheet->mergeCells("H1:M3");
    $sheet->getStyle("H1:M3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'underline' => true],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    $sheet->setCellValue("H4", $dateLine);
    $sheet->mergeCells("H4:M4");
    $sheet->getStyle("H4:M4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 12],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(1)->setRowHeight(20.5);
    $sheet->getRowDimension(2)->setRowHeight(15.75);
    $sheet->getRowDimension(3)->setRowHeight(15.75);
    $sheet->getRowDimension(4)->setRowHeight(32.25);

    // title
    $sheet->setCellValue("A5", $titleLine1);
    $sheet->mergeCells("A5:{$lastColLetter}5");
    $sheet->getStyle("A5:{$lastColLetter}5")->applyFromArray([
        'font' => ['bold' => true, 'size' => 18],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->setCellValue("A6", $titleLine2);
    $sheet->mergeCells("A6:{$lastColLetter}6");
    $sheet->getStyle("A6:{$lastColLetter}6")->applyFromArray([
        'font' => ['bold' => true, 'size' => 15, 'underline' => true],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(5)->setRowHeight(33.0);
    $sheet->getRowDimension(6)->setRowHeight(28.5);

    $sheet->mergeCells("A7:{$lastColLetter}7");
    $sheet->getRowDimension(7)->setRowHeight(10);

    // table header row 8
    $headerRow = 8;
    $headers = [
        'Tên thành tích',        // A
        'Đơn vị/Cá nhân đạt',    // B
        'Cấp khen',              // C
        'Hình thức',             // D
        'Năm học',               // E
        'Cơ quan khen',          // F
        'Số quyết định',         // G
        'Ngày đạt',              // H
        'Hiển thị',              // I
        'Trạng thái',            // J
        'Người nhập',            // K
        'Người duyệt',           // L
        'Ngày tạo'               // M
    ];

    $colIdx = 1;
    foreach ($headers as $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx) . $headerRow;
        $sheet->setCellValue($cell, $h);
        $colIdx++;
    }

    $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F2F2F2']
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
        ],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(28);

    // body
    $rowNum = $headerRow + 1;
    foreach ($rows as $x) {
        if (($x['recipient_type'] ?? '') === 'individual') {
            $recipient = trim((string) ($x['member_fullname'] ?? ''));
            $mssv = trim((string) ($x['member_mssv'] ?? ''));
            $cls = trim((string) ($x['member_class'] ?? ''));
            if ($mssv !== '')
                $recipient .= " - {$mssv}";
            if ($cls !== '')
                $recipient .= " ({$cls})";
        } else {
            $recipient = (string) ($x['recipient_name'] ?? '');
        }

        $sheet->setCellValue("A{$rowNum}", (string) ($x['title'] ?? ''));
        $sheet->setCellValue("B{$rowNum}", $recipient);
        $sheet->setCellValue("C{$rowNum}", (string) ($x['award_level'] ?? ''));
        $sheet->setCellValue("D{$rowNum}", (string) ($x['award_form'] ?? ''));
        $sheet->setCellValue("E{$rowNum}", (string) ($x['school_year'] ?? ''));
        $sheet->setCellValue("F{$rowNum}", (string) ($x['awarding_agency'] ?? ''));

        $sheet->setCellValueExplicit(
            "G{$rowNum}",
            (string) ($x['decision_no'] ?? ''),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );

        $sheet->setCellValue("H{$rowNum}", (string) ($x['achieved_at'] ?? ''));
        $sheet->setCellValue("I{$rowNum}", $visibility_vi($x['visibility'] ?? ''));
        $sheet->setCellValue("J{$rowNum}", $status_vi($x['status'] ?? ''));


        $sheet->setCellValue("K{$rowNum}", (string) ($x['creator_name'] ?? ''));
        $sheet->setCellValue("L{$rowNum}", (string) ($x['reviewer_name'] ?? ''));
        $sheet->setCellValue("M{$rowNum}", (string) ($x['created_at'] ?? ''));

        $rowNum++;
    }

    $lastRow = $rowNum - 1;

    if ($lastRow >= $headerRow) {
        // border + wrap toàn bảng
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$lastRow}")->applyFromArray([
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ],
        ]);

        // ✅ CĂN LỀ THEO YÊU CẦU
        // Trái: A,B,C,D,E,F,G,K,L
        $sheet->getStyle("A{$headerRow}:G{$lastRow}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle("K{$headerRow}:L{$lastRow}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        // Giữa: H,I,J,M
        $sheet->getStyle("H{$headerRow}:J{$lastRow}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("M{$headerRow}:M{$lastRow}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }

    // widths
    $sheet->getColumnDimension('A')->setWidth(34);
    $sheet->getColumnDimension('B')->setWidth(28);
    $sheet->getColumnDimension('C')->setWidth(14);
    $sheet->getColumnDimension('D')->setWidth(16);
    $sheet->getColumnDimension('E')->setWidth(12);
    $sheet->getColumnDimension('F')->setWidth(22);
    $sheet->getColumnDimension('G')->setWidth(16);
    $sheet->getColumnDimension('H')->setWidth(14);
    $sheet->getColumnDimension('I')->setWidth(12);
    $sheet->getColumnDimension('J')->setWidth(12);
    $sheet->getColumnDimension('K')->setWidth(18);
    $sheet->getColumnDimension('L')->setWidth(18);
    $sheet->getColumnDimension('M')->setWidth(18);

    // in PDF cho đẹp
    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

    $sheet->getPageMargins()
        ->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);

    return $spreadsheet;
}



/* =====================================================
   DRIVE SETTINGS (giống nominations)
===================================================== */
function setting_get(PDO $pdo, string $k, $default = null)
{
    $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : $v;
}

function get_root_drive_folder_id(PDO $pdo): string
{
    return trim((string) setting_get($pdo, 'gdrive_folder_id', ''));
}

function ensure_drive_subfolder(string $parentFolderId, string $subName): string
{
    $drive = new Drive(getGoogleClient());

    $nameEsc = str_replace("'", "\\'", $subName);
    $q = "mimeType='application/vnd.google-apps.folder'
        and name='{$nameEsc}'
        and '{$parentFolderId}' in parents
        and trashed=false";

    $list = $drive->files->listFiles([
        'q' => $q,
        'pageSize' => 1,
        'fields' => 'files(id,name)',
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true,
    ]);

    $files = $list->getFiles();
    if (!empty($files))
        return $files[0]->getId();

    $meta = new Google\Service\Drive\DriveFile([
        'name' => $subName,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentFolderId],
    ]);

    $created = $drive->files->create($meta, [
        'fields' => 'id,name',
        'supportsAllDrives' => true,
    ]);

    return $created->getId();
}

function get_achievements_drive_folder_id(PDO $pdo): string
{
    $root = get_root_drive_folder_id($pdo);
    if ($root === '')
        return '';
    return ensure_drive_subfolder($root, 'Minh chứng thành tích');
}

function drive_unique_name_in_folder(string $folderId, string $desiredName): string
{
    $drive = new Drive(getGoogleClient());

    $desiredName = trim((string) $desiredName);
    if ($desiredName === '')
        $desiredName = 'file';

    $ext = pathinfo($desiredName, PATHINFO_EXTENSION);
    $base = ($ext !== '') ? substr($desiredName, 0, -(strlen($ext) + 1)) : $desiredName;

    $escapeQ = fn($s) => str_replace("'", "\\'", $s);

    $exists = function (string $name) use ($drive, $folderId, $escapeQ): bool {
        $nameEsc = $escapeQ($name);
        $q = "name='{$nameEsc}' and '{$folderId}' in parents and trashed=false";
        $list = $drive->files->listFiles([
            'q' => $q,
            'pageSize' => 1,
            'fields' => 'files(id)',
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);
        return !empty($list->getFiles());
    };

    if (!$exists($desiredName))
        return $desiredName;

    $i = 1;
    while (true) {
        $candidate = ($ext !== '') ? "{$base} ({$i}).{$ext}" : "{$base} ({$i})";
        if (!$exists($candidate))
            return $candidate;
        $i++;
        if ($i > 5000)
            return $desiredName . ' (' . time() . ')';
    }
}

function upload_to_drive(PDO $pdo, string $tmpPath, string $fileName): array
{
    $drive = new Drive(getGoogleClient());

    $folderId = get_achievements_drive_folder_id($pdo);
    if ($folderId === '') {
        throw new Exception('Chưa cấu hình Google Drive folder (app_settings:gdrive_folder_id).');
    }

    $mime = @mime_content_type($tmpPath) ?: 'application/octet-stream';
    $finalName = $fileName;
    $finalName = trim((string) $finalName) ?: 'file';

    $ext = pathinfo($finalName, PATHINFO_EXTENSION);
    $base = $ext ? substr($finalName, 0, -(strlen($ext) + 1)) : $finalName;

    // suffix unique -> không cần query Drive để check trùng nữa
    $suffix = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $finalName = $ext ? "{$base}_{$suffix}.{$ext}" : "{$base}_{$suffix}";

    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name' => $finalName,
        'parents' => [$folderId]
    ]);

    $file = $drive->files->create($fileMetadata, [
        'data' => file_get_contents($tmpPath),
        'mimeType' => $mime,
        'uploadType' => 'multipart',
        'fields' => 'id, webViewLink',
        'supportsAllDrives' => true
    ]);

    // public read (nếu bạn muốn)
    try {
        $drive->permissions->create(
            $file->id,
            new Google\Service\Drive\Permission(['type' => 'anyone', 'role' => 'reader']),
            ['supportsAllDrives' => true]
        );
    } catch (Throwable $e) {
    }

    return [
        'id' => (string) $file->id,
        'url' => (string) $file->webViewLink,
        'mime' => $mime
    ];
}

function drive_delete_file_best_effort(string $driveId): void
{
    $driveId = trim($driveId);
    if ($driveId === '')
        return;
    try {
        $drive = new Drive(getGoogleClient());
        $drive->files->delete($driveId, ['supportsAllDrives' => true]);
    } catch (Throwable $e) {
        // ignore
    }
}

/* =====================================================
   FILTER + SCOPE
===================================================== */
function build_where(array $get, array &$params, int $uid, bool $canReview): string
{
    $keyword = trim($get['keyword'] ?? '');
    $school_year = trim($get['school_year'] ?? '');
    $award_level = trim($get['award_level'] ?? '');
    $recipient_type = trim($get['recipient_type'] ?? '');
    $visibility = trim($get['visibility'] ?? '');
    $status = trim($get['status'] ?? '');

    $where = "WHERE 1=1";
    $params = [];

    // Scope:
    // - User thường: chỉ thấy dữ liệu của bản thân
    $mode = trim($get['mode'] ?? 'list'); // list | review

    if (!$canReview) {
        $where .= " AND a.user_id = ?";
        $params[] = $uid;
    } else {
        // ✅ Reviewer:
        // - Nếu mode=list và status rỗng -> giữ hành vi cũ: chỉ approved
        // - Nếu mode=review và status rỗng -> KHÔNG lọc status (tức là tất cả)
        if ($mode !== 'review' && $status === '') {
            $where .= " AND a.status = 'approved'";
        }
    }


    if ($keyword !== '') {
        $where .= " AND (a.title LIKE ? OR a.content LIKE ? OR m.fullname LIKE ? OR a.recipient_name LIKE ?)";
        $like = "%$keyword%";
        array_push($params, $like, $like, $like, $like);
    }
    if ($school_year !== '') {
        $where .= " AND a.school_year = ?";
        $params[] = $school_year;
    }
    if ($award_level !== '') {
        $where .= " AND a.award_level = ?";
        $params[] = $award_level;
    }
    if ($recipient_type !== '') {
        $where .= " AND a.recipient_type = ?";
        $params[] = $recipient_type;
    }
    if ($visibility !== '') {
        $where .= " AND a.visibility = ?";
        $params[] = $visibility;
    }
    if ($status !== '') {
        $where .= " AND a.status = ?";
        $params[] = $status;
    }


    return $where;
}

function fetch_one_achievement(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM achievements WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* =====================================================
   URL PREFIX (để build download_url)
===================================================== */
function app_prefix(): string
{
    // /doanthanhnien/controllers/achievements.php -> /doanthanhnien
    return rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}

/* =====================================================
   ACTIONS
===================================================== */

if ($action === 'stats') {
    global $pdo, $uid, $canReview;

    $where = "WHERE 1=1";
    $params = [];
    if (!$canReview) {
        $where .= " AND user_id = ?";
        $params[] = $uid;
    }

    $st = $pdo->prepare("
        SELECT
          COUNT(*) AS total,
          SUM(status='submitted') AS pending,
          SUM(status='approved') AS approved,
          SUM(status='rejected') AS rejected
        FROM achievements
        $where
    ");
    $st->execute($params);

    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    ok(['stats' => array_map('intval', $row)]);
}

if ($action === 'members_search') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '')
        ok(['rows' => []]);

    $sql = "
        SELECT m.id, m.fullname, m.mssv,
               COALESCE(m.class_name, c.name) AS class_text
        FROM members m
        LEFT JOIN classes c ON c.id = m.class_id
        WHERE (m.fullname LIKE ? OR m.mssv LIKE ? OR COALESCE(m.class_name, c.name) LIKE ?)
          AND (m.stop_follow = 0 OR m.stop_follow IS NULL)
        ORDER BY m.fullname ASC
        LIMIT 20
    ";
    $like = "%$q%";
    $st = $pdo->prepare($sql);
    $st->execute([$like, $like, $like]);
    ok(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
// =====================================================
// ME MEMBER (auto pick member of current logged-in user)
// =====================================================
if ($action === 'me_member') {
    if (!can('achievements', 'view'))
        err('Forbidden', 403);

    $res = resolve_member_for_logged_user($pdo, $uid);
    ok([
        'member' => $res['member'],
        'user_fullname' => $res['user_fullname'],
    ]);
}


if ($action === 'list') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(5, min(50, (int) ($_GET['per_page'] ?? 10)));
    $offset = ($page - 1) * $perPage;

    $params = [];
    $where = build_where($_GET, $params, $uid, $canReview);

    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM achievements a
        LEFT JOIN members m ON m.id = a.member_id
        $where
    ");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $totalPages = (int) ceil($total / $perPage);

    $sql = "
        SELECT a.*,
          m.fullname AS member_fullname,
          m.mssv AS member_mssv,
          COALESCE(m.class_name, c.name) AS member_class,
          u.fullname AS creator_name,
          ru.fullname AS reviewer_name,
          (SELECT COUNT(*) FROM achievement_files af WHERE af.achievement_id = a.id) AS files_count
        FROM achievements a
        LEFT JOIN members m ON m.id = a.member_id
        LEFT JOIN classes c ON c.id = m.class_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN users ru ON ru.id = a.reviewed_by
        $where
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $st = $pdo->prepare($sql);

    $i = 1;
    foreach ($params as $p)
        $st->bindValue($i++, $p);
    $st->bindValue($i++, $perPage, PDO::PARAM_INT);
    $st->bindValue($i++, $offset, PDO::PARAM_INT);

    $st->execute();

    ok([
        'rows' => $st->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ]
    ]);
}

if ($action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0)
        err('Thiếu id');

    $rowBase = fetch_one_achievement($pdo, $id);
    if (!$rowBase)
        err('Không tìm thấy', 404);

    // ✅ user thường: chỉ được xem của bản thân
    if (!$canReview && (int) $rowBase['user_id'] !== $uid) {
        err('Forbidden', 403);
    }

    $st = $pdo->prepare("
        SELECT a.*,
          m.fullname AS member_fullname,
          m.mssv AS member_mssv,
          COALESCE(m.class_name, c.name) AS member_class,
          u.fullname AS creator_name,
          ru.fullname AS reviewer_name
        FROM achievements a
        LEFT JOIN members m ON m.id = a.member_id
        LEFT JOIN classes c ON c.id = m.class_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN users ru ON ru.id = a.reviewed_by
        WHERE a.id = ?
        LIMIT 1
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row)
        err('Không tìm thấy', 404);

    $hasDriveId = table_has_column($pdo, 'achievement_files', 'drive_file_id');
    $fields = $hasDriveId
        ? "id, storage, file_name, mime_type, file_size, local_path, drive_file_id, drive_web_view_link, created_at"
        : "id, storage, file_name, mime_type, file_size, local_path, drive_web_view_link, created_at";

    $st = $pdo->prepare("
        SELECT $fields
        FROM achievement_files
        WHERE achievement_id = ?
        ORDER BY id DESC
    ");
    $st->execute([$id]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);

    $prefix = app_prefix();
    foreach ($files as &$f) {
        $storage = (string) ($f['storage'] ?? 'local');

        if ($storage === 'drive') {
            $view = (string) ($f['drive_web_view_link'] ?? '');
            $f['view_url'] = $view;
            $f['download_url'] = $prefix . "/controllers/achievements.php?action=download_file&file_id=" . (int) $f['id'];
        } else {
            // local cũ (fallback)
            $path = (string) ($f['local_path'] ?? '');
            $f['view_url'] = ($path !== '') ? ($prefix . "/" . ltrim($path, '/')) : '';
            $f['download_url'] = $prefix . "/controllers/achievements.php?action=download_local&file_id=" . (int) $f['id'];
        }
    }
    unset($f);

    ok(['row' => $row, 'files' => $files]);
}

/* =====================================================
   STREAM DOWNLOAD: DRIVE
   - tải trực tiếp, không qua Drive UI
===================================================== */
if ($action === 'download_file') {
    header_remove('Content-Type');
    if (ob_get_length())
        @ob_end_clean();

    $fileId = (int) ($_GET['file_id'] ?? 0);
    if (!$fileId) {
        http_response_code(400);
        echo "Missing file_id";
        exit;
    }

    // lấy file + achievement để check quyền
    $st = $pdo->prepare("
        SELECT af.*, a.user_id AS owner_user_id, a.status AS ach_status
        FROM achievement_files af
        JOIN achievements a ON a.id = af.achievement_id
        WHERE af.id=? LIMIT 1
    ");
    $st->execute([$fileId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        http_response_code(404);
        echo "File not found";
        exit;
    }

    // user thường: chỉ được tải file của mình (trừ reviewer)
    if (!$canReview && (int) $f['owner_user_id'] !== $uid) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }

    // xác định drive id
    $driveId = '';
    if (!empty($f['drive_file_id']))
        $driveId = (string) $f['drive_file_id'];
    if ($driveId === '' && !empty($f['drive_web_view_link'])) {
        $driveId = extract_drive_file_id((string) $f['drive_web_view_link']);
    }
    if ($driveId === '') {
        http_response_code(500);
        echo "Drive file id missing";
        exit;
    }

    $drive = new Drive(getGoogleClient());

    try {
        $meta = $drive->files->get($driveId, [
            'fields' => 'name,mimeType',
            'supportsAllDrives' => true
        ]);

        $resp = $drive->files->get($driveId, [
            'alt' => 'media',
            'supportsAllDrives' => true
        ]);

        $mime = $meta->mimeType ?: ((string) ($f['mime_type'] ?? '') ?: 'application/octet-stream');
        $name = $meta->name ?: ((string) ($f['file_name'] ?? '') ?: ('achievement_file_' . $fileId));
        $name = str_replace(['"', "\r", "\n"], '', $name);

        header('Content-Type: ' . $mime);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $utf8 = rawurlencode($name);
        header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$utf8}");

        $body = $resp->getBody();
        while (!$body->eof()) {
            echo $body->read(1024 * 1024);
            flush();
        }
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo "Download error: " . $e->getMessage();
        exit;
    }
}

/* =====================================================
   STREAM DOWNLOAD: LOCAL (fallback cho file cũ)
===================================================== */
if ($action === 'download_local') {
    header_remove('Content-Type');
    if (ob_get_length())
        @ob_end_clean();

    $fileId = (int) ($_GET['file_id'] ?? 0);
    if (!$fileId) {
        http_response_code(400);
        echo "Missing file_id";
        exit;
    }

    $st = $pdo->prepare("
        SELECT af.*, a.user_id AS owner_user_id
        FROM achievement_files af
        JOIN achievements a ON a.id = af.achievement_id
        WHERE af.id=? LIMIT 1
    ");
    $st->execute([$fileId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        http_response_code(404);
        echo "File not found";
        exit;
    }

    if (!$canReview && (int) $f['owner_user_id'] !== $uid) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }

    $path = (string) ($f['local_path'] ?? '');
    if ($path === '') {
        http_response_code(404);
        echo "File path missing";
        exit;
    }

    $abs = __DIR__ . '/../' . ltrim($path, '/');
    if (!is_file($abs)) {
        http_response_code(404);
        echo "File not found";
        exit;
    }

    $name = (string) ($f['file_name'] ?? basename($abs));
    $name = str_replace(['"', "\r", "\n"], '', $name);

    $mime = (string) ($f['mime_type'] ?? '');
    if ($mime === '')
        $mime = 'application/octet-stream';

    header('Content-Type: ' . $mime);
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
    $utf8 = rawurlencode($name);
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$utf8}");

    readfile($abs);
    exit;
}

/* =====================================================
   SAVE (UPLOAD DRIVE)
===================================================== */
if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $recipient_type = trim($_POST['recipient_type'] ?? 'individual');
    $member_id = (int) ($_POST['member_id'] ?? 0);


    if ($id > 0) {
        if (!$canUpdate)
            err('Không có quyền sửa', 403);

        $rowBase = fetch_one_achievement($pdo, $id);
        if (!$rowBase)
            err('Không tìm thấy', 404);

        // ✅ user thường: chỉ sửa của bản thân
        if (!$canReview && (int) $rowBase['user_id'] !== $uid)
            err('Forbidden', 403);

        // ✅ user thường: đã duyệt thì không được sửa
        if (!$canReview && ($rowBase['status'] ?? '') === 'approved') {
            err('Thành tích đã duyệt, bạn không được sửa.', 403);
        }
    } else {
        if (!$canCreate)
            err('Không có quyền tạo', 403);
    }

    $title = trim($_POST['title'] ?? '');
    $recipient_type = trim($_POST['recipient_type'] ?? 'individual');
    $member_id = (int) ($_POST['member_id'] ?? 0);
    $recipient_name = trim($_POST['recipient_name'] ?? '');
    $award_level = trim($_POST['award_level'] ?? '');
    $award_form = trim($_POST['award_form'] ?? '');
    $school_year = trim($_POST['school_year'] ?? '');
    $awarding_agency = trim($_POST['awarding_agency'] ?? '');
    $decision_no = trim($_POST['decision_no'] ?? '');
    $achieved_at = trim($_POST['achieved_at'] ?? '');
    $visibility = trim($_POST['visibility'] ?? 'hidden');
    $content = trim($_POST['content'] ?? '');
    // ✅ ÉP user thường (không review) nếu là "cá nhân" thì luôn là member của chính họ
    if (!$canReview && $recipient_type === 'individual') {
        $res = resolve_member_for_logged_user($pdo, $uid);
        $me = $res['member'] ?? null;

        if (!$me || empty($me['id'])) {
            err('Tài khoản của bạn chưa được gắn hồ sơ đoàn viên (members). Vui lòng liên hệ quản trị.', 400);
        }

        $member_id = (int) $me['id']; // override POST
    }

    if ($title === '')
        err('Tên thành tích không được trống');
    if ($content === '')
        err('Mô tả chi tiết không được trống');
    if (!in_array($recipient_type, ['individual', 'collective'], true))
        err('recipient_type không hợp lệ');
    if (!in_array($visibility, ['public', 'hidden'], true))
        $visibility = 'hidden';

    if ($recipient_type === 'individual') {
        if ($member_id <= 0)
            err('Bạn phải chọn cá nhân');
        $recipient_name = null;
    } else {
        if ($recipient_name === '')
            err('Bạn phải nhập tên tập thể');
        $member_id = null;
    }

    $achieved_at_db = ($achieved_at !== '') ? $achieved_at : null;
    $now = date('Y-m-d H:i:s');

    // Rule: có can_review => auto approved, không thì submitted
    $status = $canReview ? 'approved' : 'submitted';
    $submitted_at = $canReview ? null : $now;
    $reviewed_by = $canReview ? $uid : null;
    $reviewed_at = $canReview ? $now : null;
    $review_note = null;

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            // user thường sửa -> luôn về submitted
            if (!$canReview) {
                $status = 'submitted';
                $submitted_at = $now;
                $reviewed_by = null;
                $reviewed_at = null;
                $review_note = null;
            }

            $st = $pdo->prepare("
                UPDATE achievements SET
                  member_id = :member_id,
                  title = :title,
                  recipient_type = :recipient_type,
                  recipient_name = :recipient_name,
                  award_level = :award_level,
                  award_form = :award_form,
                  school_year = :school_year,
                  awarding_agency = :awarding_agency,
                  decision_no = :decision_no,
                  visibility = :visibility,
                  content = :content,
                  achieved_at = :achieved_at,
                  status = :status,
                  submitted_at = :submitted_at,
                  reviewed_by = :reviewed_by,
                  reviewed_at = :reviewed_at,
                  review_note = :review_note,
                  updated_at = NOW()
                WHERE id = :id
            ");
            $st->execute([
                ':member_id' => $member_id,
                ':title' => $title,
                ':recipient_type' => $recipient_type,
                ':recipient_name' => $recipient_name,
                ':award_level' => $award_level ?: null,
                ':award_form' => $award_form ?: null,
                ':school_year' => $school_year ?: null,
                ':awarding_agency' => $awarding_agency ?: null,
                ':decision_no' => ($decision_no !== '' ? $decision_no : null),
                ':visibility' => $visibility,
                ':content' => $content,
                ':achieved_at' => $achieved_at_db,
                ':status' => $status,
                ':submitted_at' => $submitted_at,
                ':reviewed_by' => $reviewed_by,
                ':reviewed_at' => $reviewed_at,
                ':review_note' => $review_note,
                ':id' => $id,
            ]);
            $achievementId = $id;
        } else {
            $st = $pdo->prepare("
                INSERT INTO achievements
                  (user_id, member_id, title, recipient_type, recipient_name, award_level, award_form,
                   school_year, awarding_agency, decision_no, visibility, content, achieved_at,
                   status, submitted_at, reviewed_by, reviewed_at, review_note, created_at, updated_at)
                VALUES
                  (:user_id, :member_id, :title, :recipient_type, :recipient_name, :award_level, :award_form,
                   :school_year, :awarding_agency,:decision_no, :visibility, :content, :achieved_at,
                   :status, :submitted_at, :reviewed_by, :reviewed_at, :review_note, NOW(), NOW())
            ");
            $st->execute([
                ':user_id' => $uid,
                ':member_id' => $member_id,
                ':title' => $title,
                ':recipient_type' => $recipient_type,
                ':recipient_name' => $recipient_name,
                ':award_level' => $award_level ?: null,
                ':award_form' => $award_form ?: null,
                ':school_year' => $school_year ?: null,
                ':awarding_agency' => $awarding_agency ?: null,
                ':decision_no' => ($decision_no !== '' ? $decision_no : null),
                ':visibility' => $visibility,
                ':content' => $content,
                ':achieved_at' => $achieved_at_db,
                ':status' => $status,
                ':submitted_at' => $submitted_at,
                ':reviewed_by' => $reviewed_by,
                ':reviewed_at' => $reviewed_at,
                ':review_note' => $review_note,
            ]);
            $achievementId = (int) $pdo->lastInsertId();
        }

        // ✅ Upload files -> Google Drive
        if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
            $hasDriveId = table_has_column($pdo, 'achievement_files', 'drive_file_id');

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $count = count($_FILES['files']['name']);

            for ($i = 0; $i < $count; $i++) {
                if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
                    continue;

                $tmp = $_FILES['files']['tmp_name'][$i];
                if (!$tmp || !is_uploaded_file($tmp))
                    continue;

                $orig = (string) ($_FILES['files']['name'][$i] ?? 'file');
                $size = (int) ($_FILES['files']['size'][$i] ?? 0);

                if ($size > ACH_MAX_FILE_SIZE)
                    throw new Exception('Mỗi file tối đa 5MB');

                $ext = safe_ext($orig);
                if (!in_array($ext, ACH_ALLOWED_EXT, true))
                    throw new Exception('Loại file không được phép');

                $mime = (string) $finfo->file($tmp);
                if (!in_array($mime, ACH_ALLOWED_MIME, true))
                    throw new Exception('File không hợp lệ');

                $clean = sanitize_filename($orig);
                if ($clean === '')
                    $clean = "file.$ext";

                $driveFile = upload_to_drive($pdo, $tmp, $clean); // {id,url,mime}

                if ($hasDriveId) {
                    $st = $pdo->prepare("
                        INSERT INTO achievement_files
                          (achievement_id, storage, file_name, mime_type, file_size, local_path, drive_file_id, drive_web_view_link, uploaded_by, created_at)
                        VALUES
                          (?, 'drive', ?, ?, ?, NULL, ?, ?, ?, NOW())
                    ");
                    $st->execute([$achievementId, $orig, $mime, $size, $driveFile['id'], $driveFile['url'], $uid]);
                } else {
                    $st = $pdo->prepare("
                        INSERT INTO achievement_files
                          (achievement_id, storage, file_name, mime_type, file_size, local_path, drive_web_view_link, uploaded_by, created_at)
                        VALUES
                          (?, 'drive', ?, ?, ?, NULL, ?, ?, NOW())
                    ");
                    $st->execute([$achievementId, $orig, $mime, $size, $driveFile['url'], $uid]);
                }
            }
        }

        $pdo->commit();
        // ✅ User thường gửi duyệt -> thông báo cho người có quyền duyệt (broadcast như nominations)
        if (!$canReview) {
            // lấy fullname + lớp/khoa nếu có
            $u = $pdo->prepare("SELECT fullname FROM users WHERE id=? LIMIT 1");
            $u->execute([$uid]);
            $fullname = trim((string) $u->fetchColumn());

            $res = resolve_member_for_logged_user($pdo, $uid);
            $me = $res['member'] ?? null;
            $dept = $me ? trim((string) ($me['class_text'] ?? '')) : '';
            $deptText = $dept !== '' ? " ({$dept})" : "";

            $link = ach_link('review'); // /index.php?p=achievements&tab=review
            $msg = "📩 Thành tích mới từ {$fullname}{$deptText} cần duyệt: {$title}";

            // broadcast cho nhóm duyệt (giống nominations)
            notify_insert($pdo, $msg, null, $link);
        }

        // activity log (nếu bạn muốn)
        if (function_exists('log_activity')) {
            log_activity(
                ($id > 0 ? 'update' : 'create'),
                'achievements',
                'Thành tích',
                null,
                ($id > 0 ? 'Cập nhật thành tích: ' : 'Tạo thành tích: ') . $title
            );
        }

        ok(['id' => $achievementId, 'status' => $status]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        err("Lỗi lưu: " . $e->getMessage(), 500);
    }
}

if ($action === 'delete_file') {
    if (!$canUpdate)
        err('Không có quyền', 403);

    $fileId = (int) ($_POST['file_id'] ?? 0);
    if ($fileId <= 0)
        err('Thiếu file_id');

    $hasDriveId = table_has_column($pdo, 'achievement_files', 'drive_file_id');
    $driveIdField = $hasDriveId ? "af.drive_file_id," : "";

    // lấy file + achievement
    $st = $pdo->prepare("
        SELECT af.id, af.storage, af.local_path, af.drive_web_view_link, $driveIdField
               a.id AS achievement_id, a.user_id, a.status
        FROM achievement_files af
        JOIN achievements a ON a.id = af.achievement_id
        WHERE af.id=? LIMIT 1
    ");
    $st->execute([$fileId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f)
        err('Không tìm thấy file', 404);

    // user thường: chỉ xóa file của mình và không được xóa nếu đã approved
    if (!$canReview) {
        if ((int) $f['user_id'] !== $uid)
            err('Forbidden', 403);
        if (($f['status'] ?? '') === 'approved')
            err('Đã duyệt không được xóa file', 403);
    }

    // xóa vật lý/drive
    $storage = (string) ($f['storage'] ?? 'local');
    if ($storage === 'drive') {
        $driveId = '';
        if (!empty($f['drive_file_id']))
            $driveId = (string) $f['drive_file_id'];
        if ($driveId === '' && !empty($f['drive_web_view_link']))
            $driveId = extract_drive_file_id((string) $f['drive_web_view_link']);
        drive_delete_file_best_effort($driveId);
    } else {
        if (!empty($f['local_path'])) {
            $abs = __DIR__ . '/../' . ltrim($f['local_path'], '/');
            if (is_file($abs))
                @unlink($abs);
        }
    }

    $st = $pdo->prepare("DELETE FROM achievement_files WHERE id=?");
    $st->execute([$fileId]);
    ok();
}

if ($action === 'delete') {
    if (!$canDelete)
        err('Không có quyền xoá', 403);

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0)
        err('Thiếu id');

    $row = fetch_one_achievement($pdo, $id);
    if (!$row)
        err('Không tìm thấy', 404);

    // user thường: chỉ xóa của mình, và approved thì không được xóa
    if (!$canReview) {
        if ((int) $row['user_id'] !== $uid)
            err('Forbidden', 403);
        if (($row['status'] ?? '') === 'approved')
            err('Thành tích đã duyệt, bạn không được xóa.', 403);
    }

    $hasDriveId = table_has_column($pdo, 'achievement_files', 'drive_file_id');
    $fields = $hasDriveId
        ? "id, storage, local_path, drive_file_id, drive_web_view_link"
        : "id, storage, local_path, drive_web_view_link";

    // xóa file drive/local
    $st = $pdo->prepare("SELECT $fields FROM achievement_files WHERE achievement_id=?");
    $st->execute([$id]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $f) {
        $storage = (string) ($f['storage'] ?? 'local');
        if ($storage === 'drive') {
            $driveId = '';
            if (!empty($f['drive_file_id']))
                $driveId = (string) $f['drive_file_id'];
            if ($driveId === '' && !empty($f['drive_web_view_link']))
                $driveId = extract_drive_file_id((string) $f['drive_web_view_link']);
            drive_delete_file_best_effort($driveId);
        } else {
            $p = (string) ($f['local_path'] ?? '');
            if ($p !== '') {
                $abs = __DIR__ . '/../' . ltrim($p, '/');
                if (is_file($abs))
                    @unlink($abs);
            }
        }
    }

    // xóa record files trước
    $st = $pdo->prepare("DELETE FROM achievement_files WHERE achievement_id=?");
    $st->execute([$id]);

    $st = $pdo->prepare("DELETE FROM achievements WHERE id=?");
    $st->execute([$id]);

    if (function_exists('log_activity')) {
        log_activity('delete', 'achievements', 'Thành tích', null, 'Xóa thành tích ID=' . $id);
    }

    ok();
}

if ($action === 'review') {
    if (!$canReview)
        err('Không có quyền duyệt', 403);

    $id = (int) ($_POST['id'] ?? 0);
    $decision = trim($_POST['decision'] ?? 'approve'); // approve|reject
    $note = trim($_POST['note'] ?? '');

    if ($id <= 0)
        err('Thiếu id');
    if (!in_array($decision, ['approve', 'reject'], true))
        err('decision không hợp lệ');

    $row = fetch_one_achievement($pdo, $id);
    if (!$row)
        err('Không tìm thấy', 404);

    $status = ($decision === 'approve') ? 'approved' : 'rejected';
    $now = date('Y-m-d H:i:s');

    $st = $pdo->prepare("
        UPDATE achievements SET
          status = ?,
          reviewed_by = ?,
          reviewed_at = ?,
          review_note = ?,
          submitted_at = COALESCE(submitted_at, ?),
          updated_at = NOW()
        WHERE id = ?
    ");
    $st->execute([$status, $uid, $now, ($note !== '' ? $note : null), $now, $id]);

    if (function_exists('log_activity')) {
        $act = ($decision === 'approve') ? 'approve' : 'reject';
        log_activity($act, 'achievements', 'Thành tích', null, strtoupper($act) . ' ID=' . $id);
    }

    ok(['status' => $status]);
    // ✅ thông báo về cho chủ thành tích
    $ownerId = (int) ($row['user_id'] ?? 0);
    $title = (string) ($row['title'] ?? '');

    if ($ownerId > 0) {
        $link = ach_link('list'); // /index.php?p=achievements&tab=list

        if ($status === 'approved') {
            $msg = "✅ Thành tích \"{$title}\" đã được duyệt.";
        } else {
            $reason = $note !== '' ? $note : 'Không có ghi chú.';
            $msg = "❌ Thành tích \"{$title}\" bị từ chối. Lý do: {$reason}";
        }

        notify_insert($pdo, $msg, $ownerId, $link);
    }

}

/* =====================================================
   EXPORT (giữ endpoint để JS không bể)
   - Nếu server bạn có PhpSpreadsheet/mPDF thì bật được ngay.
   - Nếu chưa có, nó sẽ báo lỗi rõ ràng.
===================================================== */
if ($action === 'export_xlsx') {
    if (!$canPrint)
        err('Forbidden', 403);

    while (ob_get_level() > 0)
        ob_end_clean();
    ob_start();
    date_default_timezone_set('Asia/Ho_Chi_Minh');

    // ===== query data =====
    $params = [];
    $where = build_where($_GET, $params, $uid, $canReview);

    $sql = "
        SELECT
            a.title,
            a.recipient_type,
            a.recipient_name,
            a.award_level,
            a.award_form,
            a.school_year,
            a.awarding_agency,
            a.decision_no,
            a.achieved_at,
            a.visibility,
            a.status,
            a.created_at,
            m.fullname AS member_fullname,
            m.mssv AS member_mssv,
            COALESCE(m.class_name, c.name) AS member_class,
            u.fullname AS creator_name,
            ru.fullname AS reviewer_name
        FROM achievements a
        LEFT JOIN members m ON m.id = a.member_id
        LEFT JOIN classes c ON c.id = m.class_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN users ru ON ru.id = a.reviewed_by
        $where
        ORDER BY a.created_at DESC
        LIMIT 20000
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // ===== title/date/filename =====
    $titleLine1 = "DANH SÁCH THÀNH TÍCH";
    $titleLine2 = "KHO THÀNH TÍCH";

    $place = "Quận 8";
    $dateLine = $place . ", ngày " . date('j') . " tháng " . date('n') . " năm " . date('Y');

    $filename = 'danh_sach_thanh_tich_' . date('Ymd_His') . '.xlsx';

    // ===== spreadsheet =====
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        err('Thiếu thư viện PhpSpreadsheet', 500);
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Danh sach');

    $spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(12);

    // 13 cột => A..M
    $lastColLetter = 'M';

    // ===== HEADER (1..4) =====
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";

    // A1:F4
    $sheet->setCellValue("A1", $orgLeft);
    $sheet->mergeCells("A1:F4");
    $sheet->getStyle("A1:F4")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // H1:M3
    $sheet->setCellValue("H1", $orgRight);
    $sheet->mergeCells("H1:M3");
    $sheet->getStyle("H1:M3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'underline' => true],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // H4:M4
    $sheet->setCellValue("H4", $dateLine);
    $sheet->mergeCells("H4:M4");
    $sheet->getStyle("H4:M4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 12],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(1)->setRowHeight(20.5);
    $sheet->getRowDimension(2)->setRowHeight(15.75);
    $sheet->getRowDimension(3)->setRowHeight(15.75);
    $sheet->getRowDimension(4)->setRowHeight(32.25);

    // ===== TITLE (5..6) =====
    $sheet->setCellValue("A5", $titleLine1);
    $sheet->mergeCells("A5:{$lastColLetter}5");
    $sheet->getStyle("A5:{$lastColLetter}5")->applyFromArray([
        'font' => ['bold' => true, 'size' => 18],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->setCellValue("A6", $titleLine2);
    $sheet->mergeCells("A6:{$lastColLetter}6");
    $sheet->getStyle("A6:{$lastColLetter}6")->applyFromArray([
        'font' => ['bold' => true, 'size' => 15, 'underline' => true],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(5)->setRowHeight(33.0);
    $sheet->getRowDimension(6)->setRowHeight(28.5);

    // row 7 spacer
    $sheet->mergeCells("A7:{$lastColLetter}7");
    $sheet->getRowDimension(7)->setRowHeight(10);

    // ===== TABLE HEADER row 8 =====
    $headerRow = 8;
    $headers = [
        'Tên thành tích',      // A
        'Đơn vị/Cá nhân đạt',  // B
        'Cấp khen',            // C
        'Hình thức',           // D
        'Năm học',             // E
        'Cơ quan khen',        // F
        'Số quyết định',       // G
        'Ngày đạt',            // H
        'Hiển thị',            // I
        'Trạng thái',          // J
        'Người nhập',          // K
        'Người duyệt',         // L
        'Ngày tạo'             // M
    ];

    $colIdx = 1;
    foreach ($headers as $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx) . $headerRow;
        $sheet->setCellValue($cell, $h);
        $colIdx++;
    }

    $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F2F2F2']
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
        ],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(28);

    // ===== BODY =====
    $rowNum = $headerRow + 1;

    $map_visibility_vi = function ($v): string {
        $s = strtolower(trim((string) $v));
        if ($s === '')
            return '';

        // hỗ trợ số / bool
        if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'on')
            return 'Hiển thị';
        if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'off')
            return 'Ẩn';

        $map = [
            'public' => 'Công khai',
            'private' => 'Riêng tư',
            'hidden' => 'Ẩn',
            'visible' => 'Hiển thị',
            'show' => 'Hiển thị',
            'display' => 'Hiển thị',
        ];
        return $map[$s] ?? (string) $v;
    };

    $map_status_vi = function ($v): string {
        $s = strtolower(trim((string) $v));
        if ($s === '')
            return '';

        $map = [
            'pending' => 'Chờ duyệt',
            'approved' => 'Đã duyệt',
            'rejected' => 'Từ chối',
            'draft' => 'Nháp',
            'submitted' => 'Đã gửi duyệt',
            'archived' => 'Lưu trữ',
            'deleted' => 'Đã xóa',
            'active' => 'Đang áp dụng',
            'inactive' => 'Ngừng áp dụng',

            // nếu DB có kiểu "ok"/"done"...
            'done' => 'Hoàn tất',
            'completed' => 'Hoàn tất',
            'cancelled' => 'Đã hủy',
            'canceled' => 'Đã hủy',
        ];
        return $map[$s] ?? (string) $v;
    };

    foreach ($rows as $x) {
        // recipient text
        if (($x['recipient_type'] ?? '') === 'individual') {
            $recipient = trim((string) ($x['member_fullname'] ?? ''));
            $mssv = trim((string) ($x['member_mssv'] ?? ''));
            $cls = trim((string) ($x['member_class'] ?? ''));
            if ($mssv !== '')
                $recipient .= " - {$mssv}";
            if ($cls !== '')
                $recipient .= " ({$cls})";
        } else {
            $recipient = (string) ($x['recipient_name'] ?? '');
        }

        $sheet->setCellValue("A{$rowNum}", (string) ($x['title'] ?? ''));
        $sheet->setCellValue("B{$rowNum}", $recipient);
        $sheet->setCellValue("C{$rowNum}", (string) ($x['award_level'] ?? ''));
        $sheet->setCellValue("D{$rowNum}", (string) ($x['award_form'] ?? ''));
        $sheet->setCellValue("E{$rowNum}", (string) ($x['school_year'] ?? ''));
        $sheet->setCellValue("F{$rowNum}", (string) ($x['awarding_agency'] ?? ''));

        $sheet->setCellValueExplicit(
            "G{$rowNum}",
            (string) ($x['decision_no'] ?? ''),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );

        $sheet->setCellValue("H{$rowNum}", (string) ($x['achieved_at'] ?? ''));
        $sheet->setCellValue("I{$rowNum}", $map_visibility_vi($x['visibility'] ?? ''));
        $sheet->setCellValue("J{$rowNum}", $map_status_vi($x['status'] ?? ''));
        $sheet->setCellValue("K{$rowNum}", (string) ($x['creator_name'] ?? ''));
        $sheet->setCellValue("L{$rowNum}", (string) ($x['reviewer_name'] ?? ''));
        $sheet->setCellValue("M{$rowNum}", (string) ($x['created_at'] ?? ''));

        $rowNum++;
    }

    $lastRow = $rowNum - 1;

    if ($lastRow >= $headerRow) {
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$lastRow}")->applyFromArray([
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ],
        ]);

        // align (mô phỏng mẫu)
        $sheet->getStyle("A{$headerRow}:G{$lastRow}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle("H{$headerRow}:J{$lastRow}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("M{$headerRow}:M{$lastRow}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }

    // ===== widths =====
    $sheet->getColumnDimension('A')->setWidth(34); // Tên
    $sheet->getColumnDimension('B')->setWidth(28); // Đơn vị/cá nhân
    $sheet->getColumnDimension('C')->setWidth(14);
    $sheet->getColumnDimension('D')->setWidth(16);
    $sheet->getColumnDimension('E')->setWidth(12);
    $sheet->getColumnDimension('F')->setWidth(22);
    $sheet->getColumnDimension('G')->setWidth(16);
    $sheet->getColumnDimension('H')->setWidth(14);
    $sheet->getColumnDimension('I')->setWidth(12);
    $sheet->getColumnDimension('J')->setWidth(12);
    $sheet->getColumnDimension('K')->setWidth(18);
    $sheet->getColumnDimension('L')->setWidth(18);
    $sheet->getColumnDimension('M')->setWidth(18);

    if (function_exists('log_activity')) {
        log_activity('export', 'achievements', null, null, 'Xuất danh sách thành tích ra Excel');
    }

    while (ob_get_level() > 0)
        ob_end_clean();
    header_remove();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}



if ($action === 'export_pdf') {
    if (!$canPrint)
        err('Forbidden', 403);

    while (ob_get_level() > 0)
        ob_end_clean();
    ob_start();
    date_default_timezone_set('Asia/Ho_Chi_Minh');

    // query y như export_xlsx
    $params = [];
    $where = build_where($_GET, $params, $uid, $canReview);

    $sql = "
        SELECT
            a.title, a.recipient_type, a.recipient_name,
            a.award_level, a.award_form, a.school_year,
            a.awarding_agency, a.decision_no, a.achieved_at,
            a.visibility, a.status, a.created_at,
            m.fullname AS member_fullname,
            m.mssv AS member_mssv,
            COALESCE(m.class_name, c.name) AS member_class,
            u.fullname AS creator_name,
            ru.fullname AS reviewer_name
        FROM achievements a
        LEFT JOIN members m ON m.id = a.member_id
        LEFT JOIN classes c ON c.id = m.class_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN users ru ON ru.id = a.reviewed_by
        $where
        ORDER BY a.created_at DESC
        LIMIT 20000
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!class_exists('\PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf')) {
        err('Thiếu PDF writer (cài mpdf/mpdf hoặc cấu hình writer PDF)', 500);
    }

    $spreadsheet = build_achievements_spreadsheet($pdo, $rows);

    $filename = 'danh_sach_thanh_tich_' . date('Ymd_His') . '.pdf';

    if (function_exists('log_activity')) {
        log_activity('export', 'achievements', null, null, 'Xuất danh sách thành tích ra PDF');
    }

    while (ob_get_level() > 0)
        ob_end_clean();
    header_remove();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf($spreadsheet);
    $writer->save('php://output');
    exit;
}


// Nếu bạn đang làm tiếp theo thì giữ lại sau, hiện tại ưu tiên fix list/save trước.
err('Action không hợp lệ', 400);
