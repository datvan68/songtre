<?php
// controllers/auth.php

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (preg_match('/curl|wget|python|sqlmap|nikto|masscan/i', $ua)) {
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/activity_log.php';

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

// chỉ set JSON header khi cần (để form submit thường vẫn redirect ok)
if ($isAjax || in_array($action, ['login', 'otp_verify', 'otp_resend'], true)) {
    header('Content-Type: application/json; charset=utf-8');
}

// bắt fatal -> luôn trả JSON nếu Ajax
register_shutdown_function(function () use ($isAjax) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if ($isAjax && !headers_sent())
            header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        $msg = 'PHP fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'];
        if ($isAjax) {
            echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        } else {
            echo $msg;
        }
        exit;
    }
});

// rate limit auth api chung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['rate_auth'])) {
        $_SESSION['rate_auth'] = ['count' => 1, 'time' => time()];
    } else {
        $r = &$_SESSION['rate_auth'];
        if (time() - $r['time'] > 60) {
            $r = ['count' => 1, 'time' => time()];
        } else {
            $r['count']++;
            if ($r['count'] > 60) {
                http_response_code(429);
                echo json_encode(['ok' => 0, 'error' => 'Too many requests'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
}

function json_out(array $arr, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function rate_limit_login($username, $max = 5, $seconds = 60)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login:' . $ip . ':' . strtolower($username);
    $now = time();

    if (!isset($_SESSION['rate_login'])) {
        $_SESSION['rate_login'] = [];
    }

    if (!isset($_SESSION['rate_login'][$key])) {
        $_SESSION['rate_login'][$key] = ['count' => 1, 'time' => $now];
        return;
    }

    $r = &$_SESSION['rate_login'][$key];

    if ($now - $r['time'] > $seconds) {
        $r = ['count' => 1, 'time' => $now];
        return;
    }

    $r['count']++;

    if ($r['count'] > $max) {
        http_response_code(429);
        echo json_encode(['ok' => 0, 'error' => 'Quá nhiều lần thử, vui lòng chờ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* =========================
   ADMIN OTP HELPERS
========================= */

const OTP_EXPIRE_SECONDS = 300; // 5 phút
const OTP_RESEND_COOLDOWN = 60;  // 60s chống spam gửi lại
const OTP_MAX_ATTEMPTS = 5;   // 5 lần sai
const OTP_LOCK_SECONDS = 60;  // sai 5 lần -> khóa 60s

function is_admin_role_row(array $userRow): bool
{
    $rn = strtolower(trim($userRow['role_name'] ?? ''));
    return $rn === 'admin';
}

function get_admin_otp_cfg(PDO $pdo, int $userId): array
{
    $def = [
        'enabled' => 0,
        'email' => '',
        'mode' => 'login',
        'verified_at' => null,
        'last_sent_at' => null,
    ];

    try {
        $st = $pdo->prepare("
            SELECT enabled, email, mode, verified_at, last_sent_at
            FROM admin_otp_settings
            WHERE user_id = ?
            LIMIT 1
        ");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row)
            return $def;

        return [
            'enabled' => (int) ($row['enabled'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'mode' => (string) ($row['mode'] ?? 'login'),
            'verified_at' => $row['verified_at'] ?? null,
            'last_sent_at' => $row['last_sent_at'] ?? null,
        ];
    } catch (PDOException $e) {
        error_log('[OTP CFG FAIL] ' . $e->getMessage());
        return $def;
    }
}

function otp_required_by_mode(string $mode, ?string $verifiedAt): bool
{
    $mode = trim($mode ?: 'login');

    // luôn bắt mỗi lần login
    if ($mode === 'login')
        return true;

    if ($mode === 'session') {
        // 1 lần mỗi session
        return empty($_SESSION['admin_otp_session_ok']);
    }

    $days = 0;
    if ($mode === '3d')
        $days = 3;
    if ($mode === '7d')
        $days = 7;

    if ($days <= 0)
        return true;
    if (!$verifiedAt)
        return true;

    $t = strtotime($verifiedAt);
    if ($t === false)
        return true;

    return $t < (time() - $days * 86400);
}

function gen_otp_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function otp_lock_key(int $uid): string
{
    return 'otp_lock_until:' . $uid;
}
function otp_locked_until(int $uid): int
{
    return (int) ($_SESSION[otp_lock_key($uid)] ?? 0);
}
function otp_lock_remaining(int $uid): int
{
    $until = otp_locked_until($uid);
    $rem = $until - time();
    return $rem > 0 ? $rem : 0;
}
function otp_set_lock(int $uid, int $seconds = OTP_LOCK_SECONDS): void
{
    $_SESSION[otp_lock_key($uid)] = time() + $seconds;
}
function otp_clear_lock(int $uid): void
{
    unset($_SESSION[otp_lock_key($uid)]);
}

function otp_last_send_key(int $uid): string
{
    return 'otp_last_send:' . $uid;
}
function otp_last_send_ts(int $uid): int
{
    return (int) ($_SESSION[otp_last_send_key($uid)] ?? 0);
}
function otp_set_last_send(int $uid): void
{
    $_SESSION[otp_last_send_key($uid)] = time();
}

function otp_store(PDO $pdo, int $uid, string $code): void
{
    // xóa OTP cũ chưa dùng (purpose login)
    $pdo->prepare("DELETE FROM admin_otp_codes WHERE user_id=? AND purpose='login' AND consumed_at IS NULL")
        ->execute([$uid]);

    $hash = password_hash($code, PASSWORD_DEFAULT);
    $st = $pdo->prepare("
        INSERT INTO admin_otp_codes (user_id, purpose, code_hash, expires_at, attempts, max_attempts, ip, user_agent, created_at)
        VALUES (?, 'login', ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0, ?, ?, ?, NOW())
    ");
    $ip = substr(($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr(($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $st->execute([$uid, $hash, OTP_EXPIRE_SECONDS, OTP_MAX_ATTEMPTS, $ip, $ua]);
}

function otp_verify_latest(PDO $pdo, int $uid, string $code): array
{
    // check lock (sai 5 lần)
    $rem = otp_lock_remaining($uid);
    if ($rem > 0) {
        return ['ok' => 0, 'error' => "Bạn đã nhập sai quá nhiều lần. Vui lòng đợi {$rem}s rồi thử lại."];
    }

    $st = $pdo->prepare("
        SELECT id, code_hash, expires_at, attempts, max_attempts
        FROM admin_otp_codes
        WHERE user_id=? AND purpose='login'
          AND consumed_at IS NULL
          AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row)
        return ['ok' => 0, 'error' => 'OTP đã hết hạn hoặc không tồn tại. Vui lòng bấm gửi lại.'];

    $attempts = (int) ($row['attempts'] ?? 0);
    $max = (int) ($row['max_attempts'] ?? OTP_MAX_ATTEMPTS);

    if ($attempts >= $max) {
        otp_set_lock($uid, OTP_LOCK_SECONDS);
        return ['ok' => 0, 'error' => "Bạn đã nhập sai quá nhiều lần. Vui lòng đợi " . OTP_LOCK_SECONDS . "s rồi thử lại."];
    }

    if (!password_verify($code, (string) $row['code_hash'])) {
        $pdo->prepare("UPDATE admin_otp_codes SET attempts=attempts+1 WHERE id=?")->execute([(int) $row['id']]);

        // nếu vừa chạm ngưỡng
        if (($attempts + 1) >= $max) {
            otp_set_lock($uid, OTP_LOCK_SECONDS);
            return ['ok' => 0, 'error' => "Bạn đã nhập sai quá nhiều lần. Vui lòng đợi " . OTP_LOCK_SECONDS . "s rồi thử lại."];
        }

        return ['ok' => 0, 'error' => 'OTP không đúng.'];
    }

    // ok -> mark consumed
    $pdo->prepare("UPDATE admin_otp_codes SET consumed_at=NOW() WHERE id=?")->execute([(int) $row['id']]);
    otp_clear_lock($uid);

    return ['ok' => 1];
}

function otp_send_email(PDO $pdo, string $toEmail, string $code): void
{
    require_once __DIR__ . '/../config/system_mailer.php';

    $mail = system_mailer($pdo);

    // ✅ fix lỗi font/charset
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64'; // hoặc 'quoted-printable'
    $mail->isHTML(false);

    $mail->addAddress($toEmail);

    $mail->Subject = 'OTP đăng nhập';
    $mail->Body =
        "Mã OTP của bạn là: {$code}\n" .
        "Hết hạn sau 5 phút.\n" .
        "Nếu không phải bạn, hãy đổi mật khẩu ngay.";

    $mail->send();
}


/* =========================
   OTP VERIFY ACTION
   POST action=otp_verify
========================= */
if ($action === 'otp_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (!preg_match('/^\d{6}$/', $code)) {
        json_out(['ok' => 0, 'error' => 'OTP không hợp lệ (cần 6 số)'], 400);
    }

    $pending = $_SESSION['otp_pending'] ?? null;
    if (!$pending || empty($pending['user'])) {
        json_out(['ok' => 0, 'error' => 'Không có phiên OTP đang chờ'], 400);
    }

    $u = $pending['user'];
    $uid = (int) $u['id'];

    $vr = otp_verify_latest($pdo, $uid, $code);
    if (!$vr['ok']) {
        json_out(['ok' => 0, 'error' => $vr['error']], 401);
    }

    // ✅ đúng bảng: admin_otp_settings
    $pdo->prepare("UPDATE admin_otp_settings SET verified_at=NOW(), updated_at=NOW() WHERE user_id=?")
        ->execute([$uid]);

    // login finalize
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['role_id'] = (int) $u['role_id'];

    // session ok cho mode=session
    $_SESSION['admin_otp_session_ok'] = 1;

    $redirect = $pending['redirect'] ?? 'index.php?p=dashboard';
    unset($_SESSION['otp_pending']);

    log_activity('otp_verify_ok', 'auth', 'user', (int) $u['id'], 'OTP verify thành công');

    json_out(['ok' => 1, 'redirect' => $redirect]);
}

/* =========================
   OTP RESEND ACTION
   POST action=otp_resend
========================= */
if ($action === 'otp_resend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending = $_SESSION['otp_pending'] ?? null;
    if (!$pending || empty($pending['user'])) {
        json_out(['ok' => 0, 'error' => 'Không có phiên OTP đang chờ'], 400);
    }

    $u = $pending['user'];
    $uid = (int) $u['id'];

    // lock after 5 wrong
    $remLock = otp_lock_remaining($uid);
    if ($remLock > 0) {
        json_out(['ok' => 0, 'error' => "Bạn đang bị khóa. Vui lòng đợi {$remLock}s rồi thử lại."], 429);
    }

    // cooldown resend 60s per user
    $last = otp_last_send_ts($uid);
    if ($last > 0 && (time() - $last) < OTP_RESEND_COOLDOWN) {
        $wait = OTP_RESEND_COOLDOWN - (time() - $last);
        json_out(['ok' => 0, 'error' => "Vui lòng đợi {$wait}s rồi thử lại"], 429);
    }

    $cfg = get_admin_otp_cfg($pdo, $uid);
    $email = trim((string) ($cfg['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => 0, 'error' => 'Email nhận OTP chưa hợp lệ'], 400);
    }

    $code = gen_otp_code();
    otp_store($pdo, $uid, $code);
    otp_send_email($pdo, $email, $code);

    otp_set_last_send($uid);

    log_activity('otp_resend', 'auth', 'user', $uid, 'Gửi lại OTP');

    json_out(['ok' => 1]);
}

/* =========================
   LOGIN
========================= */
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $csrf = $_POST['csrf'] ?? '';
    if (empty($csrf) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
        if ($isAjax)
            json_out(['ok' => 0, 'error' => 'CSRF invalid'], 403);
        $_SESSION['login_error'] = 'CSRF invalid';
        header('Location: ../views/login.php');
        exit;
    }

    rate_limit_login($username);

    if ($username === '' || $password === '') {
        if ($isAjax)
            json_out(['ok' => 0, 'error' => 'Thiếu dữ liệu đăng nhập'], 400);
        $_SESSION['login_error'] = 'Thiếu dữ liệu đăng nhập';
        header('Location: ../views/login.php');
        exit;
    }

    $stmt = $pdo->prepare("
      SELECT 
        u.id, u.username, u.password_hash, u.role_id,
        r.name AS role_name
      FROM users u
      JOIN roles r ON r.id = u.role_id
      WHERE u.username = ?
      LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $hashed = $user['password_hash'];

        $passwordNoSlash = preg_replace('/[^0-9]/', '', $password);
        $passwordWithSlash =
            substr($passwordNoSlash, 0, 2) . '/' .
            substr($passwordNoSlash, 2, 2) . '/' .
            substr($passwordNoSlash, 4);

        if (
            password_verify($password, $hashed) ||
            password_verify($passwordNoSlash, $hashed) ||
            password_verify($passwordWithSlash, $hashed)
        ) {
            // password ok
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            unset($_SESSION['rate_login']['login:' . $ip . ':' . strtolower($username)]);

            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php?p=dashboard';
            unset($_SESSION['redirect_after_login']);

            // ===== OTP GATE (BY USER CONFIG) =====
            $cfg = get_admin_otp_cfg($pdo, (int) $user['id']);
            $enabled = (int) ($cfg['enabled'] ?? 0) === 1;
            $email = trim((string) ($cfg['email'] ?? ''));
            $mode = (string) ($cfg['mode'] ?? 'login');
            $verifiedAt = $cfg['verified_at'] ?? null;

            if ($enabled) {
                // Bật OTP mà email lỗi -> không cho bypass, báo lỗi rõ ràng
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    if ($isAjax)
                        json_out(['ok' => 0, 'error' => 'Tài khoản đã bật OTP nhưng email nhận OTP chưa hợp lệ'], 400);
                    $_SESSION['login_error'] = 'Tài khoản đã bật OTP nhưng email nhận OTP chưa hợp lệ.';
                    header('Location: ../views/login.php');
                    exit;
                }

                if (otp_required_by_mode($mode, $verifiedAt)) {
                    $uid = (int) $user['id'];

                    // lock after 5 wrong => chặn luôn gửi mới
                    $remLock = otp_lock_remaining($uid);
                    if ($remLock > 0) {
                        if ($isAjax)
                            json_out(['ok' => 0, 'error' => "Bạn đang bị khóa. Vui lòng đợi {$remLock}s rồi thử lại."], 429);
                        $_SESSION['login_error'] = "Bạn đang bị khóa OTP. Đợi {$remLock}s rồi thử lại.";
                        header('Location: ../views/login.php');
                        exit;
                    }

                    $_SESSION['otp_pending'] = [
                        'user' => [
                            'id' => $uid,
                            'username' => $user['username'],
                            'role_id' => (int) $user['role_id'],
                        ],
                        'redirect' => $redirect,
                    ];

                    log_activity('otp_pending', 'auth', 'user', $uid, 'OTP pending (chờ gửi qua otp_resend)');

                    if (!$isAjax) {
                        // Nếu bạn muốn BCH đi cùng trang OTP thì đổi route này thành trang OTP chung
                        header('Location: ' . BASE_URL . 'index.php?p=admin_otp');
                        exit;
                    }

                    json_out(['ok' => 1, 'otp_required' => 1]);
                }
            }


            // ===== LOGIN OK (NO OTP REQUIRED) =====
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_id'] = (int) $user['role_id'];

            log_activity('login', 'auth', 'user', (int) $user['id'], 'Đăng nhập thành công');

            if ($isAjax)
                json_out(['ok' => 1, 'redirect' => $redirect]);

            header('Location: ' . BASE_URL . $redirect);
            exit;
        }
    }

    log_activity('login_failed', 'auth', null, null, 'Đăng nhập thất bại với username: ' . $username);

    if ($isAjax)
        json_out(['ok' => 0, 'error' => 'Sai tài khoản hoặc mật khẩu'], 401);

    $_SESSION['login_error'] = 'Sai tài khoản hoặc mật khẩu.';
    header('Location: ../views/login.php');
    exit;
}

/* === LOGOUT === */
if ($action === 'logout') {
    if (!empty($_SESSION['user_id'])) {
        log_activity('logout', 'auth', 'user', (int) $_SESSION['user_id'], 'Đăng xuất');
    }
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . 'index.php?p=dashboard');
    exit;
}

http_response_code(400);
echo json_encode(['ok' => 0, 'error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
exit;
