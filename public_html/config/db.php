<?php
// config/db.php
$DB_HOST = 'localhost';
$DB_NAME = 'songtrensg_doanthanhnien';
$DB_USER = 'songtrensg_doanthanhnien';
$DB_PASS = '123456789'; 

// Load .env variables if file exists
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (in_array($name, ['DB_HOST', 'DATABASE_HOST'])) $DB_HOST = $value;
            if (in_array($name, ['DB_NAME', 'DATABASE_NAME'])) $DB_NAME = $value;
            if (in_array($name, ['DB_USER', 'DATABASE_USER'])) $DB_USER = $value;
            if (in_array($name, ['DB_PASS', 'DB_PASSWORD', 'DATABASE_PASSWORD'])) $DB_PASS = $value;
        }
    }
}

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  // ✅ FIX 1615 + mấy lỗi prepare lặt vặt trên hosting
  PDO::ATTR_EMULATE_PREPARES   => false,

  // ✅ Tránh PDO persistent gây loạn prepared statement cache
  PDO::ATTR_PERSISTENT         => false,];

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);

  // ⚠️ PRODUCTION: ĐÃ LOẠI BỎ logic tự tạo admintest/123456 (rất nguy hiểm).
  // Nếu cần seed admin lần đầu, dùng script riêng hoặc import từ backup.
  // Giữ lại block violations để tương thích ngược (idempotent INSERT IGNORE).

  // Tự động kiểm tra và tạo/cập nhật quyền 'violations' trong bảng permissions & phân quyền cho admin
  try {
      // Tìm parent_id của nhóm "Đánh giá" bằng cách tham chiếu từ các quyền cùng nhóm đã có sẵn
      $findParent = $pdo->query("
          SELECT parent_id FROM permissions 
          WHERE code IN ('scoring', 'nominations', 'leaderboard', 'achievements') 
            AND parent_id IS NOT NULL 
          LIMIT 1
      ");
      $parentId = $findParent->fetchColumn();
      if (!$parentId) {
          $parentId = null;
      }

      $checkPerm = $pdo->prepare("SELECT id FROM permissions WHERE code = 'violations' LIMIT 1");
      $checkPerm->execute();
      $permId = $checkPerm->fetchColumn();
      
      if (!$permId) {
          $stmt = $pdo->prepare("
              INSERT INTO permissions (code, name, grp, sort_order, parent_id)
              VALUES ('violations', 'Kỷ luật - Vi phạm', 'Đánh giá', 100, ?)
          ");
          $stmt->execute([$parentId]);
          $permId = (int) $pdo->lastInsertId();
      } else {
          $permId = (int) $permId;
          // Cập nhật lại parent_id cho violations nếu nó đang bị NULL hoặc chưa đúng
          $stmt = $pdo->prepare("
              UPDATE permissions 
              SET parent_id = ?, grp = 'Đánh giá' 
              WHERE id = ?
          ");
          $stmt->execute([$parentId, $permId]);
      }
      
      // Tự động cấp toàn quyền violations cho tất cả vai trò admin/superadmin trong role_permissions
      $pdo->prepare("
          INSERT IGNORE INTO role_permissions (role_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
          SELECT id, ?, 1, 1, 1, 1, 1, 1
          FROM roles
          WHERE name LIKE '%admin%' OR id IN (1, 2)
      ")->execute([$permId]);
      
      // Tự động cấp toàn quyền violations cho tất cả người dùng admin/superadmin trong user_permissions
      $pdo->prepare("
          INSERT IGNORE INTO user_permissions (user_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
          SELECT u.id, ?, 1, 1, 1, 1, 1, 1
          FROM users u
          JOIN roles r ON r.id = u.role_id
          WHERE r.name LIKE '%admin%' OR r.id IN (1, 2)
      ")->execute([$permId]);
  } catch (Throwable $pErr) {
      // Tránh ném lỗi nếu cấu trúc bảng permissions khác biệt
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "DB connection failed: " . htmlspecialchars($e->getMessage());
  exit;
}
