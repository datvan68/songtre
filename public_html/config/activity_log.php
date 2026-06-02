<?php
// config/activity_log.php
// Usage: require __DIR__ . '/activity_log.php'; then log_activity(...)
function getClientIP()
{
  if (
    !empty($_SERVER['HTTP_CLIENT_IP']) &&
    filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
  ) {
    return $_SERVER['HTTP_CLIENT_IP'];
  }

  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) {
      $ip = trim($ip);
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $ip;
      }
    }
  }

  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

  // nếu REMOTE_ADDR là IPv6 → trả về IPv6
  return $ip;
}

function log_activity(
  string $action,
  string $module,
  ?string $targetType = null,
  ?int $targetId = null,
  ?string $desc = null
): void {
  if (!isset($GLOBALS['pdo']))
    return;
  $pdo = $GLOBALS['pdo'];

  // auth_user() có sẵn trong auth.php của bạn
  $u = function_exists('auth_user') ? auth_user() : null;

  $userId = $u['id'] ?? ($_SESSION['user_id'] ?? null);
  $roleId = $u['role_id'] ?? null;

  $ip = getClientIp();
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $ua = mb_substr($ua, 0, 255, 'UTF-8');

  try {
    $stmt = $pdo->prepare("
      INSERT INTO activity_logs
        (user_id, role_id, action, module, target_type, target_id, description, ip_address, user_agent)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $userId,
      $roleId,
      $action,
      $module,
      $targetType,
      $targetId,
      $desc,
      $ip,
      $ua
    ]);
  } catch (Throwable $e) {
    // Không được phá flow chính nếu log fail
  }
}
