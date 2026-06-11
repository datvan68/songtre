<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

// Nếu có output rác từ file require trước đó, dọn sạch để khỏi phá JSON
if (ob_get_length())
  ob_clean();

function forbidden()
{
  http_response_code(403);
  echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => 0, 'error' => 'Chưa đăng nhập'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $perPage = 15;

  /* =========================
     LIST (AJAX PAGINATION)
  ========================= */
  if ($action === 'list_departments') {
    if (!can('departments', 'view'))
      forbidden();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
    SELECT id, name
    FROM departments
    WHERE type = 'khoa'
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
  ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['ok' => 1, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
  }


  if ($action === 'list_courses') {
    if (!can('departments', 'view'))
      forbidden();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
      SELECT id, name, status
      FROM courses
      ORDER BY id DESC
      LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['ok' => 1, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'list_classes') {
    if (!can('departments', 'view'))
      forbidden();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    // 🔎 FILTER
    $system = trim($_GET['system'] ?? ''); // CĐ / TC
    $year = trim($_GET['year'] ?? '');   // 23 / 24 / 25

    $where = [];
    $params = [];

    // Hệ đào tạo: CĐ | TC
    if ($system !== '') {
      // Normalize: CD -> CĐ
      if ($system === 'CD')
        $system = 'CĐ';
      $where[] = "name LIKE :system";
      $params[':system'] = $system . '%';
    }

    // Khóa: 23 / 24 / 25
    if ($year !== '') {
      $where[] = "name LIKE :year";
      $params[':year'] = "%$year%";
    }

    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "
    SELECT id, name, department_id, course_id, status
    FROM classes
    $whereSql
    ORDER BY name ASC
    LIMIT :limit OFFSET :offset
  ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $k => $v) {
      $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    echo json_encode([
      'ok' => 1,
      'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }


  /* =========================
     DEPARTMENTS CRUD
  ========================= */
  if ($action === 'create_department') {
    if (!can('departments', 'create'))
      forbidden();

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu tên khoa'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("
    INSERT INTO departments (name, type)
    VALUES (?, 'khoa')
  ");
    $stmt->execute([$name]);

    log_activity(
      'create',
      'departments',
      'Khoa',
      null,
      'Thêm khoa: ' . $name
    );


    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }


  if ($action === 'update_department') {
    if (!can('departments', 'update'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu id/tên khoa'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("UPDATE departments SET name=? WHERE id=?");
    $stmt->execute([$name, $id]);
    log_activity(
      'update',
      'departments',
      'Khoa',
      null,
      'Cập nhật khoa: ' . $name
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'delete_department') {
    if (!can('departments', 'delete'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu id khoa'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmtName = $pdo->prepare("SELECT name FROM departments WHERE id=? LIMIT 1");
    $stmtName->execute([$id]);
    $oldName = $stmtName->fetchColumn() ?: 'Không rõ';

    $pdo->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);

    log_activity(
      'delete',
      'departments',
      'Khoa',
      null,
      'Xóa khoa: ' . $oldName
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =========================
     COURSES CRUD
  ========================= */
  if ($action === 'create_course') {
    if (!can('departments', 'create'))
      forbidden();

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu tên khóa'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("INSERT INTO courses (name) VALUES (?)");
    $stmt->execute([$name]);

    log_activity(
      'create',
      'departments',
      'Khóa',
      null,
      'Thêm khóa: ' . $name
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'update_course') {
    if (!can('departments', 'update'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $status = isset($_POST['status']) ? (int) $_POST['status'] : 1;
    if ($id <= 0 || $name === '') {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu id/tên khóa'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("UPDATE courses SET name=?, status=? WHERE id=?");
    $stmt->execute([$name, $status, $id]);

    log_activity(
      'update',
      'departments',
      'Khóa',
      null,
      'Cập nhật khóa: ' . $name
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'delete_course') {
    if (!can('departments', 'delete'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu id khóa'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmtName = $pdo->prepare("SELECT name FROM courses WHERE id=? LIMIT 1");
    $stmtName->execute([$id]);
    $oldName = $stmtName->fetchColumn() ?: 'Không rõ';

    $pdo->prepare("DELETE FROM courses WHERE id=?")->execute([$id]);

    log_activity(
      'delete',
      'departments',
      'Khóa',
      null,
      'Xóa khóa: ' . $oldName
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =========================
     CLASSES CRUD
  ========================= */
  if ($action === 'create_class') {
    if (!can('departments', 'create'))
      forbidden();

    $name = trim($_POST['name'] ?? '');
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $courseId = (int) ($_POST['course_id'] ?? 0);

    if ($name === '' || $departmentId <= 0 || $courseId <= 0) {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu tên lớp / khoa / khóa'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("
      INSERT INTO classes (name, department_id, course_id)
      VALUES (?, ?, ?)
    ");
    $stmt->execute([$name, $departmentId, $courseId]);
    log_activity(
      'create',
      'departments',
      'Lớp',
      null,
      'Thêm lớp: ' . $name
    );
    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'update_class') {
    if (!can('departments', 'update'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $status = isset($_POST['status']) ? (int) $_POST['status'] : 1;

    if ($id <= 0 || $name === '' || $departmentId <= 0 || $courseId <= 0) {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu dữ liệu cập nhật lớp'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmt = $pdo->prepare("
      UPDATE classes
      SET name = ?, department_id = ?, course_id = ?, status = ?
      WHERE id = ?
    ");
    $stmt->execute([$name, $departmentId, $courseId, $status, $id]);

    log_activity(
      'update',
      'departments',
      'Lớp',
      null,
      'Cập nhật lớp: ' . $name
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'delete_class') {
    if (!can('departments', 'delete'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok' => 0, 'error' => 'Thiếu id lớp'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $stmtName = $pdo->prepare("SELECT name FROM classes WHERE id=? LIMIT 1");
    $stmtName->execute([$id]);
    $oldName = $stmtName->fetchColumn() ?: 'Không rõ';

    $pdo->prepare("DELETE FROM classes WHERE id=?")->execute([$id]);

    log_activity(
      'delete',
      'departments',
      'Lớp',
      null,
      'Xóa lớp: ' . $oldName
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['ok' => 0, 'error' => 'Bad action'], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
