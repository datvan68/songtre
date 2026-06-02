<?php
// controllers/statistics/accounts.php

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__, 2); // controllers/statistics -> project root

require_once $ROOT . '/config/auth.php';
require_once $ROOT . '/config/db.php';

auth_guard();

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$action = trim($action);

/* ======================
   HELPERS
====================== */
function json_ok($arr = array())
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('ok' => true), $arr), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'message' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}
function forbidden()
{
    json_err('Forbidden', 403);
}

if (function_exists('can')) {
    if (!can('statistics', 'view')) {
        forbidden();
    }
}

function get_filters()
{
    $roleId = trim(isset($_GET['role_id']) ? $_GET['role_id'] : '');
    $mode = trim(isset($_GET['mode']) ? $_GET['mode'] : 'all');
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    $from = trim(isset($_GET['created_from']) ? $_GET['created_from'] : '');
    $to = trim(isset($_GET['created_to']) ? $_GET['created_to'] : '');
    $onlyUnlinked = (string) (isset($_GET['only_unlinked']) ? $_GET['only_unlinked'] : '0') === '1' ? 1 : 0;

    if (!in_array($mode, array('all', 'role', 'custom'), true)) {
        $mode = 'all';
    }

    return array(
        'role_id' => $roleId,
        'mode' => $mode,
        'q' => $q,
        'created_from' => $from,
        'created_to' => $to,
        'only_unlinked' => $onlyUnlinked,
    );
}

function build_where($filters, &$params)
{
    $where = "1=1";

    if (!empty($filters['role_id'])) {
        $where .= " AND u.role_id = ? ";
        $params[] = (int) $filters['role_id'];
    }

    if (!empty($filters['mode']) && $filters['mode'] !== 'all') {
        $where .= " AND u.permissions_mode = ? ";
        $params[] = $filters['mode'];
    }

    if (!empty($filters['created_from'])) {
        $where .= " AND DATE(u.created_at) >= ? ";
        $params[] = $filters['created_from'];
    }

    if (!empty($filters['created_to'])) {
        $where .= " AND DATE(u.created_at) <= ? ";
        $params[] = $filters['created_to'];
    }

    if (!empty($filters['only_unlinked'])) {
        $where .= " AND m.id IS NULL ";
    }

    if (!empty($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where .= " AND (
            m.fullname LIKE ?
            OR u.fullname LIKE ?
            OR u.username LIKE ?
            OR m.mssv LIKE ?
        ) ";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    return $where;
}

function get_accounts_report(PDO $pdo, $filters)
{
    $params = array();
    $where = build_where($filters, $params);

    // rows
    $sql = "
      SELECT
        u.id AS user_id,
        u.username,
        COALESCE(NULLIF(m.fullname,''), NULLIF(u.fullname,''), NULLIF(u.username,''), '(Không rõ)') AS user_fullname,
        COALESCE(NULLIF(m.mssv,''), '') AS mssv,
        u.role_id,
        COALESCE(r.name, '(Chưa gán role)') AS role_name,
        u.permissions_mode,
        IFNULL(up.custom_perm_count, 0) AS custom_perm_count,
        IFNULL(al.log_count, 0) AS activity_count,
        IFNULL(DATE_FORMAT(al.last_at, '%Y-%m-%d %H:%i'), '') AS last_at,
        IFNULL(DATE_FORMAT(u.created_at, '%Y-%m-%d'), '') AS created_at,
        CASE WHEN m.id IS NULL THEN 0 ELSE 1 END AS has_member
      FROM users u
      LEFT JOIN roles r ON r.id = u.role_id
      LEFT JOIN members m ON m.user_id = u.id
      LEFT JOIN (
        SELECT user_id, COUNT(*) AS custom_perm_count
        FROM user_permissions
        GROUP BY user_id
      ) up ON up.user_id = u.id
      LEFT JOIN (
        SELECT user_id, MAX(created_at) AS last_at, COUNT(*) AS log_count
        FROM activity_logs
        GROUP BY user_id
      ) al ON al.user_id = u.id
      WHERE $where
      ORDER BY u.created_at DESC, u.id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // summary
    $params2 = array();
    $where2 = build_where($filters, $params2);
    $sqlSum = "
      SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN u.permissions_mode='custom' THEN 1 ELSE 0 END) AS custom_users,
        SUM(CASE WHEN u.permissions_mode='role' THEN 1 ELSE 0 END) AS role_users,
        SUM(CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END) AS linked_members,
        SUM(CASE WHEN m.id IS NULL THEN 1 ELSE 0 END) AS unlinked_members,
        SUM(IFNULL(up2.cnt,0)) AS total_custom_permissions,
        SUM(IFNULL(al2.cnt,0)) AS total_activity_logs
      FROM users u
      LEFT JOIN members m ON m.user_id = u.id
      LEFT JOIN (
        SELECT user_id, COUNT(*) AS cnt
        FROM user_permissions
        GROUP BY user_id
      ) up2 ON up2.user_id = u.id
      LEFT JOIN (
        SELECT user_id, COUNT(*) AS cnt
        FROM activity_logs
        GROUP BY user_id
      ) al2 ON al2.user_id = u.id
      WHERE $where2
    ";
    $st2 = $pdo->prepare($sqlSum);
    $st2->execute($params2);
    $summary = $st2->fetch(PDO::FETCH_ASSOC);
    if (!$summary)
        $summary = array();

    // role breakdown
    $params3 = array();
    $where3 = build_where($filters, $params3);
    $sqlRole = "
      SELECT
        u.role_id,
        COALESCE(r.name, '(Chưa gán role)') AS role_name,
        COUNT(*) AS total
      FROM users u
      LEFT JOIN roles r ON r.id = u.role_id
      LEFT JOIN members m ON m.user_id = u.id
      WHERE $where3
      GROUP BY u.role_id, role_name
      ORDER BY total DESC
    ";
    $st3 = $pdo->prepare($sqlRole);
    $st3->execute($params3);
    $roles = $st3->fetchAll(PDO::FETCH_ASSOC);

    $summary['roles'] = $roles;

    return array($rows, $summary);
}

/* ======================
   ACTIONS
====================== */

if ($action === 'role_options') {
    try {
        $st = $pdo->query("SELECT id, name FROM roles ORDER BY id ASC");
        $data = $st->fetchAll(PDO::FETCH_ASSOC);
        json_ok(array('data' => $data));
    } catch (Throwable $e) {
        json_err("Không thể tải roles: " . $e->getMessage(), 500);
    }
}

if ($action === 'accounts_report') {
    $filters = get_filters();
    try {
        list($rows, $summary) = get_accounts_report($pdo, $filters);
        json_ok(array('rows' => $rows, 'summary' => $summary));
    } catch (Throwable $e) {
        json_err("Không thể tải thống kê: " . $e->getMessage(), 500);
    }
}

if ($action === 'export_accounts_report') {
    // export mới cần vendor/autoload.php
    $autoload = $ROOT . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        json_err("Thiếu vendor/autoload.php (chưa deploy vendor hoặc chưa chạy composer install).", 500);
    }
    require_once $autoload;

    // import classes sau khi autoload
    // (use ở đầu file không bắt buộc, nhưng giữ như bạn đang viết cũng được)
    $filters = get_filters();

    try {
        list($rows, $summary) = get_accounts_report($pdo, $filters);
    } catch (Throwable $e) {
        json_err("Không thể export: " . $e->getMessage(), 500);
    }

    // ==== PhpSpreadsheet classes ====
    $Spreadsheet = 'PhpOffice\\PhpSpreadsheet\\Spreadsheet';
    $XlsxWriter = 'PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
    $Alignment = 'PhpOffice\\PhpSpreadsheet\\Style\\Alignment';
    $Border = 'PhpOffice\\PhpSpreadsheet\\Style\\Border';
    $Fill = 'PhpOffice\\PhpSpreadsheet\\Style\\Fill';

    $spreadsheet = new $Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("Accounts");

    // ===== HEADER =====
    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
    $dateLine = "Ngày " . date('d') . " tháng " . date('m') . " năm " . date('Y');

    // A1:D4
    $sheet->setCellValue("A1", $orgLeft);
    $sheet->mergeCells("A1:D4");
    $sheet->getStyle("A1:D4")->applyFromArray(array(
        'font' => array('bold' => true, 'size' => 13),
        'alignment' => array(
            'horizontal' => $Alignment::HORIZONTAL_CENTER,
            'vertical' => $Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ),
    ));

    // E1:G3
    $sheet->setCellValue("E1", $orgRight);
    $sheet->mergeCells("E1:G3");
    $sheet->getStyle("E1:G3")->applyFromArray(array(
        'font' => array('bold' => true, 'size' => 13, 'underline' => true),
        'alignment' => array(
            'horizontal' => $Alignment::HORIZONTAL_CENTER,
            'vertical' => $Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ),
    ));

    // E4:G4
    $sheet->setCellValue("E4", $dateLine);
    $sheet->mergeCells("E4:G4");
    $sheet->getStyle("E4:G4")->applyFromArray(array(
        'font' => array('italic' => true, 'size' => 12),
        'alignment' => array(
            'horizontal' => $Alignment::HORIZONTAL_RIGHT,
            'vertical' => $Alignment::VERTICAL_CENTER,
        ),
    ));

    $sheet->getRowDimension(1)->setRowHeight(20.5);
    $sheet->getRowDimension(2)->setRowHeight(15.75);
    $sheet->getRowDimension(3)->setRowHeight(15.75);
    $sheet->getRowDimension(4)->setRowHeight(32.25);

    // ===== TITLE =====
    $sheet->setCellValue("A6", "BÁO CÁO THỐNG KÊ TÀI KHOẢN");
    $sheet->mergeCells("A6:G6");
    $sheet->getStyle("A6")->applyFromArray(array(
        'font' => array('bold' => true, 'size' => 14),
        'alignment' => array(
            'horizontal' => $Alignment::HORIZONTAL_CENTER,
            'vertical' => $Alignment::VERTICAL_CENTER,
        ),
    ));

    $roleTxt = $filters['role_id'] ? ("Role ID: " . $filters['role_id']) : "Role: Tất cả";
    $modeTxt = "Mode: " . ($filters['mode'] ? $filters['mode'] : 'all');
    $fromTxt = $filters['created_from'] ? $filters['created_from'] : '---';
    $toTxt = $filters['created_to'] ? $filters['created_to'] : '---';
    $qTxt = $filters['q'] ? ("Q=" . $filters['q']) : "Q=---";
    $unlinkedTxt = !empty($filters['only_unlinked']) ? "Unlinked=1" : "Unlinked=0";

    $sheet->setCellValue("A7", "{$roleTxt} | {$modeTxt} | {$unlinkedTxt}");
    $sheet->mergeCells("A7:G7");
    $sheet->setCellValue("A8", "Thời gian tạo: {$fromTxt} → {$toTxt} | {$qTxt}");
    $sheet->mergeCells("A8:G8");
    $sheet->getStyle("A7:A8")->getFont()->setSize(10);

    // ===== TABLE =====
    $rowStart = 10;
    $headers = array('STT', 'Họ tên', 'Tài khoản', 'Role', 'Chế độ', 'Custom perms', 'Hoạt động cuối');
    $sheet->fromArray($headers, null, "A{$rowStart}");

    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getFont()->setBold(true);
    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getFill()
        ->setFillType($Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
    $sheet->getStyle("A{$rowStart}:G{$rowStart}")->getBorders()->getAllBorders()->setBorderStyle($Border::BORDER_THIN);

    $r = $rowStart + 1;
    $i = 1;

    foreach ($rows as $row) {
        $sheet->setCellValue("A{$r}", $i++);
        $sheet->setCellValue("B{$r}", (string) (isset($row['user_fullname']) ? $row['user_fullname'] : ''));
        $sheet->setCellValue("C{$r}", (string) (isset($row['username']) ? $row['username'] : ''));
        $sheet->setCellValue("D{$r}", (string) (isset($row['role_name']) ? $row['role_name'] : ''));
        $sheet->setCellValue("E{$r}", (string) (isset($row['permissions_mode']) ? $row['permissions_mode'] : ''));
        $sheet->setCellValue("F{$r}", (int) (isset($row['custom_perm_count']) ? $row['custom_perm_count'] : 0));
        $sheet->setCellValue("G{$r}", (string) (isset($row['last_at']) ? $row['last_at'] : ''));
        $r++;
    }

    $sheet->getStyle("A{$rowStart}:G" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle($Border::BORDER_THIN);

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = "baocao_taikhoan_" . date('Ymd_His') . ".xlsx";

    // clean output buffer triệt để
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new $XlsxWriter($spreadsheet);
    $writer->save('php://output');
    exit;
}

json_err("Unknown action", 400);
