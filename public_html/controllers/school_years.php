<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_length()) ob_clean();

function json_ok($data = null) {
  echo json_encode([
    'ok' => true,
    'data' => $data
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

function json_err($msg, $code = 400) {
  http_response_code($code);
  echo json_encode([
    'ok' => false,
    'error' => $msg
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';


/* ================= LIST ACTIVE ================= */
if ($action === 'list_active') {
  $rows = $pdo->query("
    SELECT id, year_label
    FROM school_years
    WHERE is_active = 1
    ORDER BY year_label DESC
  ")->fetchAll(PDO::FETCH_ASSOC);

  json_ok($rows);
}


/* ================= LIST ALL ================= */
if ($action === 'list') {
  $rows = $pdo->query("
    SELECT id, year_label, is_active
    FROM school_years
    ORDER BY year_label DESC
  ")->fetchAll(PDO::FETCH_ASSOC);

  json_ok($rows);
}


/* ================= CREATE ================= */
if ($action === 'create') {
  if (!is_admin()) json_err('FORBIDDEN', 403);

  $year = trim($_POST['year_label'] ?? '');
  if (!preg_match('/^\d{4}-\d{4}$/', $year)) {
    json_err('Sai định dạng năm học');
  }

  $stmt = $pdo->prepare("
    INSERT INTO school_years (year_label, is_active)
    VALUES (?, 1)
  ");
  $stmt->execute([$year]);

  json_ok();
}


/* ================= DELETE ================= */
if ($action === 'delete') {
  if (!is_admin()) json_err('FORBIDDEN', 403);

  $id = (int)($_POST['id'] ?? 0);
  if (!$id) json_err('ID không hợp lệ');

  // ❌ chặn xóa nếu đang dùng
  $check = $pdo->prepare("
    SELECT COUNT(*) FROM campaigns WHERE school_year_id = ?
  ");
  $check->execute([$id]);
  if ($check->fetchColumn() > 0) {
    json_err('Năm học đang được sử dụng');
  }

  $pdo->prepare("DELETE FROM school_years WHERE id=?")->execute([$id]);

  json_ok();
}

json_err('Action không hợp lệ');
