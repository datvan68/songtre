<?php
// controllers/statistics/nominations.php
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = trim($action);

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

function read_str($k, $default = '')
{
    $v = $_GET[$k] ?? $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}
function read_int($k, $default = 0)
{
    $v = $_GET[$k] ?? $_POST[$k] ?? $default;
    return (int) $v;
}

function get_me_id()
{
    // tuỳ dự án bạn lưu session thế nào, ưu tiên các key phổ biến
    if (isset($_SESSION['user_id']))
        return (int) $_SESSION['user_id'];
    if (isset($_SESSION['user']['id']))
        return (int) $_SESSION['user']['id'];
    if (isset($_SESSION['id']))
        return (int) $_SESSION['id'];
    return 0;
}

function normalize_role_name($s)
{
    $s = mb_strtolower((string) $s, 'UTF-8');
    // bỏ dấu cơ bản
    $map = [
        'á' => 'a',
        'à' => 'a',
        'ả' => 'a',
        'ã' => 'a',
        'ạ' => 'a',
        'ă' => 'a',
        'ắ' => 'a',
        'ằ' => 'a',
        'ẳ' => 'a',
        'ẵ' => 'a',
        'ặ' => 'a',
        'â' => 'a',
        'ấ' => 'a',
        'ầ' => 'a',
        'ẩ' => 'a',
        'ẫ' => 'a',
        'ậ' => 'a',
        'đ' => 'd',
        'é' => 'e',
        'è' => 'e',
        'ẻ' => 'e',
        'ẽ' => 'e',
        'ẹ' => 'e',
        'ê' => 'e',
        'ế' => 'e',
        'ề' => 'e',
        'ể' => 'e',
        'ễ' => 'e',
        'ệ' => 'e',
        'í' => 'i',
        'ì' => 'i',
        'ỉ' => 'i',
        'ĩ' => 'i',
        'ị' => 'i',
        'ó' => 'o',
        'ò' => 'o',
        'ỏ' => 'o',
        'õ' => 'o',
        'ọ' => 'o',
        'ô' => 'o',
        'ố' => 'o',
        'ồ' => 'o',
        'ổ' => 'o',
        'ỗ' => 'o',
        'ộ' => 'o',
        'ơ' => 'o',
        'ớ' => 'o',
        'ờ' => 'o',
        'ở' => 'o',
        'ỡ' => 'o',
        'ợ' => 'o',
        'ú' => 'u',
        'ù' => 'u',
        'ủ' => 'u',
        'ũ' => 'u',
        'ụ' => 'u',
        'ư' => 'u',
        'ứ' => 'u',
        'ừ' => 'u',
        'ử' => 'u',
        'ữ' => 'u',
        'ự' => 'u',
        'ý' => 'y',
        'ỳ' => 'y',
        'ỷ' => 'y',
        'ỹ' => 'y',
        'ỵ' => 'y',
    ];
    return strtr($s, $map);
}

function resolve_role(PDO $pdo, int $meId)
{
    $sql = "SELECT r.name AS role_name
          FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
          WHERE u.id = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$meId]);
    $roleName = (string) ($st->fetchColumn() ?: '');
    $rn = normalize_role_name($roleName);

    if (strpos($rn, 'admin') !== false)
        return 'admin';
    if (strpos($rn, 'bi thu') !== false || strpos($rn, 'bithu') !== false)
        return 'bithu';
    if (strpos($rn, 'gvcn') !== false || strpos($rn, 'chu nhiem') !== false)
        return 'gvcn';

    return 'user';
}

function build_scope(PDO $pdo, int $meId, string $role)
{
    // output: [$whereSql, $params]
    if ($role === 'admin')
        return ['', []];

    if ($role === 'bithu') {
        $st = $pdo->prepare("SELECT chidoan_group_id, department_id, course_id, class_id FROM bithu_scopes WHERE user_id = ? LIMIT 1");
        $st->execute([$meId]);
        $sc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sc) {
            // không có scope -> chặn
            return [' AND 1=0 ', []];
        }

        $group = (int) ($sc['chidoan_group_id'] ?? 0);
        $classId = (int) ($sc['class_id'] ?? 0);
        $deptId = (int) ($sc['department_id'] ?? 0);

        if ($group === 1 && $classId > 0) {
            return [' AND n.class_id = ? ', [$classId]];
        }

        // group 2: lọc theo khoa/phòng nếu có class_id trong nominations
        if ($group === 2 && $deptId > 0) {
            // cần join classes c (đã join ở report)
            return [' AND c.department_id = ? ', [$deptId]];
        }

        // fallback: không rõ -> chặn
        return [' AND 1=0 ', []];
    }

    if ($role === 'gvcn') {
        $st = $pdo->prepare("SELECT class_id FROM gvcn_classes WHERE user_id = ?");
        $st->execute([$meId]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [' AND 1=0 ', []];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return [" AND n.class_id IN ($ph) ", $ids];
    }

    // user thường: chỉ thấy hồ sơ mình tạo (tuỳ bạn)
    return [' AND n.user_id = ? ', [$meId]];
}

function get_filters()
{
    $groupBy = read_str('group_by', 'title');
    $allowedGroup = ['title', 'nominee', 'proposer', 'class', 'dept'];
    if (!in_array($groupBy, $allowedGroup, true))
        $groupBy = 'title';

    $status = read_str('status', 'all');
    $allowedStatus = ['all', 'pending', 'approved', 'rejected'];
    if (!in_array($status, $allowedStatus, true))
        $status = 'all';

    $type = read_str('type', 'all');
    $allowedType = ['all', 'self', 'other', 'collective'];
    if (!in_array($type, $allowedType, true))
        $type = 'all';

    $schoolYear = read_str('school_year', '');
    $titleId = read_int('title_id', 0);

    $dateFrom = read_str('date_from', '');
    $dateTo = read_str('date_to', '');

    return [
        'group_by' => $groupBy,
        'status' => $status,
        'type' => $type,
        'school_year' => $schoolYear,
        'title_id' => $titleId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

function build_where(array $filters, string $scopeWhere, array $scopeParams, &$paramsOut)
{
    $w = " WHERE 1=1 ";
    $params = [];

    if (!empty($filters['school_year'])) {
        $w .= " AND n.school_year = ? ";
        $params[] = $filters['school_year'];
    }

    if (!empty($filters['title_id'])) {
        $w .= " AND n.title_id = ? ";
        $params[] = (int) $filters['title_id'];
    }

    if (($filters['status'] ?? 'all') !== 'all') {
        $w .= " AND n.status = ? ";
        $params[] = $filters['status'];
    }

    if (($filters['type'] ?? 'all') !== 'all') {
        $w .= " AND n.type = ? ";
        $params[] = $filters['type'];
    }

    if (!empty($filters['date_from'])) {
        $w .= " AND n.created_at >= ? ";
        $params[] = $filters['date_from'] . " 00:00:00";
    }

    if (!empty($filters['date_to'])) {
        $w .= " AND n.created_at <= ? ";
        $params[] = $filters['date_to'] . " 23:59:59";
    }

    if ($scopeWhere) {
        $w .= " " . $scopeWhere . " ";
        $params = array_merge($params, $scopeParams);
    }

    $paramsOut = $params;
    return $w;
}

function get_nominations_report(PDO $pdo, array $filters, string $scopeWhere, array $scopeParams)
{
    $groupBy = $filters['group_by'] ?? 'title';

    // base joins (đủ cho mọi group)
    $baseFrom = "
    FROM nominations n
    LEFT JOIN users u_proposer ON u_proposer.id = n.user_id
    LEFT JOIN users u_nominee  ON u_nominee.id  = n.nominee_user_id
    LEFT JOIN classes c        ON c.id          = n.class_id
    LEFT JOIN departments d    ON d.id          = c.department_id
    LEFT JOIN titles t         ON t.id          = n.title_id
    LEFT JOIN reward_titles rt ON rt.id         = n.title_id
  ";

    // group expressions
    if ($groupBy === 'title') {
        $nameExpr = "COALESCE(t.name, rt.name, CONCAT('ID#', n.title_id))";
        $infoExpr = "COALESCE(t.grp, '')";
        $groupExpr = $nameExpr . ", " . $infoExpr;
    } elseif ($groupBy === 'nominee') {
        $nameExpr = "COALESCE(u_nominee.fullname, NULLIF(n.fullname,''), '(Không rõ)')";
        $infoExpr = "TRIM(CONCAT(COALESCE(c.name,''), CASE WHEN c.name IS NOT NULL AND d.name IS NOT NULL THEN ' · ' ELSE '' END, COALESCE(d.name,'')))";
        $groupExpr = $nameExpr . ", " . $infoExpr;
    } elseif ($groupBy === 'proposer') {
        $nameExpr = "COALESCE(u_proposer.fullname, CONCAT('User#', n.user_id))";
        $infoExpr = "COALESCE(NULLIF(n.proposer_pos,''), '')";
        $groupExpr = $nameExpr . ", " . $infoExpr;
    } elseif ($groupBy === 'class') {
        $nameExpr = "COALESCE(c.name, '(Không rõ)')";
        $infoExpr = "COALESCE(d.name, '')";
        $groupExpr = $nameExpr . ", " . $infoExpr;
    } else { // dept
        // ưu tiên dept theo class.department_id; fallback sang text nominations.dept
        $nameExpr = "COALESCE(d.name, NULLIF(TRIM(n.dept),''), '(Không rõ)')";
        $infoExpr = "''";
        $groupExpr = $nameExpr;
    }

    $params = [];
    $where = build_where($filters, $scopeWhere, $scopeParams, $params);

    $sql = "
    SELECT
      {$nameExpr} AS name,
      {$infoExpr} AS info,
      COUNT(*) AS total,
      SUM(CASE WHEN n.status='approved' THEN 1 ELSE 0 END) AS approved,
      SUM(CASE WHEN n.status='rejected' THEN 1 ELSE 0 END) AS rejected,
      SUM(CASE WHEN n.status='pending' THEN 1 ELSE 0 END) AS pending,
      MAX(n.created_at) AS last_at
    {$baseFrom}
    {$where}
    GROUP BY {$groupExpr}
    ORDER BY total DESC
  ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // summary
    $sumSql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN n.status='approved' THEN 1 ELSE 0 END) AS approved,
      SUM(CASE WHEN n.status='rejected' THEN 1 ELSE 0 END) AS rejected,
      SUM(CASE WHEN n.status='pending' THEN 1 ELSE 0 END) AS pending,
      COUNT(DISTINCT COALESCE(n.nominee_user_id, NULLIF(n.fullname,''))) AS unique_nominees,
      COUNT(DISTINCT n.title_id) AS unique_titles
    {$baseFrom}
    {$where}
  ";
    $st2 = $pdo->prepare($sumSql);
    $st2->execute($params);
    $summary = $st2->fetch(PDO::FETCH_ASSOC) ?: [];

    return [$rows, $summary];
}

// ======================
// ACTIONS
// ======================

if ($action === 'school_year_options') {
    // Dùng school_years làm nguồn chuẩn; nếu không có thì fallback distinct nominations.school_year
    try {
        $st = $pdo->query("SELECT year_label FROM school_years WHERE is_active=1 ORDER BY year_label DESC");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$data) {
            $st2 = $pdo->query("SELECT DISTINCT school_year AS year_label FROM nominations WHERE school_year IS NOT NULL AND school_year<>'' ORDER BY school_year DESC");
            $data = $st2->fetchAll(PDO::FETCH_ASSOC);
        }
        json_ok(['data' => $data]);
    } catch (Throwable $e) {
        json_err("Không tải được năm học", 500);
    }
}

if ($action === 'title_options') {
    try {
        // gộp titles + reward_titles, ưu tiên titles có grp
        $sql = "
      SELECT id, name, grp FROM titles
      UNION ALL
      SELECT id, name, NULL AS grp FROM reward_titles
      ORDER BY name ASC
    ";
        $st = $pdo->query($sql);
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['data' => $data]);
    } catch (Throwable $e) {
        json_err("Không tải được danh hiệu", 500);
    }
}

if ($action === 'nominations_report') {
    $meId = get_me_id();
    if ($meId <= 0)
        json_err("Unauthenticated", 401);

    $role = resolve_role($pdo, $meId);
    [$scopeWhere, $scopeParams] = build_scope($pdo, $meId, $role);

    $filters = get_filters();

    try {
        [$rows, $summary] = get_nominations_report($pdo, $filters, $scopeWhere, $scopeParams);
        json_ok(['rows' => $rows, 'summary' => $summary]);
    } catch (Throwable $e) {
        json_err("Không thể tải thống kê: " . $e->getMessage(), 500);
    }
}

if ($action === 'export_nominations_report') {
    $meId = get_me_id();
    if ($meId <= 0)
        json_err("Unauthenticated", 401);

    $role = resolve_role($pdo, $meId);
    [$scopeWhere, $scopeParams] = build_scope($pdo, $meId, $role);

    $filters = get_filters();

    try {
        [$rows, $summary] = get_nominations_report($pdo, $filters, $scopeWhere, $scopeParams);
    } catch (Throwable $e) {
        json_err("Không thể export: " . $e->getMessage(), 500);
    }

    $groupBy = $filters['group_by'] ?? 'title';
    $status = $filters['status'] ?? 'all';
    $type = $filters['type'] ?? 'all';
    $dateFrom = $filters['date_from'] ?? '';
    $dateTo = $filters['date_to'] ?? '';
    $year = $filters['school_year'] ?? '';

    // ======================
    // XLSX
    // ======================
    $spreadsheet = new Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('Awards');

    // ===== HEADER (7 cột: A..G) theo mẫu chia trái/phải =====
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
    $dateLine = "Chánh Hưng, ngày " . date("d") . " tháng " . date("m") . " năm " . date("Y");

    // Trái: A1:D4
    $ws->setCellValue("A1", $orgLeft);
    $ws->mergeCells("A1:D4");
    $ws->getStyle("A1:D4")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Phải: E1:G3
    $ws->setCellValue("E1", $orgRight);
    $ws->mergeCells("E1:G3");
    $ws->getStyle("E1:G3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'underline' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Date line: E4:G4
    $ws->setCellValue("E4", $dateLine);
    $ws->mergeCells("E4:G4");
    $ws->getStyle("E4:G4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 12],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $ws->getStyle("A1:G4")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
    $ws->getRowDimension(1)->setRowHeight(20.5);
    $ws->getRowDimension(2)->setRowHeight(15.75);
    $ws->getRowDimension(3)->setRowHeight(15.75);
    $ws->getRowDimension(4)->setRowHeight(32.25);

    // Title + filters (A..G)
    $ws->setCellValue("A5", "BÁO CÁO THỐNG KÊ THI ĐUA / ĐỀ CỬ");
    $ws->mergeCells("A5:G5");
    $ws->getStyle("A5:G5")->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $ws->setCellValue("A6", "Năm học: " . ($year ?: '---') . " | Nhóm: {$groupBy} | Trạng thái: {$status} | Loại: {$type}");
    $ws->mergeCells("A6:G6");
    $ws->setCellValue("A7", "Thời gian: " . ($dateFrom ?: '---') . " → " . ($dateTo ?: '---'));
    $ws->mergeCells("A7:G7");
    $ws->getStyle("A6:A7")->getFont()->setSize(10);

    // Table start
    $rowStart = 9;

    // 7 cột cố định: A..G
    $colB = "Đối tượng";
    $colC = "Thông tin";
    if ($groupBy === 'title') {
        $colB = "Danh hiệu";
        $colC = "Nhóm";
    }
    if ($groupBy === 'nominee') {
        $colB = "Người được đề cử";
        $colC = "Lớp / Khoa";
    }
    if ($groupBy === 'proposer') {
        $colB = "Người đề cử";
        $colC = "Chức vụ/ghi chú";
    }
    if ($groupBy === 'class') {
        $colB = "Lớp";
        $colC = "Khoa";
    }
    if ($groupBy === 'dept') {
        $colB = "Khoa/Phòng";
        $colC = "Ghi chú";
    }

    $headers = ['STT', $colB, $colC, 'Tổng', 'Duyệt', 'Từ chối', 'Lần cuối'];
    $ws->fromArray($headers, null, "A{$rowStart}");

    $ws->getStyle("A{$rowStart}:G{$rowStart}")->getFont()->setBold(true);
    $ws->getStyle("A{$rowStart}:G{$rowStart}")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
    $ws->getStyle("A{$rowStart}:G{$rowStart}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $r = $rowStart + 1;
    $i = 1;

    foreach ($rows as $row) {
        $name = (string) ($row['name'] ?? '');
        $info = (string) ($row['info'] ?? '');

        $total = (int) ($row['total'] ?? 0);
        $approved = (int) ($row['approved'] ?? 0);
        $rejected = (int) ($row['rejected'] ?? 0);
        $pending = (int) ($row['pending'] ?? max(0, $total - $approved - $rejected));
        $lastAt = (string) ($row['last_at'] ?? '');

        // nhét pending vào cột C để không tăng số cột
        $info2 = trim($info . ($pending > 0 ? " | Chờ: {$pending}" : ""));

        $ws->setCellValue("A{$r}", $i++);
        $ws->setCellValue("B{$r}", $name);
        $ws->setCellValue("C{$r}", $info2 ?: '-');
        $ws->setCellValue("D{$r}", $total);
        $ws->setCellValue("E{$r}", $approved);
        $ws->setCellValue("F{$r}", $rejected);
        $ws->setCellValue("G{$r}", $lastAt ?: '-');
        $r++;
    }

    $ws->getStyle("A{$rowStart}:G" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    foreach (range('A', 'G') as $col)
        $ws->getColumnDimension($col)->setAutoSize(true);
    $ws->freezePane("A" . ($rowStart + 1));

    $filename = "baocao_thiduakhenthuong_{$groupBy}_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// default
json_err("Unknown action", 400);
