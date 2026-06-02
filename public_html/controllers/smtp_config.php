<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
// controllers/smtp_config.php
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (preg_match('/curl|wget|python|sqlmap|nikto|masscan/i', $ua)) {
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/activity_log.php';
require_once __DIR__ . '/../config/smtp_secret.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => 0,
            'error' => 'PHP fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

auth_guard();
if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = trim($action);

function j_ok($data = [])
{
    echo json_encode(['ok' => 1, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function j_err($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function smtp_key_bytes(): string
{
    if (!defined('SMTP_ENC_KEY') || strlen((string) SMTP_ENC_KEY) < 16) {
        j_err('Thiếu SMTP_ENC_KEY (config/smtp_secret.php)', 500);
    }
    return hash('sha256', (string) SMTP_ENC_KEY, true); // 32 bytes
}

function enc_secret(string $plain): string
{
    $key = smtp_key_bytes();
    $iv = random_bytes(12); // GCM recommended 12 bytes
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false)
        j_err('Không mã hoá được dữ liệu (openssl)', 500);
    return base64_encode($iv . $tag . $ct);
}

function dec_secret(?string $payload): string
{
    if (!$payload)
        return '';
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 12 + 16)
        return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $key = smtp_key_bytes();
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return ($pt === false) ? '' : $pt;
}

function get_row(PDO $pdo): array
{
    $st = $pdo->query("SELECT * FROM system_smtp_settings WHERE id=1 LIMIT 1");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) {
        // tạo row mặc định nếu chưa có (ĐÚNG BẢNG)
        $pdo->exec("
        INSERT INTO system_smtp_settings
            (id, host, port, encryption, username, password_enc, from_email, from_name, updated_at)
        VALUES
            (1, '', 587, 'tls', '', NULL, '', '', NOW())
        ON DUPLICATE KEY UPDATE id = id
    ");

        // đọc lại cho chắc
        $st = $pdo->query("SELECT * FROM system_smtp_settings WHERE id=1 LIMIT 1");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    }
    return $row ?: [
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password_enc' => null,
        'from_email' => '',
        'from_name' => '',
        'updated_at' => null
    ];

}

function norm_enc(string $enc): string
{
    $enc = strtolower(trim($enc));
    if ($enc === 'tls' || $enc === 'ssl' || $enc === 'none')
        return $enc;
    return 'tls';
}

function valid_email(string $s): bool
{
    return (bool) filter_var($s, FILTER_VALIDATE_EMAIL);
}

/* =========================
   STATUS
========================= */
if ($action === 'status') {
    $row = get_row($pdo);
    j_ok([
        'host' => (string) ($row['host'] ?? ''),
        'port' => (string) ($row['port'] ?? ''),
        'encryption' => (string) ($row['encryption'] ?? 'tls'),
        'username' => (string) ($row['username'] ?? ''),
        'from_email' => (string) ($row['from_email'] ?? ''),
        'from_name' => (string) ($row['from_name'] ?? ''),
        'has_password' => !empty($row['password_enc']) ? 1 : 0,
        'updated_at' => $row['updated_at'] ?? null,
    ]);
}

/* =========================
   SAVE
========================= */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? '');
    $port = (int) ($_POST['port'] ?? 0);
    $enc = norm_enc($_POST['encryption'] ?? 'tls');
    $user = trim($_POST['username'] ?? '');
    $fromEmail = trim($_POST['from_email'] ?? '');
    $fromName = trim($_POST['from_name'] ?? '');
    $pass = (string) ($_POST['password'] ?? ''); // trống => giữ nguyên

    if ($host === '' || $port < 1 || $port > 65535 || $user === '' || $fromEmail === '') {
        j_err('Vui lòng nhập Host, Port, Username và From email.');
    }
    if (!valid_email($fromEmail))
        j_err('From email không hợp lệ.');
    if (!valid_email($user)) {
        // nhiều SMTP vẫn dùng email làm username, nhưng không bắt buộc 100%
        // bạn muốn nới lỏng thì comment dòng này đi
        // j_err('SMTP Username không hợp lệ.');
    }

    $row = get_row($pdo);
    $password_enc = $row['password_enc'] ?? null;

    if (trim($pass) !== '') {
        $password_enc = enc_secret($pass);
    }
    get_row($pdo); // ensure row exists

    $stmt = $pdo->prepare("
    UPDATE system_smtp_settings
    SET host=?, port=?, encryption=?, username=?, password_enc=?, from_email=?, from_name=?, updated_at=NOW()
    WHERE id=1
  ");
    $stmt->execute([$host, $port, $enc, $user, $password_enc, $fromEmail, $fromName]);

    log_activity('smtp_save', 'system', 'system', 1, 'Cập nhật cấu hình SMTP');

    j_ok(['msg' => 'Saved']);
}

/* =========================
   TEST SEND
========================= */
if ($action === 'test') {
    $to = trim($_GET['to'] ?? '');
    if ($to === '' || !valid_email($to))
        j_err('Email nhận test không hợp lệ.');

    $row = get_row($pdo);
    if (empty($row['host']) || empty($row['port']) || empty($row['username']) || empty($row['from_email'])) {
        j_err('Chưa cấu hình SMTP đầy đủ.');
    }
    if (empty($row['password_enc'])) {
        j_err('Chưa có mật khẩu SMTP (App Password / SMTP Password).');
    }

    $smtpPass = dec_secret($row['password_enc']);
    if ($smtpPass === '')
        j_err('Không giải mã được mật khẩu SMTP. Kiểm tra SMTP_ENC_KEY.', 500);

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $row['host'];
        $mail->Port = (int) $row['port'];

        $enc = strtolower($row['encryption'] ?? 'tls');
        if ($enc === 'ssl')
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        else if ($enc === 'tls')
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        else
            $mail->SMTPSecure = false;

        $mail->SMTPAuth = true;
        $mail->Username = $row['username'];
        $mail->Password = $smtpPass;

        $fromName = $row['from_name'] ?: 'DoanThanhNien System';
        $mail->setFrom($row['from_email'], $fromName);

        $mail->addAddress($to);
        $mail->Subject = 'SMTP Test - DoanThanhNien';
        $mail->Body = "SMTP OK. Time: " . date('Y-m-d H:i:s');

        $mail->send();

        log_activity('smtp_test_ok', 'system', 'system', 1, 'Test SMTP OK: ' . $to);
        j_ok(['msg' => 'Gửi email test thành công tới ' . $to]);
    } catch (Throwable $e) {
        log_activity('smtp_test_fail', 'system', 'system', 1, 'Test SMTP FAIL: ' . $e->getMessage());
        j_err('Gửi email thất bại. Chi tiết: ' . $e->getMessage(), 500);
    }
}

/* =========================
   RESET (optional)
========================= */
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("
    UPDATE system_smtp_settings
    SET host='smtp.gmail.com', port=587, encryption='tls',
        username='', password_enc=NULL, from_email='', from_name='DoanThanhNien System', updated_at=NOW()
    WHERE id=1
  ");
    $stmt->execute();
    log_activity('smtp_reset', 'system', 'system', 1, 'Reset SMTP');
    j_ok(['msg' => 'Reset']);
}

j_err('Invalid action', 400);
