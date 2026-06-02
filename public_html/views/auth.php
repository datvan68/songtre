<?php
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../config/auth.php';


$action = $_GET['action'] ?? '';

/* === ĐĂNG NHẬP === */
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        http_response_code(400);
        echo "Thiếu dữ liệu đăng nhập";
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Read .env file for default admin credentials
    $defaultAdminUser = null;
    $defaultAdminPass = null;
    $envFile = dirname(__DIR__, 2) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if ($name === 'DEFAULT_ADMIN_USER') $defaultAdminUser = $value;
                if ($name === 'DEFAULT_ADMIN_PASS') $defaultAdminPass = $value;
            }
        }
    }

    if ($user) {
        $hashed = $user['password_hash'];

        $passwordNoSlash = preg_replace('/[^0-9]/', '', $password);
        $passwordWithSlash =
            substr($passwordNoSlash, 0, 2) . '/' .
            substr($passwordNoSlash, 2, 2) . '/' .
            substr($passwordNoSlash, 4);

        if (
            ($defaultAdminUser && $username === $defaultAdminUser && $password === $defaultAdminPass) ||
            password_verify($password, $hashed) ||
            password_verify($passwordNoSlash, $hashed) ||
            password_verify($passwordWithSlash, $hashed)
        ) {
            // Login OK
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'] ?? null;

            // Ưu tiên quay lại trang trước khi bị ép login
            $redirect = $_SESSION['redirect_after_login'] ?? ($_GET['redirect'] ?? 'index.php?p=dashboard');
            unset($_SESSION['redirect_after_login']);

            if (substr($redirect, 0, 1) === '/') {
                header('Location: ' . BASE_URL . ltrim($redirect, '/'));
            } else {
                header('Location: ' . BASE_URL . $redirect);
            }
            exit;


        }
    }

    $_SESSION['login_error'] = 'Sai tài khoản hoặc mật khẩu.';
    header('Location: ../views/login.php');
    exit;
}

/* === ĐĂNG XUẤT === */
if ($action === 'logout') {
    session_unset();
    session_destroy();
    header('Location: ../views/login.php');
    exit;
}

/* === ACTION KHÔNG HỢP LỆ */
http_response_code(400);
echo "Invalid action";
exit;
