<?php
// controllers/statistics/schedule.php
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

function json_ok($arr = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $arr), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_filters()
{
    $status = trim($_GET['status'] ?? 'all');
    $q = trim($_GET['q'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $dept = trim($_GET['dept'] ?? '');
    $creatorId = trim($_GET['creator_id'] ?? '');
    $upcomingOnly = (string) ($_GET['upcoming_only'] ?? '0') === '1' ? 1 : 0;

    $allowed = ['all', 'pending', 'approved', 'update_pending', 'delete_pending', 'rejected'];
    if (!in_array($status, $allowed, true))
        $status = 'all';

    return [
        'status' => $status,
        'q' => $q,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'dept' => $dept,
        'creator_id' => $creatorId,
        'upcoming_only' => $upcomingOnly,
    ];
}

function build_where($filters, &$params)
{
    $where = "1=1";

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where .= " AND s.status = ? ";
        $params[] = $filters['status'];
    }

    if (!empty($filters['date_from'])) {
        $where .= " AND DATE(s.start_date) >= ? ";
        $params[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $where .= " AND DATE(s.start_date) <= ? ";
        $params[] = $filters['date_to'];
    }

    if (!empty($filters['dept'])) {
        $where .= " AND s.department LIKE ? ";
        $params[] = '%' . $filters['dept'] . '%';
    }

    if (!empty($filters['creator_id'])) {
        $where .= " AND s.created_by = ? ";
        $params[] = (int) $filters['creator_id'];
    }

    if (!empty($filters['upcoming_only'])) {
        $where .= " AND s.start_date >= NOW() ";
    }

    if (!empty($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where .= " AND (
            s.title LIKE ?
            OR s.department LIKE ?
            OR s.location LIKE ?
            OR s.participants LIKE ?
            OR m.fullname LIKE ?
            OR u.fullname LIKE ?
            OR u.username LIKE ?
        ) ";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    return $where;
}

function get_schedule_report($pdo, $filters)
{
    $params = [];
    $where = build_where($filters, $params);

    $sql = "
      SELECT
        s.id,
        s.title,
        s.description,
        s.department,
        s.location,
        s.participants,
        DATE_FORMAT(s.start_date, '%Y-%m-%d %H:%i') AS start_date,
        DATE_FORMAT(s.end_date, '%Y-%m-%d %H:%i') AS end_date,
        s.status,
        s.reject_note,
        s.created_by,
        COALESCE(NULLIF(m.fullname,''), NULLIF(u.fullname,''), NULLIF(u.username,''), '(Không rõ)') AS creator_name
      FROM schedule s
      LEFT JOIN users u ON u.id = s.created_by
      LEFT JOIN members m ON m.user_id = u.id
      WHERE $where
      ORDER BY s.start_date DESC, s.id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // summary
    $params2 = [];
    $where2 = build_where($filters, $params2);

    $sqlSum = "
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN s.status='approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN s.status='pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN s.status='update_pending' THEN 1 ELSE 0 END) AS update_pending,
        SUM(CASE WHEN s.status='delete_pending' THEN 1 ELSE 0 END) AS delete_pending,
        SUM(CASE WHEN s.status='rejected' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN s.start_date >= NOW() AND s.start_date < DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS upcoming_7
      FROM schedule s
      LEFT JOIN users u ON u.id = s.created_by
      LEFT JOIN members m ON m.user_id = u.id
      WHERE $where2
    ";
    $st2 = $pdo->prepare($sqlSum);
    $st2->execute($params2);
    $summary = $st2->fetch(PDO::FETCH_ASSOC) ?: [];

    // top creators
    $params3 = [];
    $where3 = build_where($filters, $params3);
    $sqlTopC = "
      SELECT
        s.created_by AS user_id,
        COALESCE(NULLIF(m.fullname,''), NULLIF(u.fullname,''), NULLIF(u.username,''), '(Không rõ)') AS name,
        COUNT(*) AS total
      FROM schedule s
      LEFT JOIN users u ON u.id = s.created_by
      LEFT JOIN members m ON m.user_id = u.id
      WHERE $where3
      GROUP BY s.created_by, name
      ORDER BY total DESC
      LIMIT 10
    ";
    $st3 = $pdo->prepare($sqlTopC);
    $st3->execute($params3);
    $summary['top_creators'] = $st3->fetchAll(PDO::FETCH_ASSOC);

    // top departments (string)
    $params4 = [];
    $where4 = build_where($filters, $params4);
    $sqlTopD = "
      SELECT
        COALESCE(NULLIF(TRIM(s.department),''), '(Không rõ)') AS department,
        COUNT(*) AS total
      FROM schedule s
      LEFT JOIN users u ON u.id = s.created_by
      LEFT JOIN members m ON m.user_id = u.id
      WHERE $where4
      GROUP BY department
      ORDER BY total DESC
      LIMIT 10
    ";
    $st4 = $pdo->prepare($sqlTopD);
    $st4->execute($params4);
    $summary['top_departments'] = $st4->fetchAll(PDO::FETCH_ASSOC);

    return [$rows, $summary];
}

/* ======================
   ACTIONS
====================== */

if ($action === 'creator_options') {
    try {
        // lấy những user đã từng tạo schedule
        $sql = "
          SELECT
            u.id,
            COALESCE(NULLIF(m.fullname,''), NULLIF(u.fullname,''), NULLIF(u.username,''), '(Không rõ)') AS name
          FROM users u
          INNER JOIN schedule s ON s.created_by = u.id
          LEFT JOIN members m ON m.user_id = u.id
          GROUP BY u.id, name
          ORDER BY name ASC
          LIMIT 500
        ";
        $st = $pdo->query($sql);
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['data' => $data]);
    } catch (Throwable $e) {
        json_err("Không thể tải danh sách người tạo: " . $e->getMessage(), 500);
    }
}

if ($action === 'schedule_report') {
    $filters = get_filters();
    try {
        [$rows, $summary] = get_schedule_report($pdo, $filters);
        json_ok(['rows' => $rows, 'summary' => $summary]);
    } catch (Throwable $e) {
        json_err("Không thể tải thống kê: " . $e->getMessage(), 500);
    }
}

if ($action === 'export_schedule_report') {
    $filters = get_filters();
    try {
        [$rows, $summary] = get_schedule_report($pdo, $filters);
    } catch (Throwable $e) {
        json_err("Không thể export: " . $e->getMessage(), 500);
    }

    // ===== XLSX 7 cột A..G =====
    // STT | Tiêu đề | Đơn vị | Bắt đầu | Kết thúc | Trạng thái | Người tạo
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("Schedule");

    // Header trái/phải theo 7 cột
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
    $dateLine = "Ngày " . date('d') . " tháng " . date('m') . " năm " . date('Y');

    // A1:D4
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

    // E1:G3
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

    // E4:G4
    $sheet->setCellValue("E4", $dateLine);
    $sheet->mergeCells("E4:G4");
    $sheet->getStyle("E4:G4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 12],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getRowDimension(1)->setRowHeight(20.5);
    $sheet->getRowDimension(2)->setRowHeight(15.75);
    $sheet->getRowDimension(3)->setRowHeight(15.75);
    $sheet->getRowDimension(4)->setRowHeight(32.25);

    // Title + filters
    $sheet->setCellValue("A6", "BÁO CÁO THỐNG KÊ LỊCH CÔNG TÁC");
    $sheet->mergeCells("A6:G6");
    $sheet->getStyle("A6")->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->setCellValue("A7", "Trạng thái: " . ($filters['status'] ?: 'all') . " | UpcomingOnly: " . ($filters['upcoming_only'] ? '1' : '0'));
    $sheet->mergeCells("A7:G7");
    $sheet->setCellValue("A8", "Thời gian: " . ($filters['date_from'] ?: '---') . " → " . ($filters['date_to'] ?: '---')
        . " | Đơn vị: " . ($filters['dept'] ?: '---')
        . " | Q: " . ($filters['q'] ?: '---'));
    $sheet->mergeCells("A8:G8");
    $sheet->getStyle("A7:A8")->getFont()->setSize(10);

    // Table header
    $rowStart = 10;
    $headers = ['STT', 'Tiêu đề', 'Đơn vị', 'Bắt đầu', 'Kết thúc', 'Trạng thái', 'Người tạo'];
    $sheet->fromArray($headers, null, "A{$rowStart}");

    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getFont()->setBold(true);
    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $r = $rowStart + 1;
    $i = 1;

    foreach ($rows as $row) {
        $sheet->setCellValue("A{$r}", $i++);
        $sheet->setCellValue("B{$r}", (string) ($row['title'] ?? ''));
        $sheet->setCellValue("C{$r}", (string) ($row['department'] ?? ''));
        $sheet->setCellValue("D{$r}", (string) ($row['start_date'] ?? ''));
        $sheet->setCellValue("E{$r}", (string) ($row['end_date'] ?? ''));
        $sheet->setCellValue("F{$r}", (string) ($row['status'] ?? ''));
        $sheet->setCellValue("G{$r}", (string) ($row['creator_name'] ?? ''));
        $r++;
    }

    $sheet->getStyle("A{$rowStart}:G" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    foreach (range('A', 'G') as $col)
        $sheet->getColumnDimension($col)->setAutoSize(true);

    $filename = "baocao_lichcongtac_" . date('Ymd_His') . ".xlsx";
    if (ob_get_length())
        ob_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

json_err("Unknown action", 400);
