<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';


header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

/* =========================
   LẤY DANH SÁCH QUYỀN
========================= */
if ($action === 'list') {

  $uid = (int) ($_GET['user_id'] ?? 0);
  if ($uid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Thiếu user_id']);
    exit;
  }


  $sql = "
SELECT 
  p.id,
  p.code,
  p.name,
  p.grp,
  p.parent_id,
  p.sort_order,
  parent.sort_order AS parent_sort,
  parent.id AS parent_id2,
  parent.name AS parent_name,

  CASE u.permissions_mode
    WHEN 'custom' THEN IFNULL(up.can_view, 0)
    ELSE IFNULL(rp.can_view, 0)
  END AS can_view,

  CASE u.permissions_mode
    WHEN 'custom' THEN IFNULL(up.can_create, 0)
    ELSE IFNULL(rp.can_create, 0)
  END AS can_create,

  CASE u.permissions_mode
    WHEN 'custom' THEN IFNULL(up.can_update, 0)
    ELSE IFNULL(rp.can_update, 0)
  END AS can_update,

  CASE u.permissions_mode
    WHEN 'custom' THEN IFNULL(up.can_review, 0)
    ELSE IFNULL(rp.can_review, 0)
  END AS can_review,

  CASE u.permissions_mode
    WHEN 'custom' THEN IFNULL(up.can_delete, 0)
    ELSE IFNULL(rp.can_delete, 0)
  END AS can_delete,

  CASE u.permissions_mode
    WHEN 'custom' THEN IFNULL(up.can_print, 0)
    ELSE IFNULL(rp.can_print, 0)
  END AS can_print

FROM permissions p

JOIN users u ON u.id = ?

LEFT JOIN permissions parent
  ON parent.id = p.parent_id

LEFT JOIN role_permissions rp
  ON rp.permission_id = p.id
 AND rp.role_id = u.role_id

LEFT JOIN user_permissions up
  ON up.permission_id = p.id
 AND up.user_id = u.id

ORDER BY
  COALESCE(parent.sort_order, p.sort_order),
  COALESCE(parent.id, p.id),
  (p.parent_id IS NOT NULL),
  p.sort_order,
  p.id
";



  $stmt = $pdo->prepare($sql);
  $stmt->execute([$uid]);
  $mode = $pdo->prepare("SELECT permissions_mode FROM users WHERE id=?");
  $mode->execute([$uid]);
  $permissionsMode = $mode->fetchColumn() ?: 'role';


  echo json_encode([
    'ok' => true,
    'mode' => $permissionsMode,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
  ]);
  exit;

}

if ($action === 'list_by_role') {
  $roleId = (int) ($_GET['role_id'] ?? 0);

  $sql = "
SELECT 
  p.id,
  p.name,
  p.parent_id,
  p.sort_order,

  parent.sort_order AS parent_sort,
  parent.id AS parent_id2,

  COALESCE(rp.can_view, 0)   AS can_view,
  COALESCE(rp.can_create, 0) AS can_create,
  COALESCE(rp.can_update, 0) AS can_update,
  COALESCE(rp.can_review, 0) AS can_review,
  COALESCE(rp.can_delete, 0) AS can_delete,
  COALESCE(rp.can_print, 0)  AS can_print

FROM permissions p

LEFT JOIN permissions parent
  ON parent.id = p.parent_id

LEFT JOIN role_permissions rp
  ON rp.permission_id = p.id
 AND rp.role_id = ?

ORDER BY
  COALESCE(parent.sort_order, p.sort_order),
  COALESCE(parent.id, p.id),
  (p.parent_id IS NOT NULL),
  p.sort_order,
  p.id
";

  $stm = $pdo->prepare($sql);
  $stm->execute([$roleId]);

  echo json_encode([
    'ok' => true,
    'rows' => $stm->fetchAll(PDO::FETCH_ASSOC)
  ]);
  exit;
}



