<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

function forbidden()
{
  http_response_code(403);
  echo json_encode([
    'success' => false,
    'error' => 'Forbidden'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

function status_vn(string $status): string
{
  return [
    'approved' => 'Đang tham gia',
    'excellent' => 'Hoàn thành xuất sắc',
    'good' => 'Hoàn thành tốt',
    'completed' => 'Hoàn thành',
    'incomplete' => 'Không hoàn thành',
    'cancelled' => 'Đã hủy',
  ][$status] ?? $status;
}


function slug_filename(string $s): string
{
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);
  $s = trim($s, '_');
  return $s;
}

function getUserDisplayName(PDO $pdo, int $userId): string
{
  $stmt = $pdo->prepare("
    SELECT COALESCE(m.fullname, u.fullname, u.username) AS name
    FROM users u
    LEFT JOIN members m ON m.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  return $stmt->fetchColumn() ?: 'Người dùng';
}

/**
 * ✅ FIX: Lấy role + scope 1 lần dùng chung cho list/export
 */
function getUserRoleScope(PDO $pdo, int $userId): array
{
  $stmt = $pdo->prepare("
    SELECT r.name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $userRole = $stmt->fetchColumn() ?: '';

  $scope = null;
  $gvcnClassIds = [];

  if ($userRole === 'bithu') {
    $stmt = $pdo->prepare("
      SELECT chidoan_group_id, department_id, course_id, class_id
      FROM bithu_scopes
      WHERE user_id = ?
      LIMIT 1
    ");
    $stmt->execute([$userId]);
    $scope = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scope) {
      forbidden();
    }
  }

  if ($userRole === 'gvcn') {
    $stmt = $pdo->prepare("
      SELECT class_id
      FROM gvcn_classes
      WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $gvcnClassIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_id');

    if (!$gvcnClassIds) {
      forbidden();
    }
  }

  return [$userRole, $scope, $gvcnClassIds];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

$publicActions = [
  'list',
  'pending_count'
];

// Guest → chỉ cho gọi public actions
if (empty($_SESSION['user_id']) && !in_array($action, $publicActions)) {
  echo json_encode([
    'success' => false,
    'need_login' => 1,
    'error' => 'Vui lòng đăng nhập'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

try {

  /* =====================================================
     0) LIST REGISTRATIONS (TAB 2 – PAGINATION)
  ===================================================== */
  if ($action === 'list') {

    if (empty($_SESSION['user_id'])) {
      echo json_encode([
        'success' => true,
        'data' => [],
        'page' => 1,
        'totalPages' => 1
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // Tự động phạt -1 điểm đối với những người đăng ký nhưng không quét QR điểm danh của các phong trào đã kết thúc
    $pdo->exec("
      UPDATE registrations r
      JOIN campaigns c ON c.id = r.campaign_id
      SET r.status = 'incomplete', r.score = -1.00
      WHERE r.status = 'approved'
        AND c.end_date < NOW()
        AND NOT EXISTS (
          SELECT 1
          FROM attendance_logs al
          WHERE al.user_id = r.user_id
            AND al.campaign_id = r.campaign_id
            AND al.result = 'ok'
        )
    ");

    if (!can('campaign_scoring', 'view')) {
      forbidden();
    }

    $perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 10)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $campaignId = (int) ($_GET['campaign_id'] ?? 0);
    $schoolYear = (int) ($_GET['school_year'] ?? 0);

    $userId = (int) $_SESSION['user_id'];

    // role/scope
    [$userRole, $scope, $gvcnClassIds] = getUserRoleScope($pdo, $userId);

    // có quyền chấm/đánh giá
    $isReviewer =
      can('campaign_scoring', 'review') ||
      can('campaign_scoring', 'update') ||
      can('campaign_scoring', 'delete');

    /* =========================
       WHERE + PARAMS
    ========================= */
    $where = [];
    $params = [];

    // Filter theo phong trào
    if ($campaignId > 0) {
      $where[] = "r.campaign_id = ?";
      $params[] = $campaignId;
    }

    // Filter theo năm học
    if ($schoolYear > 0) {
      $where[] = "c.school_year_id = ?";
      $params[] = $schoolYear;
    }

    // Điều kiện QR OK (dùng lại nhiều chỗ)
    $qrOkSql = "EXISTS (
    SELECT 1
    FROM attendance_logs al
    WHERE al.user_id = r.user_id
      AND al.campaign_id = r.campaign_id
      AND al.result = 'ok'
  )";

    /* =========================
       SCOPE theo ROLE
       - Admin/reviewer "thật" (không phải bithu/gvcn) -> xem tất cả (không scope)
       - Còn lại -> scope theo role như bạn yêu cầu
    ========================= */
    $canSeeAll = $isReviewer && !in_array($userRole, ['bithu', 'gvcn'], true);

    if ($canSeeAll) {
      // admin/scorer -> xem tất cả theo filter ở trên (nếu isReviewer)
    } elseif ($userRole === 'bithu') {

      if ((int) ($scope['chidoan_group_id'] ?? 0) === 1) {
        // bí thư chi đoàn lớp
        $where[] = 'm.class_id = ?';
        $params[] = (int) ($scope['class_id'] ?? 0);
      } else {
        // bí thư chi đoàn GV
        $where[] = 'm.chidoan_group_id = 2';
      }

    } elseif ($userRole === 'gvcn') {

      $gvcnClassIds = array_values(array_filter(array_map('intval', $gvcnClassIds)));
      if (!$gvcnClassIds) {
        forbidden();
      }

      $in = implode(',', array_fill(0, count($gvcnClassIds), '?'));
      $where[] = "m.class_id IN ($in)";
      $params = array_merge($params, $gvcnClassIds);

    } else {
      // user thường -> chỉ thấy của mình
      $where[] = 'r.user_id = ?';
      $params[] = $userId;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $fromSql = "
    FROM registrations r
    JOIN campaigns c ON c.id = r.campaign_id
    LEFT JOIN users u ON u.id = r.user_id
    LEFT JOIN members m ON m.user_id = r.user_id
    LEFT JOIN classes cls ON cls.id = m.class_id
    LEFT JOIN departments d ON d.id = m.department_id
  ";

    // COUNT
    $stmt = $pdo->prepare("
    SELECT COUNT(*)
    $fromSql
    $whereSql
  ");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    if ($page > $totalPages)
      $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    // DATA
    $stmt = $pdo->prepare("
    SELECT
      r.id,
      r.campaign_id,
      r.status,
      r.note,
      r.score,
      c.score AS base_score,
      (IFNULL(r.score,0) - IFNULL(c.score,0)) AS added_score,
      r.registered_at,

      ($qrOkSql) AS qr_ok,

      c.title AS ctitle,
      c.supervisor_id AS supervisor_id,

      COALESCE(m.fullname, u.fullname, u.username) AS fullname,
      m.phone,

      cls.name AS class_name,
      d.name AS dept_name,
      d.type AS dept_type

    $fromSql
    $whereSql
    ORDER BY r.registered_at DESC
    LIMIT $perPage OFFSET $offset
  ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // admin vẫn thấy hết; còn lại phải là phụ trách
    $isAdmin = in_array($userRole, ['admin', 'superadmin'], true); // nếu role bạn tên khác thì thêm vào
    $hasReviewPerm = can('campaign_scoring', 'review');            // điều kiện bắt buộc theo yêu cầu mới

    foreach ($rows as &$r) {
      $sup = (int) ($r['supervisor_id'] ?? 0);
      $isSupervisor = ($sup > 0 && $sup === $userId);

      // ✅ CHỈ HIỆN NÚT KHI: có quyền review và (admin hoặc phụ trách)
      $r['can_action'] = ($hasReviewPerm && ($isAdmin || $isSupervisor)) ? 1 : 0;

      unset($r['supervisor_id']); // optional
    }
    unset($r);

    echo json_encode([
      'success' => true,
      'data' => $rows,   // ✅ trả đúng $rows, KHÔNG fetchAll lần 2
      'page' => $page,
      'totalPages' => $totalPages
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }


  /* =====================================================
     EXPORT REGISTRATIONS (CÓ ĐIỂM)
  ===================================================== */
  if ($action === 'export') {

    if (empty($_SESSION['user_id'])) {
      echo json_encode([
        'success' => false,
        'need_login' => 1,
        'error' => 'Vui lòng đăng nhập'
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!can('campaign_scoring', 'view')) {
      forbidden();
    }

    $userId = (int) $_SESSION['user_id'];
    [$userRole, $scope, $gvcnClassIds] = getUserRoleScope($pdo, $userId);

    $campaignId = (int) ($_GET['campaign_id'] ?? 0);
    $schoolYear = (int) ($_GET['school_year'] ?? 0);

    $where = [];
    $params = [];

    $idsStr = $_GET['ids'] ?? '';
    $ids = [];
    if ($idsStr !== '') {
      $ids = array_map('intval', array_filter(explode(',', $idsStr)));
    }

    // ✅ scope giống list để không leak dữ liệu
    $canSeeAll =
      (can('campaign_scoring', 'review') ||
        can('campaign_scoring', 'update') ||
        can('campaign_scoring', 'delete'))
      && !in_array($userRole, ['bithu', 'gvcn'], true);

    if ($canSeeAll) {
      // ok
    } elseif ($userRole === 'bithu') {
      if ((int) $scope['chidoan_group_id'] === 1) {
        $where[] = 'm.class_id = ?';
        $params[] = (int) $scope['class_id'];
      } else {
        $where[] = 'm.chidoan_group_id = 2';
      }
    } elseif ($userRole === 'gvcn') {
      $in = implode(',', array_fill(0, count($gvcnClassIds), '?'));
      $where[] = "m.class_id IN ($in)";
      $params = array_merge($params, $gvcnClassIds);
    } else {
      $where[] = 'r.user_id = ?';
      $params[] = $userId;
    }

    if (!empty($ids)) {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $where[] = "r.id IN ($in)";
      $params = array_merge($params, $ids);
    } else {
      // ✅ CHỈ XUẤT USER ĐÃ QUÉT QR
      $where[] = "(
      EXISTS (
        SELECT 1
        FROM attendance_logs al
        WHERE al.user_id = r.user_id
          AND al.campaign_id = r.campaign_id
          AND al.result = 'ok'
      )
      OR r.status <> 'approved'
    )";

      if ($campaignId > 0) {
        $where[] = 'r.campaign_id = ?';
        $params[] = $campaignId;
      }

      if ($schoolYear > 0) {
        $where[] = 'c.school_year_id = ?';
        $params[] = $schoolYear;
      }
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
      SELECT
        c.title AS campaign,
        cls.name AS class_name,
        d.name AS dept_name,
        d.type AS dept_type,
        COALESCE(m.fullname, u.fullname, u.username) AS fullname,
        m.phone,
        r.status,
        r.score,
        r.registered_at,
        r.note
      FROM registrations r
      JOIN campaigns c ON c.id = r.campaign_id
      LEFT JOIN users u ON u.id = r.user_id
      LEFT JOIN members m ON m.user_id = r.user_id
      LEFT JOIN classes cls ON cls.id = m.class_id
      LEFT JOIN departments d ON d.id = m.department_id
      $whereSql
      ORDER BY c.title, r.registered_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // filename
    $campaignTitle = 'Tat ca phong trao';
    if ($campaignId > 0) {
      $stm = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
      $stm->execute([$campaignId]);
      $campaignTitle = $stm->fetchColumn() ?: 'Phong trao';
    }

    $slugCampaign = slug_filename((string) $campaignTitle);
    $filename = "ds_dang_ky_phong_trao_{$slugCampaign}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
      'STT',
      'Phong trào',
      'Lớp',
      'Họ tên',
      'SĐT',
      'Trạng thái',
      'Điểm',
      'Ngày đăng ký',
      'Ghi chú'
    ]);

    $statusMap = [
      'approved' => 'Đang tham gia',
      'excellent' => 'Hoàn thành xuất sắc',
      'good' => 'Hoàn thành tốt',
      'completed' => 'Hoàn thành',
      'incomplete' => 'Không hoàn thành',
      'cancelled' => 'Đã hủy',
    ];


    $stt = 1;

    foreach ($rows as $r) {

      if (!empty($r['class_name'])) {
        $unit = $r['class_name'];
      } elseif (!empty($r['dept_name'])) {
        $unit = ($r['dept_type'] === 'phong' ? 'Phòng ' : 'Khoa ') . $r['dept_name'];
      } else {
        $unit = '';
      }

      fputcsv($out, [
        $stt++,
        $r['campaign'],
        $unit,
        $r['fullname'],
        $r['phone'],
        $statusMap[$r['status']] ?? $r['status'],
        $r['score'],
        $r['registered_at'],
        $r['note']
      ]);
    }

    $campaignTitleLog = 'Tất cả phong trào';
    if ($campaignId > 0) {
      $stm = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
      $stm->execute([$campaignId]);
      $campaignTitleLog = $stm->fetchColumn() ?: 'Phong trào';
    }

    $schoolYearLabel = '';
    if ($schoolYear > 0) {
      $stmY = $pdo->prepare("SELECT year_label FROM school_years WHERE id=?");
      $stmY->execute([$schoolYear]);
      $schoolYearLabel = $stmY->fetchColumn() ?: '';
    }

    log_activity(
      'export',
      'registrations',
      'Danh sách đăng ký',
      null,
      "Xuất danh sách đăng ký phong trào <b>{$campaignTitleLog}</b>" . ($schoolYearLabel ? " năm học <b>{$schoolYearLabel}</b>" : "") . " (chỉ QR ok)"
    );

    fclose($out);
    exit;
  }

  /* =====================================================
     1) USER ĐĂNG KÝ PHONG TRÀO
  ===================================================== */
  if ($action === 'register') {

    $user_id = $_SESSION['user_id'] ?? 0;
    $cid = (int) ($_POST['campaign_id'] ?? 0);

    if (!$cid) {
      throw new Exception("Thiếu ID phong trào");
    }

    $stmt = $pdo->prepare("
      INSERT INTO registrations (user_id, campaign_id, registered_at, status, score, note)
      VALUES (?, ?, NOW(), 'approved', 0, NULL)
      ON DUPLICATE KEY UPDATE
        status='approved',
        registered_at=NOW(),
        score=0,
        note=NULL
    ");
    $stmt->execute([$user_id, $cid]);

    log_activity(
      'create',
      'registrations',
      'Phong trào',
      $cid,
      'Người dùng đăng ký tham gia phong trào ID=' . $cid
    );

    echo json_encode([
      'success' => true,
      'message' => 'Đăng ký thành công'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =====================================================
     2) ADMIN REVIEW + AUTO BONUS (chỉ chấm nếu có QR)
  ===================================================== */
  if ($action === 'review') {
    if (!can('campaign_scoring', 'review'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    $valid_status = ['excellent', 'good', 'completed', 'incomplete'];
    if (!in_array($status, $valid_status, true)) {
      throw new Exception("Trạng thái không hợp lệ");
    }

    $stmt = $pdo->prepare("SELECT user_id, campaign_id FROM registrations WHERE id=?");
    $stmt->execute([$id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    // ✅ FIX: check reg trước
    if (!$reg) {
      throw new Exception("Không tìm thấy đăng ký");
    }

    // Kiểm tra quét QR
    $chk = $pdo->prepare("
      SELECT 1
      FROM attendance_logs
      WHERE user_id = ?
        AND campaign_id = ?
        AND result = 'ok'
      LIMIT 1
    ");
    $chk->execute([(int) $reg['user_id'], (int) $reg['campaign_id']]);
    $hasQr = (bool)$chk->fetchColumn();

    $userName = getUserDisplayName($pdo, (int) $reg['user_id']);

    $stmt2 = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
    $stmt2->execute([(int) $reg['campaign_id']]);
    $camp = $stmt2->fetch(PDO::FETCH_ASSOC);
    $ctitle = $camp['title'] ?? 'phong trào';

    if (!$hasQr) {
      // Đăng ký nhưng không quét QR -> tự động tính Đăng ký không tham gia và bị trừ 1 điểm
      $status = 'incomplete';
      $userScore = -1.00;
      $statusVN = 'Đăng ký không tham gia';
    } else {
      // Có quét QR: Hoàn thành +5 điểm, Hoàn thành xuất sắc +7 điểm
      if ($status === 'excellent') {
        $userScore = 7.00;
        $statusVN = 'Hoàn thành xuất sắc';
      } elseif ($status === 'good' || $status === 'completed') {
        $userScore = 5.00;
        $status = 'completed'; // Đồng bộ về completed
        $statusVN = 'Hoàn thành';
      } else {
        $userScore = 0.00;
        $statusVN = 'Không hoàn thành';
      }
    }
    $userScoreDb = number_format($userScore, 2, '.', '');

    $pdo->prepare("
      UPDATE registrations
      SET status=?, note=?, score=?
      WHERE id=?
    ")->execute([$status, ($note !== '' ? $note : null), $userScoreDb, $id]);

    $scoreText = number_format((float) $userScoreDb, 2, '.', '');

    if (!$hasQr) {
      $msg = "Bạn đăng ký tham gia phong trào '<b>{$ctitle}</b>' nhưng không quét mã QR điểm danh (Không tham gia), bị trừ 1 điểm.";
    } elseif ($status === 'excellent') {
      $msg = "Bạn đã HOÀN THÀNH XUẤS SẮC phong trào '<b>{$ctitle}</b>' và nhận được <b>{$scoreText} điểm</b>.";
    } elseif ($status === 'completed') {
      $msg = "Bạn đã HOÀN THÀNH phong trào '<b>{$ctitle}</b>' và nhận được <b>{$scoreText} điểm</b>.";
    } else {
      $msg = "Bạn KHÔNG HOÀN THÀNH phong trào '<b>{$ctitle}</b>' (0.00 điểm).";
    }

    $pdo->prepare("
      INSERT INTO notifications (message, user_id, link)
      VALUES (?, ?, ?)
    ")->execute([$msg, (int) $reg['user_id'], "index.php?p=campaigns&tab=registered"]);

    log_activity(
      'review',
      'registrations',
      'Đăng ký phong trào',
      null,
      "Đánh giá đăng ký của <b>{$userName}</b> với trạng thái <b>{$statusVN}</b>, điểm={$userScoreDb}" . ($hasQr ? " (đã quét QR)" : " (chưa quét QR)")
    );

    echo json_encode(['success' => true, 'message' => 'Đã đánh giá kết quả thành công'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =====================================================
     3) ADMIN CHẤM ĐIỂM THỦ CÔNG (chỉ chấm nếu có QR)
  ===================================================== */
  if ($action === 'score') {
    if (!can('campaign_scoring', 'review'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $score = (float) ($_POST['score'] ?? 0);
    $scoreDb = number_format(round($score, 2), 2, '.', '');
    $note = trim($_POST['note'] ?? '');

    $info = $pdo->prepare("SELECT user_id, campaign_id FROM registrations WHERE id=?");
    $info->execute([$id]);
    $reg = $info->fetch(PDO::FETCH_ASSOC);

    // ✅ FIX: check reg trước
    if (!$reg) {
      throw new Exception("Không tìm thấy đăng ký để chấm điểm");
    }

    // ✅ chỉ chấm nếu có QR
    $chk = $pdo->prepare("
      SELECT 1
      FROM attendance_logs
      WHERE user_id = ?
        AND campaign_id = ?
        AND result = 'ok'
      LIMIT 1
    ");
    $chk->execute([(int) $reg['user_id'], (int) $reg['campaign_id']]);
    if (!$chk->fetchColumn()) {
      throw new Exception("Không thể chấm: User chưa quét mã QR");
    }

    $userName = getUserDisplayName($pdo, (int) $reg['user_id']);

    $stm2 = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
    $stm2->execute([(int) $reg['campaign_id']]);
    $ctitle = $stm2->fetchColumn() ?: 'phong trào';

    $pdo->prepare("UPDATE registrations SET score=?, note=? WHERE id=?")
      ->execute([$scoreDb, ($note !== '' ? $note : null), $id]);


    $msg = "Bạn đã được chấm <b>{$scoreDb} điểm</b> cho phong trào <b>{$ctitle}</b>.";
    $pdo->prepare("
      INSERT INTO notifications (message, user_id, link)
      VALUES (?, ?, ?)
    ")->execute([$msg, (int) $reg['user_id'], "index.php?p=campaigns&tab=registered"]);

    log_activity(
      'review',
      'registrations',
      'Đăng ký phong trào',
      null,
      "Chấm <b>{$score} điểm</b> cho <b>{$userName}</b> (QR ok)"
    );

    echo json_encode(['success' => true, 'message' => 'Đã chấm điểm'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =====================================================
     3.1) ADMIN CỘNG ĐIỂM (KHÔNG GHI ĐÈ) (chỉ nếu có QR)
  ===================================================== */
  if ($action === 'score_add') {
    if (!can('campaign_scoring', 'review'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $add = (float) ($_POST['score_add'] ?? 0);
    $add = round($add, 2);
    $addDb = number_format($add, 2, '.', '');
    $note = trim($_POST['note'] ?? '');

    if ($id <= 0) {
      throw new Exception("ID đăng ký không hợp lệ");
    }

    $stmt = $pdo->prepare("
      SELECT r.user_id, r.campaign_id, IFNULL(r.score,0) AS score, c.title
      FROM registrations r
      JOIN campaigns c ON c.id = r.campaign_id
      WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    // ✅ FIX: check reg trước
    if (!$reg) {
      throw new Exception("Không tìm thấy đăng ký");
    }

    // ✅ chỉ chấm nếu có QR
    $chk = $pdo->prepare("
      SELECT 1
      FROM attendance_logs
      WHERE user_id = ?
        AND campaign_id = ?
        AND result = 'ok'
      LIMIT 1
    ");
    $chk->execute([(int) $reg['user_id'], (int) $reg['campaign_id']]);
    if (!$chk->fetchColumn()) {
      throw new Exception("Không thể chấm: User chưa quét mã QR");
    }

    $oldScore = (float) $reg['score'];
    $newScore = round($oldScore + $add, 2);
    $newScoreDb = number_format($newScore, 2, '.', '');

    $ctitle = $reg['title'] ?? 'phong trào';

    $pdo->prepare("
      UPDATE registrations
      SET score = ?, note = ?
      WHERE id = ?
    ")->execute([$newScoreDb, ($note !== '' ? $note : null), $id]);


    $msg = "Bạn được cộng <b>{$addDb} điểm</b> cho phong trào <b>{$ctitle}</b>. Tổng điểm hiện tại: <b>{$newScoreDb}</b>.";
    $pdo->prepare("
      INSERT INTO notifications (message, user_id, link)
      VALUES (?, ?, ?)
    ")->execute([
          $msg,
          (int) $reg['user_id'],
          "index.php?p=campaigns&tab=registered"
        ]);

    $userName = getUserDisplayName($pdo, (int) $reg['user_id']);

    log_activity(
      'review',
      'registrations',
      'Đăng ký phong trào',
      null,
      "Cộng <b>{$add}</b> điểm cho <b>{$userName}</b> (từ {$oldScore} → {$newScore}) (QR ok)"
    );

    echo json_encode([
      'success' => true,
      'message' => 'Đã cộng điểm'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =====================================================
     4) ĐẾM SỐ NGƯỜI CHƯA REVIEW (ADMIN) - chỉ QR ok
  ===================================================== */
  if ($action === 'pending_count') {

    if (!can('campaign_scoring', 'view')) {
      echo json_encode(['success' => true, 'count' => 0], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!can('campaign_scoring', 'update')) {
      echo json_encode(['success' => true, 'count' => 0], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // ✅ FIX: chỉ đếm approved + có QR ok
    $stmt = $pdo->query("
      SELECT COUNT(*) 
      FROM registrations r
      WHERE r.status = 'approved'
        AND EXISTS (
          SELECT 1
          FROM attendance_logs al
          WHERE al.user_id = r.user_id
            AND al.campaign_id = r.campaign_id
            AND al.result = 'ok'
        )
    ");

    echo json_encode([
      'success' => true,
      'count' => (int) $stmt->fetchColumn()
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =====================================================
     2.5) ADMIN BULK REVIEW (chỉ những user có QR ok)
  ===================================================== */
  if ($action === 'bulk_review') {
    if (!can('campaign_scoring', 'review'))
      forbidden();

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) {
      throw new Exception("Thiếu danh sách đăng ký");
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
    if (count($ids) === 0) {
      throw new Exception("Danh sách đăng ký không hợp lệ");
    }

    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    $valid_status = ['excellent', 'good', 'completed', 'incomplete'];
    if (!in_array($status, $valid_status, true)) {
      throw new Exception("Trạng thái không hợp lệ");
    }

    $scoreFactor = 0.0;
    if ($status === 'excellent')
      $scoreFactor = 1.05;
    if ($status === 'good')
      $scoreFactor = 1.02;
    if ($status === 'completed')
      $scoreFactor = 1.00;
    if ($status === 'incomplete')
      $scoreFactor = 0.00;


    $pdo->beginTransaction();

    try {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));

      $stmt = $pdo->prepare("
        SELECT
          r.id,
          r.user_id,
          r.campaign_id,
          c.title,
          c.score AS base_score
        FROM registrations r
        JOIN campaigns c ON c.id = r.campaign_id
        WHERE r.id IN ($placeholders)
      ");
      $stmt->execute($ids);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$rows) {
        throw new Exception("Không tìm thấy đăng ký nào");
      }

      $upd = $pdo->prepare("
        UPDATE registrations
        SET status=?, note=?, score=?
        WHERE id=?
      ");

      $insNoti = $pdo->prepare("
        INSERT INTO notifications (message, user_id, link)
        VALUES (?, ?, ?)
      ");

      $updated = 0;

      foreach ($rows as $r) {
        // Kiểm tra QR cho từng đăng ký
        $chk = $pdo->prepare("
          SELECT 1
          FROM attendance_logs
          WHERE user_id = ?
            AND campaign_id = ?
            AND result = 'ok'
          LIMIT 1
        ");
        $chk->execute([(int) $r['user_id'], (int) $r['campaign_id']]);
        $hasQr = (bool)$chk->fetchColumn();

        $itemStatus = $status;
        if (!$hasQr) {
          // Chưa quét QR -> Không tham gia và bị trừ 1 điểm
          $itemStatus = 'incomplete';
          $userScore = -1.00;
        } else {
          // Đã quét QR: Hoàn thành +5 điểm, Hoàn thành xuất sắc +7 điểm
          if ($status === 'excellent') {
            $userScore = 7.00;
          } elseif ($status === 'good' || $status === 'completed') {
            $userScore = 5.00;
            $itemStatus = 'completed';
          } else {
            $userScore = 0.00;
          }
        }
        $userScoreDb = number_format($userScore, 2, '.', '');

        $upd->execute([$itemStatus, ($note !== '' ? $note : null), $userScoreDb, (int) $r['id']]);
        $updated++;

        $ctitle = $r['title'] ?? 'phong trào';
        $scoreText = number_format((float) $userScoreDb, 2, '.', '');

        if (!$hasQr) {
          $msg = "Bạn đăng ký tham gia phong trào '<b>{$ctitle}</b>' nhưng không quét mã QR điểm danh (Không tham gia), bị trừ 1 điểm.";
        } elseif ($itemStatus === 'excellent') {
          $msg = "Bạn đã HOÀN THÀNH XUẤT SẮC phong trào '<b>{$ctitle}</b>' và nhận được <b>{$scoreText} điểm</b>.";
        } elseif ($itemStatus === 'completed') {
          $msg = "Bạn đã HOÀN THÀNH phong trào '<b>{$ctitle}</b>' và nhận được <b>{$scoreText} điểm</b>.";
        } else {
          $msg = "Bạn KHÔNG HOÀN THÀNH phong trào '<b>{$ctitle}</b>' (0.00 điểm).";
        }

        $insNoti->execute([$msg, (int) $r['user_id'], "index.php?p=campaigns&tab=registered"]);
      }

      $pdo->commit();

      $statusVN = status_vn($status);
      $count = count($ids);

      log_activity(
        'review',
        'registrations',
        'Đăng ký phong trào',
        null,
        "Đánh giá hàng loạt <b>{$count}</b> đăng ký, trạng thái <b>{$statusVN}</b>"
      );

      echo json_encode([
        'success' => true,
        'message' => "Đã đánh giá hàng loạt",
        'updated' => $updated
      ], JSON_UNESCAPED_UNICODE);
      exit;

    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
  }

  /* =====================================================
     5) ADMIN HỦY ĐĂNG KÝ — XÓA LUÔN
  ===================================================== */
  if ($action === 'cancel') {
    if (!can('campaign_scoring', 'delete'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
      throw new Exception("ID đăng ký không hợp lệ");
    }

    $stmt = $pdo->prepare("
      SELECT id, user_id
      FROM registrations
      WHERE id=?
    ");
    $stmt->execute([$id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
      throw new Exception("Không tìm thấy đăng ký");
    }

    $pdo->prepare("DELETE FROM registrations WHERE id=?")->execute([$id]);

    $userName = getUserDisplayName($pdo, (int) $reg['user_id']);

    log_activity(
      'delete',
      'registrations',
      'Đăng ký phong trào',
      null,
      "Hủy và xóa đăng ký của <b>{$userName}</b>"
    );

    echo json_encode([
      'success' => true,
      'message' => 'Đã hủy và xóa đăng ký'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  throw new Exception("Hành động không hợp lệ");

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine()
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
