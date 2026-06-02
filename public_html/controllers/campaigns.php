<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$user = auth_user();
$userId = $user['id'] ?? null;


function forbidden()
{
  http_response_code(403);
  echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

function toTs(?string $s, string $mode = 'raw'): ?int
{
  $s = trim((string) $s);
  if ($s === '')
    return null;

  // hỗ trợ "YYYY-MM-DD" hoặc "YYYY-MM-DD HH:MM(:SS)" hoặc "YYYY-MM-DDTHH:MM"
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
    $s .= ($mode === 'end') ? ' 23:59:59' : ' 00:00:00';
  } else {
    $s = str_replace('T', ' ', $s);
  }

  $ts = strtotime($s);
  return $ts === false ? null : $ts;
}

function calcCampaignStatus(array $c): array
{
  $now = time();

  $start = toTs($c['start_date'] ?? null, 'start');
  $end = toTs($c['end_date'] ?? null, 'end'); // nếu có giờ thì dùng đúng giờ; nếu chỉ date thì 23:59:59

  if (($c['status'] ?? '') === 'cancelled') {
    return ['cancelled', 'Đã kết thúc'];
  }

  if ($start && $now < $start) {
    return ['hidden', 'Sắp diễn ra'];
  }

  if ($start && (!$end || $now <= $end)) {
    return ['active', 'Đang diễn ra'];
  }

  return ['cancelled', 'Đã kết thúc'];
}
function hasColumn(PDO $pdo, string $table, string $col): bool
{
  static $cache = [];
  $key = $table . '.' . $col;
  if (isset($cache[$key]))
    return $cache[$key];

  $st = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $col]);
  $cache[$key] = (bool) $st->fetchColumn();
  return $cache[$key];
}
function hasTable(PDO $pdo, string $table): bool
{
  static $cache = [];
  if (isset($cache[$table]))
    return $cache[$table];

  $st = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table]);
  $cache[$table] = (bool) $st->fetchColumn();
  return $cache[$table];
}

/**
 * Label: "Tên - MSSV - Role" (nếu có mssv/role)
 * Ưu tiên tên từ members, fallback users
 * Role: ưu tiên users.role_id -> roles.name, fallback users.role/role_name,
 *       fallback pivot user_roles nếu có.
 */
function buildUserLabelSqlWithRole(PDO $pdo, string $uAlias = 'u', string $mAlias = 'm', string $rAlias = 'r', string $pAlias = 'p'): array
{
  // users name col
  $uNameCol = pickFirstCol($pdo, 'users', ['full_name', 'fullname', 'name', 'display_name', 'username']);
  if (!$uNameCol)
    $uNameCol = 'username';

  // users phone col (fallback cuối)
  $uPhoneCol = pickFirstCol($pdo, 'users', [
    'phone',
    'mobile',
    'sdt',
    'so_dien_thoai',
    'tel',
    'dien_thoai',
    'phone_number'
  ]);

  // members cols
  $mNameCol = pickFirstCol($pdo, 'members', ['full_name', 'fullname', 'name', 'ho_ten']);
  $mMssvCol = pickFirstCol($pdo, 'members', ['mssv', 'student_code', 'student_id', 'student_no', 'ma_sv']);
  $mPhoneCol = pickFirstCol($pdo, 'members', [
    'phone',
    'sdt',
    'so_dien_thoai',
    'mobile',
    'tel',
    'dien_thoai',
    'phone_number'
  ]);

  // user_profiles phone col
  $pPhoneCol = null;
  if (hasTable($pdo, 'user_profiles')) {
    $pPhoneCol = pickFirstCol($pdo, 'user_profiles', [
      'phone',
      'mobile',
      'sdt',
      'so_dien_thoai',
      'tel',
      'dien_thoai',
      'phone_number'
    ]);
  }

  // ===== JOIN members =====
  $membersJoin = "";
  $canAllowMembers = hasTable($pdo, 'members');
  $canJoinByMemberUserId = $canAllowMembers && hasColumn($pdo, 'members', 'user_id');
  $canJoinByUsersMemberId = $canAllowMembers && hasColumn($pdo, 'users', 'member_id');

  if ($canJoinByMemberUserId) {
    $membersJoin = "LEFT JOIN members {$mAlias} ON {$mAlias}.user_id = {$uAlias}.id";
  } elseif ($canJoinByUsersMemberId) {
    $membersJoin = "LEFT JOIN members {$mAlias} ON {$mAlias}.id = {$uAlias}.member_id";
  }

  // ===== JOIN user_profiles (QUAN TRỌNG) =====
  $profileJoin = "";
  if (hasTable($pdo, 'user_profiles') && hasColumn($pdo, 'user_profiles', 'user_id')) {
    $profileJoin = "LEFT JOIN user_profiles {$pAlias} ON {$pAlias}.user_id = {$uAlias}.id";
  }

  // ===== base expr =====
  $uNameExpr = "NULLIF(TRIM({$uAlias}.`{$uNameCol}`), '')";
  $mNameExpr = ($membersJoin && $mNameCol) ? "NULLIF(TRIM({$mAlias}.`{$mNameCol}`), '')" : "NULL";
  $mMssvExpr = ($membersJoin && $mMssvCol) ? "NULLIF(TRIM({$mAlias}.`{$mMssvCol}`), '')" : "NULL";

  // ===== base label =====
  if ($membersJoin && $mNameCol && $mMssvCol) {
    $baseLabel = "
      CASE
        WHEN {$mNameExpr} IS NOT NULL THEN
          CASE
            WHEN {$mMssvExpr} IS NOT NULL THEN CONCAT({$mNameExpr}, ' - ', {$mMssvExpr})
            ELSE {$mNameExpr}
          END
        ELSE COALESCE({$uNameExpr}, {$uAlias}.username)
      END
    ";
  } elseif ($membersJoin && $mNameCol) {
    $baseLabel = "COALESCE({$mNameExpr}, COALESCE({$uNameExpr}, {$uAlias}.username))";
  } else {
    $baseLabel = "COALESCE({$uNameExpr}, {$uAlias}.username)";
  }

  // ===== phone expr: members.phone -> user_profiles.phone -> users.phone (fallback) =====
  $mPhoneExpr = ($membersJoin && $mPhoneCol) ? "NULLIF(TRIM({$mAlias}.`{$mPhoneCol}`), '')" : "NULL";
  $pPhoneExpr = ($profileJoin && $pPhoneCol) ? "NULLIF(TRIM({$pAlias}.`{$pPhoneCol}`), '')" : "NULL";
  $uPhoneExpr = ($uPhoneCol) ? "NULLIF(TRIM({$uAlias}.`{$uPhoneCol}`), '')" : "NULL";

  // Đúng yêu cầu bạn: ưu tiên members, nếu không có members thì lấy profile
  $phoneExpr = "COALESCE({$mPhoneExpr}, {$pPhoneExpr}, {$uPhoneExpr})";

  // ===== role join + role expr =====
  $roleJoin = "";
  $roleExpr = "NULL";

  if (hasTable($pdo, 'roles') && hasColumn($pdo, 'users', 'role_id')) {
    $roleNameCol = pickFirstCol($pdo, 'roles', ['name', 'title', 'role_name', 'label']);
    if ($roleNameCol) {
      $roleJoin = "LEFT JOIN roles {$rAlias} ON {$rAlias}.id = {$uAlias}.role_id";
      $roleExpr = "NULLIF(TRIM({$rAlias}.`{$roleNameCol}`), '')";
    }
  }

  if ($roleExpr === "NULL") {
    $uRoleCol = pickFirstCol($pdo, 'users', ['role_name', 'role']);
    if ($uRoleCol) {
      $roleExpr = "NULLIF(TRIM({$uAlias}.`{$uRoleCol}`), '')";
    }
  }

  // ===== final label =====
  $label = "
    CASE
      WHEN {$roleExpr} IS NOT NULL THEN CONCAT( ({$baseLabel}), ' - ', {$roleExpr} )
      ELSE ({$baseLabel})
    END
  ";

  return [
    'members_join' => $membersJoin,
    'profile_join' => $profileJoin,   // ✅ thêm cái này
    'role_join' => $roleJoin,
    'label' => $label,
    'phone' => $phoneExpr,
  ];
}




function pickFirstCol(PDO $pdo, string $table, array $cands): ?string
{
  foreach ($cands as $c) {
    if (hasColumn($pdo, $table, $c))
      return $c;
  }
  return null;
}

/**
 * Build JOIN members + label expr cho 1 user alias.
 * - Ưu tiên name từ members (full name) rồi mới fallback users
 * - Nếu có mssv ở members => "name - mssv"
 * - Tự dò quan hệ:
 *    members.user_id = users.id  (phổ biến)
 *    hoặc users.member_id = members.id
 */
function buildUserLabelSql(PDO $pdo, string $uAlias = 'u', string $mAlias = 'm'): array
{
  // users name col
  $uNameCol = pickFirstCol($pdo, 'users', ['full_name', 'fullname', 'name', 'display_name', 'username']);
  if (!$uNameCol)
    $uNameCol = 'username'; // chắc chắn có

  // members cols
  $mNameCol = pickFirstCol($pdo, 'members', ['full_name', 'fullname', 'name', 'ho_ten']);
  $mMssvCol = pickFirstCol($pdo, 'members', ['mssv', 'student_code', 'student_id', 'student_no', 'ma_sv']);

  // join members <-> users
  $join = "";
  $canJoinByMemberUserId = hasColumn($pdo, 'members', 'user_id');      // m.user_id
  $canJoinByUsersMemberId = hasColumn($pdo, 'users', 'member_id');     // u.member_id

  if ($canJoinByMemberUserId) {
    $join = "LEFT JOIN members {$mAlias} ON {$mAlias}.user_id = {$uAlias}.id";
  } elseif ($canJoinByUsersMemberId) {
    $join = "LEFT JOIN members {$mAlias} ON {$mAlias}.id = {$uAlias}.member_id";
  }

  // base expr users
  $uNameExpr = "NULLIF(TRIM({$uAlias}.`{$uNameCol}`), '')";

  // nếu không join được members hoặc members không có name col => chỉ lấy users name
  if ($join === "" || !$mNameCol) {
    return [
      'join' => "",
      'label' => "COALESCE({$uNameExpr}, {$uAlias}.username)"
    ];
  }

  $mNameExpr = "NULLIF(TRIM({$mAlias}.`{$mNameCol}`), '')";

  if ($mMssvCol) {
    $mMssvExpr = "NULLIF(TRIM({$mAlias}.`{$mMssvCol}`), '')";
    $label = "
      CASE
        WHEN {$mNameExpr} IS NOT NULL THEN
          CASE
            WHEN {$mMssvExpr} IS NOT NULL THEN CONCAT({$mNameExpr}, ' - ', {$mMssvExpr})
            ELSE {$mNameExpr}
          END
        ELSE COALESCE({$uNameExpr}, {$uAlias}.username)
      END
    ";
  } else {
    $label = "COALESCE({$mNameExpr}, COALESCE({$uNameExpr}, {$uAlias}.username))";
  }

  return [
    'join' => $join,
    'label' => $label
  ];
}

/* ==================== BÍ THƯ – LẤY LỚP QUẢN LÝ ==================== */
function getBithuClass(PDO $pdo, int $userId): ?array
{
  $stmt = $pdo->prepare("
    SELECT bs.class_id, cl.name as class_name 
    FROM bithu_scopes bs 
    LEFT JOIN classes cl ON cl.id = bs.class_id 
    WHERE bs.user_id = ? AND bs.chidoan_group_id = 1 
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
/* ==================== LẤY DANH SÁCH ĐOÀN VIÊN LỚP ĐỂ TICK CHỌN ==================== */
function getClassMembersForCampaign(PDO $pdo, int $class_id, int $campaign_id): array
{
  $stmt = $pdo->prepare("
    SELECT 
      m.id as member_id,
      m.fullname,
      m.mssv,
      m.user_id,
      IF(r.id IS NOT NULL, 1, 0) as already_registered
    FROM members m
    LEFT JOIN registrations r ON r.user_id = m.user_id AND r.campaign_id = ?
    WHERE m.class_id = ? 
      AND m.user_id IS NOT NULL 
      AND m.is_locked = 0
    ORDER BY m.fullname ASC
  ");
  $stmt->execute([$campaign_id, $class_id]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ==================== ĐĂNG KÝ NHỮNG NGƯỜI ĐƯỢC TICK ==================== */
function registerSelectedMembers(PDO $pdo, int $campaign_id, array $userIds): int
{
  if (empty($userIds))
    return 0;

  $inserted = 0;
  $stmt = $pdo->prepare("
    INSERT IGNORE INTO registrations 
    (user_id, campaign_id, registered_at, status) 
    VALUES (?, ?, NOW(), 'approved')
  ");
  foreach ($userIds as $uid) {
    $stmt->execute([$uid, $campaign_id]);
    $inserted += $stmt->rowCount();
  }
  return $inserted;
}
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

if ($action === '') {
  echo json_encode(['ok' => 0, 'error' => 'Thiếu action']);
  exit;
}
/**
 * Tạo mã QR bảo mật cho phong trào
 * Ví dụ: CAMPQR_a3f9c8d4e1123abc
 */
function semesterFallbackLabel(?string $code): ?string
{
  $code = trim((string) $code);
  if ($code === '')
    return null;
  if ($code === 'HK1')
    return 'Học kỳ I';
  if ($code === 'HK2')
    return 'Học kỳ II';
  return $code;
}

function generateSecureQR(): string
{
  return 'CAMPQR_' . bin2hex(random_bytes(8)); // 16 bytes hex => 32 ký tự
}

try {
  if ($action === 'school_years') {
    $q = $pdo->query("
    SELECT id, year_label
    FROM school_years
    ORDER BY year_label DESC
  ");
    echo json_encode([
      'ok' => 1,
      'items' => $q->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;
  }

  if ($action === 'semesters') {
    $stmt = $pdo->query("
    SELECT code, label
    FROM semesters
    WHERE is_active = 1
    ORDER BY sort_order ASC, code ASC
  ");

    echo json_encode([
      'ok' => 1,
      'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'users') {
    // nếu muốn chỉ admin mới lấy ds thì bật can() theo ý bạn
    // if (!can('campaigns','create') && !can('campaigns','update')) forbidden();

    $parts = buildUserLabelSqlWithRole($pdo, 'u', 'm', 'r', 'p');

    $sql = "
SELECT
  u.id,
  {$parts['label']} AS fullname,
  {$parts['phone']} AS phone
FROM users u
{$parts['members_join']}
{$parts['profile_join']}   -- ✅ BẮT BUỘC
{$parts['role_join']}
ORDER BY fullname ASC
";




    $stmt = $pdo->query($sql);

    echo json_encode([
      'ok' => 1,
      'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }





  /* ============================
    TAB 1 – LIST + FILTER + PAGINATION (SPA)
 ============================ */

  if ($action === 'list_tab1') {

    $viewId = (int) ($_GET['view'] ?? 0);

    $user = auth_user();
    $uid = $user['id'] ?? 0;
    $supParts = buildUserLabelSqlWithRole($pdo, 'sup', 'msup', 'rsup', 'psup');
    $supPhoneSql = $supParts['phone'];

    $supJoinSql = "
  LEFT JOIN users sup ON sup.id = c.supervisor_id
  {$supParts['members_join']}
  {$supParts['profile_join']}  -- ✅ BẮT BUỘC
  {$supParts['role_join']}
";


    $supLabelSql = $supParts['label'];

    $bithuClass = $uid ? getBithuClass($pdo, $uid) : null;

    // ===========================
// AUTO SYNC STATUS THEO NGÀY
// ===========================
// 1) hidden -> active khi đã tới ngày bắt đầu (và chưa qua ngày kết thúc)
    $pdo->exec("
  UPDATE campaigns
  SET status='active'
  WHERE status='hidden'
    AND start_date IS NOT NULL
    AND start_date <= NOW()
    AND (end_date IS NULL OR end_date >= NOW())
");

    // 2) active/hidden -> cancelled khi đã qua ngày kết thúc
    $pdo->exec("
  UPDATE campaigns
  SET status='cancelled'
  WHERE status IN ('active','hidden')
    AND end_date IS NOT NULL
    AND end_date < NOW()
");

    /* =================================================
       VIEW MODE – CHỈ 1 PHONG TRÀO (SHARE LINK)
    ================================================= */
    if ($viewId > 0) {

      $stmt = $pdo->prepare("
SELECT
  c.*,
  sy.year_label AS school_year_label,
  sem.label AS semester_label,
  {$supLabelSql} AS supervisor_name,
  {$supPhoneSql} AS supervisor_phone,

  (
    SELECT COUNT(*)
    FROM registrations r
    WHERE r.campaign_id = c.id
  ) AS reg
FROM campaigns c
LEFT JOIN school_years sy ON sy.id = c.school_year_id
LEFT JOIN semesters sem ON sem.code = c.semester_code
$supJoinSql
WHERE c.id = ?
LIMIT 1
");



      $stmt->execute([$viewId]);

      $c = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!empty($c) && empty($c['semester_label'])) {
        $c['semester_label'] = semesterFallbackLabel($c['semester_code'] ?? null);
      }

      if (!$c) {
        echo json_encode([
          'ok' => 1,
          'items' => [],
          'page' => 1,
          'totalPages' => 1
        ]);
        exit;
      }

      /* ===== MAP STATUS TEXT ===== */
      [$statusCode, $statusText] = calcCampaignStatus($c);

      $c['status'] = $statusCode;
      $c['status_text'] = $statusText;

      /* ===== FORMAT DATE ===== */
      $c['start_fmt'] = $c['start_date'] ? date('d/m/Y', strtotime($c['start_date'])) : '';
      $c['end_fmt'] = $c['end_date'] ? date('d/m/Y', strtotime($c['end_date'])) : '';
      $c['place'] = $c['place'] ?? '';
      $c['scope'] = $c['scope'] ?? '';

      if ($uid && !is_admin()) {
        $stm = $pdo->prepare("
    SELECT status 
    FROM registrations 
    WHERE user_id=? AND campaign_id=?
  ");
        $stm->execute([$uid, $c['id']]);
        $c['user_status'] = $stm->fetchColumn() ?: null;
      }

      // ✅ CHỈ ẨN SAU KHI BIẾT STATUS
      if ($c['user_status'] !== 'approved') {
        $c['url_zalo'] = null;
      }
      // THÊM THÔNG TIN BÍ THƯ
      $c['is_bithu'] = $bithuClass ? true : false;
      $c['bithu_class_id'] = $bithuClass['class_id'] ?? null;
      $c['bithu_class_name'] = $bithuClass['class_name'] ?? null;

      echo json_encode([
        'ok' => 1,
        'items' => [$c],
        'page' => 1,
        'totalPages' => 1
      ]);
      exit;
    }

    /* =================================================
       NORMAL LIST MODE – FILTER + PAGINATION
    ================================================= */

    $perPage = 6;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $status = $_GET['status'] ?? 'all';
    $q = trim($_GET['q'] ?? '');

    $schoolYear = (int) ($_GET['school_year'] ?? 0);
    $semester = trim((string) ($_GET['semester'] ?? ''));

    // Cho phép semester truyền lên là ID (numeric) hoặc CODE (HK1/HK2)
    if ($semester !== '' && ctype_digit($semester)) {
      $qSem = $pdo->prepare("SELECT code FROM semesters WHERE id=? LIMIT 1");
      $qSem->execute([(int) $semester]);
      $semester = (string) ($qSem->fetchColumn() ?? '');
    }


    $where = [];
    $params = [];

    if ($status !== 'all') {
      $where[] = "c.status = :status";
      $params[':status'] = $status;
    }

    if ($q !== '') {
      $where[] = "(c.title LIKE :q1 OR c.description LIKE :q2)";
      $kw = "%$q%";
      $params[':q1'] = $kw;
      $params[':q2'] = $kw;
    }
    // ===== LỌC NĂM HỌC =====
    if ($schoolYear > 0) {
      $where[] = "c.school_year_id = :school_year";
      $params[':school_year'] = $schoolYear;
    }

    // ===== LỌC HỌC KỲ =====
    if ($semester !== '') {
      $where[] = "c.semester_code = :semester_code";
      $params[':semester_code'] = $semester;
    }


    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    /* ===== COUNT ===== */
    $qTotal = $pdo->prepare("
    SELECT COUNT(*) 
    FROM campaigns c
    $whereSql
  ");
    $qTotal->execute($params);
    $totalRows = (int) $qTotal->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    /* ===== DATA ===== */
    $stmt = $pdo->prepare("
SELECT
  c.*,
  sy.year_label AS school_year_label,
  sem.label AS semester_label,
  {$supLabelSql} AS supervisor_name,
  {$supPhoneSql} AS supervisor_phone,

  (
    SELECT COUNT(*)
    FROM registrations r
    WHERE r.campaign_id = c.id
  ) AS reg
FROM campaigns c
LEFT JOIN school_years sy ON sy.id = c.school_year_id
LEFT JOIN semesters sem ON sem.code = c.semester_code
$supJoinSql

$whereSql
ORDER BY
  CASE WHEN c.status='active' THEN 1 ELSE 0 END DESC,
  c.start_date DESC
LIMIT $offset, $perPage
");




    foreach ($params as $k => $v) {
      $stmt->bindValue($k, $v);
    }

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$c) {

      [$statusCode, $statusText] = calcCampaignStatus($c);

      $c['status'] = $statusCode;
      $c['status_text'] = $statusText;

      $c['start_fmt'] = $c['start_date'] ? date('d/m/Y', strtotime($c['start_date'])) : '';
      $c['end_fmt'] = $c['end_date'] ? date('d/m/Y', strtotime($c['end_date'])) : '';
      $c['place'] = $c['place'] ?? '';
      $c['scope'] = $c['scope'] ?? '';
      $c['user_status'] = null;

      if ($uid && !is_admin()) {
        $stm = $pdo->prepare("
    SELECT status 
    FROM registrations 
    WHERE user_id=? AND campaign_id=?
  ");
        $stm->execute([$uid, $c['id']]);
        $c['user_status'] = $stm->fetchColumn() ?: null;
      }

      // ✅ SAU KHI BIẾT STATUS MỚI ẨN
      if ($c['user_status'] !== 'approved') {
        $c['url_zalo'] = null;
      }
      // THÊM THÔNG TIN BÍ THƯ
      $c['is_bithu'] = $bithuClass ? true : false;
      $c['bithu_class_id'] = $bithuClass['class_id'] ?? null;
      $c['bithu_class_name'] = $bithuClass['class_name'] ?? null;
    }

    echo json_encode([
      'ok' => 1,
      'items' => $rows,
      'page' => $page,
      'totalPages' => $totalPages
    ]);
    exit;
  }






  /* ============================
        1) LẤY DANH SÁCH
     ============================ */
  if ($action === 'list') {


    $q = $pdo->query("SELECT * FROM campaigns ORDER BY start_date DESC");
    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC));
    exit;
  }

  /* ============================
        2) LẤY 1 PHONG TRÀO
     ============================ */
  if ($action === 'get') {

    $id = (int) ($_GET['id'] ?? 0);

    if ($id <= 0) {
      echo json_encode(['error' => 'Thiếu ID']);
      exit;
    }
    $supParts = buildUserLabelSqlWithRole($pdo, 'sup', 'msup', 'rsup', 'psup');
    $supPhoneSql = $supParts['phone'];

    $supJoinSql = "
  LEFT JOIN users sup ON sup.id = c.supervisor_id
  {$supParts['members_join']}
  {$supParts['profile_join']}  -- ✅ BẮT BUỘC
  {$supParts['role_join']}
";

    $supLabelSql = $supParts['label'];

    $q = $pdo->prepare("
  SELECT
    c.*,
    sy.year_label AS school_year_label,
    sem.label AS semester_label,
    {$supLabelSql} AS supervisor_name,
    {$supPhoneSql} AS supervisor_phone

  FROM campaigns c
  LEFT JOIN school_years sy ON sy.id = c.school_year_id
  LEFT JOIN semesters sem ON sem.code = c.semester_code
  $supJoinSql
  WHERE c.id=?
  LIMIT 1
");

    $q->execute([$id]);
    $data = $q->fetch(PDO::FETCH_ASSOC);

    if ($data && empty($data['semester_label'])) {
      $data['semester_label'] = semesterFallbackLabel($data['semester_code'] ?? null);
    }

    echo json_encode($data ?: ['error' => 'Không tìm thấy phong trào'], JSON_UNESCAPED_UNICODE);
    exit;

  }

  /* ============================
     XỬ LÝ UPLOAD IMAGE (CREATE + UPDATE)
     - Giữ ảnh cũ nếu không upload mới
     - Check move_uploaded_file
  ============================ */
  $uploadDir = __DIR__ . '/../uploads/campaigns/';
  if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
  }

  // Hàm lấy ảnh hiện tại từ DB (dùng cho UPDATE để giữ ảnh cũ)
  function getCurrentCampaignImage(PDO $pdo, int $id): ?string
  {
    $st = $pdo->prepare("SELECT image FROM campaigns WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $img = $st->fetchColumn();
    return $img ? (string) $img : null;
  }

  // Cho phép extension an toàn
  $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];

  // Mặc định: ảnh cũ (ưu tiên DB), fallback POST old_image nếu có
  $imageName = null;
  if (!empty($_POST['id'])) { // update
    $imageName = getCurrentCampaignImage($pdo, (int) $_POST['id']);
  }
  if (!$imageName && !empty($_POST['old_image'])) {
    $imageName = basename((string) $_POST['old_image']);
  }

  // Nếu có upload file mới
  if (!empty($_FILES['image']['name'])) {
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
      http_response_code(400);
      echo json_encode(['ok' => 0, 'error' => 'File ảnh không hợp lệ (chỉ jpg/jpeg/png/webp)'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $newName = 'cp_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $targetPath = $uploadDir . $newName;

    if (!is_uploaded_file($_FILES['image']['tmp_name'])) {
      http_response_code(400);
      echo json_encode(['ok' => 0, 'error' => 'Upload không hợp lệ'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
      http_response_code(500);
      echo json_encode(['ok' => 0, 'error' => 'Không thể lưu ảnh lên server (kiểm tra quyền thư mục uploads/campaigns)'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    // (Tuỳ chọn) Xóa ảnh cũ nếu có và khác ảnh mới
    if (!empty($imageName) && $imageName !== $newName) {
      $oldPath = $uploadDir . basename($imageName);
      if (is_file($oldPath))
        @unlink($oldPath);
    }

    $imageName = $newName;
  }


  /* ============================
      3) THÊM PHONG TRÀO (ADMIN)
   ============================ */
  if ($action === 'create') {
    if (!can('campaigns', 'create'))
      forbidden();

    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);

    $schoolYearLabel = null;
    if ($schoolYearId > 0) {
      $qSY = $pdo->prepare("SELECT year_label FROM school_years WHERE id=? LIMIT 1");
      $qSY->execute([$schoolYearId]);
      $schoolYearLabel = $qSY->fetchColumn();
    }

    // nhận cả 2 key để không vỡ frontend cũ
    $semesterCode = trim((string) ($_POST['semester_code'] ?? ($_POST['semester'] ?? '')));
    $supervisorId = (int) ($_POST['supervisor_id'] ?? 0);
    $supervisorId = $supervisorId > 0 ? $supervisorId : null;
    if ($semesterCode !== '') {
      $qSem = $pdo->prepare("SELECT 1 FROM semesters WHERE code=? AND is_active=1 LIMIT 1");
      $qSem->execute([$semesterCode]);
      if (!$qSem->fetchColumn()) {
        echo json_encode(['ok' => 0, 'error' => 'Học kỳ không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    $stm = $pdo->prepare("
    INSERT INTO campaigns (
      code, image, title, place,
      start_date, end_date, register_deadline,
      school_year_id, supervisor_id, school_year, semester_code,
      target, scope, description, note, score, url_zalo
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");


    $stm->execute([
      $_POST['code'] ?? '',
      $imageName,
      $_POST['title'] ?? '',
      $_POST['place'] ?? '',
      $_POST['start_date'] ?: null,
      $_POST['end_date'] ?: null,
      $_POST['register_deadline'] ?: null,
      $schoolYearId,
      $supervisorId,
      $schoolYearLabel,
      $semesterCode ?: null,
      (int) ($_POST['target'] ?? 0),
      $_POST['scope'] ?? '',
      $_POST['description'] ?? '',
      $_POST['note'] ?? '',
      (int) ($_POST['score'] ?? 0),
      $_POST['url_zalo'] ?? null,
    ]);


    log_activity('create', 'campaigns', 'Phong trào', null, 'Thêm phong trào: ' . ($_POST['title'] ?? ''));
    echo json_encode(['ok' => 1]);
    exit;
  }



  /* ============================
      3.5) LƯU GHI CHÚ PHONG TRÀO (ADMIN)
   ============================ */
  if ($action === 'save_note') {
    if (!can('campaigns', 'update'))
      forbidden();

    $cid = (int) $_POST['campaign_id'];
    $note = trim($_POST['note'] ?? '');

    $stmt = $pdo->prepare("UPDATE campaigns SET note=? WHERE id=?");
    $stmt->execute([$note, $cid]);

    log_activity(
      'update',
      'campaigns',
      'Phong trào',
      null,
      'Cập nhật ghi chú phong trào'
    );

    echo json_encode(['ok' => 1]);
    exit;
  }

  /* ============================
      4) CẬP NHẬT PHONG TRÀO (ADMIN)
   ============================ */
  if ($action === 'update') {
    if (!can('campaigns', 'update'))
      forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['error' => 'Thiếu ID phong trào']);
      exit;
    }

    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);

    $schoolYearLabel = null;
    if ($schoolYearId > 0) {
      $qSY = $pdo->prepare("SELECT year_label FROM school_years WHERE id=? LIMIT 1");
      $qSY->execute([$schoolYearId]);
      $schoolYearLabel = $qSY->fetchColumn();
    }

    $semesterCode = trim((string) ($_POST['semester_code'] ?? ($_POST['semester'] ?? '')));
    $supervisorId = (int) ($_POST['supervisor_id'] ?? 0);
    $supervisorId = $supervisorId > 0 ? $supervisorId : null;

    if ($semesterCode !== '') {
      $qSem = $pdo->prepare("SELECT 1 FROM semesters WHERE code=? AND is_active=1 LIMIT 1");
      $qSem->execute([$semesterCode]);
      if (!$qSem->fetchColumn()) {
        echo json_encode(['ok' => 0, 'error' => 'Học kỳ không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }

    $qOld = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
    $qOld->execute([$id]);
    $oldTitle = $qOld->fetchColumn();

    $stm = $pdo->prepare("
    UPDATE campaigns SET
      image = ?,
      title = ?,
      place = ?,
      start_date = ?,
      end_date = ?,
      register_deadline = ?,
      school_year_id = ?,
      supervisor_id = ?,
      school_year = ?,
      semester_code = ?,
      target = ?,
      scope = ?,
      description = ?,
      note = ?,
      score = ?,
      url_zalo = ?
    WHERE id = ?
  ");


    $stm->execute([
      $imageName,
      $_POST['title'] ?? '',
      $_POST['place'] ?? '',
      $_POST['start_date'] ?: null,
      $_POST['end_date'] ?: null,
      $_POST['register_deadline'] ?: null,
      $schoolYearId,
      $supervisorId,
      $schoolYearLabel,
      $semesterCode ?: null,
      (int) ($_POST['target'] ?? 0),
      $_POST['scope'] ?? '',
      $_POST['description'] ?? '',
      $_POST['note'] ?? '',
      (int) ($_POST['score'] ?? 0),
      $_POST['url_zalo'] ?? null,
      $id
    ]);


    log_activity('update', 'campaigns', 'Phong trào', null, 'Cập nhật phong trào: ' . $oldTitle);
    echo json_encode(['ok' => 1]);
    exit;
  }




  /* ============================
        5) XÓA PHONG TRÀO (ADMIN)
     ============================ */
  if ($action === 'delete') {
    if (!can('campaigns', 'delete'))
      forbidden();
    $id = (int) $_POST['id'];

    // 🔹 LẤY TÊN TRƯỚC KHI XÓA
    $qOld = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
    $qOld->execute([$id]);
    $title = $qOld->fetchColumn();

    $pdo->prepare("DELETE FROM campaigns WHERE id=?")
      ->execute([(int) $_POST['id']]);

    log_activity(
      'delete',
      'campaigns',
      'Phong trào',
      null,
      'Xóa phong trào: ' . $title
    );

    echo json_encode(['ok' => 1]);
    exit;
  }

  /* ============================
        6) NGƯỜI DÙNG ĐĂNG KÝ
     ============================ */
  if ($action === 'register') {
    if (!auth_user()) {
      http_response_code(401);
      echo json_encode([
        'ok' => 0,
        'need_login' => 1,
        'error' => 'Vui lòng đăng nhập'
      ]);
      exit;
    }

    $uid = auth_user()['id'];
    $campaign_id = (int) $_POST['campaign_id'];

    // === LẤY THÔNG TIN PHONG TRÀO ===
    $stm = $pdo->prepare("
    SELECT title, target, status, register_deadline, url_zalo
    FROM campaigns
    WHERE id=?
    LIMIT 1
  ");
    $stm->execute([$campaign_id]);
    $cp = $stm->fetch(PDO::FETCH_ASSOC);

    if (!$cp) {
      echo json_encode(['error' => 'Không tìm thấy phong trào']);
      exit;
    }

    if ($cp['status'] === 'cancelled') {
      echo json_encode(['error' => 'Phong trào đã kết thúc']);
      exit;
    }

    if (!empty($cp['register_deadline']) && strtotime($cp['register_deadline']) < time()) {
      echo json_encode(['error' => 'Đã hết hạn đăng ký phong trào']);
      exit;
    }

    $target = (int) $cp['target'];

    try {
      $pdo->beginTransaction();

      // === 1️⃣ CHẶN ĐĂNG KÝ TRÙNG USER ===
      $chk = $pdo->prepare("
      SELECT id 
      FROM registrations 
      WHERE user_id=? AND campaign_id=?
      FOR UPDATE
    ");
      $chk->execute([$uid, $campaign_id]);

      if ($chk->fetchColumn()) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Bạn đã đăng ký phong trào này rồi']);
        exit;
      }

      // === 2️⃣ ĐẾM SLOT ĐÃ CHIẾM (KHÔNG CHỈ approved) ===
      $cnt = $pdo->prepare("
      SELECT COUNT(*) 
      FROM registrations 
      WHERE campaign_id=?
      FOR UPDATE
    ");
      $cnt->execute([$campaign_id]);
      $count = (int) $cnt->fetchColumn();

      if ($target > 0 && $count >= $target) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Số lượng đăng ký đã đủ']);
        exit;
      }

      // === 3️⃣ INSERT ===
      $pdo->prepare("
      INSERT INTO registrations(user_id, campaign_id, registered_at, status)
      VALUES (?, ?, NOW(), 'approved')
    ")->execute([$uid, $campaign_id]);

      $pdo->commit();

      log_activity(
        'register',
        'campaigns',
        'Phong trào',
        null,
        'Đăng ký tham gia phong trào: ' . $cp['title']
      );

      echo json_encode([
        'ok' => 1,
        'user_status' => 'approved',
        'url_zalo' => $cp['url_zalo'] ?? null
      ]);
      exit;

    } catch (Throwable $e) {
      $pdo->rollBack();
      http_response_code(500);
      echo json_encode(['error' => 'Lỗi hệ thống, vui lòng thử lại']);
      exit;
    }
  }



  /* ============================
        7) TRẠNG THÁI ĐĂNG KÝ
     ============================ */
  if ($action === 'get_status') {

    if (!auth_user()) {
      http_response_code(401);
      echo json_encode([
        'ok' => 0,
        'need_login' => 1,
        'error' => 'Vui lòng đăng nhập'
      ]);
      exit;
    }

    $uid = auth_user()['id'];
    $campaign_id = (int) ($_GET['campaign_id'] ?? 0);

    $stm = $pdo->prepare("SELECT status FROM registrations WHERE user_id=? AND campaign_id=?");
    $stm->execute([$uid, $campaign_id]);
    $row = $stm->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
      'ok' => 1,
      'status' => $row['status'] ?? 'none'
    ]);
    exit;
  }

  /* ============================
      9) USER HỦY ĐĂNG KÝ
   ============================ */
  if ($action === 'cancel_register') {

    if (!auth_user()) {
      http_response_code(401);
      echo json_encode([
        'ok' => 0,
        'need_login' => 1,
        'error' => 'Vui lòng đăng nhập'
      ]);
      exit;
    }

    $uid = auth_user()['id'];
    $campaign_id = (int) $_POST['campaign_id'];

    // Kiểm tra có đăng ký hay chưa
    $stm = $pdo->prepare("
      SELECT * FROM registrations 
      WHERE user_id=? AND campaign_id=? LIMIT 1
    ");
    $stm->execute([$uid, $campaign_id]);
    $reg = $stm->fetch();

    if (!$reg) {
      echo json_encode(['error' => 'Bạn chưa đăng ký phong trào này.']);
      exit;
    }


    // Nếu đã điểm danh → KHÔNG CHO HỦY
    $stm = $pdo->prepare("
      SELECT COUNT(*) FROM attendance_logs 
      WHERE user_id=? AND campaign_id=? 
    ");
    $stm->execute([$uid, $campaign_id]);
    $checked = (int) $stm->fetchColumn();

    if ($checked > 0) {
      echo json_encode(['error' => 'Bạn đã điểm danh. Không thể hủy đăng ký.']);
      exit;
    }

    $qCamp = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
    $qCamp->execute([$campaign_id]);
    $title = $qCamp->fetchColumn();
    // Hủy (xóa)
    $pdo->prepare("
      DELETE FROM registrations 
      WHERE user_id=? AND campaign_id=?
    ")->execute([$uid, $campaign_id]);

    log_activity(
      'delete',
      'campaigns',
      'Phong trào',
      null,
      'Hủy đăng ký phong trào: ' . $title
    );

    echo json_encode(['ok' => 1, 'message' => 'Hủy đăng ký thành công!']);
    exit;
  }

  /* ============================
        10) ADMIN HỦY ĐĂNG KÝ CHO USER
     ============================ */
  if ($action === 'admin_cancel_register') {
    if (!can('campaigns', 'update'))
      forbidden();
    $reg_id = (int) $_POST['reg_id'];

    $qCamp = $pdo->prepare("
  SELECT c.title
  FROM registrations r
  JOIN campaigns c ON c.id = r.campaign_id
  WHERE r.id=?
");
    $qCamp->execute([$reg_id]);
    $title = $qCamp->fetchColumn();

    $stm = $pdo->prepare("DELETE FROM registrations WHERE id=?");
    $stm->execute([$reg_id]);

    log_activity(
      'cancel_register',
      'campaigns',
      'Phong trào',
      null,
      'Admin hủy đăng ký phong trào: ' . $title
    );

    echo json_encode(['ok' => 1, 'message' => 'Đã hủy đăng ký']);
    exit;
  }

  /* ============================
     ACTION MỚI: LẤY DANH SÁCH ĐOÀN VIÊN ĐỂ TICK CHỌN
  ============================ */
  if ($action === 'get_class_members') {
    if (!auth_user()) {
      http_response_code(401);
      echo json_encode(['ok' => 0, 'error' => 'Vui lòng đăng nhập']);
      exit;
    }
    $uid = auth_user()['id'];
    $campaign_id = (int) ($_GET['campaign_id'] ?? 0);

    $bithu = getBithuClass($pdo, $uid);
    if (!$bithu) {
      echo json_encode(['ok' => 0, 'error' => 'Bạn không phải Bí thư chi đoàn lớp']);
      exit;
    }

    $members = getClassMembersForCampaign($pdo, $bithu['class_id'], $campaign_id);
    echo json_encode([
      'ok' => 1,
      'class_name' => $bithu['class_name'],
      'members' => $members
    ]);
    exit;
  }

  /* ============================
     ACTION MỚI: ĐĂNG KÝ NHỮNG NGƯỜI ĐƯỢC TICK
  ============================ */
  if ($action === 'register_selected') {
    if (!auth_user()) {
      http_response_code(401);
      echo json_encode(['ok' => 0, 'error' => 'Vui lòng đăng nhập']);
      exit;
    }
    $uid = auth_user()['id'];
    $campaign_id = (int) ($_POST['campaign_id'] ?? 0);
    $selected = $_POST['user_ids'] ?? [];

    $bithu = getBithuClass($pdo, $uid);
    if (!$bithu) {
      echo json_encode(['ok' => 0, 'error' => 'Bạn không phải Bí thư chi đoàn lớp']);
      exit;
    }

    $inserted = registerSelectedMembers($pdo, $campaign_id, $selected);

    $cp = $pdo->query("SELECT title FROM campaigns WHERE id = $campaign_id")->fetchColumn();

    log_activity('register_selected', 'campaigns', 'Phong trào', null, "Đăng ký {$inserted} đoàn viên cho phong trào: {$cp}");

    echo json_encode([
      'ok' => 1,
      'message' => "Đã đăng ký thành công {$inserted} đoàn viên",
      'registered_count' => $inserted
    ]);
    exit;
  }
  /* ============================
        8) ACTION SAI
     ============================ */
  echo json_encode(['error' => 'Bad action']);
  exit;


} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
