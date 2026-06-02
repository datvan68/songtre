<?php
// config/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * ✅ Lấy thông tin người dùng đang đăng nhập (JOIN roles)
 */
function auth_user()
{
    global $pdo;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    // dùng cache nếu có
    if (!empty($_SESSION['user_cache'])) {
        if (
            isset($_SESSION['user_cache']['id']) &&
            $_SESSION['user_cache']['id'] == $_SESSION['user_id']
        ) {
            return $_SESSION['user_cache'];
        }
    }

    $stmt = $pdo->prepare("
  SELECT 
    u.id,
    u.username,
    u.avatar_url,
    u.role_id,
    u.permissions_mode,   -- ✅ BẮT BUỘC
    r.name AS role_name
  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
  LIMIT 1
");

    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $_SESSION['user_cache'] = $user;
    return $user;
}

/**
 * ✅ Bảo vệ các trang cần đăng nhập
 * ❗ GIỮ NGUYÊN LOGIC – CHỈ sửa check role
 */
function auth_guard($roleName = null)
{
    if (empty($_SESSION['user_id'])) {

        $isAjax =
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $isController = strpos($_SERVER['SCRIPT_NAME'], '/controllers/') !== false;

        // ===== AJAX / API =====
        if ($isAjax || $isController) {
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok' => 0,
                'error' => 'Phiên đăng nhập đã hết hạn'
            ]);
            exit;
        }

        // ===== PAGE BÌNH THƯỜNG =====
        if (!headers_sent()) {
            $redirect = $_SERVER['SCRIPT_NAME'];
            if (!empty($_SERVER['QUERY_STRING'])) {
                $redirect .= '?' . $_SERVER['QUERY_STRING'];
            }

            $_SESSION['redirect_after_login'] = $redirect;
            header('Location: ' . BASE_URL . 'views/login.php');
            exit;
        }

        return;
    }

    // ===== CHECK ROLE NAME =====
// ===== CHECK ROLE NAME =====
    if ($roleName) {
        $user = auth_user();

        if (!$user || $user['role_name'] !== $roleName) {

            $isAjax =
                isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            $isController = strpos($_SERVER['SCRIPT_NAME'], '/controllers/') !== false;

            if ($isAjax || $isController) {
                if (!headers_sent()) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'ok' => 0,
                    'error' => 'Bạn không có quyền truy cập'
                ]);
                exit;
            }

            // PAGE thường
            if (!headers_sent()) {
                http_response_code(403);
            }

            echo "<section class='p-6 text-red-600 font-semibold'>
                403 – Bạn không có quyền truy cập trang này.
              </section>";
            exit;
        }
    }

}

function can(string $permCode, string $action = 'view'): bool
{
    // ADMIN BYPASS TẤT CẢ
    if (is_admin()) {
        return true;
    }
    $user = auth_user();
    if (!$user)
        return false;

    // 🔒 ADMIN LUÔN FULL QUYỀN
    if ($user['role_name'] === 'admin') {
        return true;
    }

    global $pdo;

    // 1️⃣ LẤY MODE
    $mode = in_array($user['permissions_mode'], ['custom', 'role'])
        ? $user['permissions_mode']
        : 'role';

    // 2️⃣ QUYỀN CUSTOM (với fallback sang role nếu chưa có dòng nào)
    if ($mode === 'custom') {
        $stm = $pdo->prepare("
      SELECT up.can_$action
      FROM user_permissions up
      JOIN permissions p ON p.id = up.permission_id
      WHERE up.user_id = ? AND p.code = ?
      LIMIT 1
    ");
        $stm->execute([$user['id'], $permCode]);
        $val = $stm->fetchColumn();

        // Nếu tìm thấy dòng → trả kết quả đó
        if ($val !== false) {
            return (bool) $val;
        }

        // Nếu user_permissions chưa có dòng nào cho permCode này → fallback sang role_permissions
        // (Trường hợp admin thêm permission mới vào role nhưng chưa đồng bộ sang user_permissions)
    }

    // 3️⃣ QUYỀN ROLE (mặc định hoặc fallback từ custom)
    $stm = $pdo->prepare("
    SELECT rp.can_$action
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = ? AND p.code = ?
    LIMIT 1
  ");
    $stm->execute([$user['role_id'], $permCode]);

    return (bool) $stm->fetchColumn();
}


/**
 * ✅ Hàng rào admin
 */
function require_admin()
{
    auth_guard('admin');
}

/**
 * ✅ Kiểm tra quyền admin
 */
function is_admin()
{
    $u = auth_user();
    return $u && $u['role_name'] === 'admin';
}
function current_role_name(): string {
  $u = auth_user();
  return strtolower(trim((string)($u['role_name'] ?? '')));
}

function is_banchaphanh(): bool {
  $r = current_role_name();
  return $r === 'banchaphanh' || $r === 'ban chấp hành' || $r === 'ban_chap_hanh';
}
