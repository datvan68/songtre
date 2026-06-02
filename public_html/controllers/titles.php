<?php
// controllers/titles.php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
auth_guard();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
header('Content-Type: application/json; charset=utf-8');

try {
  if ($action === 'list') {
    echo json_encode($pdo->query("SELECT * FROM titles ORDER BY grp, name")->fetchAll()); exit;
  }
  if ($action === 'create' && is_admin()) {
    $pdo->prepare("INSERT INTO titles(code, grp, name, criteria, evidence) VALUES(?,?,?,?,?)")
        ->execute([$_POST['code'],$_POST['grp'],$_POST['name'],$_POST['criteria'],$_POST['evidence']]);
    echo json_encode(['ok'=>1]); exit;
  }
  if ($action === 'update' && is_admin()) {
    $pdo->prepare("UPDATE titles SET grp=?, name=?, criteria=?, evidence=? WHERE id=?")
        ->execute([$_POST['grp'],$_POST['name'],$_POST['criteria'],$_POST['evidence'],(int)$_POST['id']]);
    echo json_encode(['ok'=>1]); exit;
  }
  if ($action === 'delete' && is_admin()) {
    $pdo->prepare("DELETE FROM titles WHERE id=?")->execute([(int)$_POST['id']]);
    echo json_encode(['ok'=>1]); exit;
  }
  echo json_encode(['error'=>'Bad action']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
