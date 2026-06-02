<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

auth_guard();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function jbad($msg, $code = 400)
{
  http_response_code($code);
  echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

try {

  /* =========================
     LIST EVENTS
     - Nếu không muốn lộ code/lat/lng cho user thường => giới hạn admin
  ========================== */
  if ($action === 'list_events') {
    if (!is_admin())
      jbad('FORBIDDEN', 403);

    echo json_encode(
      $pdo->query("SELECT * FROM attendance_events ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC),
      JSON_UNESCAPED_UNICODE
    );
    exit;
  }

  /* =========================
     CREATE EVENT (ADMIN)
  ========================== */
  if ($action === 'create_event') {
    if (!is_admin())
      jbad('FORBIDDEN', 403);

    $campaignId = (int) ($_POST['campaign_id'] ?? 0);
    $session = $_POST['session'] ?? 'morning';
    $code = trim((string) ($_POST['code'] ?? ''));
    $startsAt = $_POST['starts_at'] ?? null;
    $expiresAt = $_POST['expires_at'] ?? null;

    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;
    $address = $_POST['address'] ?? null;

    if ($campaignId <= 0)
      jbad('campaign_id không hợp lệ');
    if ($code === '')
      jbad('Thiếu code');
    if ($expiresAt === null || trim((string) $expiresAt) === '')
      jbad('Thiếu expires_at');

    // validate lat/lng nếu có
    if ($lat !== null && $lat !== '') {
      $lat = (float) $lat;
      if ($lat < -90 || $lat > 90)
        jbad('lat không hợp lệ');
    } else
      $lat = null;

    if ($lng !== null && $lng !== '') {
      $lng = (float) $lng;
      if ($lng < -180 || $lng > 180)
        jbad('lng không hợp lệ');
    } else
      $lng = null;

    // chống trùng code
    $st = $pdo->prepare("SELECT 1 FROM attendance_events WHERE code=? LIMIT 1");
    $st->execute([$code]);
    if ($st->fetchColumn())
      jbad('Code đã tồn tại');

    $stmt = $pdo->prepare("
      INSERT INTO attendance_events
        (campaign_id, session, code, starts_at, expires_at, lat, lng, address)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
      $campaignId,
      $session,
      $code,
      $startsAt ?: null,
      $expiresAt,
      $lat,
      $lng,
      $address
    ]);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =========================
     DELETE EVENT (ADMIN)
  ========================== */
  if ($action === 'delete_event') {
    if (!is_admin())
      jbad('FORBIDDEN', 403);

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0)
      jbad('id không hợp lệ');

    $pdo->prepare("DELETE FROM attendance_events WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =========================
     🚫 DISABLE LEGACY CHECKIN ENDPOINT
     - Checkin phải đi qua campaigns_check (anti-fake + GPS + flag)
  ========================== */
  if ($action === 'checkin') {
    jbad('CHECKIN_DISABLED_USE_QR_PAGE', 410);
  }

  /* =========================
     LOGS
     - Nên giới hạn admin/manager; hiện tại trả tất cả logs => lộ dữ liệu
  ========================== */
  if ($action === 'logs') {
    if (!is_admin())
      jbad('FORBIDDEN', 403);

    $sql = "SELECT l.*, u.username, m.fullname
            FROM attendance_logs l
            JOIN users u ON u.id = l.user_id
            LEFT JOIN members m ON m.user_id = u.id
            ORDER BY l.time DESC";
    echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
  }

  jbad('Bad action', 400);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
