<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/activity_log.php';
auth_guard(); // bắt đăng nhập

$action = $_GET['action'] ?? $_POST['action'] ?? '';


function getMssvByUserId(PDO $pdo, int $userId): string
{
  $stmt = $pdo->prepare("
    SELECT m.mssv
    FROM users u
    JOIN members m ON m.mssv = u.username
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);

  return $stmt->fetchColumn() ?: 'N/A';
}

/* ---------------------------------------------------
   1️⃣ PHẦN XỬ LÝ AJAX API
--------------------------------------------------- */
if ($action === 'get') {
  $userId = $_SESSION['user_id'] ?? 0;
  if (!$userId) {
    echo json_encode(['ok' => 0, 'error' => 'Chưa đăng nhập']);
    exit;
  }

  // USERS
  $u = $pdo->prepare("
  SELECT 
    u.username,
    u.avatar_url,
    r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
  LIMIT 1
");
  $u->execute([$userId]);
  $user = $u->fetch(PDO::FETCH_ASSOC) ?: [];


  // MEMBERS
  $m = $pdo->prepare("
SELECT
  email,
  fullname,
  phone,
  birth,
  join_date,
  mssv,
  type,
  ethnicity,
  religion,
  native_place,
  current_address,
  department_id,
  course_id,
  class_id,
  chidoan_group_id,
  party_probation_date,
  party_official_date,
  is_locked
FROM members
WHERE user_id = ?
LIMIT 1

");

  // === META CHO FORM EDIT ===
  $departments = $pdo->query("
  SELECT id, name, type
  FROM departments
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);


  $courses = $pdo->query("
  SELECT id, name
  FROM courses
  WHERE status = 1
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

  $classes = $pdo->query("
  SELECT id, name, department_id, course_id
  FROM classes
  WHERE status = 1
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);
  $chidoanGroups = $pdo->query("
  SELECT id, name
  FROM chidoan_groups
  ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

  $m->execute([$userId]);
  $member = $m->fetch(PDO::FETCH_ASSOC) ?: [];


  echo json_encode([
    'ok' => 1,
    'user' => $user,
    'member' => $member,
    'departments' => $departments,
    'courses' => $courses,
    'classes' => $classes,
    'chidoan_groups' => $chidoanGroups

  ]);
  exit;
}


if ($action === 'update_profile') {

  $userId = $_SESSION['user_id'] ?? 0;
  if (!$userId) {
    echo json_encode(['ok' => 0, 'error' => 'Chưa đăng nhập']);
    exit;
  }
  // 🔒 CHẶN SỬA HỒ SƠ NẾU BỊ KHÓA
  $stLock = $pdo->prepare("SELECT is_locked FROM members WHERE user_id=? LIMIT 1");
  $stLock->execute([$userId]);
  $isLocked = (int) ($stLock->fetchColumn() ?? 0);

  if ($isLocked === 1) {
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'Hồ sơ đã bị khóa, không thể chỉnh sửa thông tin.']);
    exit;
  }

  $mssv = getMssvByUserId($pdo, $userId);


  $partyProbation = $_POST['party_probation_date'] ?? '';
  $partyOfficial = $_POST['party_official_date'] ?? '';

  $partyProbation = $partyProbation !== '' ? $partyProbation : null;
  $partyOfficial = $partyOfficial !== '' ? $partyOfficial : null;

  // validate logic Đảng
  if ($partyOfficial && !$partyProbation) {
    echo json_encode(['ok' => 0, 'error' => 'Phải có ngày dự bị trước']);
    exit;
  }
  if ($partyProbation && $partyOfficial && $partyOfficial < $partyProbation) {
    echo json_encode(['ok' => 0, 'error' => 'Ngày chính thức phải sau ngày dự bị']);
    exit;
  }

  $userId = $_SESSION['user_id'] ?? 0;
  if (!$userId) {
    echo json_encode(['ok' => 0, 'error' => 'Chưa đăng nhập']);
    exit;
  }

  $birth = $_POST['birth'] ?? '';
  $join = $_POST['join_date'] ?? '';

  $birth = $birth !== '' ? $birth : null;
  $join = $join !== '' ? $join : null;

  try {
    $avatar = trim($_POST['avatar_url'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // update avatar (users)
    $stmtU = $pdo->prepare("
  UPDATE users
  SET avatar_url = ?
  WHERE id = ?
");
    $stmtU->execute([
      $_POST['avatar_url'] ?? null,
      $userId
    ]);

    // update email + info cá nhân (members)
    $stmtM = $pdo->prepare("
UPDATE members
SET
  fullname = ?,
  phone = ?,
  email = ?,
  birth = ?,
  join_date = ?,
  type = ?,
  ethnicity = ?,
  religion = ?,
  native_place = ?,
  current_address = ?,
  chidoan_group_id = ?,
  department_id = ?,
  course_id = ?,
  class_id = ?,
  party_probation_date = ?,
  party_official_date = ?
WHERE user_id = ?

");
    $courseId = $_POST['course_id'] ?? null;
    $classId = $_POST['class_id'] ?? null;

    // chuẩn hóa rỗng → NULL
    $courseId = ($courseId === '' || $courseId === '0') ? null : $courseId;
    $classId = ($classId === '' || $classId === '0') ? null : $classId;

    // nếu là chi đoàn giáo viên → ép NULL
    if (($_POST['chidoan_group_id'] ?? null) == 2) {
      $courseId = null;
      $classId = null;
    }

    $stmtM->execute([
      $_POST['fullname'] ?? '',
      $_POST['phone'] ?? '',
      $_POST['email'] ?? '',
      $birth,
      $join,
      $_POST['type'] ?? null,
      $_POST['ethnicity'] ?? null,
      $_POST['religion'] ?? null,
      $_POST['native_place'] ?? null,
      $_POST['current_address'] ?? null,
      $_POST['chidoan_group_id'] ?? null,
      $_POST['department_id'] ?? null,
      $courseId,
      $classId,
      $partyProbation,
      $partyOfficial,
      $userId
    ]);

    log_activity(
      'update',
      'account',
      'user',
      null,
      'Cập nhật thông tin cá nhân - MSSV: ' . $mssv
    );

    echo json_encode(['ok' => 1]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($action === 'change_password') {
  $userId = $_SESSION['user_id'] ?? 0;
  if (!$userId) {
    echo json_encode(['ok' => 0, 'error' => 'Chưa đăng nhập']);
    exit;
  }

  $mssv = getMssvByUserId($pdo, $userId);

  $current = $_POST['current_password'] ?? '';
  $new = $_POST['new_password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';

  if ($current === '' || $new === '' || $confirm === '') {
    echo json_encode(['ok' => 0, 'error' => 'Thiếu dữ liệu']);
    exit;
  }

  if ($new !== $confirm) {
    echo json_encode(['ok' => 0, 'error' => 'Mật khẩu mới không khớp']);
    exit;
  }

  if (strlen($new) < 6) {
    echo json_encode(['ok' => 0, 'error' => 'Mật khẩu phải từ 6 ký tự']);
    exit;
  }

  // 1️⃣ Lấy hash hiện tại
  $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row || !password_verify($current, $row['password_hash'])) {
    echo json_encode(['ok' => 0, 'error' => 'Mật khẩu hiện tại không đúng']);
    exit;
  }

  // 2️⃣ Hash mật khẩu mới
  $newHash = password_hash($new, PASSWORD_DEFAULT);

  // 3️⃣ Update
  $stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
  $stmt->execute([$newHash, $userId]);

  log_activity(
    'update',
    'account',
    'user',
    null,
    'Đổi mật khẩu tài khoản - MSSV: ' . $mssv
  );

  echo json_encode(['ok' => 1]);
  exit;
}


/* ---------------------------------------------------
   2️⃣ PHẦN LẤY DỮ LIỆU ĐỂ HIỂN THỊ TRANG ACCOUNT
--------------------------------------------------- */
$userId = $_SESSION['user_id'] ?? null;

$stmtU = $pdo->prepare("
  SELECT 
    u.id,
    u.username,
    u.fullname AS user_fullname,
    u.avatar_url,
    r.name AS role_name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
  LIMIT 1
");
$stmtU->execute([$userId]);
$u = $stmtU->fetch(PDO::FETCH_ASSOC) ?: [];



$info = [];
$deptName = $course = $className = '-';

if ($userId) {
  $sql = "
    SELECT m.*, 
           d.name AS deptName, 
           c.name AS courseName, 
           cl.name AS className
    FROM members m
    LEFT JOIN departments d ON d.id = m.department_id
    LEFT JOIN courses c ON c.id = m.course_id
    LEFT JOIN classes cl ON cl.id = m.class_id
    WHERE m.user_id = ?
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId]);
  $info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  $deptName = $info['deptName'] ?? '-';
  $course = $info['courseName'] ?? '-';
  $className = $info['className'] ?? '-';

  $displayFullname =
    $info['fullname']
    ?? $u['user_fullname']
    ?? $u['username']
    ?? '-';
  return compact(
    'u',
    'info',
    'deptName',
    'course',
    'className',
    'displayFullname'
  );
}


// Trả dữ liệu về cho view
return compact('u', 'info', 'deptName', 'course', 'className');
