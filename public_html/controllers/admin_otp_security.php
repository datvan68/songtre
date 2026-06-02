<?php
// controllers/admin_otp_security.php
// - Admin-only
// - OTP gửi qua Email hệ thống (system_mailer -> SMTP DB)
// - Không bắt buộc @gmail

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

auth_guard();

$allow =
  (function_exists('is_admin') && is_admin()) ||
  (function_exists('is_banchaphanh') && is_banchaphanh());

if (!$allow) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}


$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ===== Config =====
const OTP_TTL_SECONDS = 300;         // 5 phút
const OTP_COOLDOWN_SECONDS = 60;     // 1 phút (theo yêu cầu)
const OTP_MAX_ATTEMPTS = 5;

// SECRET dùng để băm OTP.
// -> hãy thay chuỗi này bằng 1 chuỗi random dài >= 32 ký tự và GIỮ NGUYÊN (không đổi lung tung).
const OTP_SECRET = 'CHANGE_ME_TO_A_RANDOM_LONG_SECRET_STRING_32PLUS';

// ===== Helpers =====
function json_out(array $arr, int $code = 200): void
{
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function get_current_user_id(): int
{
  if (isset($_SESSION['user_id']))
    return (int) $_SESSION['user_id'];
  if (isset($_SESSION['user']['id']))
    return (int) $_SESSION['user']['id'];
  if (function_exists('current_user')) {
    $u = current_user();
    if (is_array($u) && isset($u['id']))
      return (int) $u['id'];
    if (is_object($u) && isset($u->id))
      return (int) $u->id;
  }
  return 0;
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
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  return substr($ip, 0, 45);
}

function client_ua(): string
{
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  return substr($ua, 0, 255);
}

function log_opt(int $userId, string $action, array $meta = []): void
{
  // ưu tiên dùng log_activity nếu project có
  if (function_exists('log_activity')) {
    try {
      log_activity($action, 'admin_otp', 'user', $userId, json_encode($meta, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
      // ignore
    }
  }
}

function otp_purpose_label(string $purposeLabel): string
{
  $k = strtolower(trim($purposeLabel));

  // map các label "kỹ thuật" sang nhãn hiển thị đúng nghĩa
  if ($k === 'test' || $k === 'verify_test' || $k === 'verify' || $k === 'email') {
    return 'Xác minh email nhận OTP';
  }
  if ($k === 'login') {
    return 'Đăng nhập Admin';
  }

  // fallback: nếu ai đó truyền tiếng Việt sẵn thì giữ nguyên
  return $purposeLabel ?: 'OTP';
}

function send_otp_email(PDO $pdo, string $toEmail, string $code, string $purposeLabel): void
{
  $mail = system_mailer($pdo);

  $mail->clearAddresses();
  $mail->addAddress($toEmail);

  $label = otp_purpose_label($purposeLabel);

  $mail->Subject = "[DoanThanhNien] OTP - {$label}";
  $mail->Body = "Mã OTP của bạn là: {$code}\nHết hạn sau 5 phút.";
  $mail->AltBody = "OTP: {$code} (hết hạn 5 phút)";

  $mail->send();
}


// ===== Main =====
$uid = get_current_user_id();
if ($uid <= 0)
  json_out(['ok' => false, 'error' => 'Unauthenticated'], 401);

$allowedModes = ['login', 'session', '3d', '7d'];

/* =========================
   ACTION: status
========================= */
if ($action === 'status') {
  $st = $pdo->prepare("SELECT enabled,email,mode,verified_at,last_sent_at FROM admin_otp_settings WHERE user_id=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $enabled = (int) ($row['enabled'] ?? 0);
  $verifiedAt = $row['verified_at'] ?? null;

  json_out([
    'ok' => true,
    'data' => [
      'enabled' => $enabled,
      'email' => (string) ($row['email'] ?? ''),
      'mode' => (string) ($row['mode'] ?? 'login'),
      'verified' => $verifiedAt ? 1 : 0,
      'verified_at' => $verifiedAt ?: null,
      'last_sent_at' => $row['last_sent_at'] ?? null,
    ]
  ]);
}

/* =========================
   ACTION: save
========================= */
if ($action === 'save') {
  $enabled = (int) ($_POST['enabled'] ?? 0);
  $email = trim((string) ($_POST['email'] ?? ''));
  $mode = trim((string) ($_POST['mode'] ?? 'login'));

  if (!in_array($mode, $allowedModes, true)) {
    json_out(['ok' => false, 'error' => 'Mode không hợp lệ']);
  }

  if ($enabled === 1) {
    if (!valid_email($email)) {
      json_out(['ok' => false, 'error' => 'Vui lòng nhập email nhận OTP hợp lệ']);
    }
  } else {
    // tắt thì cho rỗng
    if ($email !== '' && !valid_email($email)) {
      json_out(['ok' => false, 'error' => 'Email không hợp lệ']);
    }
  }

  // lấy email cũ để nếu đổi email -> reset verified_at
  $st0 = $pdo->prepare("SELECT email FROM admin_otp_settings WHERE user_id=? LIMIT 1");
  $st0->execute([$uid]);
  $old = $st0->fetch(PDO::FETCH_ASSOC);
  $oldEmail = trim((string) ($old['email'] ?? ''));

  $resetVerified = ($enabled === 1 && $email !== '' && $oldEmail !== '' && strcasecmp($oldEmail, $email) !== 0);

  $st = $pdo->prepare("
    INSERT INTO admin_otp_settings (user_id, enabled, email, mode, verified_at, created_at, updated_at)
    VALUES (?, ?, ?, ?, NULL, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      enabled=VALUES(enabled),
      email=VALUES(email),
      mode=VALUES(mode),
      verified_at = IF(?, NULL, verified_at),
      updated_at=NOW()
  ");
  $st->execute([
    $uid,
    $enabled,
    ($email !== '' ? $email : null),
    $mode,
    $resetVerified ? 1 : 0
  ]);

  // nếu tắt thì xoá verified/session ok
  if ($enabled !== 1) {
    unset($_SESSION['admin_otp_session_ok']);
    $pdo->prepare("UPDATE admin_otp_settings SET verified_at=NULL WHERE user_id=?")->execute([$uid]);
  }

  log_opt($uid, 'admin_otp.save', ['enabled' => $enabled, 'mode' => $mode, 'email' => $email]);

  json_out(['ok' => true]);
}

/* =========================
   ACTION: disable
========================= */
if ($action === 'disable') {
  $st = $pdo->prepare("
    INSERT INTO admin_otp_settings (user_id, enabled, verified_at, created_at, updated_at)
    VALUES (?, 0, NULL, NOW(), NOW())
    ON DUPLICATE KEY UPDATE enabled=0, verified_at=NULL, updated_at=NOW()
  ");
  $st->execute([$uid]);

  unset($_SESSION['admin_otp_session_ok']);

  log_opt($uid, 'admin_otp.disable');

  json_out(['ok' => true]);
}

/* =========================
   ACTION: send_test
========================= */
if ($action === 'send_test') {
  $st = $pdo->prepare("SELECT enabled,email,last_sent_at FROM admin_otp_settings WHERE user_id=? LIMIT 1");
  $st->execute([$uid]);
  $s = $st->fetch(PDO::FETCH_ASSOC);

  if (!$s || (int) $s['enabled'] !== 1)
    json_out(['ok' => false, 'error' => 'OTP đang tắt']);
  $email = trim((string) ($s['email'] ?? ''));
  if (!valid_email($email))
    json_out(['ok' => false, 'error' => 'Chưa cấu hình email nhận OTP hợp lệ']);

  // cooldown 5 phút
  if (!empty($s['last_sent_at'])) {
    $last = strtotime($s['last_sent_at']);
    if ($last && (time() - $last) < OTP_COOLDOWN_SECONDS) {
      $remain = OTP_COOLDOWN_SECONDS - (time() - $last);
      json_out(['ok' => false, 'error' => "Vui lòng đợi {$remain}s rồi thử lại"], 429);
    }
  }

  $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $hash = otp_hash($code);

  $purpose = 'verify_email';

  $st2 = $pdo->prepare("
  INSERT INTO admin_otp_codes (user_id, purpose, code_hash, expires_at, attempts, max_attempts, ip, user_agent, created_at)
  VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0, ?, ?, ?, NOW())
");
  $st2->execute([$uid, $purpose, $hash, OTP_TTL_SECONDS, OTP_MAX_ATTEMPTS, client_ip(), client_ua()]);

  $pdo->prepare("UPDATE admin_otp_settings SET last_sent_at=NOW(), updated_at=NOW() WHERE user_id=?")->execute([$uid]);

  try {
    send_otp_email($pdo, $email, $code, $purpose); // hoặc truyền thẳng 'Xác minh email nhận OTP'
  } catch (Throwable $e) {
    error_log('[OTP SEND FAIL] ' . $e->getMessage());
    // Lưu ý: lỗi SMTP authenticate thường do Username/Password (App Password) sai
    json_out(['ok' => false, 'error' => 'Gửi email thất bại: ' . $e->getMessage()]);
  }

  log_opt($uid, 'admin_otp.send_test', ['to' => $email]);

  json_out(['ok' => true, 'data' => ['msg' => 'Đã gửi OTP xác minh']]);
}

/* =========================
   ACTION: verify_test
========================= */
if ($action === 'verify_test') {
  $code = trim((string) ($_POST['code'] ?? ''));
  if (!preg_match('/^\d{6}$/', $code))
    json_out(['ok' => false, 'error' => 'OTP không hợp lệ']);

  $st = $pdo->prepare("
    SELECT id, code_hash, attempts, max_attempts
    FROM admin_otp_codes
WHERE user_id=? AND purpose='verify_email'
      AND consumed_at IS NULL
      AND expires_at > NOW()
    ORDER BY created_at DESC
    LIMIT 1
  ");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row)
    json_out(['ok' => false, 'error' => 'OTP đã hết hạn hoặc không tồn tại, vui lòng gửi lại']);

  $attempts = (int) ($row['attempts'] ?? 0);
  $max = (int) ($row['max_attempts'] ?? OTP_MAX_ATTEMPTS);
  if ($attempts >= $max)
    json_out(['ok' => false, 'error' => 'Bạn đã nhập sai quá số lần cho phép']);

  $ok = hash_equals((string) $row['code_hash'], otp_hash($code));
  if (!$ok) {
    $pdo->prepare("UPDATE admin_otp_codes SET attempts=attempts+1 WHERE id=?")->execute([(int) $row['id']]);
    log_opt($uid, 'admin_otp.verify_test_fail');
    json_out(['ok' => false, 'error' => 'OTP sai'], 401);
  }

  $pdo->prepare("UPDATE admin_otp_codes SET consumed_at=NOW() WHERE id=?")->execute([(int) $row['id']]);
  $pdo->prepare("UPDATE admin_otp_settings SET verified_at=NOW(), updated_at=NOW() WHERE user_id=?")->execute([$uid]);

  $_SESSION['admin_otp_session_ok'] = 1;

  log_opt($uid, 'admin_otp.verify_test_ok');

  json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 400);
