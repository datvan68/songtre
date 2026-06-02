<?php
// controllers/admin_otp_login.php
// OTP cho flow đăng nhập Admin (send/verify)
// - dùng Email hệ thống (SMTP DB) qua system_mailer($pdo)
// - không bắt buộc gmail
// - cooldown 5 phút

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/system_mailer.php';
if (file_exists(__DIR__ . '/../config/activity_log.php')) {
  require_once __DIR__ . '/../config/activity_log.php';
}

header('Content-Type: application/json; charset=utf-8');

// luôn trả JSON nếu fatal
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    if (!headers_sent())
      header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PHP fatal: ' . $e['message']], JSON_UNESCAPED_UNICODE);
    exit;
  }
});

// ===== Config =====
const OTP_TTL_SECONDS = 300;         // 5 phút
const OTP_COOLDOWN_SECONDS = 60;    // 1 phút
const OTP_MAX_ATTEMPTS = 5;

// Khuyến nghị: dùng CHUNG với admin_otp_security.php để dễ quản trị
const OTP_SECRET = 'CHANGE_ME_TO_A_RANDOM_LONG_SECRET_STRING_32PLUS';

// ===== Helpers =====
function json_out(array $arr, int $code = 200): void
{
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function valid_email(string $email): bool
{
  $email = trim($email);
  return $email !== '' && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function otp_hash(string $code): string
{
  return hash_hmac('sha256', $code, OTP_SECRET);
}

function client_ip(): string
{
  return substr(($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function client_ua(): string
{
  return substr(($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function log_opt(int $userId, string $action, array $meta = []): void
{
  if (function_exists('log_activity')) {
    try {
      log_activity($action, 'admin_otp_login', 'user', $userId, json_encode($meta, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
      // ignore
    }
  }
}

function otp_mode_days(string $mode): int
{
  if ($mode === '3d')
    return 3;
  if ($mode === '7d')
    return 7;
  return 0;
}
function pending_user_id(): int
{
  $id = (int) ($_SESSION['pending_otp_user_id'] ?? 0);
  if ($id > 0)
    return $id;

  // backward compatible với code cũ
  $id = (int) ($_SESSION['pending_admin_user_id'] ?? 0);
  return $id > 0 ? $id : 0;
}

function clear_pending_user(): void
{
  unset($_SESSION['pending_otp_user_id']);
  unset($_SESSION['pending_admin_user_id']);
}

/**
 * Quyết định có cần OTP hay không (dựa trên admin_otp_settings của user admin đó)
 */
function should_require_otp(PDO $pdo, int $userId): bool
{
  $st = $pdo->prepare("SELECT enabled, email, mode, verified_at FROM admin_otp_settings WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $s = $st->fetch(PDO::FETCH_ASSOC);
  if (!$s || (int) ($s['enabled'] ?? 0) !== 1)
    return false;

  $email = trim((string) ($s['email'] ?? ''));
  // Bật OTP nhưng email lỗi => vẫn bắt OTP (không cho bypass)
  if (!valid_email($email))
    return true;

  $mode = (string) ($s['mode'] ?? 'login');
  if ($mode === 'login')
    return true;

  if ($mode === 'session') {
    // 1 lần mỗi session
    $passedAt = (int) ($_SESSION['admin_otp_passed_at'] ?? 0);
    return ($passedAt <= 0);
  }

  // 3d/7d: dựa vào verified_at
  $days = otp_mode_days($mode);
  if ($days > 0) {
    $va = $s['verified_at'] ?? null;
    if (!$va)
      return true;
    $ts = strtotime($va);
    if (!$ts)
      return true;
    return (time() - $ts) > ($days * 86400);
  }

  return true;
}

/**
 * Gửi OTP bằng SMTP DB (system_mailer)
 */
function send_otp_email(PDO $pdo, string $toEmail, string $code): void
{
  $mail = system_mailer($pdo);

  $mail->clearAddresses();
  $mail->addAddress($toEmail);

  $mail->isHTML(true);
  $mail->Subject = "[DoanThanhNien] OTP đăng nhập";
  $mail->Body = "
    <div style='font-family:Arial,sans-serif;line-height:1.6'>
      <h3>Xác minh đăng nhập</h3>
      <p>Mã OTP:</p>
      <p style='font-size:22px;letter-spacing:3px'><b>{$code}</b></p>
      <p>Mã có hiệu lực trong <b>5 phút</b>.</p>
    </div>
  ";
  $mail->AltBody = "OTP: {$code} (5 phút)";

  $mail->send();
}


// ===== Main =====
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// =========================
// ACTION: send (login)
// Expect: session có pending_admin_user_id do bước login password set
// =========================
if ($action === 'send') {
  $pending = pending_user_id();
  if ($pending <= 0)
    json_out(['ok' => false, 'error' => 'Missing pending_otp_user_id'], 400);

  if (!should_require_otp($pdo, $pending)) {
    $_SESSION['admin_otp_passed_at'] = time();
    // Không clear pending nếu login finalize cần user_id, tuỳ flow.
    // Nhưng nếu bạn finalize bằng cách khác thì clear luôn cho sạch:
    clear_pending_user();
    json_out(['ok' => true, 'data' => ['skip' => 1]]);
  }

  $st = $pdo->prepare("SELECT enabled,email,mode,last_sent_at FROM admin_otp_settings WHERE user_id=? LIMIT 1");
  $st->execute([$pending]);
  $s = $st->fetch(PDO::FETCH_ASSOC);

  if (!$s || (int) ($s['enabled'] ?? 0) !== 1)
    json_out(['ok' => false, 'error' => 'OTP đang tắt'], 400);

  $email = trim((string) ($s['email'] ?? ''));
  if (!valid_email($email))
    json_out(['ok' => false, 'error' => 'Chưa cấu hình email nhận OTP hợp lệ'], 400);

  if (!empty($s['last_sent_at'])) {
    $last = strtotime($s['last_sent_at']);
    if ($last && (time() - $last) < OTP_COOLDOWN_SECONDS) {
      $remain = OTP_COOLDOWN_SECONDS - (time() - $last);
      json_out(['ok' => false, 'error' => "Vui lòng đợi {$remain}s rồi thử lại"], 429);
    }
  }

  $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $hash = otp_hash($code);

  $st2 = $pdo->prepare("
    INSERT INTO admin_otp_codes (user_id, purpose, code_hash, expires_at, attempts, max_attempts, ip, user_agent, created_at)
    VALUES (?, 'login', ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0, ?, ?, ?, NOW())
  ");
  $st2->execute([$pending, $hash, OTP_TTL_SECONDS, OTP_MAX_ATTEMPTS, client_ip(), client_ua()]);

  $pdo->prepare("UPDATE admin_otp_settings SET last_sent_at=NOW(), updated_at=NOW() WHERE user_id=?")
    ->execute([$pending]);

  try {
    send_otp_email($pdo, $email, $code);
  } catch (Throwable $e) {
    error_log('[OTP LOGIN SEND FAIL] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Gửi email thất bại: ' . $e->getMessage()], 500);
  }

  log_opt($pending, 'otp.login_send', ['to' => $email]);

  json_out(['ok' => true, 'data' => ['msg' => 'Đã gửi OTP đăng nhập']]);
}


// =========================
// ACTION: verify (login)
// Expect: session có pending_admin_user_id
// =========================
if ($action === 'verify') {
  $pending = pending_user_id();
  if ($pending <= 0)
    json_out(['ok' => false, 'error' => 'Missing pending_admin_user_id'], 400);

  $code = trim((string) ($_POST['code'] ?? ''));
  if (!preg_match('/^\d{6}$/', $code))
    json_out(['ok' => false, 'error' => 'OTP không hợp lệ'], 400);

  $st = $pdo->prepare("
    SELECT id, code_hash, attempts, max_attempts
    FROM admin_otp_codes
    WHERE user_id=? AND purpose='login'
      AND consumed_at IS NULL
      AND expires_at > NOW()
    ORDER BY created_at DESC
    LIMIT 1
  ");
  $st->execute([$pending]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row)
    json_out(['ok' => false, 'error' => 'OTP đã hết hạn hoặc không tồn tại'], 400);

  $attempts = (int) ($row['attempts'] ?? 0);
  $max = (int) ($row['max_attempts'] ?? OTP_MAX_ATTEMPTS);
  if ($attempts >= $max)
    json_out(['ok' => false, 'error' => 'Bạn đã nhập sai quá số lần cho phép'], 429);

  $ok = hash_equals((string) $row['code_hash'], otp_hash($code));
  if (!$ok) {
    $pdo->prepare("UPDATE admin_otp_codes SET attempts=attempts+1 WHERE id=?")->execute([(int) $row['id']]);
    log_opt($pending, 'admin_otp.login_verify_fail');
    json_out(['ok' => false, 'error' => 'OTP sai'], 401);
  }

  // consumed + update verified_at
  $pdo->prepare("UPDATE admin_otp_codes SET consumed_at=NOW() WHERE id=?")->execute([(int) $row['id']]);
  $pdo->prepare("UPDATE admin_otp_settings SET verified_at=NOW(), updated_at=NOW() WHERE user_id=?")->execute([$pending]);

  // mark passed
  $_SESSION['admin_otp_passed_at'] = time();
  clear_pending_user();

  log_opt($pending, 'otp.login_verify_ok');

  json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
