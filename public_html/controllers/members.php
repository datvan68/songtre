<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/activity_log.php';

auth_guard();

use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

function forbidden()
{
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
function is_admin_role($roleName): bool
{
    return strtolower(trim((string) $roleName)) === 'admin';
}

function member_is_locked(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare("SELECT is_locked FROM members WHERE id=? LIMIT 1");
    $st->execute([$id]);
    return (int) ($st->fetchColumn() ?? 0) === 1;
}

function locked_err()
{
    http_response_code(423); // Locked
    echo json_encode(['error' => 'Đoàn viên đã bị khóa. Không thể chỉnh sửa.'], JSON_UNESCAPED_UNICODE);
    exit;
}
function parse_ids($raw): array
{
    // hỗ trợ: ids[]=1&ids[]=2 hoặc ids="1,2,3"
    if (is_string($raw)) {
        $raw = preg_split('/[,\s]+/', trim($raw));
    }
    if (!is_array($raw))
        return [];

    $ids = [];
    foreach ($raw as $v) {
        $n = (int) $v;
        if ($n > 0)
            $ids[] = $n;
    }
    $ids = array_values(array_unique($ids));

    // chặn phá: giới hạn 500 ids/lần (tuỳ bạn)
    if (count($ids) > 500) {
        $ids = array_slice($ids, 0, 500);
    }
    return $ids;
}

$uid = $_SESSION['user_id'] ?? 0;

// lấy role name hiện tại
$stmt = $pdo->prepare("
  SELECT r.name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
");
$stmt->execute([$uid]);
$currentRole = strtolower(trim((string) $stmt->fetchColumn()));

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

    if (!$scope) {
        forbidden(); // bí thư mà không có scope
    }
}


/* ===== GVCN ===== */
if ($currentRole === 'gvcn') {
    $stmt = $pdo->prepare("
        SELECT class_id
        FROM gvcn_classes
        WHERE user_id = ?
    ");
    $stmt->execute([$uid]);
    $gvcnClassIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_id');

    if (empty($gvcnClassIds)) {
        forbidden(); // GVCN mà không có lớp
    }
}


// ✅ lấy action TRƯỚC
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);
// ✅ LẤY ROLE_ID MẶC ĐỊNH CHO USER
$roleUserId = (int) $pdo->query("
  SELECT id FROM roles WHERE name = 'user' LIMIT 1
")->fetchColumn();

if (!$roleUserId) {
    throw new Exception("Không tìm thấy role 'user'");
}

if ($action === 'get') {
    if (!can('members', 'view'))
        forbidden();
}

if ($action === 'create') {
    if (!can('members', 'create'))
        forbidden();
}

if ($action === 'update') {
    if (!can('members', 'update'))
        forbidden();
}
if (in_array($action, ['review_get', 'review_save'], true)) {
    if (!can('members', 'review'))
        forbidden();
}
if ($action === 'review_search') {
    if (!can('members', 'review'))
        forbidden();
}
// ✅ ADMIN actions: mở khóa / khóa theo năm
if (in_array($action, ['review_unlock_year', 'review_lock_year'], true)) {
    if (!is_admin_role($currentRole))
        forbidden();
    if (!can('members', 'review'))
        forbidden();
}


if ($action === 'delete') {
    if (!can('members', 'delete'))
        forbidden();
}

if ($action === 'set_lock') {
    // chỉ admin được khóa/mở
    if (!is_admin_role($currentRole))
        forbidden();
    if (!can('members', 'update'))
        forbidden(); // dùng chung quyền update
}
if ($action === 'bulk_set_lock') {
    // chỉ admin được khóa/mở
    if (!is_admin_role($currentRole))
        forbidden();
    if (!can('members', 'update'))
        forbidden();
}

if (in_array($action, ['import_xlsx', 'export_xlsx'])) {
    if (!can('members', 'print'))
        forbidden();
}
if ($action === 'search') {
    if (!can('members', 'view'))
        forbidden();
}

function validateClass(PDO $pdo, $classId, $deptId, $courseId)
{
    // Không chọn lớp → OK
    if (!$classId) {
        return true;
    }

    // Có lớp nhưng chưa chọn khoa → SAI
    if (!$deptId) {
        return false;
    }

    // Đã chọn khoa + khóa → kiểm tra theo cả 2
    if ($courseId) {
        $q = $pdo->prepare("
            SELECT COUNT(*) 
            FROM classes
            WHERE id = ?
              AND department_id = ?
              AND course_id = ?
        ");
        $q->execute([$classId, $deptId, $courseId]);
        return $q->fetchColumn() > 0;
    }

    // Chỉ chọn khoa (chưa chọn khóa) → kiểm tra theo khoa
    $q = $pdo->prepare("
        SELECT COUNT(*) 
        FROM classes
        WHERE id = ?
          AND department_id = ?
    ");
    $q->execute([$classId, $deptId]);

    return $q->fetchColumn() > 0;
}


// === RESET AUTO_INCREMENT ===
function resetAutoIncrement($pdo, $table)
{
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT=1");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

function expand_school_year_range(string $range): array
{
    $range = trim($range);
    if (!preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $range, $m)) {
        return [];
    }
    $start = (int) $m[1];
    $end = (int) $m[2];

    // ví dụ 2023-2026 => tạo 2023-2024, 2024-2025, 2025-2026
    if ($end <= $start)
        return [];

    $years = [];
    for ($y = $start; $y < $end; $y++) {
        $years[] = $y . '-' . ($y + 1);
    }

    // chặn phá: không cho range quá dài
    if (count($years) > 20)
        return [];
    return $years;
}

function is_valid_school_year(string $sy): bool
{
    if (!preg_match('/^(\d{4})-(\d{4})$/', $sy, $m))
        return false;
    return ((int) $m[2] === (int) $m[1] + 1);
}

/**
 * Check member có nằm trong scope của user hiện tại không.
 * Trả về row tối thiểu nếu hợp lệ, ngược lại forbidden().
 */
function require_member_in_scope(PDO $pdo, int $memberId, string $currentRole, ?array $scope, array $gvcnClassIds): array
{
    $st = $pdo->prepare("SELECT id, class_id, chidoan_group_id, is_locked FROM members WHERE id=? LIMIT 1");
    $st->execute([$memberId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row)
        forbidden();

    if ($currentRole === 'bithu') {
        if (!$scope)
            forbidden();

        if ((int) $scope['chidoan_group_id'] === 1) {
            // bí thư lớp: chỉ đúng lớp scope
            if ((int) $row['class_id'] !== (int) $scope['class_id'])
                forbidden();
        } else {
            // bí thư GV: chỉ đoàn viên nhóm GV
            if ((int) $row['chidoan_group_id'] !== 2)
                forbidden();
        }
    }

    if ($currentRole === 'gvcn') {
        if (empty($gvcnClassIds))
            forbidden();
        if (!in_array((int) $row['class_id'], array_map('intval', $gvcnClassIds), true))
            forbidden();
    }

    return $row;
}

/**
 * AUTO-LOCK REVIEW: chạy mỗi request.
 * - Không khóa members nữa.
 * - Chỉ set member_reviews.lock_applied=1 khi reviewed_at + 7 ngày <= NOW()
 */
function apply_member_review_autolock(PDO $pdo): void
{
    try {
        $pdo->query("SELECT 1 FROM member_reviews LIMIT 1");
    } catch (Throwable $e) {
        return;
    }

    try {
        $pdo->exec("
            UPDATE member_reviews
            SET
                lock_applied = 1,
                lock_applied_at = NOW()
            WHERE
                lock_applied = 0
                AND reviewed_at IS NOT NULL
                AND DATE_ADD(reviewed_at, INTERVAL 7 DAY) <= NOW()
        ");
    } catch (Throwable $e) {
        // ignore
    }
}
function read_json_body(): ?array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') === false)
        return null;

    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/**
 * Resolve school_year theo id hoặc year_label
 * Return: ['id' => int, 'year_label' => string, 'is_active' => int]
 */
function resolve_school_year(PDO $pdo, int $schoolYearId, string $yearLabel): array
{
    if ($schoolYearId > 0) {
        $st = $pdo->prepare("SELECT id, year_label, is_active FROM school_years WHERE id=? LIMIT 1");
        $st->execute([$schoolYearId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row)
            throw new Exception("Không tìm thấy năm học (id=$schoolYearId).");
        return [
            'id' => (int) $row['id'],
            'year_label' => (string) $row['year_label'],
            'is_active' => (int) $row['is_active'],
        ];
    }

    $yearLabel = trim($yearLabel);
    if ($yearLabel === '')
        throw new Exception("Thiếu school_year_id hoặc year_label.");

    $st = $pdo->prepare("SELECT id, year_label, is_active FROM school_years WHERE year_label=? LIMIT 1");
    $st->execute([$yearLabel]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row)
        throw new Exception("Không tìm thấy năm học ($yearLabel).");

    return [
        'id' => (int) $row['id'],
        'year_label' => (string) $row['year_label'],
        'is_active' => (int) $row['is_active'],
    ];
}

// ✅ Review permissions
if (in_array($action, ['review_get', 'review_save'], true)) {
    if (!can('members', 'review'))
        forbidden();
}
if ($action === 'review_search' || $action === 'review_years') {
    if (!can('members', 'review'))
        forbidden();
}

// ✅ ADMIN actions (review window + lock/unlock year)
if (
    in_array($action, [
        'review_unlock_year',
        'review_lock_year',
        'review_window_years',
        'review_window_open',
        'review_window_close'
    ], true)
) {
    if (!is_admin_role($currentRole))
        forbidden();
    if (!can('members', 'review'))
        forbidden();
}


try {
    apply_member_review_autolock($pdo);

    if ($action === 'review_window_years') {
        if (!can('members', 'review'))
            forbidden();

        // admin gate đã xử lý ở trên
        $st = $pdo->query("
        SELECT
            sy.id AS school_year_id,
            sy.year_label,
            sy.is_active,
            COALESCE(w.is_open, 0) AS is_open,
            w.opened_at,
            w.closed_at
        FROM school_years sy
        LEFT JOIN member_review_windows w ON w.school_year_id = sy.id
        ORDER BY sy.year_label
    ");
        $years = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => 1, 'years' => $years], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'review_window_open') {
        $input = read_json_body();
        if (!$input)
            $input = $_POST;

        $schoolYearId = (int) ($input['school_year_id'] ?? 0);
        $yearLabel = trim((string) ($input['year_label'] ?? ''));

        try {
            $sy = resolve_school_year($pdo, $schoolYearId, $yearLabel);

            if ((int) $sy['is_active'] !== 1) {
                throw new Exception("Năm học {$sy['year_label']} không active, không thể mở đánh giá.");
            }

            $pdo->beginTransaction();

            // (tuỳ bạn) chỉ cho 1 năm mở tại 1 thời điểm
            $stOther = $pdo->prepare("
            SELECT sy.year_label
            FROM member_review_windows w
            JOIN school_years sy ON sy.id = w.school_year_id
            WHERE w.is_open = 1 AND w.school_year_id <> ?
            LIMIT 1
            FOR UPDATE
        ");
            $stOther->execute([(int) $sy['id']]);
            $other = $stOther->fetchColumn();
            if ($other) {
                throw new Exception("Đang có năm $other mở đánh giá. Hãy đóng trước khi mở năm {$sy['year_label']}.");
            }

            // Lock row (nếu đã có)
            $stRow = $pdo->prepare("
            SELECT school_year_id
            FROM member_review_windows
            WHERE school_year_id = ?
            LIMIT 1
            FOR UPDATE
        ");
            $stRow->execute([(int) $sy['id']]);

            // Upsert mở (CHO PHÉP MỞ LẠI SAU KHI ĐÓNG)
            // yêu cầu UNIQUE/PK on member_review_windows.school_year_id
            $stUp = $pdo->prepare("
            INSERT INTO member_review_windows (school_year_id, is_open, opened_at, closed_at)
            VALUES (?, 1, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                is_open = 1,
                opened_at = NOW()
        ");
            $stUp->execute([(int) $sy['id']]);

            $pdo->commit();

            log_activity('review', 'members', 'school_year', (int) $sy['id'], 'Admin mở đánh giá năm ' . $sy['year_label']);

            echo json_encode(['ok' => 1, 'school_year_id' => (int) $sy['id'], 'year_label' => $sy['year_label']], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'review_window_close') {
        if (!is_admin_role($currentRole))
            forbidden();
        if (!can('members', 'review'))
            forbidden();

        $input = read_json_body();
        if (!$input)
            $input = $_POST;

        $schoolYearId = (int) ($input['school_year_id'] ?? 0);
        $yearLabel = trim((string) ($input['year_label'] ?? ''));

        try {
            $sy = resolve_school_year($pdo, $schoolYearId, $yearLabel);

            $pdo->beginTransaction();

            // Lock row
            $stRow = $pdo->prepare("
            SELECT school_year_id
            FROM member_review_windows
            WHERE school_year_id = ?
            LIMIT 1
            FOR UPDATE
        ");
            $stRow->execute([(int) $sy['id']]);
            $exists = (bool) $stRow->fetchColumn();

            if (!$exists) {
                // chưa có record window thì tạo record đóng (để UI thấy "chưa mở")
                $stIns = $pdo->prepare("
                INSERT INTO member_review_windows (school_year_id, is_open, opened_at, closed_at)
                VALUES (?, 0, NULL, NOW())
            ");
                $stIns->execute([(int) $sy['id']]);
            } else {
                $stUp = $pdo->prepare("
                UPDATE member_review_windows
                SET is_open = 0,
                    closed_at = NOW()
                WHERE school_year_id = ?
                LIMIT 1
            ");
                $stUp->execute([(int) $sy['id']]);
            }

            $pdo->commit();

            log_activity('review', 'members', 'school_year', (int) $sy['id'], 'Admin đóng đánh giá năm ' . $sy['year_label']);

            echo json_encode(['ok' => 1, 'school_year_id' => (int) $sy['id'], 'year_label' => $sy['year_label']], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }


    if ($action === 'review_years') {
        if (!can('members', 'review'))
            forbidden();

        // ✅ trả về tất cả năm active, kể cả chưa mở => frontend hiện "chưa mở đánh giá"
        $st = $pdo->query("
        SELECT
            sy.id AS school_year_id,
            sy.year_label,
            sy.is_active,
            COALESCE(w.is_open, 0) AS is_open,
            w.opened_at,
            w.closed_at
        FROM school_years sy
        LEFT JOIN member_review_windows w ON w.school_year_id = sy.id
        WHERE sy.is_active = 1
        ORDER BY sy.year_label
    ");
        $years = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => 1, 'years' => $years], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'lock_all') {
        auth_guard();

        if (!can('members', 'update')) {
            http_response_code(403);
            echo json_encode(['ok' => 0, 'error' => 'Forbidden']);
            exit;
        }

        // chỉ admin
        $uid = $_SESSION['user_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?");
        $stmt->execute([$uid]);
        $role = $stmt->fetchColumn();

        if (!is_admin_role($role)) {
            http_response_code(403);
            echo json_encode(['ok' => 0, 'error' => 'Admin only'], JSON_UNESCAPED_UNICODE);
            exit;
        }


        $lock = (int) ($_POST['is_locked'] ?? 1);
        $q = trim($_POST['q'] ?? '');
        $filter = trim($_POST['filter'] ?? '');
        $department_id = (int) ($_POST['department_id'] ?? 0);
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $class_id = (int) ($_POST['class_id'] ?? 0);
        $hide_stopped = (int) ($_POST['hide_stopped'] ?? 0);

        $where = "WHERE 1=1";
        $params = [];

        if ($filter === 'member' || $filter === 'youth') {
            $where .= " AND m.type = ?";
            $params[] = $filter;
        }
        if ($department_id) {
            $where .= " AND m.department_id = ?";
            $params[] = $department_id;
        }
        if ($course_id) {
            $where .= " AND m.course_id = ?";
            $params[] = $course_id;
        }
        if ($class_id) {
            $where .= " AND m.class_id = ?";
            $params[] = $class_id;
        }

        if ($hide_stopped === 1) {
            $where .= " AND m.stop_follow = 0";
        }

        if ($q !== '') {
            $where .= " AND (m.mssv LIKE ? OR m.fullname LIKE ? OR cl.name LIKE ?)";
            $like = "%$q%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // UPDATE có JOIN để search theo tên lớp (cl.name)
        $sql = "
    UPDATE members m
    LEFT JOIN classes cl ON cl.id = m.class_id
    SET m.is_locked = ?
    $where
  ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$lock], $params));

        echo json_encode(['ok' => 1, 'updated' => $stmt->rowCount()]);
        exit;
    }

    if ($action === 'review_search') {
        if (!can('members', 'review'))
            forbidden();

        $where = " WHERE 1=1 ";
        $where .= " AND (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1)) ";
        $where .= " AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1)) ";
        $params = [];

        $keyword = trim($_GET['q'] ?? '');
        $perPage = 10;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        // ===== scope giống action search =====
        if ($currentRole === 'bithu') {
            if ((int) $scope['chidoan_group_id'] === 1) {
                $where .= " AND m.class_id = ? ";
                $params[] = (int) $scope['class_id'];
            } else {
                $where .= " AND m.chidoan_group_id = 2 ";
            }
        } elseif ($currentRole === 'gvcn') {
            $placeholders = implode(',', array_fill(0, count($gvcnClassIds), '?'));
            $where .= " AND m.class_id IN ($placeholders) ";
            $params = array_merge($params, $gvcnClassIds);
        }

        if ($keyword !== '') {
            $where .= " AND (
            m.fullname LIKE ? OR
            m.mssv LIKE ? OR
            cl.name LIKE ?
        )";
            $kw = "%$keyword%";
            array_push($params, $kw, $kw, $kw);
        }

        // COUNT
        $cnt = $pdo->prepare("
        SELECT COUNT(*)
        FROM members m
        LEFT JOIN classes cl ON cl.id = m.class_id
        $where
    ");
        $cnt->execute($params);
        $totalRows = (int) $cnt->fetchColumn();
        $totalPages = (int) ceil($totalRows / $perPage);

        // DATA
        $stm = $pdo->prepare("
    SELECT
        m.id,
        m.mssv,
        m.fullname,
        m.is_locked,
        cl.name AS class_name2,
        EXISTS(
            SELECT 1 FROM member_reviews r
            WHERE r.member_id = m.id
            LIMIT 1
        ) AS has_review
    FROM members m
    LEFT JOIN classes cl ON cl.id = m.class_id
    $where
    ORDER BY
        (cl.name IS NULL) ASC,
        cl.name ASC,
        m.fullname ASC
    LIMIT $perPage OFFSET $offset
");

        $stm->execute($params);

        echo json_encode([
            'ok' => true,
            'rows' => $stm->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'review_get') {
        if (!can('members', 'review'))
            forbidden();

        $memberId = (int) ($_GET['id'] ?? 0);
        if (!$memberId) {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu id đoàn viên'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // scope check
        $memRow = require_member_in_scope($pdo, $memberId, (string) $currentRole, $scope, $gvcnClassIds);

        $st = $pdo->prepare("
        SELECT
            school_year, rating, note,
            reviewed_by, reviewed_at,
            lock_applied, lock_applied_at
        FROM member_reviews
        WHERE member_id = ?
        ORDER BY school_year
    ");
        $st->execute([$memberId]);
        $reviews = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => 1,
            'member' => [
                'id' => (int) $memRow['id'],
                'is_locked' => (int) $memRow['is_locked'], // member.is_locked không chặn đánh giá nữa
            ],
            'reviews' => $reviews
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }


    if ($action === 'review_save') {
        if (!can('members', 'review'))
            forbidden();

        // =========================
        // INPUT: JSON ưu tiên, fallback POST
        // =========================
        $input = null;
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input))
                $input = null;
        }

        $memberId = (int) (($input['member_id'] ?? $_POST['member_id'] ?? 0));
        if (!$memberId) {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu member_id'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // scope check
        require_member_in_scope($pdo, $memberId, (string) $currentRole, $scope, $gvcnClassIds);



        $allowed = ['excellent', 'good', 'completed', 'incomplete'];

        /**
         * JSON từ JS:
         * { member_id, school_years: [{ year_label, rating, note }] }
         * - rating = ''  => XÓA đánh giá cũ của năm đó
         *
         * Fallback form cũ:
         * range_year + rating[sy] + note[sy]
         * - giữ behavior cũ: rating rỗng => skip (không xóa)
         */
        $items = $input['school_years'] ?? null;

        $years = [];
        $ratingMap = [];
        $noteMap = [];
        $rangeYear = '';
        $isJsonMode = (is_array($items) && !empty($items));

        if ($isJsonMode) {
            foreach ($items as $it) {
                $sy = trim((string) ($it['year_label'] ?? $it['school_year'] ?? ''));
                if ($sy === '')
                    continue;

                $rating = trim((string) ($it['rating'] ?? ''));
                $note = isset($it['note']) ? trim((string) $it['note']) : '';

                $years[] = $sy;
                $ratingMap[$sy] = $rating; // có thể rỗng để xóa
                $noteMap[$sy] = $note;
            }

            $years = array_values(array_unique($years));
            sort($years);

            if (empty($years)) {
                http_response_code(400);
                echo json_encode(['error' => 'Chưa có năm học để lưu'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // log đẹp: 2023-2024 ... 2025-2026 => 2023-2026
            if (preg_match('/^(\d{4})-\d{4}$/', $years[0], $m1) && preg_match('/^\d{4}-(\d{4})$/', $years[count($years) - 1], $m2)) {
                $rangeYear = $m1[1] . '-' . $m2[1];
            } else {
                $rangeYear = implode(', ', $years);
            }

        } else {
            // fallback form cũ
            $rangeYear = trim((string) ($_POST['range_year'] ?? ''));
            $years = expand_school_year_range($rangeYear);
            if (empty($years)) {
                http_response_code(400);
                echo json_encode(['error' => 'range_year không hợp lệ. Ví dụ đúng: 2023-2026'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $ratingMap = $_POST['rating'] ?? [];
            $noteMap = $_POST['note'] ?? [];
            if (!is_array($ratingMap))
                $ratingMap = [];
            if (!is_array($noteMap))
                $noteMap = [];
        }

        // =========================
        // SAVE / DELETE
        // =========================
        $saved = 0;
        $deleted = 0;

        // load danh sách năm active để validate
        $activeYears = [];
        $stYears = $pdo->query("
    SELECT year_label
    FROM school_years
    WHERE is_active = 1
");
        $activeYears = $stYears->fetchAll(PDO::FETCH_COLUMN);
        $activeSet = array_fill_keys($activeYears, true);

        $ins = $pdo->prepare("
    INSERT INTO member_reviews
        (member_id, school_year, rating, note, reviewed_by, reviewed_at, lock_applied, lock_applied_at)
    VALUES
        (?, ?, ?, ?, ?, NOW(), 1, NOW())
    ON DUPLICATE KEY UPDATE
        rating = VALUES(rating),
        note = VALUES(note),
        reviewed_by = VALUES(reviewed_by),
        reviewed_at = VALUES(reviewed_at),
        lock_applied = 1,
        lock_applied_at = NOW()
");


        $del = $pdo->prepare("
    DELETE FROM member_reviews
    WHERE member_id = ? AND school_year = ?
    LIMIT 1
");

        $chk = $pdo->prepare("
    SELECT lock_applied
    FROM member_reviews
    WHERE member_id = ? AND school_year = ?
    LIMIT 1
");
        // ✅ set năm đang mở đánh giá (is_open=1)
        $openYears = [];
        try {
            $stOpen = $pdo->query("
        SELECT sy.year_label
        FROM school_years sy
        JOIN member_review_windows w ON w.school_year_id = sy.id
        WHERE sy.is_active = 1 AND w.is_open = 1
    ");
            $openYears = $stOpen->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $openYears = [];
        }
        $openSet = array_fill_keys($openYears, true);

        foreach ($years as $sy) {
            if (!is_valid_school_year($sy)) {
                throw new Exception("Năm học không hợp lệ: $sy");
            }

            // ✅ chỉ cho lưu/xóa những năm active
            if (!isset($activeSet[$sy])) {
                throw new Exception("Năm học $sy không còn hiệu lực (không active).");
            }

            $rating = trim((string) ($ratingMap[$sy] ?? ''));
            $note = isset($noteMap[$sy]) ? trim((string) $noteMap[$sy]) : null;
            if ($note === '')
                $note = null;

            $chk->execute([$memberId, $sy]);
            $applied = $chk->fetchColumn(); // false nếu chưa có record
            $exists = ($applied !== false);
            $locked = ($exists && (int) $applied === 1);

            // ❌ năm đã khóa => không cho sửa/xóa qua review_save
            if ($locked) {
                throw new Exception("Năm học $sy đã bị khóa đánh giá. Admin phải mở khóa trước khi sửa.");
            }

            // ✅ Nếu rating rỗng và chưa có record -> không có thao tác gì => SKIP
            // (vì JS gửi cả list năm, tránh bị chặn bởi trạng thái open)
            if ($rating === '' && !$exists) {
                continue;
            }

            // ✅ từ đây trở xuống mới là thao tác thay đổi dữ liệu => bắt buộc năm phải đang mở đánh giá
            if (!isset($openSet[$sy])) {
                throw new Exception("Năm học $sy chưa mở đánh giá.");
            }

            // ✅ rating rỗng => delete record (nếu có)
            if ($rating === '') {
                $del->execute([$memberId, $sy]);
                if ($del->rowCount() > 0)
                    $deleted++;
                continue;
            }

            if (!in_array($rating, $allowed, true)) {
                throw new Exception("Rating không hợp lệ ($sy): $rating");
            }

            // ✅ lưu là khóa ngay theo năm (behavior cũ của bạn)
            $ins->execute([$memberId, $sy, $rating, $note, (int) $uid]);
            $saved++;
        }



        // log
        log_activity(
            'review',
            'members',
            'member',
            $memberId,
            'Cập nhật đánh giá đoàn viên (' . $rangeYear . ')'
        );

        echo json_encode([
            'ok' => 1,
            'saved' => $saved,
            'deleted' => $deleted,
            'auto_lock_after_days' => 7
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'review_lock_year') {
        if (!is_admin_role($currentRole))
            forbidden();
        if (!can('members', 'review'))
            forbidden();

        $input = null;
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input))
                $input = null;
        }

        $memberId = (int) ($input['member_id'] ?? $_POST['member_id'] ?? 0);
        $schoolYear = trim((string) ($input['school_year'] ?? $_POST['school_year'] ?? ''));

        if (!$memberId || $schoolYear === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu member_id hoặc school_year'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!is_valid_school_year($schoolYear)) {
            http_response_code(400);
            echo json_encode(['error' => 'school_year không hợp lệ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        require_member_in_scope($pdo, $memberId, (string) $currentRole, $scope, $gvcnClassIds);

        $st = $pdo->prepare("
        UPDATE member_reviews
        SET lock_applied = 1, lock_applied_at = NOW()
        WHERE member_id = ? AND school_year = ?
        LIMIT 1
    ");
        $st->execute([$memberId, $schoolYear]);

        log_activity('review', 'members', 'member', $memberId, 'Admin khóa đánh giá năm ' . $schoolYear);

        echo json_encode(['ok' => 1, 'affected' => (int) $st->rowCount()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'review_unlock_year') {
        if (!is_admin_role($currentRole))
            forbidden();
        if (!can('members', 'review'))
            forbidden();

        $input = null;
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input))
                $input = null;
        }

        $memberId = (int) ($input['member_id'] ?? $_POST['member_id'] ?? 0);
        $schoolYear = trim((string) ($input['school_year'] ?? $_POST['school_year'] ?? ''));

        if (!$memberId || $schoolYear === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu member_id hoặc school_year'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!is_valid_school_year($schoolYear)) {
            http_response_code(400);
            echo json_encode(['error' => 'school_year không hợp lệ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        require_member_in_scope($pdo, $memberId, (string) $currentRole, $scope, $gvcnClassIds);

        $st = $pdo->prepare("
        UPDATE member_reviews
        SET lock_applied = 0, lock_applied_at = NULL
        WHERE member_id = ? AND school_year = ?
        LIMIT 1
    ");
        $st->execute([$memberId, $schoolYear]);

        log_activity('review', 'members', 'member', $memberId, 'Admin mở khóa đánh giá năm ' . $schoolYear);

        echo json_encode(['ok' => 1, 'affected' => (int) $st->rowCount()], JSON_UNESCAPED_UNICODE);
        exit;
    }


    if ($action === 'get') {
        if (!can('members', 'view'))
            forbidden();

        $id = (int) ($_GET['id'] ?? 0);
        if (!$id)
            forbidden();

        $sql = "
        SELECT 
            m.id,
            m.user_id,
            m.mssv,
            m.fullname,
            m.stop_follow,
            m.note,
            m.is_locked,
            m.chidoan_group_id,
            m.department_id,
            m.course_id,
            m.class_id,
            m.class_name,
            m.type,
            m.birth,
            m.join_date,
            m.ethnicity,
            m.religion,
            m.phone,
            m.email,
            m.native_place,
            m.current_address,
            m.party_probation_date,
            m.party_official_date,
            m.is_excellent_member,
            m.excellent_member_date,
            m.excellent_member_place,
            m.learned_party_class,
            m.party_class_date,
            m.party_class_place,

            d.name AS dept_name,
            d.type AS dept_type,
            c.name AS course_name,
            cl.name AS class_name2
        FROM members m
        LEFT JOIN departments d ON d.id = m.department_id
        LEFT JOIN courses c ON c.id = m.course_id
        LEFT JOIN classes cl ON cl.id = m.class_id
        WHERE m.id = ?
    ";

        $params = [$id];

        // 🔒 ÁP QUYỀN BÍ THƯ
        if ($currentRole === 'bithu') {
            if ((int) $scope['chidoan_group_id'] === 1) {
                // bí thư lớp
                $sql .= " AND m.class_id = ? ";
                $params[] = $scope['class_id'];
            } else {
                // bí thư GV → CHỈ GV
                $sql .= " AND m.chidoan_group_id = 2 ";
            }
        }
        // 🔒 ÁP QUYỀN GVCN
        if ($currentRole === 'gvcn') {
            $placeholders = implode(',', array_fill(0, count($gvcnClassIds), '?'));
            $sql .= " AND m.class_id IN ($placeholders) ";
            $params = array_merge($params, $gvcnClassIds);
        }

        $stm = $pdo->prepare($sql);
        $stm->execute($params);

        $row = $stm->fetch(PDO::FETCH_ASSOC);
        if (!$row)
            forbidden();

        echo json_encode($row, JSON_UNESCAPED_UNICODE);
        exit;
    }





    // === CREATE ===
    if ($action === 'create') {

        try {
            $pdo->beginTransaction(); // 🔥 BẮT ĐẦU TRANSACTION

            // ========================
            // VALIDATE DỮ LIỆU TRƯỚC
            // ========================
            $mssv = trim($_POST['mssv'] ?? '');
            if ($mssv === '') {
                throw new Exception('MSSV không được để trống');
            }

            // Check duplicate members (ƯU TIÊN CHECK MEMBERS)
            $checkMem = $pdo->prepare("SELECT id FROM members WHERE mssv=?");
            $checkMem->execute([$mssv]);
            if ($checkMem->fetchColumn()) {
                throw new Exception("Đoàn viên MSSV '$mssv' đã tồn tại");
            }

            // Check duplicate users
            $checkUser = $pdo->prepare("SELECT id FROM users WHERE username=?");
            $checkUser->execute([$mssv]);
            if ($checkUser->fetchColumn()) {
                throw new Exception("MSSV '$mssv' đã tồn tại trong users");
            }

            // ========================
            // XỬ LÝ NGÀY
            // ========================
            $birth = $_POST['birth'] !== '' ? $_POST['birth'] : null;
            $join = $_POST['join_date'] !== '' ? $_POST['join_date'] : null;

            $partyProbation = $_POST['party_probation_date'] !== '' ? $_POST['party_probation_date'] : null;
            $partyOfficial = $_POST['party_official_date'] !== '' ? $_POST['party_official_date'] : null;

            if ($partyOfficial && !$partyProbation) {
                throw new Exception('Phải có ngày dự bị trước khi có ngày chính thức');
            }
            if ($partyProbation && $partyOfficial && $partyOfficial < $partyProbation) {
                throw new Exception('Ngày chính thức phải sau hoặc bằng ngày dự bị');
            }

            // ========================
            // TẠO USER (TẠM THỜI)
            // ========================
            $passwordPlain = '123456';
            if ($birth && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birth, $m)) {
                $passwordPlain = "{$m[3]}{$m[2]}{$m[1]}";
            }

            $passwordHash = password_hash($passwordPlain, PASSWORD_BCRYPT);

            $pdo->prepare("
            INSERT INTO users (username, password_hash, role_id)
            VALUES (?, ?, ?)
        ")->execute([$mssv, $passwordHash, $roleUserId]);

            $userId = $pdo->lastInsertId();

            // ========================
            // XỬ LÝ CHI ĐOÀN
            // ========================
            $chidoanGroupId = (int) ($_POST['chidoan_group_id'] ?? 0);
            $deptId = $_POST['department_id'] ?? null;
            $courseId = $_POST['course_id'] ?? null;
            $classId = $_POST['class_id'] ?? null;

            if ($currentRole === 'bithu') {
                if ((int) $scope['chidoan_group_id'] === 1) {
                    // bí thư lớp → ép theo scope
                    $chidoanGroupId = 1;
                    $deptId = $scope['department_id'];
                    $courseId = $scope['course_id'];
                    $classId = $scope['class_id'];
                } else {
                    // bí thư GV → chỉ ép NHÓM, KHÔNG ÉP KHOA
                    $chidoanGroupId = 2;
                    $courseId = null;
                    $classId = null;
                }
            }

            if ($chidoanGroupId === 1 && (!$deptId || !$courseId || !$classId)) {
                throw new Exception('Chi đoàn lớp bắt buộc chọn Khoa, Khóa và Lớp');
            }

            if ($chidoanGroupId === 2) {

                // CREATE → bắt buộc phải có department_id từ form hoặc scope
                if (!$deptId) {
                    throw new Exception('Chi đoàn giáo viên phải chọn Khoa hoặc Phòng');
                }

                $courseId = null;
                $classId = null;
            }



            if ($classId && !validateClass($pdo, $classId, $deptId, $courseId)) {
                throw new Exception('Lớp không thuộc khoa / khóa đã chọn');
            }

            // ========================
            // LẤY TÊN LỚP
            // ========================
            $className = null;
            if ($classId) {
                $q = $pdo->prepare("SELECT name FROM classes WHERE id=?");
                $q->execute([$classId]);
                $className = $q->fetchColumn();
            }

            // ========================
            // INSERT MEMBERS (QUYẾT ĐỊNH SỐNG CÒN)
            // ========================
            $type = $_POST['type'] ?? 'member';
            $isExcellentMember = 0;
            $excellentMemberDate = null;
            $excellentMemberPlace = null;
            $learnedPartyClass = 0;
            $partyClassDate = null;
            $partyClassPlace = null;

            if ($type === 'member') {
                $isExcellentMember = isset($_POST['is_excellent_member']) ? (int)$_POST['is_excellent_member'] : 0;
                if ($isExcellentMember === 1) {
                    $excellentMemberDate = !empty($_POST['excellent_member_date']) ? $_POST['excellent_member_date'] : null;
                    $excellentMemberPlace = !empty($_POST['excellent_member_place']) ? trim($_POST['excellent_member_place']) : null;
                }
                
                $learnedPartyClass = isset($_POST['learned_party_class']) ? (int)$_POST['learned_party_class'] : 0;
                if ($learnedPartyClass === 1) {
                    $partyClassDate = !empty($_POST['party_class_date']) ? $_POST['party_class_date'] : null;
                    $partyClassPlace = !empty($_POST['party_class_place']) ? trim($_POST['party_class_place']) : null;
                }
            }

            $stmt = $pdo->prepare("
            INSERT INTO members (
              user_id, mssv, fullname,
              chidoan_group_id,
              department_id, course_id, class_id, class_name,
              type, birth, join_date,
              party_probation_date, party_official_date,
              ethnicity, religion, phone, email,
              native_place, current_address,
              is_excellent_member, excellent_member_date, excellent_member_place,
              learned_party_class, party_class_date, party_class_place
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

            $stmt->execute([
                $userId,
                $mssv,
                $_POST['fullname'],

                $chidoanGroupId,
                $deptId,
                $courseId,
                $classId,
                $className,

                $type,
                $birth,
                $join,
                $partyProbation,
                $partyOfficial,

                $_POST['ethnicity'],
                $_POST['religion'],
                $_POST['phone'],
                $_POST['email'],
                trim($_POST['native_place'] ?? '') ?: null,
                trim($_POST['current_address'] ?? '') ?: null,
                $isExcellentMember,
                $excellentMemberDate,
                $excellentMemberPlace,
                $learnedPartyClass,
                $partyClassDate,
                $partyClassPlace
            ]);

            // ========================
            // COMMIT – THÀNH CÔNG
            // ========================
            $pdo->commit();

            log_activity(
                'create',
                'members',
                'member',
                $userId,
                'Thêm đoàn viên MSSV ' . $mssv
            );

            echo json_encode(['ok' => 1, 'msg' => "Đã thêm thành viên $mssv"]);
            exit;

        } catch (Throwable $e) {

            // ❌ LỖI → ROLLBACK → KHÔNG CÓ USER RÁC
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'set_lock') {
        if (!is_admin_role($currentRole))
            forbidden();
        if (!can('members', 'update'))
            forbidden();

        $id = (int) ($_POST['id'] ?? 0);
        $lock = (int) ($_POST['is_locked'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID không hợp lệ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($lock === 1) {
            $pdo->prepare("UPDATE members SET is_locked=1, locked_by=?, locked_at=NOW() WHERE id=?")
                ->execute([$uid, $id]);

            log_activity('update', 'members', 'member', $id, 'Khóa hồ sơ đoàn viên');
        } else {
            $pdo->prepare("UPDATE members SET is_locked=0, locked_by=NULL, locked_at=NULL WHERE id=?")
                ->execute([$id]);

            log_activity('update', 'members', 'member', $id, 'Mở khóa hồ sơ đoàn viên');
        }

        echo json_encode(['ok' => 1]);
        exit;
    }
    if ($action === 'bulk_set_lock') {
        if (!is_admin_role($currentRole))
            forbidden();
        if (!can('members', 'update'))
            forbidden();

        $ids = parse_ids($_POST['ids'] ?? $_POST['id'] ?? []);
        $lock = (int) ($_POST['is_locked'] ?? 0);
        $lock = $lock === 1 ? 1 : 0;

        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'Danh sách ID trống/không hợp lệ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            // cố gắng dùng locked_by/locked_at (như set_lock)
            if ($lock === 1) {
                $sql = "UPDATE members
                    SET is_locked=1, locked_by=?, locked_at=NOW()
                    WHERE id IN ($placeholders)";
                $params = array_merge([$uid], $ids);
            } else {
                $sql = "UPDATE members
                    SET is_locked=0, locked_by=NULL, locked_at=NULL
                    WHERE id IN ($placeholders)";
                $params = $ids;
            }

            $st = $pdo->prepare($sql);
            $st->execute($params);
            $affected = (int) $st->rowCount();

        } catch (Throwable $e) {
            // fallback nếu DB thiếu locked_by/locked_at
            if ($lock === 1) {
                $sql = "UPDATE members SET is_locked=1 WHERE id IN ($placeholders)";
            } else {
                $sql = "UPDATE members SET is_locked=0 WHERE id IN ($placeholders)";
            }
            $st = $pdo->prepare($sql);
            $st->execute($ids);
            $affected = (int) $st->rowCount();
        }

        // log gọn (tránh log quá dài)
        $msg = ($lock === 1)
            ? ('Khóa hàng loạt ' . count($ids) . ' hồ sơ đoàn viên')
            : ('Mở khóa hàng loạt ' . count($ids) . ' hồ sơ đoàn viên');

        log_activity('update', 'members', 'member', null, $msg);

        echo json_encode([
            'ok' => 1,
            'requested' => count($ids),
            'affected' => $affected
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }



    if ($action === 'update_stop_follow') {
        if (!can('members', 'update'))
            forbidden();

        $id = (int) ($_POST['id'] ?? 0);
        $stop = (int) ($_POST['stop_follow'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID không hợp lệ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (member_is_locked($pdo, $id))
            locked_err();

        $pdo->prepare("
    UPDATE members
    SET stop_follow = ?
    WHERE id = ?
")->execute([$stop, $id]);


        log_activity(
            'update',
            'members',
            'member',
            $id,
            $stop ? 'Ngừng theo dõi đoàn viên' : 'Bật theo dõi đoàn viên'
        );

        echo json_encode(['ok' => 1]);
        exit;
    }
    if ($action === 'update_note') {
        if (!can('members', 'update'))
            forbidden();

        $id = (int) ($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID không hợp lệ'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (member_is_locked($pdo, $id))
            locked_err();

        $pdo->prepare("
    UPDATE members
    SET note = ?
    WHERE id = ?
")->execute([$note !== '' ? $note : null, $id]);


        log_activity(
            'update',
            'members',
            'member',
            $id,
            'Cập nhật ghi chú đoàn viên'
        );

        echo json_encode(['ok' => 1]);
        exit;
    }


    // === UPDATE ===
    if ($action === 'update') {

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id)
            forbidden();

        // ===== LẤY MEMBER CŨ (PHẢI LẤY ĐỦ dept/course để giữ nguyên cho GVCN) =====
        $stOld = $pdo->prepare("
        SELECT
            id,
            chidoan_group_id,
            department_id,
            course_id,
            class_id,
            is_locked
        FROM members
        WHERE id=?
        LIMIT 1
    ");
        $stOld->execute([$id]);
        $old = $stOld->fetch(PDO::FETCH_ASSOC);
        if (!$old)
            forbidden();

        if ((int) $old['is_locked'] === 1)
            locked_err();

        // ===== GIÁ TRỊ GỬI LÊN (đọc trước) =====
        $chidoanGroupId = (int) ($_POST['chidoan_group_id'] ?? $old['chidoan_group_id']);
        $deptId = $_POST['department_id'] ?? $old['department_id'];
        $courseId = $_POST['course_id'] ?? $old['course_id'];
        $classId = $_POST['class_id'] ?? $old['class_id'];

        // cast số (tránh bậy bạ)
        $deptId = $deptId !== null ? (int) $deptId : null;
        $courseId = $courseId !== null ? (int) $courseId : null;
        $classId = $classId !== null ? (int) $classId : null;

        // 🔒 GVCN chỉ được sửa đoàn viên lớp mình + KHÔNG ĐƯỢC ĐỔI khoa/khóa/lớp
        if ($currentRole === 'gvcn') {
            if (!in_array((int) $old['class_id'], array_map('intval', $gvcnClassIds), true)) {
                forbidden();
            }

            // ép giữ nguyên theo DB
            $chidoanGroupId = 1;
            $deptId = (int) $old['department_id'];
            $courseId = (int) $old['course_id'];
            $classId = (int) $old['class_id'];
        }

        // ===== KIỂM TRA QUYỀN BÍ THƯ =====
        if ($currentRole === 'bithu') {
            if ((int) ($scope['chidoan_group_id'] ?? 0) === 1) {
                // bí thư lớp: chỉ được sửa đúng lớp scope
                if ((int) $old['class_id'] !== (int) $scope['class_id'])
                    forbidden();

                $chidoanGroupId = 1;
                $deptId = (int) $scope['department_id'];
                $courseId = (int) $scope['course_id'];
                $classId = (int) $scope['class_id'];
            } else {
                // bí thư GV: chỉ sửa được member thuộc nhóm GV
                if ((int) $old['chidoan_group_id'] !== 2)
                    forbidden();

                $chidoanGroupId = 2;
                // KHÔNG đụng deptId (cho chọn/giữ theo DB hoặc form), nhưng ép course/class null
                $courseId = null;
                $classId = null;
            }
        }

        // ===== VALIDATE THEO NHÓM =====
        if ($chidoanGroupId === 1) {
            if (!$deptId || !$courseId || !$classId) {
                echo json_encode(['error' => 'Chi đoàn lớp bắt buộc chọn Khoa, Khóa và Lớp'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // validate lớp thuộc khoa/khóa
            if (!validateClass($pdo, $classId, $deptId, $courseId)) {
                echo json_encode(['error' => 'Lớp không thuộc khoa / khóa đã chọn'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        if ($chidoanGroupId === 2) {
            if (!$deptId) {
                echo json_encode(['error' => 'Chi đoàn giáo viên phải chọn Khoa hoặc Phòng'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $courseId = null;
            $classId = null;
        }

        // ===== TÊN LỚP (phải lấy lại) =====
        $className = null;
        if ($classId) {
            $q = $pdo->prepare("SELECT name FROM classes WHERE id=? LIMIT 1");
            $q->execute([$classId]);
            $className = $q->fetchColumn() ?: null;
        }

        // ===== NGÀY =====
        $birth = ($_POST['birth'] ?? '') !== '' ? $_POST['birth'] : null;
        $join = ($_POST['join_date'] ?? '') !== '' ? $_POST['join_date'] : null;
        $partyProbation = ($_POST['party_probation_date'] ?? '') !== '' ? $_POST['party_probation_date'] : null;
        $partyOfficial = ($_POST['party_official_date'] ?? '') !== '' ? $_POST['party_official_date'] : null;

        if ($partyOfficial && !$partyProbation) {
            echo json_encode(['error' => 'Phải có ngày dự bị trước'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($partyProbation && $partyOfficial && $partyOfficial < $partyProbation) {
            echo json_encode(['error' => 'Ngày chính thức phải sau hoặc bằng ngày dự bị'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ===== UPDATE =====
        $type = $_POST['type'] ?? 'member';
        $isExcellentMember = 0;
        $excellentMemberDate = null;
        $excellentMemberPlace = null;
        $learnedPartyClass = 0;
        $partyClassDate = null;
        $partyClassPlace = null;

        if ($type === 'member') {
            $isExcellentMember = isset($_POST['is_excellent_member']) ? (int)$_POST['is_excellent_member'] : 0;
            if ($isExcellentMember === 1) {
                $excellentMemberDate = !empty($_POST['excellent_member_date']) ? $_POST['excellent_member_date'] : null;
                $excellentMemberPlace = !empty($_POST['excellent_member_place']) ? trim($_POST['excellent_member_place']) : null;
            }
            
            $learnedPartyClass = isset($_POST['learned_party_class']) ? (int)$_POST['learned_party_class'] : 0;
            if ($learnedPartyClass === 1) {
                $partyClassDate = !empty($_POST['party_class_date']) ? $_POST['party_class_date'] : null;
                $partyClassPlace = !empty($_POST['party_class_place']) ? trim($_POST['party_class_place']) : null;
            }
        }

        $stUp = $pdo->prepare("
        UPDATE members SET
          mssv=?, fullname=?,
          chidoan_group_id=?,
          department_id=?, course_id=?, class_id=?, class_name=?,
          type=?, birth=?, join_date=?,
          party_probation_date=?, party_official_date=?,
          ethnicity=?, religion=?, phone=?, email=?,
          native_place=?, current_address=?,
          is_excellent_member=?, excellent_member_date=?, excellent_member_place=?,
          learned_party_class=?, party_class_date=?, party_class_place=?
        WHERE id=?
        LIMIT 1
    ");

        $stUp->execute([
            trim((string) ($_POST['mssv'] ?? '')),
            trim((string) ($_POST['fullname'] ?? '')),
            $chidoanGroupId,
            $deptId,
            $courseId,
            $classId,
            $className,
            $type,
            $birth,
            $join,
            $partyProbation,
            $partyOfficial,
            $_POST['ethnicity'] ?? null,
            $_POST['religion'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['email'] ?? null,
            trim($_POST['native_place'] ?? '') ?: null,
            trim($_POST['current_address'] ?? '') ?: null,
            $isExcellentMember,
            $excellentMemberDate,
            $excellentMemberPlace,
            $learnedPartyClass,
            $partyClassDate,
            $partyClassPlace,
            $id
        ]);

        log_activity('update', 'members', 'member', $id, 'Cập nhật đoàn viên MSSV ' . ($_POST['mssv'] ?? ''));

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
        exit;
    }


    // === DELETE ===
    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        if (member_is_locked($pdo, $id))
            locked_err();

        $mssv = $pdo->prepare("SELECT mssv FROM members WHERE id=?");
        $mssv->execute([$id]);
        $mssv = $mssv->fetchColumn();

        if ($currentRole === 'bithu') {
            $stmt = $pdo->prepare("
      SELECT class_id
      FROM members
      WHERE id=?
    ");
            $stmt->execute([$id]);

            if ($currentRole === 'bithu') {
                $stmt = $pdo->prepare("
        SELECT chidoan_group_id, department_id, class_id
        FROM members
        WHERE id=?
    ");
                $stmt->execute([$id]);
                $old = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($scope['chidoan_group_id'] == 1) {
                    if ((int) $old['class_id'] !== (int) $scope['class_id']) {
                        forbidden();
                    }
                } else {
                    if ((int) $old['chidoan_group_id'] !== 2) {
                        forbidden();
                    }
                }

            }
            if ($currentRole === 'gvcn') {
                $stmt = $pdo->prepare("
        SELECT class_id
        FROM members
        WHERE id=?
    ");
                $stmt->execute([$id]);
                $classId = (int) $stmt->fetchColumn();

                if (!in_array($classId, $gvcnClassIds, true)) {
                    forbidden();
                }
            }


        }

        $pdo->prepare("DELETE FROM members WHERE id=?")->execute([$id]);

        log_activity(
            'delete',
            'members',
            'member',
            $id,
            'Xóa đoàn viên MSSV ' . $mssv
        );

        echo json_encode(['ok' => 1]);
        exit;

    }


    // ============================================================
// === IMPORT XLSX — FIXED 11 CỘT + DATE SERIAL + UTF-8 =======
// ============================================================
    function keepOld($new, $old)
    {
        return ($new !== null && $new !== '') ? $new : $old;
    }


    if ($action === 'import_xlsx' && $currentRole === 'bithu') {
        forbidden();
    }
    if ($action === 'import_xlsx') {
        set_time_limit(0);
        ini_set('max_execution_time', 0);

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== 0) {
            echo json_encode(['error' => 'Không nhận được file XLSX']);
            exit;
        }

        $file = $_FILES['file']['tmp_name'];

        if (!$xlsx = SimpleXLSX::parseFile($file)) {
            echo json_encode(['error' => SimpleXLSX::parseError()]);
            exit;
        }

        if (method_exists($xlsx, 'setLegacyCompatibility')) {
            $xlsx->setLegacyCompatibility(true);
        }

        $rows = $xlsx->rows();
        $header = array_map('trim', array_shift($rows));

        // ==== MAP HEADER (BỎ QUA TUỔI) ====
        $map = [];

        foreach ($header as $i => $h) {
            $h = mb_strtolower(trim($h), 'UTF-8');

            if (strpos($h, 'mssv') !== false)
                $map['mssv'] = $i;
            elseif (strpos($h, 'họ tên') !== false)
                $map['fullname'] = $i;
            elseif ($h === 'khoa')
                $map['dept'] = $i;
            elseif ($h === 'khóa')
                $map['course'] = $i;
            elseif ($h === 'lớp')
                $map['class'] = $i;
            elseif (strpos($h, 'đối tượng') !== false)
                $map['type'] = $i;
            elseif (strpos($h, 'ngày sinh') !== false)
                $map['birth'] = $i;
            elseif (strpos($h, 'ngày vào') !== false)
                $map['join'] = $i;
            elseif (strpos($h, 'dân tộc') !== false)
                $map['ethnicity'] = $i;
            elseif (strpos($h, 'tôn giáo') !== false)
                $map['religion'] = $i;
            elseif (strpos($h, 'sđt') !== false)
                $map['phone'] = $i;
            elseif (strpos($h, 'email') !== false)
                $map['email'] = $i;
            elseif (strpos($h, 'nguyên quán') !== false)
                $map['native_place'] = $i;
            elseif (strpos($h, 'địa chỉ') !== false)
                $map['current_address'] = $i;
            elseif (strpos($h, 'dự bị') !== false)
                $map['party_probation'] = $i;
            elseif (strpos($h, 'chính thức') !== false)
                $map['party_official'] = $i;

            // ❌ TUỔI ĐỜI / TUỔI ĐOÀN → KHÔNG MAP
        }

        $required = ['mssv', 'fullname'];

        foreach ($required as $k) {
            if (!isset($map[$k])) {
                echo json_encode(['error' => "Thiếu cột bắt buộc: $k"]);
                exit;
            }
        }


        $added = 0;
        $updated = 0;
        $skipped = 0;
        $duplicates = [];

        // ==== CHUẨN HÓA NGÀY (FINAL) ====
        function normalizeDate($v)
        {
            if ($v === null)
                return null;
            $v = trim((string) $v);
            if ($v === '')
                return null;

            // yyyy-mm-dd | yyyy/mm/dd | yyyy-mm-dd hh:mm:ss
            if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $v, $m)) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            }

            // dd/mm/yyyy OR mm/dd/yyyy
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $v, $m)) {
                $a = (int) $m[1];
                $b = (int) $m[2];
                $y = (int) $m[3];

                // nếu số đầu > 12 → chắc chắn là dd/mm
                if ($a > 12) {
                    return sprintf('%04d-%02d-%02d', $y, $b, $a);
                }

                // nếu số thứ hai > 12 → chắc chắn là mm/dd
                if ($b > 12) {
                    return sprintf('%04d-%02d-%02d', $y, $a, $b);
                }

                // mơ hồ → mặc định dd/mm (phổ biến VN)
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }

            // Excel serial date
            if (is_numeric($v)) {
                $n = (float) $v;
                if ($n > 30000 && $n < 50000) {
                    return gmdate('Y-m-d', ($n - 25569) * 86400);
                }
            }

            return null;
        }



        // ==== LẤY ID THEO TÊN ====
        function getIdByName(PDO $pdo, $table, $name)
        {
            $name = trim($name ?? '');
            if ($name === '')
                return null;

            $s = $pdo->prepare("SELECT id FROM `$table` WHERE name COLLATE utf8mb4_general_ci = ? LIMIT 1");
            $s->execute([$name]);
            return $s->fetchColumn() ?: null;
        }

        // ==== PREPARED STATEMENTS ====
        $stmtInsert = $pdo->prepare("
INSERT INTO members (
  user_id, mssv, fullname,
  chidoan_group_id,
  department_id, course_id, class_id, class_name,
  type, birth, join_date,
  native_place, current_address,
  ethnicity, religion, phone, email
)

VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

        $stmtUpdate = $pdo->prepare("
UPDATE members SET
  fullname=?,
  chidoan_group_id=?,
  department_id=?, course_id=?, class_id=?, class_name=?,
  birth=?, join_date=?,
  native_place=?, current_address=?,
  ethnicity=?, religion=?, phone=?, email=?
WHERE mssv=?

    ");

        // ==== XỬ LÝ ROW (HỖ TRỢ 10 & 12 CỘT) ====
        $pdo->beginTransaction();
        foreach ($rows as $row) {


            // Chuẩn hóa UTF-8 + pad đủ 12 cột
            $row = array_map(
                fn($v) => is_string($v) ? trim(mb_convert_encoding($v, 'UTF-8', 'UTF-8')) : $v,
                $row
            );
            // Chuẩn hóa UTF-8
            $row = array_map(
                fn($v) => is_string($v) ? trim(mb_convert_encoding($v, 'UTF-8', 'UTF-8')) : $v,
                $row
            );

            // === LẤY DỮ LIỆU THEO MAP (KHÔNG BAO GIỜ ĐỤNG TUỔI) ===
            $mssv = $row[$map['mssv']] ?? '';
            $fullname = $row[$map['fullname']] ?? '';
            $className = isset($map['class']) ? ($row[$map['class']] ?? '') : '';
            $typeVi = isset($map['type']) ? ($row[$map['type']] ?? '') : '';
            $birthRaw = isset($map['birth']) ? ($row[$map['birth']] ?? '') : '';
            $joinRaw = isset($map['join']) ? ($row[$map['join']] ?? '') : '';
            $ethnicity = isset($map['ethnicity']) ? ($row[$map['ethnicity']] ?? '') : '';
            $religion = isset($map['religion']) ? ($row[$map['religion']] ?? '') : '';
            $phone = isset($map['phone']) ? ($row[$map['phone']] ?? '') : '';
            $email = isset($map['email']) ? ($row[$map['email']] ?? '') : '';
            $nativePlace = isset($map['native_place'])
                ? trim($row[$map['native_place']] ?? '')
                : null;

            $currentAddress = isset($map['current_address'])
                ? trim($row[$map['current_address']] ?? '')
                : null;

            // ép rỗng → null
            $nativePlace = $nativePlace === '' ? null : $nativePlace;
            $currentAddress = $currentAddress === '' ? null : $currentAddress;
            $partyProbationRaw = isset($map['party_probation']) ? ($row[$map['party_probation']] ?? '') : '';
            $partyOfficialRaw = isset($map['party_official']) ? ($row[$map['party_official']] ?? '') : '';
            $partyProbation = normalizeDate($partyProbationRaw);
            $partyOfficial = normalizeDate($partyOfficialRaw);
            if ($partyOfficial && !$partyProbation) {
                $partyProbation = null;
            }
            if ($partyProbation && $partyOfficial && $partyOfficial < $partyProbation) {
                $partyOfficial = null;
            }
            // Khoa / Khóa (nếu có)
            $deptName = isset($map['dept']) ? $row[$map['dept']] : '';
            $courseName = isset($map['course']) ? $row[$map['course']] : '';

            $deptId = getIdByName($pdo, 'departments', $deptName);
            $courseId = getIdByName($pdo, 'courses', $courseName);
            $classId = $className !== ''
                ? getIdByName($pdo, 'classes', $className)
                : null;
            $chidoanGroupId = 1; // import học sinh → chi đoàn học sinh

            // 🔥 FIX: nếu có lớp mà thiếu khoa / khóa → suy ngược từ lớp
            if ($classId && (!$deptId || !$courseId)) {
                $q = $pdo->prepare("
                    SELECT department_id, course_id
                    FROM classes
                    WHERE id = ?
                    LIMIT 1
                ");
                $q->execute([$classId]);
                $meta = $q->fetch(PDO::FETCH_ASSOC);

                if ($meta) {
                    // chỉ set nếu đang null (KHÔNG ghi đè Excel)
                    if (!$deptId) {
                        $deptId = $meta['department_id'];
                    }
                    if (!$courseId) {
                        $courseId = $meta['course_id'];
                    }
                }
            }

            // MSSV rỗng → skip
            if (trim($mssv) === '') {
                $skipped++;
                continue;
            }

            // Chuẩn hóa ngày
            $birth = normalizeDate($birthRaw);
            $join_date = normalizeDate($joinRaw);

            // Chuẩn hóa đối tượng
            $type = mb_strtolower($typeVi, 'UTF-8');
            if (in_array($type, ['đoàn viên', 'doan vien', 'member'])) {
                $type = 'member';
            } else {
                // ✅ MẶC ĐỊNH: THANH NIÊN
                $type = 'youth';
            }



            // ==== USER ====
            // 1️⃣ Kiểm tra user theo MSSV
            $checkUser = $pdo->prepare("SELECT id FROM users WHERE username=?");
            $checkUser->execute([$mssv]);
            $userId = $checkUser->fetchColumn();

            // 2️⃣ Nếu CHƯA có user → TẠO USER (BẤT KỂ member hay youth)
            if (!$userId) {

                // mật khẩu mặc định
                $passwordPlain = '123456';

                // 👉 CHỈ ĐOÀN VIÊN + có ngày sinh mới dùng ngày sinh làm pass
                if (
                    !empty($birth) &&
                    preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birth, $m)
                ) {
                    // DDMMYYYY
                    $passwordPlain = "{$m[3]}{$m[2]}{$m[1]}";
                }

                $passHash = password_hash($passwordPlain, PASSWORD_BCRYPT);

                $pdo->prepare("
                    INSERT INTO users (username, password_hash, role_id)
                    VALUES (?, ?, ?)
                    ")->execute([$mssv, $passHash, $roleUserId]);

                $userId = $pdo->lastInsertId();

            }



            // ==== MEMBER ====
            $checkMem = $pdo->prepare("SELECT id FROM members WHERE mssv=?");
            $checkMem->execute([$mssv]);
            $exists = $checkMem->fetchColumn();

            // === LẤY DATA CŨ ===
            $old = null;
            if ($exists) {
                $getOld = $pdo->prepare("
                    SELECT
                    fullname,
                    chidoan_group_id,
                    department_id, course_id, class_id, class_name,
                    birth, join_date,
                    native_place, current_address,
                    ethnicity, religion, phone, email,
                    is_locked
                    FROM members
                    WHERE mssv=?
                    LIMIT 1
                ");

                $getOld->execute([$mssv]);
                $old = $getOld->fetch(PDO::FETCH_ASSOC);
                if ((int) ($old['is_locked'] ?? 0) === 1) {
                    $skipped++;
                    continue;
                }
            }


            if ($exists) {
                $stmtUpdate->execute([
                    keepOld($fullname, $old['fullname']),
                    keepOld($chidoanGroupId, $old['chidoan_group_id']),

                    keepOld($deptId, $old['department_id']),
                    keepOld($courseId, $old['course_id']),
                    keepOld($classId, $old['class_id']),
                    keepOld($className, $old['class_name']),

                    keepOld($birth, $old['birth']),
                    keepOld($join_date, $old['join_date']),

                    keepOld($nativePlace, $old['native_place']),
                    keepOld($currentAddress, $old['current_address']),

                    keepOld($ethnicity, $old['ethnicity']),
                    keepOld($religion, $old['religion']),
                    keepOld($phone, $old['phone']),
                    keepOld($email, $old['email']),

                    $mssv
                ]);
                $updated++;
                $duplicates[] = $mssv;
            } else {
                $stmtInsert->execute([
                    $userId,
                    $mssv,
                    $fullname,

                    $chidoanGroupId,

                    $deptId,
                    $courseId,
                    $classId,
                    $className,

                    $type,
                    $birth,
                    $join_date,

                    $nativePlace,
                    $currentAddress,

                    $ethnicity,
                    $religion,
                    $phone,
                    $email
                ]);

                $added++;
            }

        }
        $pdo->commit();

        log_activity(
            'import',
            'members',
            'member',
            null,
            "Import Excel: thêm $added, cập nhật $updated, bỏ qua $skipped"
        );

        echo json_encode([
            'ok' => 1,
            'msg' => "Nhập $added mới, cập nhật $updated trùng, bỏ qua $skipped lỗi.",
            'added' => $added,
            'updated' => $updated,
            'duplicates' => $duplicates
        ]);

        exit;
    }


    // === EXPORT XLSX (CÓ LỌC) ===
// === EXPORT XLSX (CÓ LỌC) — PhpSpreadsheet (Header y chang mẫu) ===
    if ($action === 'export_xlsx') {

        // dọn output buffer
        while (ob_get_level() > 0)
            ob_end_clean();
        ob_start();

        date_default_timezone_set('Asia/Ho_Chi_Minh');

        if (!can('members', 'print'))
            forbidden();

        $filter = trim($_GET['filter'] ?? '');
        $whereScope = '';
        $whereScope .= " AND (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1)) ";
        $whereScope .= " AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1)) ";
        $params = [];

        /* ======================
           🔒 BÍ THƯ – SCOPE
        ====================== */
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
        }

        /* ======================
           🔍 FILTER MỀM (KHOA / KHÓA / LỚP)
        ====================== */
        $deptId = (int) ($_GET['department_id'] ?? 0);
        $courseId = (int) ($_GET['course_id'] ?? 0);
        $classId = (int) ($_GET['class_id'] ?? 0);
        $hideStopped = (int) ($_GET['hide_stopped'] ?? 0);

        if ($deptId) {
            $whereScope .= " AND (m.department_id = ? OR cl.department_id = ?) ";
            $params[] = $deptId;
            $params[] = $deptId;
        }

        if ($courseId) {
            $whereScope .= " AND m.course_id = ? ";
            $params[] = $courseId;
        }

        if ($classId) {
            if ($currentRole === 'gvcn' && !in_array($classId, $gvcnClassIds, true)) {
                $whereScope .= " AND 1=0 ";
            } else {
                $whereScope .= " AND m.class_id = ? ";
                $params[] = $classId;
            }
        }

        if ($hideStopped === 1) {
            $whereScope .= " AND m.stop_follow = 0 ";
        }

        if ($filter !== '') {
            $whereScope .= " AND m.type = ? ";
            $params[] = $filter;
        }

        /* ======================
           🧠 SQL
        ====================== */
        $sql = "
SELECT 
    m.mssv,
    m.fullname,
    COALESCE(d1.name, d2.name) AS dept_name,
    COALESCE(d1.type, d2.type) AS dept_type,
    c.name AS course_name,
    cl.name AS class_name,
    m.type,
    m.birth,
    m.join_date,
    IF(m.birth IS NOT NULL, TIMESTAMPDIFF(YEAR, m.birth, CURDATE()), NULL) AS age_life,
    IF(m.join_date IS NOT NULL, TIMESTAMPDIFF(YEAR, m.join_date, CURDATE()), NULL) AS age_youth,
    m.ethnicity,
    m.religion,
    m.phone,
    m.email,
    m.native_place,
    m.current_address,
    m.party_probation_date,
    m.party_official_date,
    m.stop_follow
FROM members m
LEFT JOIN classes cl ON cl.id = m.class_id
LEFT JOIN departments d1 ON d1.id = m.department_id
LEFT JOIN departments d2 ON d2.id = cl.department_id
LEFT JOIN courses c ON c.id = m.course_id
WHERE 1=1
$whereScope
ORDER BY m.fullname
";

        $stm = $pdo->prepare($sql);
        $stm->execute($params);
        $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

        /* ======================
           HELPERS TITLE/FILENAME
        ====================== */
        function formatUnit_export($r)
        {
            if (!empty($r['class_name']))
                return $r['class_name'];
            if (!empty($r['dept_name'])) {
                return (($r['dept_type'] ?? '') === 'phong' ? 'Phòng ' : 'Khoa ') . $r['dept_name'];
            }
            return '';
        }

        function get_export_unit_title(PDO $pdo, int $deptId, int $classId): string
        {
            if ($classId) {
                $st = $pdo->prepare("SELECT name FROM classes WHERE id=? LIMIT 1");
                $st->execute([$classId]);
                $name = (string) ($st->fetchColumn() ?: '');
                return $name !== '' ? $name : '...';
            }
            if ($deptId) {
                $st = $pdo->prepare("SELECT name, type FROM departments WHERE id=? LIMIT 1");
                $st->execute([$deptId]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $prefix = (($r['type'] ?? '') === 'phong') ? 'PHÒNG ' : 'KHOA ';
                    return $prefix . ($r['name'] ?? '...');
                }
            }
            return 'TOÀN TRƯỜNG';
        }

        function get_export_entity_label(string $filter): string
        {
            if ($filter === 'member')
                return 'ĐOÀN VIÊN';
            if ($filter === 'youth')
                return 'THANH NIÊN';
            return 'ĐOÀN VIÊN';
        }

        function vn_slug(string $s): string
        {
            $s = trim(mb_strtolower($s, 'UTF-8'));
            $map = [
                'a' => 'áàạảãâấầậẩẫăắằặẳẵ',
                'e' => 'éèẹẻẽêếềệểễ',
                'i' => 'íìịỉĩ',
                'o' => 'óòọỏõôốồộổỗơớờợởỡ',
                'u' => 'úùụủũưứừựửữ',
                'y' => 'ýỳỵỷỹ',
                'd' => 'đ',
            ];
            foreach ($map as $to => $from)
                $s = preg_replace('/[' . $from . ']/u', $to, $s);
            $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
            $s = preg_replace('/_+/', '_', $s);
            return trim($s, '_');
        }

        function get_filter_suffix(string $filter): string
        {
            if ($filter === 'member')
                return 'doan_vien';
            if ($filter === 'youth')
                return 'thanh_nien';
            return 'tat_ca';
        }

        $entity = get_export_entity_label($filter);
        $unitTitle = get_export_unit_title($pdo, $deptId, $classId);
        $titleLine1 = "DANH SÁCH {$entity}";
        $titleLine2 = ($classId ? "CHI ĐOÀN {$unitTitle}" : $unitTitle);

        $place = "Quận 8";
        $dateLine = $place . ", ngày " . date('j') . " tháng " . date('n') . " năm " . date('Y');

        // filename (giữ logic cũ)
        $filterSuffix = get_filter_suffix($filter);

        $className = null;
        if ($classId) {
            $st = $pdo->prepare("SELECT name FROM classes WHERE id=? LIMIT 1");
            $st->execute([$classId]);
            $className = $st->fetchColumn() ?: null;
        }

        $deptInfo = null;
        if ($deptId) {
            $st = $pdo->prepare("SELECT name, type FROM departments WHERE id=? LIMIT 1");
            $st->execute([$deptId]);
            $deptInfo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($classId && $className) {
            if ($deptInfo) {
                $deptPrefix = (($deptInfo['type'] ?? '') === 'phong') ? 'phong' : 'khoa';
                $filename = 'danh_sach_' . $deptPrefix . '_' . vn_slug($deptInfo['name'])
                    . '_lop_' . vn_slug($className)
                    . '_' . $filterSuffix . '.xlsx';
            } else {
                $filename = 'danh_sach_lop_' . vn_slug($className)
                    . '_' . $filterSuffix . '.xlsx';
            }
        } elseif ($deptInfo) {
            $deptPrefix = (($deptInfo['type'] ?? '') === 'phong') ? 'phong' : 'khoa';
            $filename = 'danh_sach_' . $deptPrefix . '_' . vn_slug($deptInfo['name'])
                . '_' . $filterSuffix . '.xlsx';
        } else {
            $filename = 'danh_sach_toan_truong_' . $filterSuffix . '.xlsx';
        }

        /* ======================
           📦 PhpSpreadsheet
        ====================== */
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Danh sach');

        // default font
        $spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(12);

        // 19 cột => A..S
        $lastColLetter = 'S';

        // ===== HEADER (Dòng 1 -> 4) KHÔNG VIỀN =====
        $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
        $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";

        // A1:F4
        $sheet->setCellValue("A1", $orgLeft);
        $sheet->mergeCells("A1:F4");
        $sheet->getStyle("A1:F4")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        // N1:S3
        $sheet->setCellValue("N1", $orgRight);
        $sheet->mergeCells("N1:S3");
        $sheet->getStyle("N1:S3")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'underline' => true],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        // N4:S4 (date line)
        $sheet->setCellValue("N4", $dateLine);
        $sheet->mergeCells("N4:S4");
        $sheet->getStyle("N4:S4")->applyFromArray([
            'font' => ['italic' => true, 'size' => 12],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Row heights theo mẫu
        $sheet->getRowDimension(1)->setRowHeight(20.5);
        $sheet->getRowDimension(2)->setRowHeight(15.75);
        $sheet->getRowDimension(3)->setRowHeight(15.75);
        $sheet->getRowDimension(4)->setRowHeight(32.25);

        // ===== TITLE (Dòng 5 -> 6) =====
        $sheet->setCellValue("A5", $titleLine1);
        $sheet->mergeCells("A5:S5");
        $sheet->getStyle("A5:S5")->applyFromArray([
            'font' => ['bold' => true, 'size' => 18],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->setCellValue("A6", $titleLine2);
        $sheet->mergeCells("A6:S6");
        $sheet->getStyle("A6:S6")->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'underline' => true],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(5)->setRowHeight(33.0);
        $sheet->getRowDimension(6)->setRowHeight(28.5);

        // Row 7: trống ngăn cách
        $sheet->mergeCells("A7:S7");
        $sheet->getRowDimension(7)->setRowHeight(10);

        /* ======================
           TABLE HEADER (Row 8)
        ====================== */
        $headerRow = 8;
        $headers = [
            'MSSV',
            'Họ tên',
            'Khoa',
            'Khóa',
            'Lớp',
            'Đối tượng',
            'Ngày sinh',
            'Ngày vào Đoàn',
            'Tuổi đời',
            'Tuổi đoàn',
            'Nguyên quán',
            'Nơi ở hiện tại',
            'Dân tộc',
            'Tôn giáo',
            'SĐT',
            'Email',
            'Ngày dự bị Đảng',
            'Ngày chính thức Đảng',
            'Ngừng theo dõi'
        ];

        $colIdx = 1;
        foreach ($headers as $h) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx) . $headerRow;
            $sheet->setCellValue($cell, $h);
            $colIdx++;
        }

        $sheet->getStyle("A{$headerRow}:S{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F2F2F2']
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        /* ======================
           BODY
        ====================== */
        $rowNum = $headerRow + 1;

        foreach ($rows as $r) {
            $deptLabel = '';
            if (!empty($r['dept_name'])) {
                $deptLabel = (($r['dept_type'] ?? '') === 'phong' ? 'Phòng ' : 'Khoa ') . $r['dept_name'];
            }

            // A: MSSV (string)
            $sheet->setCellValueExplicit("A{$rowNum}", (string) ($r['mssv'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("B{$rowNum}", (string) ($r['fullname'] ?? ''));
            $sheet->setCellValue("C{$rowNum}", $deptLabel);
            $sheet->setCellValue("D{$rowNum}", (string) ($r['course_name'] ?? ''));
            $sheet->setCellValue("E{$rowNum}", (string) formatUnit_export($r));
            $sheet->setCellValue("F{$rowNum}", (($r['type'] ?? '') === 'member') ? 'Đoàn viên' : 'Thanh niên');

            $sheet->setCellValue("G{$rowNum}", (string) ($r['birth'] ?? ''));
            $sheet->setCellValue("H{$rowNum}", (string) ($r['join_date'] ?? ''));

            $sheet->setCellValue("I{$rowNum}", $r['age_life'] !== null ? (int) $r['age_life'] : '');
            $sheet->setCellValue("J{$rowNum}", $r['age_youth'] !== null ? (int) $r['age_youth'] : '');

            $sheet->setCellValue("K{$rowNum}", (string) ($r['native_place'] ?? ''));
            $sheet->setCellValue("L{$rowNum}", (string) ($r['current_address'] ?? ''));

            $sheet->setCellValue("M{$rowNum}", (string) ($r['ethnicity'] ?? ''));
            $sheet->setCellValue("N{$rowNum}", (string) ($r['religion'] ?? ''));

            $sheet->setCellValueExplicit("O{$rowNum}", (string) ($r['phone'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("P{$rowNum}", (string) ($r['email'] ?? ''));

            $sheet->setCellValue("Q{$rowNum}", (string) ($r['party_probation_date'] ?? ''));
            $sheet->setCellValue("R{$rowNum}", (string) ($r['party_official_date'] ?? ''));

            $sheet->setCellValue("S{$rowNum}", ((int) ($r['stop_follow'] ?? 0) === 1) ? 'X' : '');

            $rowNum++;
        }

        $lastRow = $rowNum - 1;

        // border + wrap bảng (từ row 8)
        if ($lastRow >= $headerRow) {
            $sheet->getStyle("A{$headerRow}:S{$lastRow}")->applyFromArray([
                'alignment' => [
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    'wrapText' => true
                ],
                'borders' => [
                    'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
                ],
            ]);

            // canh lề hợp lý
            $sheet->getStyle("A{$headerRow}:A{$lastRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $sheet->getStyle("B{$headerRow}:E{$lastRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

            $sheet->getStyle("F{$headerRow}:J{$lastRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $sheet->getStyle("S{$headerRow}:S{$lastRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }



        /* ======================
           Column widths (theo mẫu bạn set)
        ====================== */
        $sheet->getColumnDimension('A')->setWidth(14); // MSSV
        $sheet->getColumnDimension('B')->setWidth(26); // Họ tên
        $sheet->getColumnDimension('C')->setWidth(24); // Khoa
        $sheet->getColumnDimension('D')->setWidth(10); // Khóa
        $sheet->getColumnDimension('E')->setWidth(16); // Lớp
        $sheet->getColumnDimension('F')->setWidth(12); // Đối tượng
        $sheet->getColumnDimension('G')->setWidth(14); // Ngày sinh
        $sheet->getColumnDimension('H')->setWidth(16); // Ngày vào Đoàn
        $sheet->getColumnDimension('I')->setWidth(10); // Tuổi đời
        $sheet->getColumnDimension('J')->setWidth(14); // Tuổi đoàn
        $sheet->getColumnDimension('K')->setWidth(26); // Nguyên quán
        $sheet->getColumnDimension('L')->setWidth(32); // Nơi ở hiện tại
        $sheet->getColumnDimension('M')->setWidth(12);
        $sheet->getColumnDimension('N')->setWidth(12);
        $sheet->getColumnDimension('O')->setWidth(14);
        $sheet->getColumnDimension('P')->setWidth(32);
        $sheet->getColumnDimension('Q')->setWidth(16);
        $sheet->getColumnDimension('R')->setWidth(18);
        $sheet->getColumnDimension('S')->setWidth(14);

        // log
        log_activity('export', 'members', null, null, 'Xuất danh sách đoàn viên ra Excel');

        // output
        while (ob_get_level() > 0)
            ob_end_clean();
        header_remove();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }



    // === SEARCH ===
    if ($action === 'search') {

        $where = " WHERE 1=1 ";
        $where .= " AND (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1)) ";
        $where .= " AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1)) ";
        $params = [];

        $keyword = trim($_GET['q'] ?? '');
        $filter = trim($_GET['filter'] ?? '');
        $hideStopped = (int) ($_GET['hide_stopped'] ?? 0);

        $perPage = 10;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        /* ======================
           🔒 BÍ THƯ – SCOPE CỨNG
        ====================== */
        if ($currentRole === 'bithu') {

            if ((int) $scope['chidoan_group_id'] === 1) {
                $where .= " AND m.class_id = ? ";
                $params[] = (int) $scope['class_id'];
            } else {
                $where .= " AND m.chidoan_group_id = 2 ";
            }

        } elseif ($currentRole === 'gvcn') {

            // 🔒 scope cứng
            $placeholders = implode(',', array_fill(0, count($gvcnClassIds), '?'));
            $where .= " AND m.class_id IN ($placeholders) ";
            $params = array_merge($params, $gvcnClassIds);

            // 🔍 filter mềm TRONG scope
            $deptId = (int) ($_GET['department_id'] ?? 0);
            $courseId = (int) ($_GET['course_id'] ?? 0);
            $classId = (int) ($_GET['class_id'] ?? 0);

            if ($deptId) {
                $where .= " AND (m.department_id = ? OR cl.department_id = ?) ";
                $params[] = $deptId;
                $params[] = $deptId;
            }


            if ($courseId) {
                $where .= " AND m.course_id = ? ";
                $params[] = $courseId;
            }

            if ($classId) {
                if (!in_array($classId, $gvcnClassIds, true)) {
                    $where .= " AND 1=0 ";
                } else {
                    $where .= " AND m.class_id = ? ";
                    $params[] = $classId;
                }
            }

        } else {
            /* ======================
               🧑‍💼 ADMIN – FILTER TỰ DO
            ====================== */
            $deptId = (int) ($_GET['department_id'] ?? 0);
            $courseId = (int) ($_GET['course_id'] ?? 0);
            $classId = (int) ($_GET['class_id'] ?? 0);

            if ($deptId) {
                $where .= " AND (m.department_id = ? OR cl.department_id = ?) ";
                $params[] = $deptId;
                $params[] = $deptId;
            }


            if ($courseId) {
                $where .= " AND m.course_id = ? ";
                $params[] = $courseId;
            }

            if ($classId) {
                $where .= " AND m.class_id = ? ";
                $params[] = $classId;
            }
        }
        // ✅ BÍ THƯ CHI ĐOÀN GV: cho phép filter theo khoa/phòng
        if (
            $currentRole === 'bithu'
            && (int) $scope['chidoan_group_id'] === 2
            && !empty($_GET['department_id'])
        ) {
            $where .= " AND m.department_id = ? ";
            $params[] = (int) $_GET['department_id'];
        }
        /* ============================================================
              ✅ COLUMN FILTERS (lọc theo tiêu đề cột - THEAD)
              - chỉ ADD điều kiện, không ảnh hưởng action khác
           ============================================================ */

        $col_mssv = trim($_GET['mssv'] ?? '');
        if ($col_mssv !== '') {
            $where .= " AND m.mssv LIKE ? ";
            $params[] = '%' . $col_mssv . '%';
        }

        $col_fullname = trim($_GET['fullname'] ?? '');
        if ($col_fullname !== '') {
            $where .= " AND m.fullname LIKE ? ";
            $params[] = '%' . $col_fullname . '%';
        }

        $col_class = trim($_GET['class_name'] ?? '');
        if ($col_class !== '') {
            $where .= " AND (cl.name LIKE ? OR m.class_name LIKE ?) ";
            $like = '%' . $col_class . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // type từ thead ưu tiên hơn filter cũ
        $col_type = trim($_GET['type'] ?? '');
        if ($col_type !== '') {
            $where .= " AND m.type = ? ";
            $params[] = $col_type;
            $filter = ''; // tránh apply thêm filter cũ phía dưới
        }

        $col_birth = trim($_GET['birth'] ?? '');
        if ($col_birth !== '') {
            $where .= " AND DATE(m.birth) = ? ";
            $params[] = $col_birth;
        }

        $col_join = trim($_GET['join_date'] ?? '');
        if ($col_join !== '') {
            $where .= " AND DATE(m.join_date) = ? ";
            $params[] = $col_join;
        }

        $ageLifeMin = (int) ($_GET['age_life_min'] ?? 0);
        if ($ageLifeMin > 0) {
            $where .= " AND m.birth IS NOT NULL AND TIMESTAMPDIFF(YEAR, m.birth, CURDATE()) >= ? ";
            $params[] = $ageLifeMin;
        }

        $ageYouthMin = (int) ($_GET['age_youth_min'] ?? 0);
        if ($ageYouthMin > 0) {
            $where .= " AND m.join_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, m.join_date, CURDATE()) >= ? ";
            $params[] = $ageYouthMin;
        }

        $col_native = trim($_GET['native_place'] ?? '');
        if ($col_native !== '') {
            $where .= " AND m.native_place LIKE ? ";
            $params[] = '%' . $col_native . '%';
        }

        $col_addr = trim($_GET['current_address'] ?? '');
        if ($col_addr !== '') {
            $where .= " AND m.current_address LIKE ? ";
            $params[] = '%' . $col_addr . '%';
        }

        $col_eth = trim($_GET['ethnicity'] ?? '');
        if ($col_eth !== '') {
            $where .= " AND m.ethnicity LIKE ? ";
            $params[] = '%' . $col_eth . '%';
        }

        $col_rel = trim($_GET['religion'] ?? '');
        if ($col_rel !== '') {
            $where .= " AND m.religion LIKE ? ";
            $params[] = '%' . $col_rel . '%';
        }

        $col_phone = trim($_GET['phone'] ?? '');
        if ($col_phone !== '') {
            $where .= " AND m.phone LIKE ? ";
            $params[] = '%' . $col_phone . '%';
        }

        $col_email = trim($_GET['email'] ?? '');
        if ($col_email !== '') {
            $where .= " AND m.email LIKE ? ";
            $params[] = '%' . $col_email . '%';
        }

        $col_note = trim($_GET['note'] ?? '');
        if ($col_note !== '') {
            $where .= " AND m.note LIKE ? ";
            $params[] = '%' . $col_note . '%';
        }

        // Đảng viên: party=1 => có dự bị hoặc chính thức; party=0 => cả 2 null
        $col_party = trim($_GET['party'] ?? '');
        if ($col_party === '1') {
            $where .= " AND (m.party_probation_date IS NOT NULL OR m.party_official_date IS NOT NULL) ";
        } elseif ($col_party === '0') {
            $where .= " AND (m.party_probation_date IS NULL AND m.party_official_date IS NULL) ";
        }

        $col_prob = trim($_GET['party_probation_date'] ?? '');
        if ($col_prob !== '') {
            $where .= " AND DATE(m.party_probation_date) = ? ";
            $params[] = $col_prob;
        }

        $col_off = trim($_GET['party_official_date'] ?? '');
        if ($col_off !== '') {
            $where .= " AND DATE(m.party_official_date) = ? ";
            $params[] = $col_off;
        }



        // score_min cần join rs giống data query
        $scoreMinRaw = trim($_GET['score_min'] ?? '');
        $scoreMin = ($scoreMinRaw !== '' && is_numeric($scoreMinRaw)) ? (int) $scoreMinRaw : null;
        /* ======================
           🔍 SEARCH
        ====================== */
        if ($keyword !== '') {
            $where .= " AND (
            m.fullname LIKE ? OR
            m.mssv LIKE ? OR
            cl.name LIKE ?
        )";
            $kw = "%$keyword%";
            array_push($params, $kw, $kw, $kw);
        }



        /* ======================
           🎯 FILTER TYPE
        ====================== */
        if ($filter !== '') {
            $where .= " AND m.type = ? ";
            $params[] = $filter;
        }

        /* ======================
           🚫 ẨN NGỪNG THEO DÕI
        ====================== */
        $where .= " AND m.stop_follow = 0 ";

        /* ======================
           📊 STATS (🔥 QUAN TRỌNG)
        ====================== */
        $stmtStat = $pdo->prepare("
        SELECT m.type, COUNT(*) AS total
        FROM members m
        LEFT JOIN classes cl ON cl.id = m.class_id
        $where
        AND m.stop_follow = 0

        GROUP BY m.type
    ");
        $stmtStat->execute($params);
        $statRaw = $stmtStat->fetchAll(PDO::FETCH_KEY_PAIR);

        $stats = [
            'member' => (int) ($statRaw['member'] ?? 0),
            'youth' => (int) ($statRaw['youth'] ?? 0),
        ];

        /* ======================
           📄 COUNT
        ====================== */
        $cnt = $pdo->prepare("
        SELECT COUNT(*)
        FROM members m
        LEFT JOIN classes cl ON cl.id = m.class_id
        $where
    ");
        $cnt->execute($params);
        $totalRows = (int) $cnt->fetchColumn();
        $totalPages = (int) ceil($totalRows / $perPage);

        /* ======================
           📋 DATA
        ====================== */
        $sql = "
    SELECT 
        m.*, 
        d.name AS dept_name,
        d.type AS dept_type,
        c.name AS course_name,
        cl.name AS class_name2,

        IF(m.birth IS NOT NULL,
           TIMESTAMPDIFF(YEAR, m.birth, CURDATE()),
           NULL
        ) AS age_life,

        IF(m.join_date IS NOT NULL,
           TIMESTAMPDIFF(YEAR, m.join_date, CURDATE()),
           NULL
        ) AS age_youth,

        COALESCE(rs.total_score, 0) AS total_score

    FROM members m
    LEFT JOIN departments d ON d.id = m.department_id
    LEFT JOIN courses c ON c.id = m.course_id
    LEFT JOIN classes cl ON cl.id = m.class_id
    LEFT JOIN (
        SELECT user_id, SUM(score) AS total_score
        FROM registrations
        WHERE status IN ('good','excellent')
        GROUP BY user_id
    ) rs ON rs.user_id = m.user_id
    $where
    ORDER BY total_score DESC, m.fullname
    LIMIT $perPage OFFSET $offset
    ";

        $stm = $pdo->prepare($sql);
        $stm->execute($params);

        echo json_encode([
            'rows' => $stm->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages,

            // 🔥 STATS THEO FILTER
            'stats' => $stats,

            // 🔐 PERMISSION
            'perm' => [
                'create' => can('members', 'create'),
                'update' => can('members', 'update'),
                'delete' => can('members', 'delete'),
                'print' => can('members', 'print'),
                'view' => can('members', 'view'),
                'review' => can('members', 'review'),
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }



    // === SAMPLE XLSX ===
// === SAMPLE XLSX ===
    if ($action === 'sample_xlsx') {

        $data = [
            [
                'MSSV',
                'Họ tên',
                'Lớp',
                'Đối tượng',
                'Ngày sinh (dd/mm/yyyy)',
                'Ngày vào Đoàn (dd/mm/yyyy)',
                'Nguyên quán',
                'Nơi ở hiện tại',
                'Dân tộc',
                'Tôn giáo',
                'SĐT',
                'Email',
                'Ngày dự bị Đảng (dd/mm/yyyy)',
                'Ngày chính thức Đảng (dd/mm/yyyy)'
            ],
            [
                '2230610001',
                'Nguyễn Phúc An',
                'CĐ23A-THUD',
                'Đoàn viên',
                '17/01/2005',
                '01/09/2023',
                'TP. Hồ Chí Minh',
                'Quận 12, TP.HCM',
                'Kinh',
                'Không',
                '0776642710',
                'example@gmail.com',
                '01/06/2024',
                '01/06/2025'
            ],
            [
                '2230610002',
                'Trần Minh Khoa',
                'CĐ23A-THUD',
                'Thanh niên',
                '12/03/2005',
                '',
                'TP. Hồ Chí Minh',
                'Quận 12, TP.HCM',
                'Kinh',
                '',
                '0988123456',
                'khoa@gmail.com',
                '',
                ''
            ]
        ];

        $xlsx = SimpleXLSXGen::fromArray($data);
        $xlsx->downloadAs("mau_nhap_doanvien.xlsx");
        exit;
    }


    // === DEFAULT ===
    echo json_encode(['error' => 'Bad action']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
