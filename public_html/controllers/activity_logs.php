<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');
ob_clean();
function jexit($arr, $code = 200)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function forbidden()
{
    jexit(['ok' => 0, 'error' => 'Forbidden'], 403);
}

auth_guard();

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === '')
    jexit(['ok' => 0, 'error' => 'Thiếu action'], 400);

// ===== ACL =====
// Gợi ý quyền:
// - activity_logs.view: xem lịch sử (tối thiểu: chỉ xem của chính mình)
// - activity_logs.view_all: xem tất cả
// - activity_logs.export: export csv
$canView = is_admin() || (function_exists('can') && can('activity_logs', 'view')) || (function_exists('can') && can('activity_logs', 'view_all'));
if (!$canView)
    forbidden();

$canViewAll = is_admin() || (function_exists('can') && can('activity_logs', 'view_all'));
$canExport = is_admin() || (function_exists('can') && can('activity_logs', 'export'));

$user = function_exists('auth_user') ? auth_user() : null;
$currentUserId = (int) ($user['id'] ?? ($_SESSION['user_id'] ?? 0));

try {

    // =========================
    // META: roles + users (filters)
    // =========================
    if ($action === 'meta') {

        // roles – chắc chắn tồn tại
        $roles = $pdo->query("
    SELECT id, name
    FROM roles
    ORDER BY id ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

        $users = $pdo->query("
  SELECT
    u.id,
    u.username,
    COALESCE(m.fullname, u.username) AS fullname
  FROM users u
  LEFT JOIN members m ON m.user_id = u.id
  ORDER BY m.fullname IS NULL, m.fullname, u.username
")->fetchAll(PDO::FETCH_ASSOC);


        echo json_encode([
            'ok' => 1,
            'roles' => $roles,
            'users' => $users
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }


    // =========================
    // LIST + FILTER + PAGINATION
    // =========================
    if ($action === 'list') {
        // log_activity(
        //     'view',
        //     'activity_logs',
        //     null,
        //     null,
        //     'Xem lịch sử hoạt động'
        // );
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per_page'] ?? $_GET['perPage'] ?? 10);
        $perPage = max(5, min(50, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

        if (!$canViewAll && empty($_GET['user_id'])) {
            $where[] = "al.user_id = ?";
            $params[] = $currentUserId;
        }

        // chỉ cho filter user / role khi có quyền xem tất cả
        if ($canViewAll) {

            if (!empty($_GET['user_id'])) {
                $where[] = "al.user_id = ?";
                $params[] = (int) $_GET['user_id'];
            }

            if (!empty($_GET['role_id'])) {
                $where[] = "al.role_id = ?";
                $params[] = (int) $_GET['role_id'];
            }

        }


        if (!empty($_GET['module'])) {
            $where[] = "LOWER(TRIM(al.module)) = ?";
            $params[] = strtolower(trim($_GET['module']));
        }

        if (!empty($_GET['act'])) {
            $where[] = "al.action = ?";
            $params[] = $_GET['act'];
        }

        if (!empty($_GET['from'])) {
            $where[] = "al.created_at >= ?";
            $params[] = $_GET['from'] . ' 00:00:00';
        }

        if (!empty($_GET['to'])) {
            $where[] = "al.created_at <= ?";
            $params[] = $_GET['to'] . ' 23:59:59';
        }


        $q = trim($_GET['q'] ?? '');


        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM activity_logs al
    $whereSql
  ");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("
    SELECT
      al.*,
      u.username,
      r.name AS role_name
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    LEFT JOIN roles r ON r.id = al.role_id
    $whereSql
    ORDER BY al.id DESC
    LIMIT $perPage OFFSET $offset
  ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $modules = $pdo->query("SELECT DISTINCT LOWER(TRIM(module)) AS module FROM activity_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
        $actions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'ok' => 1,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'rows' => $rows,
            'modules' => $modules,
            'actions' => $actions,
            'can_view_all' => $canViewAll ? 1 : 0,
            'can_export' => 1
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }



    // =========================
    // EXPORT CSV
    // =========================
    if ($action === 'export') {
        if (!$canExport)
            forbidden();

        // export dùng cùng filter như list (không paginate)
        $q = trim($_GET['q'] ?? '');
        $roleId = trim($_GET['role_id'] ?? '');
        $userId = trim($_GET['user_id'] ?? '');
        $module = trim($_GET['module'] ?? '');
        $act = trim($_GET['act'] ?? '');
        $dateFrom = trim($_GET['from'] ?? '');
        $dateTo = trim($_GET['to'] ?? '');

        $where = [];
        $params = [];

        if (!$canViewAll) {
            $where[] = "al.user_id = ?";
            $params[] = $currentUserId;
        } else {
            if ($userId !== '') {
                $where[] = "al.user_id = ?";
                $params[] = (int) $userId;
            }
            if ($roleId !== '') {
                $where[] = "al.role_id = ?";
                $params[] = (int) $roleId;
            }
        }

        if ($module !== '') {
            $where[] = "al.module = ?";
            $params[] = $module;
        }
        if ($act !== '') {
            $where[] = "al.action = ?";
            $params[] = $act;
        }
        if ($dateFrom !== '') {
            $where[] = "al.created_at >= ?";
            $params[] = $dateFrom . " 00:00:00";
        }
        if ($dateTo !== '') {
            $where[] = "al.created_at <= ?";
            $params[] = $dateTo . " 23:59:59";
        }

        if ($q !== '') {
            $where[] = "(u.username LIKE ? 
                OR COALESCE(m.fullname,'') LIKE ? 
                OR al.description LIKE ? 
                OR al.ip_address LIKE ? 
                OR al.action LIKE ? 
                OR al.module LIKE ? 
                OR al.target_type LIKE ?)";
            for ($i = 0; $i < 7; $i++)
                $params[] = "%" . $q . "%";
        }


        $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        $stmt = $pdo->prepare("
        SELECT
        al.created_at,
        u.username,
        COALESCE(m.fullname,'') AS fullname,
        r.name AS role_name,
        al.action,
        al.module,
        al.target_type,
        al.target_id,
        al.description,
        al.ip_address
      FROM activity_logs al
      LEFT JOIN users u ON u.id = al.user_id
      LEFT JOIN members m ON m.user_id = al.user_id
      LEFT JOIN roles r ON r.id = al.role_id
      $whereSql
      ORDER BY al.id DESC
      LIMIT 5000
    ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // trả về JSON csv_text để JS download
        $out = fopen('php://temp', 'r+');
        // 🔥 BOM cho Excel đọc UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Thời gian',
            'Tài khoản',
            'Họ tên',
            'Vai trò',
            'Hành động',
            'Chức năng',
            'Đối tượng',
            'Mô tả',
            'Địa chỉ IP'
        ]);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['created_at'],
                $r['username'],
                $r['full_name'],
                $r['role_name'],
                $r['action'],
                $r['module'],
                $r['target_type'],
                $r['description'],
                $r['ip_address'],
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        jexit(['ok' => 1, 'csv' => $csv, 'filename' => 'activity_logs_' . date('Ymd_His') . '.csv']);
    }

    jexit(['ok' => 0, 'error' => 'Action không hỗ trợ'], 400);

} catch (Throwable $e) {
    jexit(['ok' => 0, 'error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
