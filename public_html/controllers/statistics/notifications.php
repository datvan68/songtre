<?php
// controllers/statistics/notifications.php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

auth_guard();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

/* ======================
   JSON HELPERS
====================== */
function json_ok($data = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($message, $code = 400, $extra = [])
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => false, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ======================
   AUTH CONTEXT (SAFE)
====================== */
function current_user_id_safe()
{
    if (function_exists('auth_user')) {
        $u = auth_user();
        return (int) ($u['id'] ?? 0);
    }
    if (isset($_SESSION['user']['id']))
        return (int) $_SESSION['user']['id'];
    if (isset($_SESSION['user_id']))
        return (int) $_SESSION['user_id'];
    return 0;
}

function current_role_name_safe(PDO $pdo, int $meId): string
{
    // ưu tiên session nếu có
    if (isset($_SESSION['role_name']))
        return (string) $_SESSION['role_name'];
    if (isset($_SESSION['user']['role']))
        return (string) $_SESSION['user']['role'];
    if ($meId <= 0)
        return '';

    $st = $pdo->prepare("SELECT r.name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1");
    $st->execute([$meId]);
    $name = (string) ($st->fetchColumn() ?: '');
    return $name;
}

/* ======================
   FILTERS
====================== */
function get_filters(): array
{
    $dateFrom = trim($_GET['date_from'] ?? $_POST['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? $_POST['date_to'] ?? '');
    $status = trim($_GET['status'] ?? $_POST['status'] ?? 'all'); // all|read|unread
    $groupBy = trim($_GET['group_by'] ?? $_POST['group_by'] ?? ($_GET['groupBy'] ?? $_POST['groupBy'] ?? 'user'));
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');

    $allowedGroup = ['user', 'day'];
    if (!in_array($groupBy, $allowedGroup, true))
        $groupBy = 'user';

    $allowedStatus = ['all', 'read', 'unread'];
    if (!in_array($status, $allowedStatus, true))
        $status = 'all';

    // validate date format (YYYY-MM-DD)
    if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom))
        $dateFrom = '';
    if ($dateTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))
        $dateTo = '';

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'status' => $status,
        'group_by' => $groupBy,
        'q' => $q,
    ];
}

/* ======================
   QUERY BUILDER
====================== */
function build_where(array $filters, bool $onlySelf, int $meId): array
{
    $where = "1=1";
    $params = [];

    if (!empty($filters['date_from'])) {
        $where .= " AND n.created_at >= ? ";
        $params[] = $filters['date_from'] . " 00:00:00";
    }
    if (!empty($filters['date_to'])) {
        $where .= " AND n.created_at <= ? ";
        $params[] = $filters['date_to'] . " 23:59:59";
    }

    if (($filters['status'] ?? 'all') === 'read') {
        $where .= " AND n.is_read = 1 ";
    } elseif (($filters['status'] ?? 'all') === 'unread') {
        $where .= " AND n.is_read = 0 ";
    }

    if ($onlySelf && $meId > 0) {
        $where .= " AND n.user_id = ? ";
        $params[] = $meId;
    }

    // q filter (fullname/username/message)
    if (!empty($filters['q'])) {
        $where .= " AND (m.fullname LIKE ? OR u.fullname LIKE ? OR u.username LIKE ? OR n.message LIKE ?) ";
        $like = '%' . $filters['q'] . '%';
        $params[] = $like; // m.fullname
        $params[] = $like; // u.fullname
        $params[] = $like; // u.username
        $params[] = $like; // n.message
    }


    return [$where, $params];
}

function get_notifications_report(PDO $pdo, array $filters, bool $onlySelf, int $meId): array
{
    [$where, $params] = build_where($filters, $onlySelf, $meId);

    $groupBy = $filters['group_by'] ?? 'user';

    if ($groupBy === 'day') {
        $sql = "
      SELECT
        DATE(n.created_at) AS day,
        COUNT(*) AS total,
        SUM(CASE WHEN n.is_read=1 THEN 1 ELSE 0 END) AS read_count,
        SUM(CASE WHEN n.is_read=0 THEN 1 ELSE 0 END) AS unread_count,
        MAX(n.created_at) AS last_at
FROM notifications n
LEFT JOIN users u ON u.id = n.user_id
LEFT JOIN members m ON m.user_id = n.user_id
WHERE $where
      GROUP BY DATE(n.created_at)
      ORDER BY day DESC
    ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "
    SELECT
      n.user_id,
      COALESCE(NULLIF(m.fullname,''), NULLIF(u.fullname,''), NULLIF(u.username,''), '(Không rõ)') AS user_fullname,
      COALESCE(NULLIF(u.username,''), '') AS username,
      COUNT(*) AS total,
      SUM(CASE WHEN n.is_read=1 THEN 1 ELSE 0 END) AS read_count,
      SUM(CASE WHEN n.is_read=0 THEN 1 ELSE 0 END) AS unread_count,
      MAX(n.created_at) AS last_at
    FROM notifications n
    LEFT JOIN users u ON u.id = n.user_id
    LEFT JOIN members m ON m.user_id = n.user_id
    WHERE $where
    GROUP BY n.user_id, user_fullname, username
    ORDER BY total DESC
  ";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $sqlSum = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN n.is_read=1 THEN 1 ELSE 0 END) AS read_count,
      SUM(CASE WHEN n.is_read=0 THEN 1 ELSE 0 END) AS unread_count,
      COUNT(DISTINCT n.user_id) AS unique_users
FROM notifications n
LEFT JOIN users u ON u.id = n.user_id
LEFT JOIN members m ON m.user_id = n.user_id
WHERE $where

  ";
    $st2 = $pdo->prepare($sqlSum);
    $st2->execute($params);
    $summary = (array) $st2->fetch(PDO::FETCH_ASSOC);

    return [$rows, $summary];
}

/* ======================
   ACTIONS
====================== */

$meId = current_user_id_safe();
$roleName = current_role_name_safe($pdo, $meId);

// an toàn: nếu không phải admin => chỉ xem của mình
$onlySelf = true;
if (mb_strtolower($roleName, 'UTF-8') === 'admin')
    $onlySelf = false;

// optional permission gate
if (function_exists('can')) {
    // nếu hệ thống bạn có module statistics, bật check này.
    // nếu chưa có permission, bạn có thể comment block này.
    if (!can('statistics', 'view')) {
        json_err("Bạn không có quyền xem thống kê.", 403);
    }
}

/* ===== JSON REPORT ===== */
if ($action === 'notifications_report') {
    $filters = get_filters();
    try {
        [$rows, $summary] = get_notifications_report($pdo, $filters, $onlySelf, $meId);
    } catch (Throwable $e) {
        json_err("Không thể tải thống kê: " . $e->getMessage(), 500);
    }
    json_ok([
        'rows' => $rows,
        'summary' => [
            'total' => (int) ($summary['total'] ?? 0),
            'read' => (int) ($summary['read_count'] ?? 0),
            'unread' => (int) ($summary['unread_count'] ?? 0),
            'unique_users' => (int) ($summary['unique_users'] ?? 0),
        ],
    ]);
}

/* ===== EXPORT XLSX (7 columns) ===== */
if ($action === 'export_notifications_report') {
    $filters = get_filters();

    try {
        [$rows, $summary] = get_notifications_report($pdo, $filters, $onlySelf, $meId);
    } catch (Throwable $e) {
        json_err("Không thể export: " . $e->getMessage(), 500);
    }

    $groupBy = $filters['group_by'] ?? 'user';
    $status = $filters['status'] ?? 'all';
    $dateFrom = $filters['date_from'] ?? '';
    $dateTo = $filters['date_to'] ?? '';

    // ===== Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Notifications');

    // ===== HEADER (A..G) chia trái/phải theo 7 cột
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";

    $dateLine = "Ngày " . date('d') . " tháng " . date('m') . " năm " . date('Y');

    // Left: A1:D4
    $sheet->setCellValue("A1", $orgLeft);
    $sheet->mergeCells("A1:D4");
    $sheet->getStyle("A1:D4")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Right: E1:G3
    $sheet->setCellValue("E1", $orgRight);
    $sheet->mergeCells("E1:G3");
    $sheet->getStyle("E1:G3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'underline' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Date line: E4:G4
    $sheet->setCellValue("E4", $dateLine);
    $sheet->mergeCells("E4:G4");
    $sheet->getStyle("E4:G4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 12],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    // Row heights theo mẫu
    $sheet->getRowDimension(1)->setRowHeight(20.5);
    $sheet->getRowDimension(2)->setRowHeight(15.75);
    $sheet->getRowDimension(3)->setRowHeight(15.75);
    $sheet->getRowDimension(4)->setRowHeight(32.25);

    // ===== Title
    $sheet->setCellValue("A5", "BÁO CÁO THỐNG KÊ THÔNG BÁO");
    $sheet->mergeCells("A5:G5");
    $sheet->getStyle("A5")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->setCellValue("A6", "Nhóm theo: {$groupBy} | Trạng thái: {$status}");
    $sheet->mergeCells("A6:G6");
    $sheet->setCellValue("A7", "Thời gian: " . ($dateFrom ?: '---') . " → " . ($dateTo ?: '---'));
    $sheet->mergeCells("A7:G7");
    $sheet->getStyle("A6:A7")->getFont()->setSize(10);

    // ===== Table header (row 9)
    $rowStart = 9;

    // 7 columns always
    // group_by=user: STT | Người nhận | Username | Tổng | Đã đọc | Chưa đọc | Lần cuối
    // group_by=day : STT | Ngày | (trống) | Tổng | Đã đọc | Chưa đọc | Lần cuối
    $headers = ['STT', 'Người nhận', 'Username', 'Tổng', 'Đã đọc', 'Chưa đọc', 'Lần cuối'];
    if ($groupBy === 'day')
        $headers = ['STT', 'Ngày', '', 'Tổng', 'Đã đọc', 'Chưa đọc', 'Lần cuối'];

    $sheet->fromArray($headers, null, 'A' . $rowStart);

    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getFont()->setBold(true);
    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // ===== Data
    $r = $rowStart + 1;
    $i = 1;

    foreach ($rows as $row) {
        if ($groupBy === 'day') {
            $sheet->setCellValue("A{$r}", $i++);
            $sheet->setCellValue("B{$r}", (string) ($row['day'] ?? ''));
            $sheet->setCellValue("C{$r}", '');
            $sheet->setCellValue("D{$r}", (int) ($row['total'] ?? 0));
            $sheet->setCellValue("E{$r}", (int) ($row['read_count'] ?? 0));
            $sheet->setCellValue("F{$r}", (int) ($row['unread_count'] ?? 0));
            $sheet->setCellValue("G{$r}", (string) ($row['last_at'] ?? ''));
        } else {
            $sheet->setCellValue("A{$r}", $i++);
            $sheet->setCellValue("B{$r}", (string) ($row['user_fullname'] ?? ''));
            $sheet->setCellValue("C{$r}", (string) ($row['username'] ?? ''));
            $sheet->setCellValue("D{$r}", (int) ($row['total'] ?? 0));
            $sheet->setCellValue("E{$r}", (int) ($row['read_count'] ?? 0));
            $sheet->setCellValue("F{$r}", (int) ($row['unread_count'] ?? 0));
            $sheet->setCellValue("G{$r}", (string) ($row['last_at'] ?? ''));
        }
        $r++;
    }

    $sheet->getStyle("A{$rowStart}:G" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = "baocao_thongbao_{$groupBy}_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

json_err("Unknown action", 400);
