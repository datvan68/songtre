<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';
auth_guard('admin');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'list') {
  $rows = $pdo->query("
    SELECT id, code, label
    FROM attachment_types
    ORDER BY sort_order ASC, id ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok' => 1, 'data' => $rows]);
  exit;
}

if ($action === 'add') {
  $label = trim($_POST['label'] ?? '');
  if ($label === '') {
    echo json_encode(['ok' => 0, 'error' => 'Tên không hợp lệ']);
    exit;
  }

  $code = 'file_' . time();

  $stm = $pdo->prepare("
    INSERT INTO attachment_types (code, label)
    VALUES (?, ?)
  ");
  $stm->execute([$code, $label]);

  echo json_encode(['ok' => 1]);
  exit;
}

if ($action === 'update') {
  $id = (int) $_POST['id'];
  $label = trim($_POST['label']);

  $stm = $pdo->prepare("
    UPDATE attachment_types SET label=? WHERE id=?
  ");
  $stm->execute([$label, $id]);

  echo json_encode(['ok' => 1]);
  exit;
}

if ($action === 'delete') {
  $id = (int) $_POST['id'];

  $pdo->prepare("DELETE FROM attachment_types WHERE id=?")->execute([$id]);

  echo json_encode(['ok' => 1]);
  exit;
}
