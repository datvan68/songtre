<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';

auth_guard();
if (!is_admin()) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_ok($data = null) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => 1, 'data' => $data], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_err($msg, $code = 400) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

function setting_get(PDO $pdo, string $k, $default = null) {
  $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
  $st->execute([$k]);
  $v = $st->fetchColumn();
  return ($v === false || $v === null) ? $default : $v;
}
function setting_set(PDO $pdo, string $k, string $v): void {
  $st = $pdo->prepare("
    INSERT INTO app_settings (k, v) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE v=VALUES(v)
  ");
  $st->execute([$k, $v]);
}

if ($action === 'status') {
  json_ok([
    'enabled'  => (int) setting_get($pdo, 'zalo_oa_enabled', '0'),
    'oaid'     => (string) setting_get($pdo, 'zalo_oa_id', ''),
    'welcome'  => (string) setting_get($pdo, 'zalo_oa_welcome', 'Rất vui khi được hỗ trợ bạn!'),
    'autopopup'=> (int) setting_get($pdo, 'zalo_oa_autopopup', '0'),
  ]);
}

if ($action === 'save') {
  $enabled = (int) ($_POST['enabled'] ?? 0);
  $oaid = trim((string)($_POST['oaid'] ?? ''));
  $welcome = trim((string)($_POST['welcome'] ?? ''));
  $autopopup = (int) ($_POST['autopopup'] ?? 0);

  if ($enabled === 1) {
    if ($oaid === '' || !preg_match('/^\d+$/', $oaid)) {
      json_err('OA ID không hợp lệ (phải là số)');
    }
  }

  if ($welcome === '') $welcome = 'Rất vui khi được hỗ trợ bạn!';
  if ($autopopup !== 0 && $autopopup !== 1) $autopopup = 0;

  setting_set($pdo, 'zalo_oa_enabled', (string)$enabled);
  setting_set($pdo, 'zalo_oa_id', $oaid);
  setting_set($pdo, 'zalo_oa_welcome', $welcome);
  setting_set($pdo, 'zalo_oa_autopopup', (string)$autopopup);

  json_ok(['msg' => 'Đã lưu cấu hình Zalo OA']);
}

json_err('Bad action', 400);
