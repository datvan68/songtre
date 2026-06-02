<?php
// /doanthanhnien/controllers/statistics/attendance.php

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

// ===== PDO =====
$pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
if (!$pdo) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Missing PDO connection'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ======================
// HELPERS
// ======================
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
function parse_ymd($s)
{
    $s = trim((string) $s);
    if ($s === '')
        return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s))
        return '';
    return $s;
}
function ymd_add_days($ymd, $days)
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt)
        return '';
    $dt->modify(($days >= 0 ? '+' : '') . $days . ' day');
    return $dt->format('Y-m-d');
}
function safe_int($v)
{
    $v = (int) $v;
    return $v > 0 ? $v : 0;
}

// ======================
// CURRENT USER ROLE + SCOPE (gvcn/bithu)
// SQL của bạn: users.role_id -> roles.id
// ======================
$meId = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

$currentRole = 'admin'; // default
$scopeWhere = '';
$scopeParams = [];

try {
    if ($meId > 0) {
        $st = $pdo->prepare("
      SELECT LOWER(r.name)
      FROM users u
      LEFT JOIN roles r ON r.id = u.role_id
      WHERE u.id = ?
      LIMIT 1
    ");
        $st->execute([$meId]);
        $rn = (string) ($st->fetchColumn() ?: '');
        if ($rn !== '')
            $currentRole = $rn;
    }

    // GVCN: gvcn_classes(user_id,class_id) => limit members.class_id
    if ($currentRole === 'gvcn') {
        $st = $pdo->prepare("SELECT class_id FROM gvcn_classes WHERE user_id=?");
        $st->execute([$meId]);
        $classIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $classIds = array_values(array_filter($classIds, fn($x) => $x > 0));

        if (count($classIds) > 0) {
            $in = implode(',', array_fill(0, count($classIds), '?'));
            $scopeWhere .= " AND m.class_id IN ($in) ";
            $scopeParams = array_merge($scopeParams, $classIds);
        }
    }

    // BÍ THƯ: bithu_scopes(user_id, chidoan_group_id, class_id, ...)
    if ($currentRole === 'bithu') {
        $st = $pdo->prepare("SELECT * FROM bithu_scopes WHERE user_id=? LIMIT 1");
        $st->execute([$meId]);
        $sc = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($sc) {
            $cg = (int) ($sc['chidoan_group_id'] ?? 0);
            $cid = (int) ($sc['class_id'] ?? 0);

            if ($cg === 1 && $cid > 0) {
                $scopeWhere .= " AND m.class_id = ? ";
                $scopeParams[] = $cid;
            } elseif ($cg === 2) {
                $scopeWhere .= " AND m.chidoan_group_id = 2 ";
            }
        }
    }
} catch (Throwable $e) {
    // Nếu lỗi do thiếu bảng/cột => bỏ scope
    $scopeWhere = '';
    $scopeParams = [];
}

// ======================
// FILTERS (từ attendance.js)
// ======================
function get_filters()
{
    $schoolYear = trim((string) ($_GET['school_year'] ?? '')); // school_years.id
    $semester = trim((string) ($_GET['semester'] ?? ''));   // semesters.code
    $campaignId = safe_int($_GET['campaign_id'] ?? 0);

    $dateFrom = parse_ymd($_GET['date_from'] ?? '');
    $dateTo = parse_ymd($_GET['date_to'] ?? '');

    $status = trim((string) ($_GET['status'] ?? 'all')); // all|ok|fail
    $groupBy = trim((string) ($_GET['group_by'] ?? 'member')); // member|class|dept|campaign

    if (!in_array($status, ['all', 'ok', 'fail'], true))
        $status = 'all';
    if (!in_array($groupBy, ['member', 'class', 'dept', 'campaign'], true))
        $groupBy = 'member';

    return compact('schoolYear', 'semester', 'campaignId', 'dateFrom', 'dateTo', 'status', 'groupBy');
}

function build_where(array $filters, array &$params, string $scopeWhere, array $scopeParams)
{
    $params = [];
    $where = "1=1";

    // Lọc theo campaign (ưu tiên)
    if ($filters['campaignId'] > 0) {
        $where .= " AND a.campaign_id = ? ";
        $params[] = $filters['campaignId'];
    } else {
        // Lọc theo năm học (campaigns.school_year_id)
        if ($filters['schoolYear'] !== '') {
            $where .= " AND c.school_year_id = ? ";
            $params[] = $filters['schoolYear'];
        }
        // Lọc theo học kỳ (campaigns.semester_code) + fallback (campaigns.semester)
        if ($filters['semester'] !== '') {
            $where .= " AND (c.semester_code = ? OR c.semester = ?) ";
            $params[] = $filters['semester'];
            $params[] = $filters['semester'];
        }
    }

    // Date range (inclusive đến ngày)
    if ($filters['dateFrom'] !== '') {
        $where .= " AND a.time >= ? ";
        $params[] = $filters['dateFrom'] . " 00:00:00";
    }
    if ($filters['dateTo'] !== '') {
        $toNext = ymd_add_days($filters['dateTo'], 1);
        $where .= " AND a.time < ? ";
        $params[] = $toNext . " 00:00:00";
    }

    // Status filter theo attendance_logs.result
    if ($filters['status'] === 'ok') {
        $where .= " AND a.result = 'ok' ";
    } elseif ($filters['status'] === 'fail') {
        $where .= " AND a.result = 'fail' ";
    }

    // Scope (gvcn/bithu) dựa trên members (join LEFT, nhưng scope đặt ở WHERE => tự loại log không có member)
    $where .= " $scopeWhere ";
    $params = array_merge($params, $scopeParams);

    return $where;
}

function base_joins()
{
    // Theo SQL của bạn:
    // attendance_logs a (user_id -> users.id), (campaign_id -> campaigns.id), time, result
    // members m nối theo m.user_id = a.user_id
    // classes cl nối theo m.class_id
    // departments d_m nối theo m.department_id, d_c theo cl.department_id
    return "
    FROM attendance_logs a
    JOIN campaigns c ON c.id = a.campaign_id
    JOIN users u ON u.id = a.user_id
    LEFT JOIN members m ON m.user_id = a.user_id
    LEFT JOIN classes cl ON cl.id = m.class_id
    LEFT JOIN departments d_m ON d_m.id = m.department_id
    LEFT JOIN departments d_c ON d_c.id = cl.department_id
  ";
}

function expr_member_name()
{
    return "COALESCE(m.fullname, u.fullname, u.username, '')";
}
function expr_class_name()
{
    // members có class_name text, nhưng chuẩn là classes.name
    return "COALESCE(cl.name, m.class_name, '')";
}
function expr_dept_name()
{
    // ưu tiên members.department_id, fallback classes.department_id
    return "COALESCE(d_m.name, d_c.name, '')";
}
function expr_dept_id()
{
    return "COALESCE(m.department_id, cl.department_id, 0)";
}

function get_attendance_report(PDO $pdo, array $filters, string $scopeWhere, array $scopeParams)
{
    $params = [];
    $where = build_where($filters, $params, $scopeWhere, $scopeParams);

    $joins = base_joins();

    $memberName = expr_member_name();
    $className = expr_class_name();
    $deptName = expr_dept_name();
    $deptIdExpr = expr_dept_id();

    // ok/fail counts (MariaDB: SUM(condition) ok)
    $okExpr = "SUM(a.result='ok')";
    $failExpr = "SUM(a.result='fail')";

    $groupBy = $filters['groupBy'];

    if ($groupBy === 'member') {
        $sql = "
      SELECT
        $memberName AS member_name,
        $className  AS class_name,
        $deptName   AS dept_name,
        COUNT(*) AS total,
        $okExpr   AS ok,
        $failExpr AS fail,
        MAX(a.time) AS last_at
      $joins
      WHERE $where
      GROUP BY a.user_id
    ";
    } elseif ($groupBy === 'class') {
        $sql = "
      SELECT
        $className AS class_name,
        $deptName  AS dept_name,
        COUNT(*) AS total,
        $okExpr   AS ok,
        $failExpr AS fail,
        MAX(a.time) AS last_at
      $joins
      WHERE $where
      GROUP BY COALESCE(m.class_id, 0)
    ";
    } elseif ($groupBy === 'dept') {
        $sql = "
      SELECT
        $deptName AS dept_name,
        COUNT(*) AS total,
        $okExpr   AS ok,
        $failExpr AS fail,
        MAX(a.time) AS last_at
      $joins
      WHERE $where
      GROUP BY $deptIdExpr
    ";
    } else { // campaign
        $sql = "
      SELECT
        c.title AS campaign_title,
        COUNT(*) AS total,
        $okExpr   AS ok,
        $failExpr AS fail,
        MAX(a.time) AS last_at
      $joins
      WHERE $where
      GROUP BY a.campaign_id
    ";
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // summary
    $sqlSum = "
    SELECT
      COUNT(*) AS total,
      $okExpr AS ok,
      $failExpr AS fail,
      COUNT(DISTINCT a.user_id) AS unique_members,
      COUNT(DISTINCT a.campaign_id) AS unique_campaigns
    $joins
    WHERE $where
  ";
    $st2 = $pdo->prepare($sqlSum);
    $st2->execute($params);
    $summary = $st2->fetch(PDO::FETCH_ASSOC) ?: [
        'total' => 0,
        'ok' => 0,
        'fail' => 0,
        'unique_members' => 0,
        'unique_campaigns' => 0,
    ];

    return [$rows, $summary];
}

// ======================
// ACTIONS
// ======================

// school_year_options (school_years)
if ($action === 'school_year_options') {
    try {
        $st = $pdo->query("
      SELECT id, year_label
      FROM school_years
      WHERE is_active = 1
      ORDER BY year_label DESC
    ");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        json_ok(['data' => $rows]);
    } catch (Throwable $e) {
        json_ok(['data' => []]);
    }
}

// semester_options (semesters)
if ($action === 'semester_options') {
    try {
        $st = $pdo->query("
      SELECT code, label
      FROM semesters
      WHERE is_active = 1
      ORDER BY sort_order ASC, code ASC
    ");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        json_ok(['data' => $rows]);
    } catch (Throwable $e) {
        json_ok([
            'data' => [
                ['code' => 'HK1', 'label' => 'Học kỳ 1'],
                ['code' => 'HK2', 'label' => 'Học kỳ 2'],
            ]
        ]);
    }
}

// attendance_report
if ($action === 'attendance_report') {
    $filters = get_filters();

    try {
        [$rows, $summary] = get_attendance_report($pdo, $filters, $scopeWhere, $scopeParams);
        json_ok(['rows' => $rows, 'summary' => $summary]);
    } catch (Throwable $e) {
        json_err("Lỗi khi thống kê điểm danh: " . $e->getMessage(), 500);
    }
}

// export_attendance_report (XLSX)
if ($action === 'export_attendance_report') {
    $filters = get_filters();

    try {
        [$rows, $summary] = get_attendance_report($pdo, $filters, $scopeWhere, $scopeParams);
    } catch (Throwable $e) {
        json_err("Không thể export: " . $e->getMessage(), 500);
    }

    $groupBy = $filters['groupBy'] ?? 'member';
    $status = $filters['status'] ?? 'all';
    $dateFrom = $filters['dateFrom'] ?? '';
    $dateTo = $filters['dateTo'] ?? '';

    $spreadsheet = new Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('Attendance');

    // ======================
    // HEADER (7 CỘT: A..G) - KHÔNG VIỀN
    // ======================
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
    $dateLine = "Chánh Hưng, ngày " . date("d") . " tháng " . date("m") . " năm " . date("Y");

    // Trái: A1:D4
    $ws->setCellValue("A1", $orgLeft);
    $ws->mergeCells("A1:C4");
    $ws->getStyle("A1:D4")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Phải: E1:G3
    $ws->setCellValue("D1", $orgRight);
    $ws->mergeCells("D1:G3");
    $ws->getStyle("D1:G3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'underline' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Dòng ngày: E4:G4
    $ws->setCellValue("D4", $dateLine);
    $ws->mergeCells("D4:G4");
    $ws->getStyle("D4:G4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 11],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    // Không viền vùng header
    $ws->getStyle("A1:G4")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);

    // Row heights theo mẫu
    $ws->getRowDimension(1)->setRowHeight(20.5);
    $ws->getRowDimension(2)->setRowHeight(15.75);
    $ws->getRowDimension(3)->setRowHeight(15.75);
    $ws->getRowDimension(4)->setRowHeight(32.25);

    // ======================
    // TITLE + FILTER (A..G)
    // ======================
    $ws->setCellValue('A5', 'BÁO CÁO THỐNG KÊ ĐIỂM DANH');
    $ws->mergeCells('A5:G5');
    $ws->getStyle('A5')->getFont()->setBold(true)->setSize(14);
    $ws->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $ws->setCellValue('A6', "Nhóm theo: {$groupBy}  |  Trạng thái: {$status}");
    $ws->mergeCells('A6:G6');

    $ws->setCellValue('A7', "Thời gian: " . ($dateFrom ?: '---') . " → " . ($dateTo ?: '---'));
    $ws->mergeCells('A7:G7');

    $ws->getStyle('A6:A7')->getFont()->setSize(10);

    // ======================
    // TABLE (CỐ ĐỊNH 7 CỘT)
    // A:STT B:Đối tượng C:Thông tin D:Tổng E:OK F:Fail G:Lần cuối
    // ======================
    $rowStart = 9;

    // Đặt label theo groupBy nhưng vẫn giữ 7 cột
    $colB = 'Đối tượng';
    $colC = 'Thông tin';
    if ($groupBy === 'member') {
        $colB = 'Họ tên';
        $colC = 'Lớp / Khoa';
    }
    if ($groupBy === 'class') {
        $colB = 'Lớp';
        $colC = 'Khoa';
    }
    if ($groupBy === 'dept') {
        $colB = 'Khoa/Phòng';
        $colC = 'Ghi chú';
    }   // vẫn giữ cột C (có thể để trống)
    if ($groupBy === 'campaign') {
        $colB = 'Phong trào';
        $colC = 'Ghi chú';
    }   // vẫn giữ cột C (có thể để trống)

    $headers = ['STT', $colB, $colC, 'Tổng', 'OK', 'Fail', 'Lần cuối'];
    $ws->fromArray($headers, null, 'A' . $rowStart);

    $endCol = 'G';

    // Style header bảng
    $ws->getStyle("A{$rowStart}:{$endCol}{$rowStart}")->getFont()->setBold(true);
    $ws->getStyle("A{$rowStart}:{$endCol}{$rowStart}")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
    $ws->getStyle("A{$rowStart}:{$endCol}{$rowStart}")->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $r = $rowStart + 1;
    $i = 1;

    foreach ($rows as $row) {
        // B + C theo groupBy nhưng luôn map về 7 cột
        $name = '';
        $info = '';

        if ($groupBy === 'member') {
            $name = (string) ($row['member_name'] ?? '');
            $cls = (string) ($row['class_name'] ?? '');
            $dep = (string) ($row['dept_name'] ?? '');
            $info = trim($cls . ($dep ? " · " . $dep : ""));
        } elseif ($groupBy === 'class') {
            $name = (string) ($row['class_name'] ?? '');
            $info = (string) ($row['dept_name'] ?? '');
        } elseif ($groupBy === 'dept') {
            $name = (string) ($row['dept_name'] ?? '');
            $info = ''; // giữ cột C nhưng để trống
        } else { // campaign
            $name = (string) ($row['campaign_title'] ?? '');
            $info = ''; // giữ cột C nhưng để trống
        }

        $ws->setCellValue("A{$r}", $i++);
        $ws->setCellValue("B{$r}", $name);
        $ws->setCellValue("C{$r}", $info);
        $ws->setCellValue("D{$r}", (int) ($row['total'] ?? 0));
        $ws->setCellValue("E{$r}", (int) ($row['ok'] ?? 0));
        $ws->setCellValue("F{$r}", (int) ($row['fail'] ?? 0));
        $ws->setCellValue("G{$r}", (string) ($row['last_at'] ?? ''));

        $r++;
    }

    // viền toàn bảng
    $ws->getStyle("A{$rowStart}:{$endCol}" . ($r - 1))->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    // autosize 7 cột
    foreach (range('A', 'G') as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }


    $filename = "baocao_diemdanh_{$groupBy}_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}


// unknown
json_err('Unknown action', 404);
