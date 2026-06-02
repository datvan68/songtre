<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

auth_guard();

function json_ok($data = null)
{
  echo json_encode(['ok' => 1] + (is_array($data) ? $data : ['data' => $data]), JSON_UNESCAPED_UNICODE);
  exit;
}

function json_err($msg, $code = 400)
{
  http_response_code($code);
  echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * 🔒 Chỉ người có quyền xem/quản lý/chấm điểm lớp mới được vào
 */
if (!can('campaign_scoring', 'view') && !can('campaign_scoring', 'update') && !can('campaign_scoring', 'review')) {
  http_response_code(403);
  echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$action = trim($action);

/**
 * ======================================================
 * 1) LIST – CHỈ HIỆN LỚP CÓ THÀNH VIÊN ĐÃ QUÉT QR (OK)
 * ======================================================
 */
if ($action === 'list') {

  $campaignId = (int) ($_GET['campaign_id'] ?? 0);
  if (!$campaignId) {
    json_err('Invalid campaign');
  }

  $stmt = $pdo->prepare("
    SELECT
  c.id   AS class_id,
  c.name AS class_name,

  COUNT(DISTINCT m.user_id) AS class_size,

  COUNT(DISTINCT
    CASE
      WHEN al.user_id IS NOT NULL OR r.status <> 'approved'
      THEN m.user_id
    END
  ) AS joined_quantity,

  ccs.target_quantity,
  ccr.score,
  MAX(ccr.locked) AS locked,
  sy.year_label AS school_year_label,
  cam.school_year_id AS school_year_id

FROM classes c
JOIN members m ON m.class_id = c.id

LEFT JOIN attendance_logs al
  ON al.user_id = m.user_id
 AND al.campaign_id = ?
 AND al.result = 'ok'

LEFT JOIN registrations r
  ON r.user_id = m.user_id
 AND r.campaign_id = ?

LEFT JOIN campaign_class_scores ccs
  ON ccs.campaign_id = ?
 AND ccs.class_id = c.id

LEFT JOIN campaign_class_results ccr
  ON ccr.campaign_id = ?
 AND ccr.class_id = c.id

LEFT JOIN campaigns cam ON cam.id = ?
LEFT JOIN school_years sy ON sy.id = cam.school_year_id

GROUP BY
  c.id,
  c.name,
  ccs.target_quantity,
  ccr.score,
  sy.year_label,
  cam.school_year_id

HAVING joined_quantity > 0
ORDER BY joined_quantity DESC, c.name ASC

  ");

  $stmt->execute([$campaignId, $campaignId, $campaignId, $campaignId, $campaignId]);

  json_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/**
 * ======================================================
 * 2) SAVE – NHẬP CHỈ TIÊU LỚP
 * ======================================================
 */
if ($action === 'save') {

  $campaignId = (int) ($_POST['campaign_id'] ?? 0);
  $classId = (int) ($_POST['class_id'] ?? 0);
  $target = (int) ($_POST['target_quantity'] ?? 0);

  if (!$campaignId || !$classId || $target <= 0) {
    json_err('Invalid data');
  }

  // ⛔ Không cho sửa nếu đã chốt
  $chk = $pdo->prepare("
    SELECT locked
    FROM campaign_class_results
    WHERE campaign_id = ? AND class_id = ?
    LIMIT 1
  ");
  $chk->execute([$campaignId, $classId]);

  if ((int) $chk->fetchColumn() === 1) {
    json_err('Score is locked');
  }

  $stmt = $pdo->prepare("
    INSERT INTO campaign_class_scores (campaign_id, class_id, target_quantity)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      target_quantity = VALUES(target_quantity),
      updated_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([$campaignId, $classId, $target]);

  // log
  $cls = $pdo->prepare("SELECT name FROM classes WHERE id=?");
  $cls->execute([$classId]);
  $className = $cls->fetchColumn() ?: 'Không rõ lớp';

  log_activity(
    'update',
    'campaign_scoring',
    'Lớp',
    null,
    "Nhập chỉ tiêu cho lớp {$className}: {$target} người"
  );

  json_ok();
}

/**
 * ======================================================
 * 3) CALCULATE – TÍNH ĐIỂM LỚP DỰA THEO QR (attendance_logs)
 * ======================================================
 */
if ($action === 'calculate') {

  $campaignId = (int) ($_POST['campaign_id'] ?? 0);
  if (!$campaignId) {
    json_err('Invalid campaign');
  }

  // ⛔ Không cho tính nếu đã chốt
  $chkLock = $pdo->prepare("
    SELECT COUNT(*)
    FROM campaign_class_results
    WHERE campaign_id = ? AND locked = 1
  ");
  $chkLock->execute([$campaignId]);

  if ((int) $chkLock->fetchColumn() > 0) {
    json_err('Phong trào đã chốt điểm');
  }

  /**
   * ✅ Check lớp có người quét QR OK mà chưa nhập chỉ tiêu
   * (chỉ xét lớp phát sinh từ attendance_logs)
   */
  $miss = $pdo->prepare("
    SELECT COUNT(*) FROM (
      SELECT DISTINCT c.id
      FROM attendance_logs al
      JOIN members m ON m.user_id = al.user_id
      JOIN classes c ON c.id = m.class_id
      LEFT JOIN campaign_class_scores ccs
        ON ccs.campaign_id = al.campaign_id
       AND ccs.class_id = c.id
      WHERE al.campaign_id = ?
        AND al.result = 'ok'
        AND (ccs.target_quantity IS NULL OR ccs.target_quantity <= 0)
    ) t
  ");
  $miss->execute([$campaignId]);

  if ((int) $miss->fetchColumn() > 0) {
    json_err('Chưa nhập đủ chỉ tiêu cho tất cả lớp');
  }

  /**
   * ✅ TÍNH ĐIỂM:
   * joined_quantity = COUNT DISTINCT user đã quét QR OK
   * score = min(10, (joined/target)*10) làm tròn 1 chữ số
   */
  $sql = "
    INSERT INTO campaign_class_results (
  campaign_id,
  class_id,
  joined_quantity,
  target_quantity,
  score
)
SELECT
  r.campaign_id,
  c.id,

  COUNT(DISTINCT m.user_id) AS joined_quantity,
  ccs.target_quantity,

  ROUND(
    LEAST(
      10,
      (COUNT(DISTINCT m.user_id) / ccs.target_quantity) * 10
    ),
    1
  ) AS score

FROM members m
JOIN classes c ON c.id = m.class_id

JOIN registrations r
  ON r.user_id = m.user_id
 AND r.campaign_id = ?
 AND r.status <> 'approved'

LEFT JOIN attendance_logs al
  ON al.user_id = m.user_id
 AND al.campaign_id = r.campaign_id
 AND al.result = 'ok'

JOIN campaign_class_scores ccs
  ON ccs.campaign_id = r.campaign_id
 AND ccs.class_id = c.id

WHERE
  al.user_id IS NOT NULL
  OR r.status <> 'approved'

GROUP BY c.id, ccs.target_quantity

ON DUPLICATE KEY UPDATE
  joined_quantity = VALUES(joined_quantity),
  target_quantity = VALUES(target_quantity),
  score = VALUES(score),
  calculated_at = CURRENT_TIMESTAMP

  ";

  $pdo->prepare($sql)->execute([$campaignId]);

  log_activity(
    'review',
    'campaign_scoring',
    'Phong trào',
    null,
    'Thực hiện tính điểm phong trào cho các lớp (theo QR attendance_logs)'
  );

  json_ok();
}

/**
 * ======================================================
 * 4) UNLOCK – MỞ CHỐT (CHỈ ADMIN)
 * ======================================================
 */
if ($action === 'unlock') {

  if (!is_admin()) {
    json_err('Forbidden', 403);
  }

  $campaignId = (int) ($_POST['campaign_id'] ?? 0);
  if (!$campaignId) {
    json_err('Invalid campaign');
  }

  $pdo->prepare("
    UPDATE campaign_class_results
    SET locked = 0
    WHERE campaign_id = ?
  ")->execute([$campaignId]);

  log_activity(
    'update',
    'campaign_scoring',
    'Phong trào',
    null,
    'Mở khóa chỉnh sửa điểm phong trào'
  );

  json_ok();
}

/**
 * ======================================================
 * 5) LOCK – CHỐT ĐIỂM
 * ======================================================
 */
if ($action === 'lock') {

  if (!can('campaign_scoring', 'review')) {
    json_err('Forbidden', 403);
  }

  $campaignId = (int) ($_POST['campaign_id'] ?? 0);
  if (!$campaignId) {
    json_err('Invalid campaign');
  }

  // ❌ Không cho lock nếu chưa có kết quả
  $chk = $pdo->prepare("
    SELECT COUNT(*)
    FROM campaign_class_results
    WHERE campaign_id = ?
  ");
  $chk->execute([$campaignId]);

  if ((int) $chk->fetchColumn() === 0) {
    json_err('Chưa có điểm lớp để chốt');
  }

  $pdo->prepare("
    UPDATE campaign_class_results
    SET locked = 1
    WHERE campaign_id = ?
  ")->execute([$campaignId]);

  log_activity(
    'update',
    'campaign_scoring',
    'Phong trào',
    null,
    'Chốt điểm phong trào – khóa chỉnh sửa điểm các lớp'
  );

  json_ok();
}

if ($action === 'export') {
  $campaignId = (int) ($_GET['campaign_id'] ?? 0);
  if (!$campaignId) {
    json_err('Invalid campaign');
  }

  $schoolYearFilter = (int) ($_GET['school_year_id'] ?? 0);

  $stmt = $pdo->prepare("
    SELECT
      c.id   AS class_id,
      c.name AS class_name,
      COUNT(DISTINCT m.user_id) AS class_size,
      COUNT(DISTINCT
        CASE
          WHEN al.user_id IS NOT NULL OR r.status <> 'approved'
          THEN m.user_id
        END
      ) AS joined_quantity,
      ccs.target_quantity,
      ccr.score,
      sy.year_label AS school_year_label,
      cam.school_year_id AS school_year_id
    FROM classes c
    JOIN members m ON m.class_id = c.id
    LEFT JOIN attendance_logs al
      ON al.user_id = m.user_id
     AND al.campaign_id = ?
     AND al.result = 'ok'
    LEFT JOIN registrations r
      ON r.user_id = m.user_id
     AND r.campaign_id = ?
    LEFT JOIN campaign_class_scores ccs
      ON ccs.campaign_id = ?
     AND ccs.class_id = c.id
    LEFT JOIN campaign_class_results ccr
      ON ccr.campaign_id = ?
     AND ccr.class_id = c.id
    LEFT JOIN campaigns cam ON cam.id = ?
    LEFT JOIN school_years sy ON sy.id = cam.school_year_id
    GROUP BY
      c.id,
      c.name,
      ccs.target_quantity,
      ccr.score,
      sy.year_label,
      cam.school_year_id
    HAVING joined_quantity > 0
    ORDER BY joined_quantity DESC, c.name ASC
  ");

  $stmt->execute([$campaignId, $campaignId, $campaignId, $campaignId, $campaignId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if ($schoolYearFilter > 0) {
    $rows = array_filter($rows, function($r) use ($schoolYearFilter) {
      return (int)$r['school_year_id'] === $schoolYearFilter;
    });
  }

  $classIdsStr = $_GET['class_ids'] ?? '';
  $classIds = [];
  if ($classIdsStr !== '') {
    $classIds = array_map('intval', array_filter(explode(',', $classIdsStr)));
  }

  if (!empty($classIds)) {
    $rows = array_filter($rows, function($r) use ($classIds) {
      return in_array((int)$r['class_id'], $classIds, true);
    });
  }

  function slug_filename(string $s): string
  {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);
    $s = trim($s, '_');
    return $s;
  }

  $stm = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
  $stm->execute([$campaignId]);
  $campaignTitle = $stm->fetchColumn() ?: 'phong trào';
  $slugCampaign = slug_filename((string) $campaignTitle);
  $filename = "bang_diem_lop_phong_trao_{$slugCampaign}.csv";

  header('Content-Type: text/csv; charset=utf-8');
  header("Content-Disposition: attachment; filename=\"{$filename}\"");

  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");

  fputcsv($out, [
    'STT',
    'Lớp',
    'Năm học',
    'Số lượng tham gia',
    'Chỉ tiêu lớp',
    'Điểm lớp'
  ]);

  $stt = 1;
  foreach ($rows as $r) {
    fputcsv($out, [
      $stt++,
      $r['class_name'],
      $r['school_year_label'] ?: '—',
      "{$r['joined_quantity']} / {$r['class_size']}",
      $r['target_quantity'] ?? '—',
      $r['score'] ?? '—'
    ]);
  }

  log_activity(
    'export',
    'campaign_scoring',
    'Chấm điểm lớp',
    null,
    "Xuất bảng điểm lớp phong trào <b>{$campaignTitle}</b>"
  );

  fclose($out);
  exit;
}

json_err('Invalid action');
