<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/activity_log.php';
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
function json_ok($data = null)
{
  echo json_encode([
    'ok' => true,
    'data' => $data
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
const BITHU_ROLE_ID = "3";
const GVCN_ROLE_ID = "6";
function json_error($msg, $code = 400)
{
  http_response_code($code);
  echo json_encode([
    'ok' => false,
    'error' => $msg
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function getUserInfo(PDO $pdo, int $id): array
{
  $stmt = $pdo->prepare("
    SELECT u.username, u.fullname, r.name AS role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.id=?
    LIMIT 1
  ");
  $stmt->execute([$id]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}


function is_scoped_role(PDO $pdo, int $userId): bool
{
  $stmt = $pdo->prepare("
    SELECT r.name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id=?
    LIMIT 1
  ");
  $stmt->execute([$userId]);

  return in_array(
    strtolower($stmt->fetchColumn() ?? ''),
    ['bithu', 'gvcn'],
    true
  );
}
function get_user_scope(PDO $pdo, int $userId): ?array
{
  $stmt = $pdo->prepare("
SELECT chidoan_group_id, department_id, course_id, class_id
FROM bithu_scopes
WHERE user_id=?
ORDER BY id

  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row)
    return $row;

  // fallback members
  $stmt = $pdo->prepare("
    SELECT 
SELECT 
  chidoan_group_id,
  department_id,
  course_id,
  class_id
FROM members
WHERE user_id=?
LIMIT 1

  ");
  $stmt->execute([$userId]);

  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function save_gvcn_classes(PDO $pdo, int $userId, array $data)
{

  // ❗ XÓA HẾT CŨ
  $pdo->prepare("DELETE FROM gvcn_classes WHERE user_id=?")
    ->execute([$userId]);

  $classIds = $data['class_ids'] ?? [];

  if (!is_array($classIds))
    return;

  $stmt = $pdo->prepare("
    INSERT INTO gvcn_classes (user_id, class_id)
    VALUES (?, ?)
  ");

  foreach ($classIds as $cid) {
    $cid = (int) $cid;
    if ($cid <= 0)
      continue;

    $stmt->execute([$userId, $cid]);
  }
}

function save_bithu_scope(PDO $pdo, int $userId, array $post)
{
  $roleId = (int) ($post['role_id'] ?? 0);
  $group = (int) ($post['chidoan_group_id'] ?? 0);

  $pdo->prepare("DELETE FROM bithu_scopes WHERE user_id=?")
    ->execute([$userId]);

  // ===== GVCN =====
  if ($roleId === 6) {
    $classes = $post['class_ids'] ?? [];
    if (!is_array($classes) || count($classes) === 0) {
      throw new Exception("GVCN phải chọn ít nhất 1 lớp");
    }

    $stmt = $pdo->prepare("
      INSERT INTO bithu_scopes
        (user_id, chidoan_group_id, department_id, course_id, class_id)
      VALUES (?,?,?,?,?)
    ");

    foreach ($classes as $cid) {
      $stmt->execute([
        $userId,
        1,
        (int) $post['department_id'],
        (int) $post['course_id'],
        (int) $cid
      ]);
    }
    return;
  }

  // ===== BÍ THƯ – CHI ĐOÀN GIÁO VIÊN =====
  if ($group === 2) {
    // ❗ KHÔNG LƯU SCOPE CHI TIẾT
    $pdo->prepare("
    INSERT INTO bithu_scopes (user_id, chidoan_group_id)
    VALUES (?,2)
  ")->execute([$userId]);

    return;
  }


  // ===== BÍ THƯ – CHI ĐOÀN LỚP =====
  if ($group === 1) {
    $class = (int) ($post['class_id'] ?? 0);
    if (!$class) {
      throw new Exception("Bí thư chi đoàn lớp phải chọn lớp");
    }

    $pdo->prepare("
      INSERT INTO bithu_scopes
        (user_id, chidoan_group_id, department_id, course_id, class_id)
      VALUES (?,?,?,?,?)
    ")->execute([
          $userId,
          1,
          (int) $post['department_id'],
          (int) $post['course_id'],
          $class
        ]);
  }
}



function hasCustomPerms(array $perms): bool
{
  foreach ($perms as $p) {
    if (
      !empty($p['view']) ||
      !empty($p['create']) ||
      !empty($p['update']) ||
      !empty($p['review']) ||
      !empty($p['delete']) ||
      !empty($p['print'])
    ) {
      return true;
    }
  }
  return false;
}


function forbidden()
{
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$action = $_GET['action'] ?? '';

/**
 * Copy toàn bộ quyền từ role sang user
 * @param PDO $pdo
 * @param int $roleId
 * @param int $userId
 * @return void
 */
function copy_role_permissions_to_user(PDO $pdo, int $roleId, int $userId)
{
  $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")
    ->execute([$userId]);

  $pdo->prepare("
    INSERT INTO user_permissions
      (user_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
    SELECT
      ?, permission_id,
      can_view, can_create, can_update, can_review, can_delete, can_print
    FROM role_permissions
    WHERE role_id=?
  ")->execute([$userId, $roleId]);
}




function user_has_member(PDO $pdo, int $userId): bool
{
  $stm = $pdo->prepare("SELECT COUNT(*) FROM members WHERE user_id=?");
  $stm->execute([$userId]);
  return $stm->fetchColumn() > 0;
}


if ($action === 'get_bithu_scope') {
  $uid = (int) ($_GET['user_id'] ?? 0);
  if (!$uid)
    json_error("Invalid user");

  // 1️⃣ ƯU TIÊN bithu_scopes
  $stmt = $pdo->prepare("
        SELECT chidoan_group_id, department_id, course_id, class_id
        FROM bithu_scopes
        WHERE user_id = ?
        LIMIT 1
    ");
  $stmt->execute([$uid]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    json_ok($row); // ✅ EXIT LUÔN
  }

  // 2️⃣ FALLBACK → members
  $stmt = $pdo->prepare("
SELECT 
  m.chidoan_group_id,
  m.department_id,
  m.course_id,
  m.class_id
FROM members m
WHERE m.user_id = ?
LIMIT 1


    ");
  $stmt->execute([$uid]);
  $member = $stmt->fetch(PDO::FETCH_ASSOC);

  json_ok($member ?: []);
}




/* =====================================================
   UPDATE ĐƠN GIẢN
===================================================== */
if ($action === 'update') {

  if (!can('permissions', 'update'))
    forbidden();


  $id = (int) ($_POST['id'] ?? 0);
  $username = trim($_POST['username'] ?? '');
  $password = trim($_POST['password'] ?? '');
  $roleId = (int) ($_POST['role_id'] ?? 0);
  $fullname = trim($_POST['fullname'] ?? '');

  if (!$id || !$username || !$roleId) {
    echo json_encode(['ok' => false, 'error' => 'Thiếu dữ liệu']);
    exit;
  }

  try {
    $hasMember = user_has_member($pdo, $id);

    // ===== UPDATE USER =====
    if ($password !== '') {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      if ($hasMember) {
        $stmt = $pdo->prepare("
      UPDATE users SET username=?, password_hash=?, role_id=? WHERE id=?
    ");
        $stmt->execute([$username, $hash, $roleId, $id]);
      } else {
        $stmt = $pdo->prepare("
      UPDATE users SET username=?, fullname=?, password_hash=?, role_id=? WHERE id=?
    ");
        $stmt->execute([$username, $fullname, $hash, $roleId, $id]);
      }
    } else {
      if ($hasMember) {
        $stmt = $pdo->prepare("
      UPDATE users SET username=?, role_id=? WHERE id=?
    ");
        $stmt->execute([$username, $roleId, $id]);
      } else {
        $stmt = $pdo->prepare("
      UPDATE users SET username=?, fullname=?, role_id=? WHERE id=?
    ");
        $stmt->execute([$username, $fullname, $roleId, $id]);
      }
    }
    unset($_SESSION['user_cache']);
    $oldInfo = getUserInfo($pdo, $id);

    if ($roleId == BITHU_ROLE_ID) {
      save_bithu_scope($pdo, $id, $_POST);
    }

    if ($roleId == GVCN_ROLE_ID) {
      save_gvcn_classes($pdo, $id, $_POST);
    }



    log_activity(
      'update',
      'permissions',
      'Tài khoản',
      null,
      'Cập nhật thông tin tài khoản: ' . ($oldInfo['username'] ?? 'Không rõ')
    );
    echo json_encode(['ok' => true]);

  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}


/* =====================================================
   DELETE USER
===================================================== */
if ($action === 'delete') {
  if (!can('permissions', 'delete'))
    forbidden();

  $id = (int) ($_POST['id'] ?? 0);

  if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'Thiếu ID']);
    exit;
  }
  // LẤY INFO TRƯỚC
  $info = getUserInfo($pdo, $id);
  try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
    $stmt->execute([$id]);
    unset($_SESSION['user_cache']);
    log_activity(
      'delete',
      'permissions',
      'Tài khoản',
      null,
      'Xóa tài khoản: ' . ($info['username'] ?? 'Không rõ')
    );

    echo json_encode(['ok' => true]);

  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

/* =====================================================
   CREATE USER (TÀI KHOẢN TẠO TAY)
===================================================== */
if ($action === 'create') {
  if (!can('permissions', 'create'))
    forbidden();

  $username = trim($_POST['username'] ?? '');
  $fullname = trim($_POST['fullname'] ?? '');
  $password = trim($_POST['password'] ?? '');
  $roleId = (int) ($_POST['role_id'] ?? 0);

  if (!$username || !$fullname || !$password || !$roleId) {
    echo json_encode(['ok' => false, 'error' => 'Thiếu dữ liệu']);
    exit;
  }

  try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?");
    $check->execute([$username]);
    if ($check->fetchColumn() > 0) {
      throw new Exception("Tài khoản đã tồn tại");
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    /* tạo user */
    $pdo->prepare("
  INSERT INTO users (username, fullname, password_hash, role_id, permissions_mode)
  VALUES (?,?,?,?, 'role')
")->execute([$username, $fullname, $hash, $roleId]);

    $userId = (int) $pdo->lastInsertId();

    /* tạo profile */
    $pdo->prepare("
  INSERT INTO user_profiles (user_id)
  VALUES (?)
")->execute([$userId]);

    /* copy quyền */
    copy_role_permissions_to_user($pdo, $roleId, $userId);

    /* 🔥 LƯU SCOPE NGAY LÚC TẠO */
    if ($roleId == BITHU_ROLE_ID) {
      save_bithu_scope($pdo, $userId, $_POST);
    }

    if ($roleId == GVCN_ROLE_ID) {
      save_gvcn_classes($pdo, $userId, $_POST);
    }

    $pdo->commit();


    unset($_SESSION['user_cache']);

    log_activity(
      'create',
      'permissions',
      'Tài khoản',
      null,
      'Tạo tài khoản: ' . $username
    );

    echo json_encode(['ok' => true]);


  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}


/* =====================================================
   UPDATE FULL (USER + PERMISSIONS)
===================================================== */
if ($action === 'update_full') {
  if (!can('permissions', 'update'))
    forbidden();

  $pdo->beginTransaction();

  try {
    $id = (int) $_POST['id'];
    $username = trim($_POST['username']);
    $password = trim($_POST['password'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $fullname = trim($_POST['fullname'] ?? '');
    $perms = $_POST['perms'] ?? [];

    if (!$id || !$username || !$roleId) {
      throw new Exception("Thiếu dữ liệu");
    }

    $hasMember = user_has_member($pdo, $id);

    // ===== UPDATE USER =====
    if ($password !== '') {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      if ($hasMember) {
        $pdo->prepare("
          UPDATE users SET username=?, password_hash=?, role_id=? WHERE id=?
        ")->execute([$username, $hash, $roleId, $id]);
      } else {
        $pdo->prepare("
          UPDATE users SET username=?, fullname=?, password_hash=?, role_id=? WHERE id=?
        ")->execute([$username, $fullname, $hash, $roleId, $id]);
      }
    } else {
      if ($hasMember) {
        $pdo->prepare("
          UPDATE users SET username=?, role_id=? WHERE id=?
        ")->execute([$username, $roleId, $id]);
      } else {
        $pdo->prepare("
          UPDATE users SET username=?, fullname=?, role_id=? WHERE id=?
        ")->execute([$username, $fullname, $roleId, $id]);
      }
    }

    // ===== QUYỀN =====
    $hasCustom = hasCustomPerms($perms);

    if ($hasCustom) {

      // 👉 chuyển sang custom
      $pdo->prepare("
    UPDATE users 
    SET permissions_mode='custom' 
    WHERE id=?
  ")->execute([$id]);

      $pdo->prepare("DELETE FROM user_permissions WHERE user_id=?")
        ->execute([$id]);

      $stmt = $pdo->prepare("
    INSERT INTO user_permissions
      (user_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
    VALUES (?,?,?,?,?,?,?,?)
  ");

      foreach ($perms as $pid => $p) {
        if (
          empty($p['view']) &&
          empty($p['create']) &&
          empty($p['update']) &&
          empty($p['review']) &&
          empty($p['delete']) &&
          empty($p['print'])
        ) {
          continue; // bỏ permission rỗng
        }

        $stmt->execute([
          $id,
          (int) $pid,
          (int) !empty($p['view']),
          (int) !empty($p['create']),
          (int) !empty($p['update']),
          (int) !empty($p['review']),
          (int) !empty($p['delete']),
          (int) !empty($p['print']),
        ]);
      }

    } else {

      // 👉 reset về role
      $pdo->prepare("
    UPDATE users 
    SET permissions_mode='role' 
    WHERE id=?
  ")->execute([$id]);

      $pdo->prepare("
    DELETE FROM user_permissions WHERE user_id=?
  ")->execute([$id]);

      copy_role_permissions_to_user($pdo, $roleId, $id);
    }


    unset($_SESSION['user_cache']);
    $hasCustom = hasCustomPerms($perms);

    if ($roleId == BITHU_ROLE_ID) {
      save_bithu_scope($pdo, $id, $_POST);
    }

    if ($roleId == GVCN_ROLE_ID) {
      save_gvcn_classes($pdo, $id, $_POST);
    }


    if ($hasCustom) {
      log_activity(
        'update',
        'permissions',
        'Tài khoản',
        null,
        'Chuyển quyền sang TÙY CHỈNH cho tài khoản: ' . $username
      );
    } else {
      log_activity(
        'update',
        'permissions',
        'Tài khoản',
        null,
        'Khôi phục quyền theo ROLE cho tài khoản: ' . $username
      );
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// ===============================
// GET GVCN SCOPE (MULTI CLASS)
// ===============================
if ($action === 'get_gvcn_scope') {
  auth_guard();

  $userId = (int) ($_GET['user_id'] ?? 0);
  if (!$userId)
    json_error("Invalid user");

  $stmt = $pdo->prepare("
    SELECT 
      bs.class_id,
      c.name AS class_name,
      c.course_id,
      co.name AS course_name,
      d.id AS department_id,
      d.name AS department_name
    FROM bithu_scopes bs
    JOIN classes c ON c.id = bs.class_id
    JOIN courses co ON co.id = c.course_id
    JOIN departments d ON d.id = c.department_id
    WHERE bs.user_id = ?
  ");
  $stmt->execute([$userId]);

  json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($action === 'get_gvcn_classes') {
  auth_guard();

  $userId = (int) ($_GET['user_id'] ?? 0);
  if (!$userId)
    json_error("Thiếu user");

  // 🔥 LẤY NHÓM CHI ĐOÀN TỪ MEMBERS
  $stmt = $pdo->prepare("
    SELECT chidoan_group_id
    FROM members
    WHERE user_id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $chidoanGroupId = (int) ($stmt->fetchColumn() ?: 1);

  // LẤY LỚP PHỤ TRÁCH
  $stmt = $pdo->prepare("
    SELECT
      c.id,
      c.name,
      c.department_id,
      c.course_id
    FROM gvcn_classes gc
    JOIN classes c ON c.id = gc.class_id
    WHERE gc.user_id = ?
  ");
  $stmt->execute([$userId]);

  json_ok([
    'chidoan_group_id' => $chidoanGroupId,
    'classes' => $stmt->fetchAll(PDO::FETCH_ASSOC)
  ]);
}


if ($action === 'courses') {
  if (!can('permissions', 'view'))
    forbidden();

  $stmt = $pdo->query("
    SELECT id, name
    FROM courses
    ORDER BY name
  ");

  echo json_encode([
    'ok' => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
  ]);
  exit;
}
if ($action === 'departments') {
  if (!can('permissions', 'view'))
    forbidden();

  $type = $_GET['type'] ?? '';

  if (!in_array($type, ['khoa', 'phong'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Type không hợp lệ']);
    exit;
  }

  $stmt = $pdo->prepare("
    SELECT id, name
    FROM departments
    WHERE type=?
    ORDER BY name
  ");
  $stmt->execute([$type]);

  echo json_encode([
    'ok' => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
  ]);
  exit;
}
if ($action === 'classes') {
  if (!can('permissions', 'view'))
    forbidden();

  $deptId = (int) ($_GET['department_id'] ?? 0);
  $courseId = (int) ($_GET['course_id'] ?? 0);

  if (!$deptId || !$courseId) {
    echo json_encode(['ok' => true, 'rows' => []]);
    exit;
  }

  $stmt = $pdo->prepare("
    SELECT id, name
    FROM classes
    WHERE department_id=? AND course_id=?
    ORDER BY name
  ");
  $stmt->execute([$deptId, $courseId]);

  echo json_encode([
    'ok' => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
  ]);
  exit;
}
