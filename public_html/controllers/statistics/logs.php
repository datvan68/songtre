<?php
// /doanthanhnien/controllers/statistics/logs.php

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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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
    $dt->modify(($days >= 0 ? '+' : '') . (int) $days . ' day');
    return $dt->format('Y-m-d');
}
function safe_int($v)
{
    $v = (int) $v;
    return $v > 0 ? $v : 0;
}
function clamp_int($v, $min, $max, $default)
{
    $n = (int) $v;
    if ($n <= 0)
        $n = (int) $default;
    if ($n < $min)
        $n = $min;
    if ($n > $max)
        $n = $max;
    return $n;
}

function vn_action($a)
{
    $a = strtolower(trim((string) $a));
    $map = [
        'create' => 'Tạo mới',
        'add' => 'Thêm',
        'import' => 'Nhập',
        'update' => 'Cập nhật',
        'edit' => 'Sửa',
        'save' => 'Lưu',
        'delete' => 'Xóa',
        'remove' => 'Gỡ',
        'lock' => 'Khóa',
        'unlock' => 'Mở khóa',
        'approve' => 'Duyệt',
        'reject' => 'Từ chối',
        'export' => 'Xuất',
        'print' => 'In',
        'login' => 'Đăng nhập',
        'logout' => 'Đăng xuất',
        'view' => 'Xem',
    ];
    return $map[$a] ?? ($a !== '' ? $a : '');
}

function vn_module($m)
{
    $m = strtolower(trim((string) $m));
    $map = [
        'members' => 'Đoàn viên',
        'campaigns' => 'Phong trào',
        'registrations' => 'Đăng ký phong trào',
        'attendance' => 'Điểm danh',
        'finance' => 'Tài chính',
        'inventory' => 'Thiết bị / Đồ dùng',
        'tasks' => 'Công việc',
        'schedule' => 'Lịch công tác',
        'nominations' => 'Thi đua / Đề cử',
        'awards' => 'Khen thưởng',
        'users' => 'Tài khoản',
        'roles' => 'Vai trò',
        'permissions' => 'Phân quyền',
        'notifications' => 'Thông báo',
        'logs' => 'Nhật ký',
        'settings' => 'Cấu hình',
    ];
    return $map[$m] ?? ($m !== '' ? $m : '');
}

function vn_target_type($t)
{
    $t = strtolower(trim((string) $t));
    $map = [
        'member' => 'Đoàn viên',
        'user' => 'Tài khoản',
        'campaign' => 'Phong trào',
        'registration' => 'Đăng ký',
        'transaction' => 'Giao dịch',
        'inventory' => 'Thiết bị',
        'task' => 'Công việc',
        'schedule' => 'Lịch công tác',
        'nomination' => 'Hồ sơ thi đua',
        'notification' => 'Thông báo',
        'role' => 'Vai trò',
        'permission' => 'Quyền',
    ];
    return $map[$t] ?? ($t !== '' ? $t : '');
}

// ======================
// CURRENT USER ROLE + SCOPE (gvcn/bithu) theo SQL của bạn
// users.role_id -> roles.id
// scope dựa trên members m (actor user)
// ======================
$meId = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

$currentRole = 'admin';
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

    // Bí thư: bithu_scopes(user_id, chidoan_group_id, class_id, ...)
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
    $scopeWhere = '';
    $scopeParams = [];
}

// ======================
// FILTERS
// Lưu ý: để tránh đụng param action=..., filter hành động dùng log_action
// ======================
function get_filters()
{
    $page = safe_int($_GET['page'] ?? 1);
    if ($page <= 0)
        $page = 1;

    $pageSize = clamp_int($_GET['page_size'] ?? 10, 10, 200, 10);

    $q = trim((string) ($_GET['q'] ?? ''));
    $module = trim((string) ($_GET['module'] ?? ''));

    // filter hành động: ưu tiên log_action, fallback action_filter
    $logAction = trim((string) ($_GET['log_action'] ?? ($_GET['action_filter'] ?? '')));

    $dateFrom = parse_ymd($_GET['date_from'] ?? '');
    $dateTo = parse_ymd($_GET['date_to'] ?? '');

    $sort = trim((string) ($_GET['sort'] ?? 'newest'));
    if (!in_array($sort, ['newest', 'oldest'], true))
        $sort = 'newest';

    return compact('page', 'pageSize', 'q', 'module', 'logAction', 'dateFrom', 'dateTo', 'sort');
}

function base_joins()
{
    // activity_logs l: user_id, role_id, action, module, target_type, target_id, description, ip_address, user_agent, created_at
    // users u: username, fullname
    // members m: ưu tiên fullname trong members
    // roles: ưu tiên role_id lưu trong log, fallback role của user
    return "
    FROM activity_logs l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN members m ON m.user_id = u.id
    LEFT JOIN roles rlog ON rlog.id = l.role_id
    LEFT JOIN roles ruser ON ruser.id = u.role_id
  ";
}

function expr_fullname()
{
    // Ưu tiên members.fullname, nếu không có thì users.fullname, cuối cùng username
    return "COALESCE(m.fullname, u.fullname, u.username, '')";
}

function expr_role_name()
{
    return "COALESCE(rlog.name, ruser.name, '')";
}

function build_where(array $filters, array &$params, string $scopeWhere, array $scopeParams)
{
    $params = [];
    $where = "1=1";

    if ($filters['module'] !== '') {
        $where .= " AND l.module = ? ";
        $params[] = $filters['module'];
    }

    if ($filters['logAction'] !== '') {
        $where .= " AND l.action = ? ";
        $params[] = $filters['logAction'];
    }

    // Date range (inclusive)
    if ($filters['dateFrom'] !== '') {
        $where .= " AND l.created_at >= ? ";
        $params[] = $filters['dateFrom'] . " 00:00:00";
    }
    if ($filters['dateTo'] !== '') {
        $toNext = ymd_add_days($filters['dateTo'], 1);
        $where .= " AND l.created_at < ? ";
        $params[] = $toNext . " 00:00:00";
    }

    // Search q (username/fullname/description/ip/module/action/target_type/target_id)
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $where .= "
      AND (
        u.username LIKE ?
        OR u.fullname LIKE ?
        OR m.fullname LIKE ?
        OR l.description LIKE ?
        OR l.ip_address LIKE ?
        OR l.module LIKE ?
        OR l.action LIKE ?
        OR l.target_type LIKE ?
        OR CAST(l.target_id AS CHAR) LIKE ?
      )
    ";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    }

    // Scope (gvcn/bithu) theo members (actor)
    $where .= " $scopeWhere ";
    $params = array_merge($params, $scopeParams);

    return $where;
}

function get_logs_report(PDO $pdo, array $filters, string $scopeWhere, array $scopeParams)
{
    $params = [];
    $joins = base_joins();
    $where = build_where($filters, $params, $scopeWhere, $scopeParams);

    $fullnameExpr = expr_fullname();
    $roleExpr = expr_role_name();

    $order = ($filters['sort'] === 'oldest')
        ? " ORDER BY l.created_at ASC, l.id ASC "
        : " ORDER BY l.created_at DESC, l.id DESC ";

    // total rows
    $stCount = $pdo->prepare("SELECT COUNT(*) $joins WHERE $where");
    $stCount->execute($params);
    $totalRows = (int) ($stCount->fetchColumn() ?: 0);

    $pageSize = (int) $filters['pageSize'];
    $totalPages = (int) ceil(($totalRows ?: 0) / max(1, $pageSize));
    if ($totalPages <= 0)
        $totalPages = 1;

    $page = (int) $filters['page'];
    if ($page > $totalPages)
        $page = $totalPages;
    if ($page < 1)
        $page = 1;

    $offset = ($page - 1) * $pageSize;

    $sql = "
    SELECT
      l.id,
      l.created_at,
      l.module,
      l.action,
      l.target_type,
      l.target_id,
      l.description,
      l.ip_address,
      l.user_agent,
      l.user_id,
      u.username,
      $fullnameExpr AS fullname,
      $roleExpr AS role_name
    $joins
    WHERE $where
    $order
    LIMIT $pageSize OFFSET $offset
  ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // summary
    $sqlSum = "
    SELECT
      COUNT(*) AS total,
      COUNT(DISTINCT l.user_id) AS unique_users,
      COUNT(DISTINCT l.module) AS unique_modules
    $joins
    WHERE $where
  ";
    $stSum = $pdo->prepare($sqlSum);
    $stSum->execute($params);
    $summary = $stSum->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'unique_users' => 0, 'unique_modules' => 0];

    // top actions
    $stTA = $pdo->prepare("
    SELECT l.action, COUNT(*) AS total
    $joins
    WHERE $where
    GROUP BY l.action
    ORDER BY total DESC
    LIMIT 10
  ");
    $stTA->execute($params);
    $topActions = $stTA->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // top modules
    $stTM = $pdo->prepare("
    SELECT l.module, COUNT(*) AS total
    $joins
    WHERE $where
    GROUP BY l.module
    ORDER BY total DESC
    LIMIT 10
  ");
    $stTM->execute($params);
    $topModules = $stTM->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // top users
    $stTU = $pdo->prepare("
    SELECT
      l.user_id,
      u.username,
      $fullnameExpr AS fullname,
      COUNT(*) AS total
    $joins
    WHERE $where
    GROUP BY l.user_id, u.username, $fullnameExpr
    ORDER BY total DESC
    LIMIT 10
  ");
    $stTU->execute($params);
    $topUsers = $stTU->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $summary['top_actions'] = $topActions;
    $summary['top_modules'] = $topModules;
    $summary['top_users'] = $topUsers;

    return [$rows, $summary, ['page' => $page, 'totalPages' => $totalPages, 'totalRows' => $totalRows]];
}

// ======================
// ACTIONS
// ======================

// log_options: distinct modules + actions (phục vụ select)
if ($action === 'log_options') {
    try {
        $filters = get_filters();
        // options không cần q/sort/page; nhưng vẫn nên áp dụng scope + date range nếu có
        $params = [];
        $joins = base_joins();
        $where = build_where($filters, $params, $scopeWhere, $scopeParams);

        // modules
        $stM = $pdo->prepare("
      SELECT DISTINCT l.module
      $joins
      WHERE $where AND l.module IS NOT NULL AND l.module <> ''
      ORDER BY l.module ASC
      LIMIT 300
    ");
        $stM->execute($params);
        $modules = $stM->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // actions
        $stA = $pdo->prepare("
      SELECT DISTINCT l.action
      $joins
      WHERE $where AND l.action IS NOT NULL AND l.action <> ''
      ORDER BY l.action ASC
      LIMIT 300
    ");
        $stA->execute($params);
        $actions = $stA->fetchAll(PDO::FETCH_COLUMN) ?: [];

        json_ok(['modules' => array_values($modules), 'actions' => array_values($actions)]);
    } catch (Throwable $e) {
        json_ok(['modules' => [], 'actions' => []]);
    }
}

// logs_report: JSON (phân trang + summary)
if ($action === 'logs_report') {
    $filters = get_filters();
    try {
        [$rows, $summary, $pageInfo] = get_logs_report($pdo, $filters, $scopeWhere, $scopeParams);
        json_ok(['rows' => $rows, 'summary' => $summary, 'page' => $pageInfo]);
    } catch (Throwable $e) {
        json_err("Lỗi khi thống kê nhật ký: " . $e->getMessage(), 500);
    }
}

// export_logs: XLSX theo bộ lọc hiện tại
if ($action === 'export_logs') {
    $filters = get_filters();
    // export: lấy nhiều hơn (tối đa 5000) để tránh quá nặng
    $filters['page'] = 1;
    $filters['pageSize'] = 5000;

    try {
        [$rows, $summary, $pageInfo] = get_logs_report($pdo, $filters, $scopeWhere, $scopeParams);
    } catch (Throwable $e) {
        json_err("Không thể export: " . $e->getMessage(), 500);
    }

    // Clean output buffer to avoid corrupt XLSX
    if (ob_get_length()) {
        @ob_clean();
    }

    $spreadsheet = new Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('Logs');

    // ===== Columns (full thông tin, tiếng Việt) =====
    // ✅ BỎ IP: chỉ còn 10 cột
    $headers = [
        'STT',
        'Thời gian',
        'Người thao tác',
        'Tài khoản',
        'Vai trò',
        'Phân hệ',
        'Hành động',
        'Loại đối tượng',
        'ID đối tượng',
        'Mô tả',
    ];
    $colCount = count($headers); // = 10
    $lastCol = Coordinate::stringFromColumnIndex($colCount); // = J

    // ===== HEADER theo mẫu (TRÁI 6 CỘT - PHẢI 4 CỘT) =====
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
    $dateLine = "Chánh Hưng, ngày " . date("d") . " tháng " . date("m") . " năm " . date("Y");

    // ✅ Cố định: trái 6 cột, phải 4 cột
    // Nếu số cột thay đổi trong tương lai: vẫn cố gắng giữ trái=6, phải=4, nhưng không vượt tổng cột.
    $leftCols = 6;
    if ($leftCols >= $colCount)
        $leftCols = max(1, $colCount - 1);

    $leftEnd = Coordinate::stringFromColumnIndex($leftCols);
    $rightStart = Coordinate::stringFromColumnIndex($leftCols + 1);
    $rightEnd = $lastCol;

    // Left block A1:leftEnd4
    $ws->setCellValue("A1", $orgLeft);
    $ws->mergeCells("A1:{$leftEnd}4");
    $ws->getStyle("A1:{$leftEnd}4")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Right block rightStart1:rightEnd3
    $ws->setCellValue("{$rightStart}1", $orgRight);
    $ws->mergeCells("{$rightStart}1:{$rightEnd}3");
    $ws->getStyle("{$rightStart}1:{$rightEnd}3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'underline' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Date line rightStart4:rightEnd4
    $ws->setCellValue("{$rightStart}4", $dateLine);
    $ws->mergeCells("{$rightStart}4:{$rightEnd}4");
    $ws->getStyle("{$rightStart}4:{$rightEnd}4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 11],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    // No borders for header block
    $ws->getStyle("A1:{$lastCol}4")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);

    // Row heights giống mẫu
    $ws->getRowDimension(1)->setRowHeight(20.5);
    $ws->getRowDimension(2)->setRowHeight(15.75);
    $ws->getRowDimension(3)->setRowHeight(15.75);
    $ws->getRowDimension(4)->setRowHeight(32.25);

    // ===== Title + filters =====
    $ws->setCellValue("A5", "BÁO CÁO NHẬT KÝ HOẠT ĐỘNG");
    $ws->mergeCells("A5:{$lastCol}5");
    $ws->getStyle("A5")->getFont()->setBold(true)->setSize(14);
    $ws->getStyle("A5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $dateFrom = $filters['dateFrom'] ?: '---';
    $dateTo = $filters['dateTo'] ?: '---';
    $moduleF = $filters['module'] !== '' ? $filters['module'] : 'Tất cả';
    $actionF = $filters['logAction'] !== '' ? $filters['logAction'] : 'Tất cả';
    $qF = $filters['q'] !== '' ? $filters['q'] : '---';
    $sortF = $filters['sort'] === 'oldest' ? 'Cũ nhất' : 'Mới nhất';

    $ws->setCellValue("A6", "Bộ lọc: Phân hệ = {$moduleF} | Hành động = {$actionF} | Sắp xếp = {$sortF}");
    $ws->mergeCells("A6:{$lastCol}6");
    $ws->setCellValue("A7", "Thời gian: {$dateFrom} → {$dateTo} | Từ khóa: {$qF}");
    $ws->mergeCells("A7:{$lastCol}7");
    $ws->getStyle("A6:A7")->getFont()->setSize(10);

    // ===== Table =====
    $rowStart = 9;
    $ws->fromArray($headers, null, "A{$rowStart}");

    $ws->getStyle("A{$rowStart}:{$lastCol}{$rowStart}")->getFont()->setBold(true);
    $ws->getStyle("A{$rowStart}:{$lastCol}{$rowStart}")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
    $ws->getStyle("A{$rowStart}:{$lastCol}{$rowStart}")->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $r = $rowStart + 1;
    $i = 1;

    foreach ($rows as $x) {
        $ws->setCellValue("A{$r}", $i++);

        $ws->setCellValue("B{$r}", (string) ($x['created_at'] ?? ''));
        $ws->setCellValue("C{$r}", (string) ($x['fullname'] ?? ''));
        $ws->setCellValue("D{$r}", (string) ($x['username'] ?? ''));
        $ws->setCellValue("E{$r}", (string) ($x['role_name'] ?? ''));

        $ws->setCellValue("F{$r}", vn_module($x['module'] ?? ''));
        $ws->setCellValue("G{$r}", vn_action($x['action'] ?? ''));

        $ws->setCellValue("H{$r}", vn_target_type($x['target_type'] ?? ''));
        $ws->setCellValue("I{$r}", (string) ($x['target_id'] ?? ''));

        $ws->setCellValue("J{$r}", (string) ($x['description'] ?? ''));

        $r++;
    }

    // Borders toàn bảng
    if ($r > $rowStart + 1) {
        $ws->getStyle("A{$rowStart}:{$lastCol}" . ($r - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
    }

    // Wrap cho mô tả (cột J)
    $ws->getStyle("J" . ($rowStart + 1) . ":J" . max($rowStart + 1, $r - 1))
        ->getAlignment()->setWrapText(true);

    // Freeze
    $ws->freezePane("A" . ($rowStart + 1));

    // Column widths (tự nhìn rõ)
    $ws->getColumnDimension("A")->setWidth(6);
    $ws->getColumnDimension("B")->setWidth(18);
    $ws->getColumnDimension("C")->setWidth(24);
    $ws->getColumnDimension("D")->setWidth(18);
    $ws->getColumnDimension("E")->setWidth(14);
    $ws->getColumnDimension("F")->setWidth(18);
    $ws->getColumnDimension("G")->setWidth(14);
    $ws->getColumnDimension("H")->setWidth(16);
    $ws->getColumnDimension("I")->setWidth(12);
    $ws->getColumnDimension("J")->setWidth(55);

    $filename = "nhatki_logs_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

json_err('Unknown action', 404);
