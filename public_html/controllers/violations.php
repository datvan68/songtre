<?php
error_reporting(0);
ini_set('display_errors', 0);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_length()) ob_clean();

// 1) Tự động kiểm tra và tạo bảng violations nếu chưa tồn tại
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS violations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      member_id INT NOT NULL,
      reason TEXT NOT NULL,
      treatment VARCHAR(255) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      created_by INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  // Lỗi tạo bảng nhưng không crash app
}

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

// Bảo vệ đăng nhập & phân quyền xem
auth_guard();
if (!can('violations', 'view')) {
  json_err('Bạn không có quyền truy cập chức năng này', 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* =====================================================
   1) LẤY THÔNG TIN ĐOÀN VIÊN/SINH VIÊN THEO MSSV
   ===================================================== */
if ($action === 'get_member') {
  $mssv = trim($_GET['mssv'] ?? '');
  if ($mssv === '') {
    json_err('Vui lòng nhập mã số sinh viên');
  }

  $stmt = $pdo->prepare("
    SELECT
      m.id,
      m.fullname,
      m.mssv,
      m.phone,
      COALESCE(c.name, m.class_name) AS class_name,
      d.name AS dept_name
    FROM members m
    LEFT JOIN classes c ON c.id = m.class_id
    LEFT JOIN departments d ON d.id = m.department_id
    WHERE m.mssv = ?
    LIMIT 1
  ");
  $stmt->execute([$mssv]);
  $member = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$member) {
    json_err('Không tìm thấy sinh viên có mã số này trong hệ thống');
  }

  // Đếm số lần vi phạm hiện tại
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM violations WHERE member_id = ?");
  $cnt->execute([$member['id']]);
  $member['violation_count'] = (int) $cnt->fetchColumn();

  json_ok($member);
}

/* =====================================================
   2) LẤY LỊCH SỬ VI PHẠM CỦA SINH VIÊN
   ===================================================== */
if ($action === 'list_by_member') {
  $memberId = (int) ($_GET['member_id'] ?? 0);
  if (!$memberId) {
    json_err('ID sinh viên không hợp lệ');
  }

  $stmt = $pdo->prepare("
    SELECT
      v.id,
      v.reason,
      v.treatment,
      v.created_at,
      COALESCE(m.fullname, u.fullname, u.username) AS creator_name
    FROM violations v
    LEFT JOIN users u ON u.id = v.created_by
    LEFT JOIN members m ON m.user_id = u.id
    WHERE v.member_id = ?
    ORDER BY v.created_at DESC
  ");
  $stmt->execute([$memberId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  json_ok($rows);
}

/* =====================================================
   3) GHI NHẬN VI PHẠM MỚI
   ===================================================== */
if ($action === 'save') {
  if (!can('violations', 'create') && !can('violations', 'update')) {
    json_err('Bạn không có quyền ghi nhận vi phạm', 403);
  }

  $memberId = (int) ($_POST['member_id'] ?? 0);
  $reason = trim($_POST['reason'] ?? '');
  $treatment = trim($_POST['treatment'] ?? '');

  if (!$memberId) {
    json_err('Vui lòng chọn sinh viên hợp lệ trước');
  }
  if ($reason === '') {
    json_err('Lý do vi phạm không được để trống');
  }
  if ($treatment === '') {
    json_err('Hình thức xử lý không được để trống');
  }

  // Lấy tên sinh viên để lưu log
  $stm = $pdo->prepare("SELECT fullname, mssv FROM members WHERE id=?");
  $stm->execute([$memberId]);
  $mInfo = $stm->fetch(PDO::FETCH_ASSOC);
  $mName = $mInfo['fullname'] ?? 'Sinh viên';
  $mMssv = $mInfo['mssv'] ?? '';

  $stmt = $pdo->prepare("
    INSERT INTO violations (member_id, reason, treatment, created_by)
    VALUES (?, ?, ?, ?)
  ");
  $stmt->execute([$memberId, $reason, $treatment, $_SESSION['user_id']]);

  log_activity(
    'create',
    'violations',
    'Kỷ luật',
    null,
    "Ghi nhận vi phạm cho sinh viên <b>{$mName} ({$mMssv})</b>: Lý do: {$reason}, Hình thức: {$treatment}"
  );

  json_ok();
}

/* =====================================================
   4) XÓA BẢN GHI VI PHẠM
   ===================================================== */
if ($action === 'delete') {
  if (!can('violations', 'delete')) {
    json_err('Bạn không có quyền xóa kỷ luật', 403);
  }

  $id = (int) ($_POST['id'] ?? 0);
  if (!$id) {
    json_err('ID vi phạm không hợp lệ');
  }

  // Lấy thông tin vi phạm để log trước khi xóa
  $stm = $pdo->prepare("
    SELECT m.fullname, m.mssv, v.reason
    FROM violations v
    JOIN members m ON m.id = v.member_id
    WHERE v.id=?
  ");
  $stm->execute([$id]);
  $vInfo = $stm->fetch(PDO::FETCH_ASSOC);

  if ($vInfo) {
    $mName = $vInfo['fullname'];
    $mMssv = $vInfo['mssv'];
    $vReason = $vInfo['reason'];

    $pdo->prepare("DELETE FROM violations WHERE id=?")->execute([$id]);

    log_activity(
      'delete',
      'violations',
      'Kỷ luật',
      null,
      "Xóa ghi nhận vi phạm của sinh viên <b>{$mName} ({$mMssv})</b>. Lý do đã xóa: {$vReason}"
    );
  }

  json_ok();
}

json_err('Hành động không hợp lệ');
