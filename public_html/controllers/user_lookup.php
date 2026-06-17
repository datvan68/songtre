<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

auth_guard();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
/* ===== ROLE + SCOPE (giống trang members) ===== */
function forbidden()
{
    json_err("Forbidden", 403);
}
function is_admin_role($roleName): bool
{
    return strtolower(trim((string) $roleName)) === 'admin';
}

$uid = (int) $userId;

// lấy role name hiện tại
$stmt = $pdo->prepare("
  SELECT r.name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
");
$stmt->execute([$uid]);
$currentRole = strtolower((string) ($stmt->fetchColumn() ?? ''));

// scope
$scope = null;
$gvcnClassIds = [];

if ($currentRole === 'bithu') {
    $stmt = $pdo->prepare("
        SELECT chidoan_group_id, department_id, course_id, class_id
        FROM bithu_scopes
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$uid]);
    $scope = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scope)
        forbidden(); // bí thư mà không có scope
}

if ($currentRole === 'gvcn') {
    $stmt = $pdo->prepare("
        SELECT class_id
        FROM gvcn_classes
        WHERE user_id = ?
    ");
    $stmt->execute([$uid]);
    $gvcnClassIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_id'));

    if (empty($gvcnClassIds))
        forbidden(); // gvcn mà không có lớp
}

/* ===== PERM: ai có user_lookup.review thì xem được hết =====
   Fallback thêm members.review để khỏi vỡ nếu bạn chưa tạo module user_lookup
*/
$canLookupAll = false;
if (function_exists('can')) {
    $canLookupAll =
        can('user_lookup', 'review')
        || can('members', 'review');
}

/* ===== JSON HELPERS ===== */
function json_ok($data = null)
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== PERMISSION GUARD =====
   Gợi ý: chỉ cho ai có quyền xem Members / Accounts (tùy hệ thống bạn)
   Nếu bạn muốn chỉ admin được xem, đổi thành: if (!$isAdmin) json_err(...)
*/
if (function_exists('can')) {
    $canView = can('user_lookup', 'view');
    if (!$canView)
        json_err("Bạn không có quyền tra cứu user.", 403);
}

/* ===== UTIL ===== */
function pickDisplayName($row)
{
    $member = trim((string) ($row['member_fullname'] ?? ''));
    $uFull = trim((string) ($row['user_fullname'] ?? ''));
    $uname = trim((string) ($row['username'] ?? ''));
    if ($member !== '')
        return $member;
    if ($uFull !== '')
        return $uFull;
    return $uname;
}
function get_borrow_points(PDO $pdo, $mssv)
{
    if (empty($mssv)) {
        return 10;
    }

    $stmt = $pdo->prepare("
        SELECT return_deadline, return_date, status 
        FROM inventory_borrows 
        WHERE borrower_name LIKE ?
    ");
    $stmt->execute(["$mssv%"]);
    $borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $initialPoints = 10;
    $deducted = 0;
    $today = date('Y-m-d');

    foreach ($borrows as $b) {
        $deadline = $b['return_deadline'];
        $actualReturn = ($b['status'] === 'returned') ? $b['return_date'] : $today;

        if ($deadline && $actualReturn > $deadline) {
            $diff = strtotime($actualReturn) - strtotime($deadline);
            $days = floor($diff / (60 * 60 * 24));
            if ($days > 7) {
                $deducted += floor(($days - 1) / 7);
            }
        }
    }
    return max(0, $initialPoints - $deducted);
}
function can_view_achievements_any(): bool
{
    // reviewer xem được tất cả
    if (function_exists('can') && can('achievements', 'review'))
        return true;
    return false;
}
function can_view_achievements_self(): bool
{
    // user xem của mình (theo module achievements)
    if (function_exists('can') && can('achievements', 'view'))
        return true;
    return false;
}

try {
    switch ($action) {

        /* =========================
           SEARCH USERS (typeahead)
        ========================= */
        case 'search_users': {
            $whereScope = " WHERE 1=1 ";
            $whereScope .= " AND (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1)) ";
            $whereScope .= " AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1)) ";
            $params = [];

            global $pdo;

            $q = trim((string) ($_POST['q'] ?? ''));
            $limit = (int) ($_POST['limit'] ?? 10);
            // Nếu có quyền xem hết => không scope
            if (!$canLookupAll) {
                if ($currentRole === 'bithu') {
                    if ((int) $scope['chidoan_group_id'] === 1) {
                        $whereScope .= " AND m.class_id = ? ";
                        $params[] = (int) $scope['class_id'];
                    } else {
                        $whereScope .= " AND m.chidoan_group_id = 2 ";
                    }
                } elseif ($currentRole === 'gvcn') {
                    $placeholders = implode(',', array_fill(0, count($gvcnClassIds), '?'));
                    $whereScope .= " AND m.class_id IN ($placeholders) ";
                    $params = array_merge($params, $gvcnClassIds);
                } else {
                    // user thường + role khác: chỉ xem bản thân
                    $whereScope .= " AND u.id = ? ";
                    $params[] = (int) $uid;
                }
            }

            if ($limit < 5)
                $limit = 5;
            if ($limit > 20)
                $limit = 20;

            if ($q === '') {
                // Nếu không gõ gì thì show 10 user mới nhất (đỡ trống)
                $stmt = $pdo->prepare("
  SELECT
    u.id, u.username,
    u.fullname AS user_fullname,
    u.avatar_url,
    r.name AS role_name,

    m.fullname AS member_fullname,
    m.mssv,
    d.name AS department_name,
    co.name AS course_name,
    cl.name AS class_name

  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  LEFT JOIN members m ON m.user_id = u.id
  LEFT JOIN departments d ON d.id = m.department_id
  LEFT JOIN courses co ON co.id = m.course_id
  LEFT JOIN classes cl ON cl.id = m.class_id
  $whereScope
  ORDER BY u.id DESC
  LIMIT $limit
");
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } else {
                $like = "%" . $q . "%";
                $whereSearch = "
  AND (
    u.username LIKE ?
    OR u.fullname LIKE ?
    OR m.fullname LIKE ?
    OR m.mssv LIKE ?
  )
";

                $stmt = $pdo->prepare("
  SELECT
    u.id, u.username,
    u.fullname AS user_fullname,
    u.avatar_url,
    r.name AS role_name,

    m.fullname AS member_fullname,
    m.mssv,
    d.name AS department_name,
    co.name AS course_name,
    cl.name AS class_name

  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  LEFT JOIN members m ON m.user_id = u.id
  LEFT JOIN departments d ON d.id = m.department_id
  LEFT JOIN courses co ON co.id = m.course_id
  LEFT JOIN classes cl ON cl.id = m.class_id

  $whereScope
  $whereSearch

  ORDER BY
    (m.fullname IS NOT NULL) DESC,
    u.id DESC
  LIMIT $limit
");

                $execParams = array_merge($params, [$like, $like, $like, $like]);
                $stmt->execute($execParams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            }

            foreach ($rows as &$r) {
                $r['display_name'] = pickDisplayName($r);
            }

            json_ok(['items' => $rows]);
        }

        /* =========================
           GET FULL USER DETAIL
        ========================= */
        case 'get_user_detail': {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid <= 0)
                json_err("Thiếu user_id");
            $targetUid = $uid;

            // Nếu có quyền xem hết => bỏ qua scope
            if (!$canLookupAll) {

                // user thường chỉ xem bản thân
                if ($targetUid !== (int) $GLOBALS['uid'] && $currentRole !== 'bithu' && $currentRole !== 'gvcn') {
                    forbidden();
                }

                // bithu/gvcn xem theo scope
                if ($targetUid !== (int) $GLOBALS['uid']) {

                    // cần member row để kiểm scope (giống members)
                    $st = $pdo->prepare("SELECT class_id, chidoan_group_id FROM members WHERE user_id = ? LIMIT 1");
                    $st->execute([$targetUid]);
                    $mrow = $st->fetch(PDO::FETCH_ASSOC);

                    if (!$mrow)
                        forbidden();

                    if ($currentRole === 'bithu') {
                        if ((int) $scope['chidoan_group_id'] === 1) {
                            if ((int) $mrow['class_id'] !== (int) $scope['class_id'])
                                forbidden();
                        } else {
                            if ((int) $mrow['chidoan_group_id'] !== 2)
                                forbidden();
                        }
                    } elseif ($currentRole === 'gvcn') {
                        if (!in_array((int) $mrow['class_id'], $gvcnClassIds, true))
                            forbidden();
                    }
                }
            }

            /* ===== 1) USER CORE ===== */
            $stmt = $pdo->prepare("
        SELECT
          u.id, u.username,
          u.fullname AS user_fullname,
          u.avatar_url,
          u.role_id,
          u.permissions_mode,
          u.created_at,

          r.name AS role_name,

          m.id AS member_id,
          m.mssv,
          m.fullname AS member_fullname,
          m.type AS member_type,
          m.department_id,
          m.course_id,
          m.class_id,
          m.class_name AS class_text,
          m.chidoan_group_id,
          m.birth,
          m.current_address,
          m.join_date,
          m.phone AS member_phone,
          m.email AS member_email,
          m.attendance_count,
          m.stop_follow,
          m.note AS note,             
          m.note AS member_note,

          d.name AS department_name,
          d.type AS department_type,
          co.name AS course_name,
          cl.name AS class_name

        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        LEFT JOIN members m ON m.user_id = u.id
        LEFT JOIN departments d ON d.id = m.department_id
        LEFT JOIN courses co ON co.id = m.course_id
        LEFT JOIN classes cl ON cl.id = m.class_id
        WHERE u.id = ?
        LIMIT 1
    ");
            $stmt->execute([$uid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user)
                json_err("Không tìm thấy user");

            $user['display_name'] = pickDisplayName($user);
            $memberIdLookup = (int) ($user['member_id'] ?? 0); // ✅ BẮT BUỘC: lấy m.id từ JOIN members

            $isBCH = (strtolower($user['role_name'] ?? '') === 'banchaphanh');

            /* ===== 2) USER PROFILE ===== */
            $stmt = $pdo->prepare("
        SELECT user_id, birth, phone, email, address, avatar_url, created_at, updated_at
        FROM user_profiles
        WHERE user_id = ?
        LIMIT 1
    ");
            $stmt->execute([$uid]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            /* ===== 3) CAMPAIGNS ===== */
            $stmt = $pdo->prepare("
    SELECT
      reg.id AS registration_id,
      reg.campaign_id,
      reg.status AS reg_status,
      reg.score AS reg_score,
      reg.note AS reg_note,
      reg.registered_at,

      c.title AS campaign_title,
      c.start_date,
      c.end_date,
      c.score AS campaign_score,
      c.school_year,

      c.semester_code AS semester_code,
      s.label AS semester_label

    FROM registrations reg
    JOIN campaigns c ON c.id = reg.campaign_id
    LEFT JOIN semesters s ON s.code = c.semester_code
    WHERE reg.user_id = ?
    ORDER BY c.start_date DESC, reg.registered_at DESC, reg.id DESC
    LIMIT 50
");
            $stmt->execute([$uid]);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);


            $stmt = $pdo->prepare("
        SELECT
          COUNT(*) AS total_regs,
          SUM(CASE WHEN reg.status = 'cancelled' THEN 0 ELSE 1 END) AS total_joined,
          SUM(COALESCE(reg.score, 0)) AS total_reg_score,
          SUM(COALESCE(c.score, 0)) AS total_campaign_score
        FROM registrations reg
        JOIN campaigns c ON c.id = reg.campaign_id
        WHERE reg.user_id = ?
    ");
            $stmt->execute([$uid]);
            $campaignStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'total_regs' => 0,
                'total_joined' => 0,
                'total_reg_score' => 0,
                'total_campaign_score' => 0
            ];

            /* ===== 4) ATTENDANCE ===== */
            $stmt = $pdo->prepare("
        SELECT
          SUM(CASE WHEN result = 'ok' THEN 1 ELSE 0 END) AS ok_count,
          SUM(CASE WHEN result = 'fail' THEN 1 ELSE 0 END) AS fail_count
        FROM attendance_logs
        WHERE user_id = ?
    ");
            $stmt->execute([$uid]);
            $attStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['ok_count' => 0, 'fail_count' => 0];

            $stmt = $pdo->prepare("
        SELECT
          l.id,
          l.result,
          l.time,
          l.session AS log_session,

          c.title AS campaign_title,

          e.session AS event_session,
          e.starts_at,
          e.expires_at

        FROM attendance_logs l
        JOIN campaigns c ON c.id = l.campaign_id
        JOIN attendance_events e ON e.id = l.event_id
        WHERE l.user_id = ?
        ORDER BY l.time DESC, l.id DESC
        LIMIT 30
    ");
            $stmt->execute([$uid]);
            $attendanceLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /* ===== 5) TASKS ===== */
            $stmt = $pdo->prepare("
        SELECT
          SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,
          SUM(CASE WHEN status='doing' THEN 1 ELSE 0 END) AS doing_count,
          SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done_count,
          AVG(COALESCE(progress,0)) AS avg_progress
        FROM task_item_assignees
        WHERE user_id = ?
    ");
            $stmt->execute([$uid]);
            $taskStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'pending_count' => 0,
                'doing_count' => 0,
                'done_count' => 0,
                'avg_progress' => 0
            ];

            $stmt = $pdo->prepare("
    SELECT
      tia.task_id,
      tia.status,
      tia.progress,
      tia.finished_at,
      tia.result_type,

      ti.title AS task_title,
      ti.priority,
      ti.deadline,
      ti.start_date,

      tp.title AS project_title,
      tp.school_year,

      tp.semester AS semester_code

    FROM task_item_assignees tia
    JOIN task_items ti ON ti.id = tia.task_id
    LEFT JOIN task_projects tp ON tp.id = ti.project_id
    WHERE tia.user_id = ?
    ORDER BY ti.deadline DESC, tia.task_id DESC
    LIMIT 30
");
            $stmt->execute([$uid]);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);


            /* ===== 6) DUTY (chỉ trả nếu BCH) ===== */
            $dutyStats = ['total_shifts' => 0, 'total_score' => 0];
            $duty = [];

            if ($isBCH) {
                $stmt = $pdo->prepare("
            SELECT
              COUNT(*) AS total_shifts,
              SUM(score) AS total_score
            FROM duty_assignments
            WHERE user_id = ?
        ");
                $stmt->execute([$uid]);
                $dutyStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $dutyStats;

                $stmt = $pdo->prepare("
            SELECT
              a.id,
              a.week_id,
              w.week_start,
              w.week_end,
              a.day,
              a.shift,
              a.type,
              a.score,
              a.created_at
            FROM duty_assignments a
            JOIN duty_weeks w ON w.id = a.week_id
            WHERE a.user_id = ?
            ORDER BY w.week_start DESC, a.day ASC, a.shift ASC
            LIMIT 40
        ");
                $stmt->execute([$uid]);
                $duty = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            /* ===== 7) NOMINATIONS ===== */
            $stmt = $pdo->prepare("
        SELECT
          n.id,
          n.type,
          n.fullname,
          n.school_year,
          n.status,
          n.created_at,
          rt.name AS title_name
        FROM nominations n
        LEFT JOIN reward_titles rt ON rt.id = n.title_id
        WHERE n.nominee_user_id = ? OR n.user_id = ?
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 20
    ");
            $stmt->execute([$uid, $uid]);
            $nominations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /* ===== 8) FINANCE ===== */
            $stmt = $pdo->prepare("
        SELECT
          id, type, item_name, amount, trans_date, method, reference_no, status, created_at
        FROM finance_transactions
        WHERE created_by = ?
        ORDER BY trans_date DESC, id DESC
        LIMIT 20
    ");
            $stmt->execute([$uid]);
            $finance = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /* ===== 9) INVENTORY BORROWS ===== */
            $borrows = [];
            $borrowPoints = 10;
            $mssv = trim((string) ($user['mssv'] ?? ''));
            if ($mssv !== '') {
                $borrowPoints = get_borrow_points($pdo, $mssv);
                $stmt = $pdo->prepare("
                    SELECT
                      b.id,
                      i.name AS item_name,
                      b.borrower_name,
                      b.quantity,
                      b.borrow_date,
                      b.return_deadline,
                      b.return_date,
                      b.status,
                      b.purpose,
                      b.note
                    FROM inventory_borrows b
                    JOIN inventory_items i ON i.id = b.inventory_id
                    WHERE b.borrower_name LIKE ?
                    ORDER BY b.borrow_date DESC, b.id DESC
                    LIMIT 20
                ");
                $stmt->execute([$mssv . '%']);
                $borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $financePaidRows = [];
            $financePaidStats = []; // không cần total_count nữa

            if (function_exists('can') && !can('finance', 'view')) {
                $financePaidStats = ['forbidden' => true];
            } else if ($memberIdLookup > 0) {

                // danh sách: đã đóng cái gì (transaction_id -> item_name)
                $stmt = $pdo->prepare("
      SELECT DISTINCT
        t.id AS transaction_id,
        t.item_name,
        t.trans_date,
        t.status,
        t.method
      FROM finance_transaction_participants p
      JOIN finance_transactions t ON t.id = p.transaction_id
      WHERE p.member_id = ?
        AND t.type = 'income'
        AND (t.status IS NULL OR t.status NOT IN ('cancelled','rejected'))
      ORDER BY t.trans_date DESC, t.id DESC
      LIMIT 50
    ");
                $stmt->execute([$memberIdLookup]);
                $financePaidRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            /* ===== 10) REVIEWS (member_reviews) ===== */
            $reviewsForbidden = false;
            $reviewYears = [];
            $reviews = [];

            // ✅ xác định đang xem chính mình?
            $isSelf = ((int) $targetUid === (int) $GLOBALS['uid']);

            // ✅ chỉ forbidden nếu KHÔNG phải self và KHÔNG có quyền review
            $canReview = true;
            if (function_exists('can')) {
                $canReview = can('members', 'review'); // giữ đúng key bạn đang dùng
            }
            if (!$isSelf && !$canReview) {
                $reviewsForbidden = true;
            } else if ($memberIdLookup > 0) {

                // Lấy danh sách năm active để UI show cả năm "chưa đánh giá"
                try {
                    $stmt = $pdo->query("
            SELECT year_label
            FROM school_years
            WHERE is_active = 1
            ORDER BY year_label
        ");
                    $reviewYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Throwable $e) {
                    $reviewYears = [];
                }

                // Lấy đánh giá theo năm
                try {
                    $stmt = $pdo->prepare("
            SELECT
                r.school_year,
                r.rating,
                r.note,
                r.reviewed_by,
                r.reviewed_at,
                r.lock_applied,
                r.lock_applied_at
            FROM member_reviews r
            WHERE r.member_id = ?
            ORDER BY r.school_year
        ");
                    $stmt->execute([$memberIdLookup]);
                    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $reviews = [];
                }
            }
            /* ===== 11) ACHIEVEMENTS (thành tích cá nhân) ===== */
            $achievementsForbidden = false;
            $achievementStats = [
                'total' => 0,
                'draft' => 0,
                'submitted' => 0,
                'approved' => 0,
                'rejected' => 0,
            ];
            $achievements = [];

            $isSelf = ((int) $targetUid === (int) $GLOBALS['uid']);
            $canAchAny = can_view_achievements_any();
            $canAchSelf = can_view_achievements_self();

            // Quy tắc:
            // - reviewer (achievements.review) => xem được mọi người
            // - không phải reviewer => chỉ xem nếu đang xem chính mình và có achievements.view
            if (!$canAchAny && !($isSelf && $canAchSelf)) {
                $achievementsForbidden = true;
            } else {

                // list achievements của target user
                $stmt = $pdo->prepare("
                    SELECT
                        a.id,
                        a.title,
                        a.content,
                        a.achieved_at,
                        a.status,
                        a.submitted_at,
                        a.reviewed_at,
                        a.review_note,
                        a.created_at,
                        a.updated_at,
                        (SELECT COUNT(*) FROM achievement_files f WHERE f.achievement_id = a.id) AS files_count
                    FROM achievements a
                    WHERE a.user_id = ?
                    ORDER BY a.created_at DESC, a.id DESC
                    LIMIT 30
                ");
                $stmt->execute([$targetUid]);
                $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // stats theo status
                $stmt = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total,
                        SUM(status='draft') AS draft,
                        SUM(status='submitted') AS submitted,
                        SUM(status='approved') AS approved,
                        SUM(status='rejected') AS rejected
                    FROM achievements
                    WHERE user_id = ?
                ");
                $stmt->execute([$targetUid]);
                $achievementStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $achievementStats;

                // ép int cho chắc
                foreach ($achievementStats as $k => $v) {
                    $achievementStats[$k] = (int) ($v ?? 0);
                }
            }


            /* ===== 12) VIOLATIONS (Kỷ luật - Vi phạm) ===== */
            $violationsForbidden = false;
            $violations = [];

            $isSelf = ((int) $targetUid === (int) $GLOBALS['uid']);
            $canViolationsView = false;
            if (function_exists('can')) {
                $canViolationsView = can('violations', 'view');
            }

            if (!$canViolationsView && !$isSelf) {
                $violationsForbidden = true;
            } else if ($memberIdLookup > 0) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT
                            v.id,
                            v.reason,
                            v.treatment,
                            v.created_at,
                            COALESCE(m.fullname, uc.fullname, uc.username) AS creator_name
                        FROM violations v
                        LEFT JOIN users uc ON uc.id = v.created_by
                        LEFT JOIN members m ON m.user_id = uc.id
                        WHERE v.member_id = ?
                        ORDER BY v.created_at DESC
                        LIMIT 50
                    ");
                    $stmt->execute([$memberIdLookup]);
                    $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $violations = [];
                }
            }

            /* ===== 13) FINANCE USER DETAILS (Đã đóng và Chưa đóng) ===== */
            $financeUserPaid = [];
            $financeUserUnpaid = [];

            if ($memberIdLookup > 0) {
                $className = trim((string)($user['class_name'] ?? $user['class_text'] ?? ''));

                // Lấy tất cả giao dịch sinh viên tham gia và tính toán lại số tiền cá nhân nếu là giao dịch lớp
                $sqlPaid = "
                    SELECT DISTINCT
                        t.id AS transaction_id,
                        t.item_name,
                        CASE 
                            WHEN t.class_text IS NOT NULL AND TRIM(t.class_text) <> '' THEN
                                ROUND(t.amount / (
                                    SELECT CASE WHEN COUNT(ftp2.member_id) > 0 THEN COUNT(ftp2.member_id) ELSE 1 END
                                    FROM finance_transaction_participants ftp2 
                                    WHERE ftp2.transaction_id = t.id
                                ))
                            ELSE t.amount
                        END AS amount,
                        t.trans_date,
                        t.method,
                        t.status,
                        CASE 
                            WHEN t.class_text IS NOT NULL AND TRIM(t.class_text) <> '' THEN 'Cả lớp'
                            ELSE 'Cá nhân'
                        END AS scope
                    FROM finance_transaction_participants p
                    JOIN finance_transactions t ON t.id = p.transaction_id
                    WHERE p.member_id = ?
                      AND t.type = 'income'
                      AND (t.status IS NULL OR t.status NOT IN ('cancelled','rejected'))
                ";
                $paidParams = [$memberIdLookup];

                $sqlPaid .= " ORDER BY trans_date DESC";
                $stmtPaid = $pdo->prepare($sqlPaid);
                $stmtPaid->execute($paidParams);
                $financeUserPaid = $stmtPaid->fetchAll(PDO::FETCH_ASSOC);

                $mType = strtolower(trim((string)($user['member_type'] ?? '')));
                $isDV = in_array($mType, ['member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan']) ? 1 : 0;
                $isTN = in_array($mType, ['youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh']) ? 1 : 0;

                // Lấy các khoản chưa đóng (chỉ loại trừ nếu sinh viên đã đóng cá nhân)
                $sqlUnpaid = "
                    SELECT 
                        fi.id,
                        fi.name AS item_name,
                        COALESCE(fi.target_type, 'tat_ca') AS target_type
                    FROM finance_items fi
                    WHERE fi.type = 'income'
                      AND fi.is_active = 1
                      AND (
                          fi.target_type = 'tat_ca'
                          OR (fi.target_type = 'doan_vien' AND ? = 1)
                          OR (fi.target_type = 'thanh_nien' AND ? = 1)
                      )
                      -- Chỉ giữ lại khoản thu nếu không có chiến dịch nào liên quan, hoặc sinh viên có đăng ký tham gia chiến dịch đó
                      AND (
                          NOT EXISTS (
                              SELECT 1 FROM campaigns c2
                              WHERE LOWER(c2.title) LIKE CONCAT('%', LOWER(fi.name), '%')
                          )
                          OR EXISTS (
                              SELECT 1 FROM campaigns c2
                              JOIN registrations reg ON reg.campaign_id = c2.id
                              WHERE LOWER(c2.title) LIKE CONCAT('%', LOWER(fi.name), '%')
                                AND reg.user_id = ?
                                AND reg.status <> 'cancelled'
                          )
                      )
                      AND NOT EXISTS (
                          SELECT 1
                          FROM finance_transaction_participants ftp
                          JOIN finance_transactions t ON t.id = ftp.transaction_id
                          WHERE ftp.member_id = ?
                            AND t.type = 'income'
                            AND t.item_name = fi.name
                            AND (t.status IS NULL OR t.status NOT IN ('cancelled','rejected'))
                      )
                ";
                $unpaidParams = [$isDV, $isTN, $targetUid, $memberIdLookup];

                $sqlUnpaid .= " ORDER BY fi.name ASC";
                $stmtUnpaid = $pdo->prepare($sqlUnpaid);
                $stmtUnpaid->execute($unpaidParams);
                $financeUserUnpaid = $stmtUnpaid->fetchAll(PDO::FETCH_ASSOC);
            }

            /* ✅ TRẢ 1 LẦN DUY NHẤT Ở CUỐI */
            json_ok([
                'user' => $user,
                'profile' => $profile,

                'campaign_stats' => $campaignStats,
                'campaigns' => $campaigns,

                'attendance_stats' => $attStats,
                'attendance_logs' => $attendanceLogs,

                'finance_paid_stats' => $financePaidStats,
                'finance_paid_rows' => $financePaidRows,

                'task_stats' => $taskStats,
                'tasks' => $tasks,

                'duty_stats' => $dutyStats,
                'duty' => $duty,

                'nominations' => $nominations,
                'finance' => $finance,
                'borrows' => $borrows,
                'borrow_points' => $borrowPoints,

                'review_years' => $reviewYears,
                'reviews' => $reviews,
                'reviews_forbidden' => $reviewsForbidden,

                'achievement_stats' => $achievementStats,
                'achievements' => $achievements,
                'achievements_forbidden' => $achievementsForbidden,

                'violations' => $violations,
                'violations_forbidden' => $violationsForbidden,
                'finance_user_paid' => $financeUserPaid,
                'finance_user_unpaid' => $financeUserUnpaid,
            ]);
        }


        default:
            json_err("Action không hợp lệ: $action", 400);
    }
} catch (Throwable $e) {
    json_err("Server error: " . $e->getMessage(), 500);
}
