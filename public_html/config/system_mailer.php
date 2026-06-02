<?php
// config/system_mailer.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp_secret.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

function smtp_key_bytes(): string
{
    return hash('sha256', (string) SMTP_ENC_KEY, true);
}

function dec_secret(?string $payload): string
{
    if (!$payload)
        return '';
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 28)
        return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $key = smtp_key_bytes();
    $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return ($pt === false) ? '' : $pt;
}

function system_smtp_get(PDO $pdo): array
{
    $st = $pdo->query("SELECT * FROM system_smtp_settings WHERE id=1 LIMIT 1");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row)
        return [];
    return $row;
}

function system_mailer(PDO $pdo): PHPMailer
{
    $row = system_smtp_get($pdo);
    if (empty($row['host']) || empty($row['port']) || empty($row['username']) || empty($row['from_email'])) {
        throw new RuntimeException('Chưa cấu hình SMTP đầy đủ');
    }
    if (empty($row['password_enc'])) {
        throw new RuntimeException('Chưa có mật khẩu SMTP');
    }

    $pass = dec_secret($row['password_enc']);
    if ($pass === '')
        throw new RuntimeException('Không giải mã được mật khẩu SMTP');

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isSMTP();
    $mail->SMTPDebug = 0;

    $mail->Host = $row['host'];
    $mail->Port = (int) $row['port'];
    $mail->SMTPAuth = true;
    $mail->Username = $row['username'];
    $mail->Password = $pass;

    $enc = strtolower($row['encryption'] ?? 'tls');
    if ($enc === 'ssl')
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    else if ($enc === 'tls')
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    else
        $mail->SMTPSecure = false;

    $fromName = $row['from_name'] ?: 'DoanThanhNien System';
    $mail->setFrom($row['from_email'], $fromName);

    return $mail;
}
