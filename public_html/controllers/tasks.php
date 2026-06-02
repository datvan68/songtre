<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

auth_guard();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);

/* =========================
   HARD FIX PDO "near ?"
   => ép emulate prepares để MySQL KHÔNG thấy dấu '?'
========================= */
try {
    if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); // ✅ FIX 1064 near '?'
    }
} catch (Throwable $e) {
    // ignore
}

function notify_admin_task_done(PDO $pdo, int $taskId, int $doneByUserId, string $taskTitle, string $projText = '')
{
    // ✅ Lấy tên ưu tiên từ members.fullname, fallback users.fullname
    // (JOIN theo members.user_id = users.id => không dùng u.member_id)
    $st = $pdo->prepare("
        SELECT
            COALESCE(
                NULLIF(m.fullname, ''),
                NULLIF(u.fullname, ''),
                NULLIF(u.username, ''),
                CONCAT('User#', u.id)
            ) AS display_name
        FROM users u
        LEFT JOIN members m ON m.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $st->execute([$doneByUserId]);
    $doneByName = (string) ($st->fetchColumn() ?: ("User#$doneByUserId"));

    // ✅ Lấy danh sách admin ids theo role = admin
    $stA = $pdo->prepare("
        SELECT u.id
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.name = 'admin'
    ");
    $stA->execute();
    $adminIds = array_map('intval', $stA->fetchAll(PDO::FETCH_COLUMN));

    if (empty($adminIds))
        return;

    $title = trim((string) $taskTitle);
    $proj = trim((string) $projText);

    $msg = "✅ {$doneByName} đã hoàn thành: " . ($title ?: ("Công việc #$taskId"));
    if ($proj !== '')
        $msg .= " (Dự án: {$proj})";

    $link = "/?p=tasks";

    $ins = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    foreach ($adminIds as $aid) {
        if ($aid > 0) {
            $ins->execute([$aid, $msg, $link]);
        }
    }
}



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

function require_can($code, $act)
{
    if (!function_exists('can') || !can($code, $act)) {
        json_err('FORBIDDEN', 403);
    }
}

function trim_s($v)
{
    return trim((string) $v);
}
function to_int($v, $d = 0)
{
    return is_numeric($v) ? (int) $v : $d;
}

function read_json(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw)
        return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function normalize_dt($v): string
{
    $s = trim((string) $v);
    if ($s === '')
        return '';
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $s))
        $s .= ':00';
    return $s;
}
function is_valid_dt($s): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $s);
}

/* =========================
   DB HELPERS
========================= */
function table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function has_col(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];

    $key = $table . ':' . $col;
    if (isset($cache[$key]))
        return $cache[$key];

    try {
        $st = $pdo->query("DESCRIBE `$table`");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if (($r['Field'] ?? '') === $col) {
                $cache[$key] = true;
                return true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $cache[$key] = false;
    return false;
}

/* =========================
   MULTI ASSIGNEES SUPPORT
========================= */
function has_multi_assignee(PDO $pdo): bool
{
    return table_exists($pdo, 'task_item_assignees');
}


function safe_filename(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
    $name = trim($name, '._-');
    return $name !== '' ? $name : ('file_' . time());
}
function ensure_dir(string $dir): void
{
    if (!is_dir($dir))
        @mkdir($dir, 0777, true);
}
function ensure_assignee_row(PDO $pdo, int $taskId, int $uid): void
{
    if ($taskId <= 0 || $uid <= 0)
        return;
    if (!table_exists($pdo, 'task_item_assignees'))
        return;

    $hasStatus = has_col($pdo, 'task_item_assignees', 'status');
    $hasProg = has_col($pdo, 'task_item_assignees', 'progress');

    if ($hasStatus && $hasProg) {
        $pdo->prepare("
            INSERT IGNORE INTO task_item_assignees (task_id, user_id, status, progress)
            VALUES (?, ?, 'pending', 0)
        ")->execute([$taskId, $uid]);
    } else {
        $pdo->prepare("
            INSERT IGNORE INTO task_item_assignees (task_id, user_id)
            VALUES (?, ?)
        ")->execute([$taskId, $uid]);
    }
}

function recalc_task_aggregate(PDO $pdo, int $taskId): array
{
    if (!table_exists($pdo, 'task_item_assignees')) {
        return ['total' => 0, 'done' => 0, 'progress' => 0, 'status' => 'pending'];
    }

    $hasStatus = has_col($pdo, 'task_item_assignees', 'status');
    if (!$hasStatus) {
        // không có status thì không tính kiểu này được
        return ['total' => 0, 'done' => 0, 'progress' => 0, 'status' => 'pending'];
    }

    $stT = $pdo->prepare("SELECT deadline FROM task_items WHERE id=? LIMIT 1");
    $stT->execute([$taskId]);
    $deadline = (string) ($stT->fetchColumn() ?: '');

    $st = $pdo->prepare("
        SELECT
          COUNT(*) AS total_cnt,
          SUM(status='done') AS done_cnt,
          SUM(status='doing') AS doing_cnt,
          MAX(finished_at) AS last_done_at
        FROM task_item_assignees
        WHERE task_id=?
    ");
    $st->execute([$taskId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int) ($row['total_cnt'] ?? 0);
    $done = (int) ($row['done_cnt'] ?? 0);
    $doing = (int) ($row['doing_cnt'] ?? 0);
    $lastDoneAt = (string) ($row['last_done_at'] ?? '');

    $progress = 0;
    if ($total > 0) {
        $progress = (int) round(($done / $total) * 100);
        $progress = max(0, min(100, $progress));
    }

    // status task theo assignees
    if ($total > 0 && $done >= $total) {
        $newStatus = 'done';
    } elseif ($done > 0 || $doing > 0) {
        $newStatus = 'doing';
    } else {
        $newStatus = 'pending';
    }

    // finished_at = lúc người cuối cùng done
    $finishedAt = null;
    $rtype = null;

    if ($newStatus === 'done') {
        $finishedAt = $lastDoneAt !== '' ? $lastDoneAt : date('Y-m-d H:i:s');
        $rtype = compute_result_type($finishedAt, $deadline);
    }

    // update task_items theo aggregate
    $pdo->prepare("
        UPDATE task_items
        SET
          progress = ?,
          status = ?,
          finished_at = ?,
          result_type = ?
        WHERE id = ?
    ")->execute([
                $progress,
                $newStatus,
                $finishedAt,
                $rtype,
                $taskId
            ]);

    return [
        'total' => $total,
        'done' => $done,
        'progress' => $progress,
        'status' => $newStatus
    ];
}

function compute_result_type($finishedAt, $deadline): string
{
    $f = strtotime((string) $finishedAt);
    $d = strtotime((string) $deadline);
    if (!$f || !$d)
        return '';
    if ($f < $d)
        return 'early';
    if ($f === $d)
        return 'ontime';
    return 'late';
}

function is_admin_tasks(): bool
{
    return can('tasks', 'create') || can('tasks', 'update') || can('tasks', 'delete');
}

/* =========================
   MULTI ASSIGNEE TABLE
   ưu tiên task_item_assignees, fallback task_assignees
========================= */
function assignee_table(PDO $pdo): string
{
    if (table_exists($pdo, 'task_item_assignees'))
        return 'task_item_assignees';
    if (table_exists($pdo, 'task_assignees'))
        return 'task_assignees';
    return '';
}

function normalize_assignee_ids($raw): array
{
    if (!is_array($raw))
        return [];
    $ids = array_values(array_unique(array_map('intval', $raw)));
    $ids = array_filter($ids, fn($x) => $x > 0);
    return array_values($ids);
}

function can_access_task(PDO $pdo, int $taskId, int $me, bool $isAdmin): bool
{
    if ($isAdmin)
        return true;

    $hasSup = has_col($pdo, 'task_items', 'supervisor_id');

    if ($hasSup) {
        $st = $pdo->prepare("SELECT assignee_id, supervisor_id FROM task_items WHERE id=? LIMIT 1");
        $st->execute([$taskId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $primary = (int) ($row['assignee_id'] ?? 0);
        $sup = (int) ($row['supervisor_id'] ?? 0);

        if ($primary === $me)
            return true;
        if ($sup === $me)
            return true;
    } else {
        $st = $pdo->prepare("SELECT assignee_id FROM task_items WHERE id=? LIMIT 1");
        $st->execute([$taskId]);
        $primary = (int) $st->fetchColumn();
        if ($primary === $me)
            return true;
    }

    $tbl = assignee_table($pdo);
    if ($tbl !== '') {
        $st2 = $pdo->prepare("SELECT 1 FROM `$tbl` WHERE task_id=? AND user_id=? LIMIT 1");
        $st2->execute([$taskId, $me]);
        if ($st2->fetchColumn())
            return true;
    }

    return false;
}


/* =========================
   PROJECT HELPERS
========================= */
function semester_now(): string
{
    $m = (int) date('n');
    return ($m >= 8 && $m <= 12) ? 'HK1' : 'HK2';
}
function school_year_label_now(): string
{
    $y = (int) date('Y');
    $m = (int) date('n');
    if ($m >= 8)
        return $y . '-' . ($y + 1);
    return ($y - 1) . '-' . $y;
}

function get_or_create_project_id(PDO $pdo, int $userId, string $projectText, int $schoolYearId = 0, string $semesterCode = ''): int
{
    $projectText = trim($projectText);
    if ($projectText === '')
        return 0;

    // ✅ resolve year_label
    $yearLabel = '';
    if ($schoolYearId > 0 && table_exists($pdo, 'school_years')) {
        $stY = $pdo->prepare("SELECT year_label FROM school_years WHERE id=? LIMIT 1");
        $stY->execute([$schoolYearId]);
        $yearLabel = trim((string) ($stY->fetchColumn() ?: ''));
    }
    if ($yearLabel === '')
        $yearLabel = school_year_label_now();

    // ✅ normalize semester: HK 1 -> HK1
    $semesterCode = strtoupper(preg_replace('/\s+/', '', trim((string) $semesterCode)));
    if ($semesterCode === '')
        $semesterCode = semester_now();

    // ✅ NEW: tìm theo (title + school_year + semester normalized)
    $st = $pdo->prepare("
        SELECT id
        FROM task_projects
        WHERE title = ?
          AND school_year = ?
          AND UPPER(REPLACE(semester,' ','')) = ?
        LIMIT 1
    ");
    $st->execute([$projectText, $yearLabel, $semesterCode]);
    $id = (int) $st->fetchColumn();
    if ($id > 0)
        return $id;

    // tạo project mới cho combo năm/hk này
    $st2 = $pdo->prepare("
        INSERT INTO task_projects
          (school_year, semester, title, description, manager_id, status, created_by)
        VALUES
          (?, ?, ?, NULL, NULL, 'active', ?)
    ");
    $st2->execute([$yearLabel, $semesterCode, $projectText, $userId]);

    return (int) $pdo->lastInsertId();
}



function has_table(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool) $st->fetchColumn();
}

function notify_task_assignees(PDO $pdo, int $taskId, array $assigneeIds, string $taskTitle, string $projectText, int $assignerId)
{
    if (!has_table($pdo, "notifications"))
        return;

    $assigneeIds = array_values(array_unique(array_map('intval', $assigneeIds)));
    if (!$assigneeIds)
        return;

    // Lấy tên người giao việc cho đẹp
    $stName = $pdo->prepare("SELECT COALESCE(fullname, username) FROM users WHERE id=? LIMIT 1");
    $stName->execute([$assignerId]);
    $assignerName = $stName->fetchColumn() ?: ("User#" . $assignerId);

    // link tới trang tasks (Toro sửa theo route của Toro nếu khác)
    $link = "/index.php?p=tasks&task_id={$taskId}";

    $ins = $pdo->prepare("INSERT INTO notifications (message, user_id, link) VALUES (?, ?, ?)");

    foreach ($assigneeIds as $uid) {
        if ($uid <= 0)
            continue;

        $msg = "📝 Bạn được giao công việc: {$taskTitle}"
            . ($projectText ? " (Dự án: {$projectText})" : "")
            . " - bởi {$assignerName}";

        $ins->execute([$msg, $uid, $link]);
    }
}

/* =========================
   MULTI-ASSIGNEE SUPPORT
========================= */


/* =========================
   GUARD VIEW TASKS
========================= */
require_can('tasks', 'view');
$isAdmin = is_admin_tasks();

/* =========================
   ROUTES
========================= */
switch ($action) {

    case 'meta':
        try {
            // ✅ projects
            $projects = $pdo->query("
            SELECT id, title, school_year, semester, status
            FROM task_projects
            ORDER BY created_at DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

            $data = ['projects' => $projects];

            // ✅ school_years (dropdown năm học)
            if (table_exists($pdo, 'school_years')) {
                $schoolYears = $pdo->query("
                SELECT id, year_label, is_active
                FROM school_years
                ORDER BY is_active DESC, year_label DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $schoolYears = [];
            }

            // ✅ semesters (dropdown học kỳ)
            if (table_exists($pdo, 'semesters')) {
                $semesters = $pdo->query("
                SELECT code, label, sort_order, is_active
                FROM semesters
                ORDER BY is_active DESC, sort_order ASC, code ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $semesters = [];
            }

            $data['school_years'] = $schoolYears;
            $data['semesters'] = $semesters;

            if ($isAdmin) {
                // ✅ users: CHỈ ROLE BCH + có quyền tasks/task
                $users = $pdo->query("
                SELECT
                    u.id,
                    u.username,
                    u.fullname AS user_fullname,
                    m.fullname AS member_fullname,
                    r.name AS role_name,
                    COALESCE(NULLIF(m.fullname,''), NULLIF(u.fullname,''), u.username) AS fullname
                FROM users u
                LEFT JOIN members m ON m.user_id = u.id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE
                    (
                        r.name = 'banchaphanh'
                        OR r.name = 'Ban Chấp Hành'
                        OR r.name = 'BCH'
                    )
                    AND
                    (
                        (
                            u.permissions_mode = 'role'
                            AND EXISTS (
                                SELECT 1
                                FROM role_permissions rp
                                JOIN permissions p ON p.id = rp.permission_id
                                WHERE rp.role_id = u.role_id
                                  AND p.code IN ('tasks','task')
                                  AND rp.can_view = 1
                            )
                        )
                        OR
                        (
                            u.permissions_mode = 'custom'
                            AND EXISTS (
                                SELECT 1
                                FROM user_permissions up
                                JOIN permissions p2 ON p2.id = up.permission_id
                                WHERE up.user_id = u.id
                                  AND p2.code IN ('tasks','task')
                                  AND up.can_view = 1
                            )
                        )
                    )
                ORDER BY fullname ASC, u.username ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

                $data['users'] = $users;
            }

            json_ok($data);
        } catch (Throwable $e) {
            json_err("Lỗi load meta: " . $e->getMessage(), 500);
        }
        break;




    case 'list':
        try {
            $input = read_json();
            $src = !empty($input) ? $input : $_GET;

            $page = max(1, to_int($src['page'] ?? 1, 1));
            $pageSize = min(100, max(5, to_int($src['page_size'] ?? 20, 20)));
            $offset = ($page - 1) * $pageSize;

            $projectText = trim_s($src['project_text'] ?? '');
            $assigneeId = to_int($src['assignee_id'] ?? 0, 0);
            $status = trim_s($src['status'] ?? '');
            $q = trim_s($src['q'] ?? '');

            $where = " WHERE 1=1 ";
            $params = [];

            // ✅ check optional columns
            $hasPriority = has_col($pdo, 'task_items', 'priority');
            $hasTags = has_col($pdo, 'task_items', 'tags');
            $hasExtra = has_col($pdo, 'task_items', 'extra_note');
            $hasSupervisor = has_col($pdo, 'task_items', 'supervisor_id');

            // ✅ NEW (Hướng B): task_items.school_year_id + task_items.semester_code
            $hasSY = has_col($pdo, 'task_items', 'school_year_id');
            $hasSem = has_col($pdo, 'task_items', 'semester_code');

            $selSupId = $hasSupervisor ? "t.supervisor_id AS supervisor_id" : "NULL AS supervisor_id";
            $selSupName = $hasSupervisor ? "COALESCE(us.fullname, us.username) AS supervisor_name" : "'' AS supervisor_name";
            $joinSup = $hasSupervisor ? "LEFT JOIN users us ON us.id = t.supervisor_id" : "";

            $selPriority = $hasPriority ? "t.priority" : "NULL AS priority";
            $selTags = $hasTags ? "t.tags" : "NULL AS tags";
            $selExtra = $hasExtra ? "t.extra_note" : "NULL AS extra_note";

            $schoolYearId = to_int($src['school_year_id'] ?? 0, 0);

            // nhận cả semester_code hoặc semester
            $semesterCode = trim_s($src['semester_code'] ?? ($src['semester'] ?? ''));
            $semesterCode = strtoupper(preg_replace('/\s+/', '', $semesterCode)); // HK 1 -> HK1

            $multi = has_multi_assignee($pdo);

            // ✅ Filter quyền xem (user chỉ thấy task liên quan + trụ trì)
            if (!$isAdmin) {
                if ($multi) {
                    if ($hasSupervisor) {
                        $where .= " AND (t.assignee_id = ? OR t.supervisor_id = ? OR EXISTS (
                        SELECT 1 FROM task_item_assignees a
                        WHERE a.task_id = t.id AND a.user_id = ?
                    )) ";
                        $params[] = $userId;
                        $params[] = $userId;
                        $params[] = $userId;
                    } else {
                        $where .= " AND (t.assignee_id = ? OR EXISTS (
                        SELECT 1 FROM task_item_assignees a
                        WHERE a.task_id = t.id AND a.user_id = ?
                    )) ";
                        $params[] = $userId;
                        $params[] = $userId;
                    }
                } else {
                    if ($hasSupervisor) {
                        $where .= " AND (t.assignee_id = ? OR t.supervisor_id = ?) ";
                        $params[] = $userId;
                        $params[] = $userId;
                    } else {
                        $where .= " AND t.assignee_id = ? ";
                        $params[] = $userId;
                    }
                }
            } else {
                if ($assigneeId > 0) {
                    $where .= " AND t.assignee_id = ? ";
                    $params[] = $assigneeId;
                }
            }

            /* ✅ FILTER NĂM HỌC / HỌC KỲ
               - ƯU TIÊN HƯỚNG B: theo task_items.school_year_id + task_items.semester_code
               - fallback về task_projects nếu DB chưa có cột
            */
            if ($schoolYearId > 0) {
                if ($hasSY) {
                    $where .= " AND t.school_year_id = ? ";
                    $params[] = $schoolYearId;
                } elseif (table_exists($pdo, 'school_years')) {
                    // fallback cũ: map id -> year_label để so với p.school_year
                    $stY = $pdo->prepare("SELECT year_label FROM school_years WHERE id=? LIMIT 1");
                    $stY->execute([$schoolYearId]);
                    $yl = trim((string) ($stY->fetchColumn() ?: ''));
                    if ($yl !== '') {
                        $where .= " AND p.school_year = ? ";
                        $params[] = $yl;
                    }
                }
            }

            if ($semesterCode !== '') {
                if ($hasSem) {
                    $where .= " AND UPPER(REPLACE(t.semester_code,' ','')) = ? ";
                    $params[] = $semesterCode;
                } else {
                    // fallback cũ theo project
                    $where .= " AND UPPER(REPLACE(p.semester,' ','')) = ? ";
                    $params[] = $semesterCode;
                }
            }

            // ✅ lọc theo dự án (theo title project)
            if ($projectText !== '') {
                $like = '%' . $projectText . '%';

                $where .= " AND (p.title LIKE ? OR t.title LIKE ? OR t.description LIKE ? ";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;

                if ($hasTags) {
                    $where .= " OR t.tags LIKE ? ";
                    $params[] = $like;
                }

                if ($hasExtra) {
                    $where .= " OR t.extra_note LIKE ? ";
                    $params[] = $like;
                }

                $where .= " ) ";
            }

            if ($status === 'overdue') {
                $where .= " AND t.status <> 'done' AND t.deadline IS NOT NULL AND t.deadline < NOW() ";
            } elseif (in_array($status, ['pending', 'doing', 'done'], true)) {
                $where .= " AND t.status = ? ";
                $params[] = $status;
            }

            // ✅ search text
            if ($q !== '') {
                $like = "%$q%";
                $where .= " AND (t.title LIKE ? OR t.description LIKE ? OR p.title LIKE ? ";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;

                if ($hasTags) {
                    $where .= " OR t.tags LIKE ? ";
                    $params[] = $like;
                }
                if ($hasExtra) {
                    $where .= " OR t.extra_note LIKE ? ";
                    $params[] = $like;
                }

                $where .= " ) ";
            }

            // ✅ COUNT
            $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM task_items t
            JOIN task_projects p ON p.id = t.project_id
            $where
        ");
            $st->execute($params);
            $total = (int) $st->fetchColumn();
            $totalPages = (int) ceil($total / $pageSize);

            // ✅ assignees string
            $selAssignees = $multi
                ? "(SELECT GROUP_CONCAT(COALESCE(u.fullname, u.username) ORDER BY COALESCE(u.fullname,u.username) SEPARATOR ', ')
                FROM task_item_assignees a
                JOIN users u ON u.id = a.user_id
                WHERE a.task_id = t.id
              ) AS assignees"
                : "NULL AS assignees";

            // ✅ school_year label join (optional)
            $joinSY = ($hasSY && table_exists($pdo, 'school_years'))
                ? "LEFT JOIN school_years sy ON sy.id = t.school_year_id"
                : "";

            $selSYId = $hasSY ? "t.school_year_id" : "NULL AS school_year_id";
            // ✅ school_year label join (optional)
            $joinSY = ($hasSY && table_exists($pdo, 'school_years'))
                ? "LEFT JOIN school_years sy ON sy.id = t.school_year_id"
                : "";

            $selSYId = $hasSY ? "t.school_year_id AS school_year_id" : "NULL AS school_year_id";

            // school_year_label luôn có (fallback về project)
            $selSYLabel = ($hasSY && table_exists($pdo, 'school_years'))
                ? "COALESCE(NULLIF(sy.year_label,''), NULLIF(p.school_year,'')) AS school_year_label"
                : "p.school_year AS school_year_label";

            // semester_code luôn có (fallback về project), normalize HK 1 -> HK1
            $selSemCode = $hasSem
                ? "COALESCE(
          NULLIF(UPPER(REPLACE(t.semester_code,' ','')), ''),
          NULLIF(UPPER(REPLACE(p.semester,' ','')), '')
      ) AS semester_code"
                : "UPPER(REPLACE(p.semester,' ','')) AS semester_code";


            // ✅ SELECT
            $selectParts = [
                "t.id",
                "t.project_id",
                $selSYId,
                $selSemCode,
                $selSYLabel,

                "t.title",
                "t.description",
                $selPriority,
                "t.assignee_id",
                "t.assigned_by",
                "t.start_date",

                $selSupId,
                $selSupName,

                "t.deadline",
                "t.finished_at",
                "t.status",
                "t.result_type",
                "t.progress",
                $selTags,
                $selExtra,
                $selAssignees,
                "p.title AS project_title",
                "p.school_year AS project_school_year",
                "p.semester AS project_semester",
                "COALESCE(u1.fullname, u1.username) AS assignee_name",
                "COALESCE(u2.fullname, u2.username) AS assigner_name"
            ];

            if ($multi) {
                $selectParts[] = "me.status AS my_status";
                $selectParts[] = "me.progress AS my_progress";
                $selectParts[] = "me.finished_at AS my_finished_at";
                $selectParts[] = "me.result_type AS my_result_type";
                $selectParts[] = "(SELECT COUNT(*) FROM task_item_assignees a WHERE a.task_id = t.id) AS assignee_total";
                $selectParts[] = "(SELECT COUNT(*) FROM task_item_assignees a WHERE a.task_id = t.id AND a.status='done') AS assignee_done";
            } else {
                $selectParts[] = "NULL AS my_status";
                $selectParts[] = "NULL AS my_progress";
                $selectParts[] = "NULL AS my_finished_at";
                $selectParts[] = "NULL AS my_result_type";
                $selectParts[] = "0 AS assignee_total";
                $selectParts[] = "0 AS assignee_done";
            }

            $sql = "
            SELECT " . implode(",\n            ", $selectParts) . "
            FROM task_items t
            JOIN task_projects p ON p.id = t.project_id
            JOIN users u1 ON u1.id = t.assignee_id
            LEFT JOIN users u2 ON u2.id = t.assigned_by
            $joinSup
            $joinSY
            " . ($multi ? "LEFT JOIN task_item_assignees me ON me.task_id = t.id AND me.user_id = " . (int) $userId : "") . "
            $where
            ORDER BY
                CASE WHEN t.status='done' THEN 2 WHEN t.status='doing' THEN 1 ELSE 0 END ASC,
                t.deadline ASC,
                t.id DESC
            LIMIT $offset, $pageSize
        ";

            $st2 = $pdo->prepare($sql);
            $st2->execute($params);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);

            // ===== STATS (cùng filter) =====
            $statsSql = "
            SELECT
                COUNT(*) AS total,
                SUM(t.status='pending') AS pending,
                SUM(t.status='doing') AS doing,
                SUM(t.status='done') AS done,
                SUM(t.status!='done' AND t.deadline IS NOT NULL AND t.deadline < NOW()) AS overdue
            FROM task_items t
            JOIN task_projects p ON p.id = t.project_id
            $where
        ";
            $stStats = $pdo->prepare($statsSql);
            $stStats->execute($params);
            $stats = $stStats->fetch(PDO::FETCH_ASSOC) ?: [];
            // ===== END STATS =====

            json_ok([
                'rows' => $rows,
                'stats' => $stats,
                'paging' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => max(1, $totalPages),
                ]
            ]);

        } catch (Throwable $e) {
            json_err("Lỗi load danh sách công việc: " . $e->getMessage(), 500);
        }
        break;



    case 'detail':
        try {
            $input = read_json();
            $id = to_int($_GET['id'] ?? ($input['id'] ?? 0), 0);
            if ($id <= 0)
                json_err('Thiếu id');

            if (!can_access_task($pdo, $id, $userId, $isAdmin)) {
                json_err('FORBIDDEN', 403);
            }

            $hasSupervisor = has_col($pdo, 'task_items', 'supervisor_id');

            // ✅ NEW (Hướng B)
            $hasSY = has_col($pdo, 'task_items', 'school_year_id');
            $hasSem = has_col($pdo, 'task_items', 'semester_code');

            $selSup = $hasSupervisor
                ? "t.supervisor_id, COALESCE(us.fullname, us.username) AS supervisor_name,"
                : "NULL AS supervisor_id, '' AS supervisor_name,";

            $joinSup = $hasSupervisor
                ? "LEFT JOIN users us ON us.id = t.supervisor_id"
                : "";

            $selSY = $hasSY ? "t.school_year_id AS school_year_id," : "NULL AS school_year_id,";

            $selSem = $hasSem
                ? "COALESCE(
        NULLIF(UPPER(REPLACE(t.semester_code,' ','')), ''),
        NULLIF(UPPER(REPLACE(p.semester,' ','')), '')
     ) AS semester_code,"
                : "UPPER(REPLACE(p.semester,' ','')) AS semester_code,";

            $joinSY = ($hasSY && table_exists($pdo, 'school_years'))
                ? "LEFT JOIN school_years sy ON sy.id = t.school_year_id"
                : "";

            $selSYLabel = ($hasSY && table_exists($pdo, 'school_years'))
                ? "COALESCE(NULLIF(sy.year_label,''), NULLIF(p.school_year,'')) AS school_year_label,"
                : "p.school_year AS school_year_label,";


            $st = $pdo->prepare("
            SELECT
                t.id, t.project_id, t.project_text, t.title, t.description,
                $selSY
                $selSem
                $selSYLabel
                t.priority, t.tags, t.extra_note,
                t.assignee_id, t.assigned_by,
                $selSup
                t.start_date, t.deadline, t.finished_at,
                t.status, t.result_type, t.progress,
                p.title AS project_title,
                p.school_year AS project_school_year,
                p.semester AS project_semester,

                COALESCE(u1.fullname, u1.username) AS assignee_name,
                COALESCE(u2.fullname, u2.username) AS assigner_name
            FROM task_items t
            JOIN task_projects p ON p.id=t.project_id
            JOIN users u1 ON u1.id=t.assignee_id
            LEFT JOIN users u2 ON u2.id=t.assigned_by
            $joinSup
            $joinSY
            WHERE t.id=?
            LIMIT 1
        ");

            $st->execute([$id]);
            $task = $st->fetch(PDO::FETCH_ASSOC);
            if (!$task)
                json_err('Không tìm thấy công việc');

            $files = [];
            if (table_exists($pdo, 'task_files')) {
                $stf = $pdo->prepare("
                SELECT id, attachment_type_id, file_path, created_at
                FROM task_files
                WHERE task_id=?
                ORDER BY id DESC
            ");
                $stf->execute([$id]);
                $files = $stf->fetchAll(PDO::FETCH_ASSOC);
            }

            $assignees = [];
            $tbl = assignee_table($pdo);
            if ($tbl !== '') {
                if ($tbl === 'task_item_assignees') {
                    $sta = $pdo->prepare("
                    SELECT
                      a.user_id,
                      COALESCE(u.fullname, u.username) AS fullname,
                      a.status, a.progress, a.finished_at, a.result_type
                    FROM task_item_assignees a
                    JOIN users u ON u.id = a.user_id
                    WHERE a.task_id=?
                    ORDER BY fullname ASC
                ");
                    $sta->execute([$id]);
                    $assignees = $sta->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $sta = $pdo->prepare("
                    SELECT
                      a.user_id,
                      COALESCE(u.fullname, u.username) AS fullname
                    FROM task_assignees a
                    JOIN users u ON u.id = a.user_id
                    WHERE a.task_id=?
                    ORDER BY fullname ASC
                ");
                    $sta->execute([$id]);
                    $assignees = $sta->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            if (!$assignees) {
                $assignees = [
                    [
                        'user_id' => (int) $task['assignee_id'],
                        'fullname' => (string) $task['assignee_name'],
                        'status' => (string) $task['status'],
                        'progress' => (int) $task['progress'],
                        'finished_at' => $task['finished_at'],
                        'result_type' => $task['result_type'],
                    ]
                ];
            }

            json_ok(['task' => $task, 'files' => $files, 'assignees' => $assignees]);

        } catch (Throwable $e) {
            json_err("Lỗi load chi tiết: " . $e->getMessage(), 500);
        }
        break;


    case 'create':
        try {
            require_can('tasks', 'create');

            $input = read_json();
            if (!$input)
                json_err('Dữ liệu không hợp lệ');

            $title = trim_s($input['title'] ?? '');
            $description = trim_s($input['description'] ?? '');

            $projectId = to_int($input['project_id'] ?? 0, 0);
            $projectText = trim_s($input['project_text'] ?? '');

            $deadline = normalize_dt($input['deadline'] ?? '');
            $status = trim_s($input['status'] ?? 'pending');

            $hasSupervisor = has_col($pdo, 'task_items', 'supervisor_id');

            // ✅ NEW (Hướng B)
            $hasSY = has_col($pdo, 'task_items', 'school_year_id');
            $hasSem = has_col($pdo, 'task_items', 'semester_code');

            $schoolYearId = to_int($input['school_year_id'] ?? 0, 0);

            // nhận cả semester_code hoặc semester
            $semesterCode = trim_s($input['semester_code'] ?? ($input['semester'] ?? ''));
            $semesterCode = strtoupper(preg_replace('/\s+/', '', $semesterCode)); // HK 1 -> HK1

            // ✅ assignee
            $assigneeId = to_int($input['assignee_id'] ?? 0, 0);
            $assigneeIds = normalize_assignee_ids($input['assignee_ids'] ?? []);
            if (!$assigneeIds && $assigneeId > 0)
                $assigneeIds = [$assigneeId];

            // ✅ supervisor (người trụ trì)
            $supervisorId = to_int($input['supervisor_id'] ?? 0, 0);
            if ($hasSupervisor) {
                if ($supervisorId <= 0)
                    json_err('Thiếu người trụ trì');
            } else {
                if ($supervisorId > 0)
                    json_err('DB chưa có cột supervisor_id. Hãy chạy migration SQL.', 500);
            }

            if ($title === '')
                json_err('Thiếu tiêu đề');

            if ($projectId <= 0) {
                $projectId = get_or_create_project_id($pdo, $userId, $projectText, $schoolYearId, $semesterCode);
            }
            if ($projectId <= 0)
                json_err('Thiếu dự án');

            if (!is_valid_dt($deadline))
                json_err('Deadline không hợp lệ');
            if (!in_array($status, ['pending', 'doing', 'done'], true))
                $status = 'pending';

            if ($assigneeId <= 0 && !empty($assigneeIds))
                $assigneeId = (int) $assigneeIds[0];
            if ($assigneeId <= 0)
                json_err('Thiếu người thực hiện');

            if (!in_array($assigneeId, $assigneeIds, true)) {
                array_unshift($assigneeIds, $assigneeId);
                $assigneeIds = normalize_assignee_ids($assigneeIds);
            }

            $priority = trim_s($input['priority'] ?? 'medium');
            if (!in_array($priority, ['low', 'medium', 'high'], true))
                $priority = 'medium';

            $tags = trim_s($input['tags'] ?? '');
            $extra = trim_s($input['extra_note'] ?? '');
            $progress = ($status === 'done') ? 100 : 0;

            $pdo->beginTransaction();

            // ✅ INSERT dynamic theo có/không supervisor_id + có/không school_year_id/semester_code
            $colsArr = [
                "project_id",
                "project_text",
                "title",
                "description",
                "priority",
                "assignee_id",
                "assigned_by",
            ];

            $placeArr = array_fill(0, count($colsArr), "?");
            $exec = [
                $projectId,
                $projectText,
                $title,
                $description,
                $priority,
                $assigneeId,
                $userId
            ];

            if ($hasSY) {
                $colsArr[] = "school_year_id";
                $placeArr[] = "?";
                $exec[] = ($schoolYearId > 0 ? $schoolYearId : null);
            }
            if ($hasSem) {
                $colsArr[] = "semester_code";
                $placeArr[] = "?";
                $exec[] = ($semesterCode !== '' ? $semesterCode : null);
            }

            if ($hasSupervisor) {
                $colsArr[] = "supervisor_id";
                $placeArr[] = "?";
                $exec[] = $supervisorId;
            }

            $colsArr[] = "deadline";
            $colsArr[] = "tags";
            $colsArr[] = "extra_note";
            $colsArr[] = "status";
            $colsArr[] = "progress";

            $placeArr[] = "?";
            $placeArr[] = "?";
            $placeArr[] = "?";
            $placeArr[] = "?";
            $placeArr[] = "?";

            $exec[] = $deadline;
            $exec[] = $tags;
            $exec[] = $extra;
            $exec[] = $status;
            $exec[] = $progress;

            $cols = implode(", ", $colsArr);
            $place = implode(", ", $placeArr);

            $st = $pdo->prepare("INSERT INTO task_items ($cols) VALUES ($place)");
            $st->execute($exec);

            $taskId = (int) $pdo->lastInsertId();

            notify_task_assignees(
                $pdo,
                (int) $taskId,
                $assigneeIds,
                (string) $title,
                (string) $projectText,
                (int) $userId
            );

            // sync multi assignees
            $tbl = assignee_table($pdo);
            if ($tbl !== '' && !empty($assigneeIds)) {
                if ($tbl === 'task_item_assignees') {
                    foreach ($assigneeIds as $uid) {
                        ensure_assignee_row($pdo, $taskId, (int) $uid);
                    }
                } else {
                    $ins = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)");
                    foreach ($assigneeIds as $uid) {
                        $ins->execute([$taskId, (int) $uid]);
                    }
                }
            }

            if ($status === 'done') {
                $finishedAt = date('Y-m-d H:i:s');
                $rtype = compute_result_type($finishedAt, $deadline);
                $pdo->prepare("
                UPDATE task_items
                SET finished_at=?, result_type=?, progress=100
                WHERE id=?
            ")->execute([$finishedAt, $rtype, $taskId]);
            }

            log_activity('create', 'tasks', 'task_items', $taskId, "Tạo công việc: $title");

            $pdo->commit();
            json_ok(['id' => $taskId]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            json_err('Lỗi tạo công việc: ' . $e->getMessage(), 500);
        }
        break;



    case 'update':
        try {
            $input = read_json();
            if (!$input)
                json_err('Dữ liệu không hợp lệ');

            $id = to_int($input['id'] ?? 0, 0);
            if ($id <= 0)
                json_err('Thiếu id');

            if (!can_access_task($pdo, $id, $userId, $isAdmin)) {
                json_err('FORBIDDEN', 403);
            }

            $hasSupervisor = has_col($pdo, 'task_items', 'supervisor_id');

            // ✅ NEW (Hướng B)
            $hasSY = has_col($pdo, 'task_items', 'school_year_id');
            $hasSem = has_col($pdo, 'task_items', 'semester_code');

            // lấy task hiện tại
            $curSql = "SELECT status, deadline, assignee_id, title, project_text";
            if ($hasSupervisor)
                $curSql .= ", supervisor_id";
            if ($hasSY)
                $curSql .= ", school_year_id";
            if ($hasSem)
                $curSql .= ", semester_code";
            $curSql .= " FROM task_items WHERE id=? LIMIT 1";

            $st = $pdo->prepare($curSql);
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur)
                json_err('Không tìm thấy công việc');

            $oldStatus = (string) ($cur['status'] ?? 'pending');

            // ✅ USER update theo từng người (multi-assignees)
            $tbl = assignee_table($pdo);
            $useMulti = ($tbl === 'task_item_assignees' && has_col($pdo, 'task_item_assignees', 'status'));

            if (!$isAdmin && $useMulti) {

                $pdo->beginTransaction();

                ensure_assignee_row($pdo, (int) $id, (int) $userId);

                $myStatusRaw = $input['my_status'] ?? ($input['status'] ?? null);
                $myStatus = $myStatusRaw !== null ? trim_s($myStatusRaw) : 'doing';
                if (!in_array($myStatus, ['pending', 'doing', 'done'], true))
                    $myStatus = 'doing';

                $myProgRaw = $input['my_progress'] ?? ($input['progress'] ?? null);
                $myProg = ($myProgRaw !== null) ? max(0, min(100, (int) $myProgRaw)) : null;

                if ($myStatus === 'pending')
                    $myProg = 0;
                if ($myStatus === 'done')
                    $myProg = 100;
                if ($myProg === null)
                    $myProg = ($myStatus === 'done' ? 100 : 0);

                $finishedAt = null;
                $rtype = null;

                if ($myStatus === 'done') {
                    $finishedAt = date('Y-m-d H:i:s');
                    $rtype = compute_result_type($finishedAt, (string) ($cur['deadline'] ?? ''));
                }

                $pdo->prepare("
                UPDATE task_item_assignees
                SET status=?, progress=?, finished_at=?, result_type=?
                WHERE task_id=? AND user_id=?
            ")->execute([
                            $myStatus,
                            (int) $myProg,
                            $finishedAt,
                            $rtype,
                            (int) $id,
                            (int) $userId
                        ]);

                $agg = recalc_task_aggregate($pdo, (int) $id);

                if ($oldStatus !== 'done' && ($agg['status'] ?? '') === 'done') {
                    notify_admin_task_done(
                        $pdo,
                        (int) $id,
                        (int) $userId,
                        (string) ($cur['title'] ?? ''),
                        (string) ($cur['project_text'] ?? '')
                    );
                }

                log_activity('update', 'tasks', 'task_items', (int) $id, "User cập nhật tiến độ task #$id");

                $pdo->commit();

                json_ok([
                    'updated' => 1,
                    'aggregate' => $agg
                ]);
            }

            // === OLD IDS: lấy trước khi sync assignees ===
            $oldIds = [];
            if ($tbl !== '') {
                $stOld = $pdo->prepare("SELECT user_id FROM `$tbl` WHERE task_id=?");
                $stOld->execute([$id]);
                $oldIds = array_map('intval', $stOld->fetchAll(PDO::FETCH_COLUMN));
                $oldIds = array_values(array_filter($oldIds, fn($x) => $x > 0));
            } else {
                $oldIds = [(int) $cur['assignee_id']];
            }

            $newAssigneeIds = [];
            $hasAssigneeIdsPayload = isset($input['assignee_ids']);
            if ($hasAssigneeIdsPayload) {
                $newAssigneeIds = normalize_assignee_ids($input['assignee_ids']);
            }

            $primaryNew = 0;
            if (isset($input['assignee_id'])) {
                $primaryNew = to_int($input['assignee_id'], 0);
            } elseif (!empty($newAssigneeIds)) {
                $primaryNew = (int) $newAssigneeIds[0];
            } else {
                $primaryNew = (int) $cur['assignee_id'];
            }

            if ($hasAssigneeIdsPayload) {
                if ($primaryNew > 0 && !in_array($primaryNew, $newAssigneeIds, true)) {
                    array_unshift($newAssigneeIds, $primaryNew);
                    $newAssigneeIds = normalize_assignee_ids($newAssigneeIds);
                }
            }

            $taskTitle = isset($input['title']) ? trim_s($input['title']) : (string) ($cur['title'] ?? '');
            $projText = isset($input['project_text']) ? trim_s($input['project_text']) : (string) ($cur['project_text'] ?? '');

            $pdo->beginTransaction();

            $set = [];
            $params = [];

            if ($isAdmin) {

                if (isset($input['title'])) {
                    $set[] = "title=?";
                    $params[] = trim_s($input['title']);
                }

                if (isset($input['description'])) {
                    $set[] = "description=?";
                    $params[] = trim_s($input['description']);
                }

                if ($primaryNew > 0) {
                    $set[] = "assignee_id=?";
                    $params[] = $primaryNew;
                }

                if (isset($input['project_text'])) {
                    $set[] = "project_text=?";
                    $params[] = trim_s($input['project_text']);
                }

                if (isset($input['deadline'])) {
                    $dl = normalize_dt($input['deadline']);
                    if (!is_valid_dt($dl))
                        json_err('Deadline không hợp lệ');
                    $set[] = "deadline=?";
                    $params[] = $dl;
                }

                if (isset($input['priority'])) {
                    $p = trim_s($input['priority']);
                    if (!in_array($p, ['low', 'medium', 'high'], true))
                        $p = 'medium';
                    $set[] = "priority=?";
                    $params[] = $p;
                }

                if (isset($input['tags'])) {
                    $set[] = "tags=?";
                    $params[] = trim_s($input['tags']);
                }

                if (isset($input['extra_note'])) {
                    $set[] = "extra_note=?";
                    $params[] = trim_s($input['extra_note']);
                }

                // ✅ NEW (Hướng B): admin sửa school_year_id / semester_code
                if ($hasSY && isset($input['school_year_id'])) {
                    $syid = to_int($input['school_year_id'], 0);
                    $set[] = "school_year_id=?";
                    $params[] = ($syid > 0 ? $syid : null);
                }
                if ($hasSem && (isset($input['semester_code']) || isset($input['semester']))) {
                    $sem = trim_s($input['semester_code'] ?? $input['semester']);
                    $sem = strtoupper(preg_replace('/\s+/', '', $sem));
                    $set[] = "semester_code=?";
                    $params[] = ($sem !== '' ? $sem : null);
                }

                // ✅ supervisor_id chỉ cho admin sửa
                if ($hasSupervisor && isset($input['supervisor_id'])) {
                    $sup = to_int($input['supervisor_id'], 0);
                    if ($sup <= 0)
                        json_err('Thiếu người trụ trì');
                    $set[] = "supervisor_id=?";
                    $params[] = $sup;
                }

                // === SYNC multi-assignees (chỉ khi payload có assignee_ids) ===
                if ($hasAssigneeIdsPayload && $tbl !== '') {

                    $pdo->prepare("DELETE FROM `$tbl` WHERE task_id=?")->execute([$id]);

                    if (!empty($newAssigneeIds)) {
                        if ($tbl === 'task_item_assignees') {
                            foreach ($newAssigneeIds as $uid) {
                                $uid = (int) $uid;
                                if ($uid > 0)
                                    ensure_assignee_row($pdo, (int) $id, $uid);
                            }
                        } else {
                            $ins = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)");
                            foreach ($newAssigneeIds as $uid) {
                                $uid = (int) $uid;
                                if ($uid > 0)
                                    $ins->execute([$id, $uid]);
                            }
                        }
                    }
                }
            }

            $status = null;
            if (isset($input['status']) || isset($input['my_status'])) {
                $s = trim_s($input['status'] ?? $input['my_status']);
                if (!in_array($s, ['pending', 'doing', 'done'], true))
                    $s = 'pending';
                $status = $s;
                $set[] = "status=?";
                $params[] = $s;
            }

            if (isset($input['progress']) || isset($input['my_progress'])) {
                $progVal = $input['progress'] ?? $input['my_progress'];
                $prog = max(0, min(100, (int) $progVal));
                $set[] = "progress=?";
                $params[] = $prog;
            }

            if ($set) {
                $params[] = $id;
                $pdo->prepare("UPDATE task_items SET " . implode(',', $set) . " WHERE id=?")->execute($params);
            }

            $newDeadline = isset($input['deadline']) ? normalize_dt($input['deadline']) : (string) $cur['deadline'];
            $newStatus = $status ?? (string) $cur['status'];

            if ($newStatus === 'done') {
                $finishedAt = date('Y-m-d H:i:s');
                $rtype = compute_result_type($finishedAt, $newDeadline);
                $pdo->prepare("
                UPDATE task_items
                SET finished_at=?, result_type=?, progress=100
                WHERE id=?
            ")->execute([$finishedAt, $rtype, $id]);
            } else {
                if ((string) $cur['status'] === 'done') {
                    $pdo->prepare("
                    UPDATE task_items
                    SET finished_at=NULL, result_type=NULL
                    WHERE id=?
                ")->execute([$id]);
                }
            }

            // ✅ NOTIFY: chỉ notify khi admin đổi assignees thực sự
            if ($isAdmin) {
                $newIdsForDiff = [];

                if ($tbl !== '' && $hasAssigneeIdsPayload) {
                    $newIdsForDiff = $newAssigneeIds;
                } elseif ($tbl === '') {
                    $newIdsForDiff = $primaryNew > 0 ? [$primaryNew] : [];
                }

                if (!empty($newIdsForDiff)) {
                    $added = array_values(array_diff($newIdsForDiff, $oldIds));
                    if (!empty($added)) {
                        notify_task_assignees(
                            $pdo,
                            (int) $id,
                            $added,
                            (string) $taskTitle,
                            (string) $projText,
                            (int) $userId
                        );
                    }
                }
            }

            // ✅ USER hoàn thành -> báo admin (1 lần)
            if (!$isAdmin && $oldStatus !== 'done' && $newStatus === 'done') {
                notify_admin_task_done(
                    $pdo,
                    (int) $id,
                    (int) $userId,
                    (string) $taskTitle,
                    (string) $projText
                );
            }

            log_activity('update', 'tasks', 'task_items', $id, "Cập nhật công việc #$id");

            if ($tbl === 'task_item_assignees') {
                recalc_task_aggregate($pdo, (int) $id);
            }

            $pdo->commit();
            json_ok(['updated' => 1]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            json_err('Lỗi cập nhật công việc: ' . $e->getMessage(), 500);
        }
        break;






    case 'delete':
        try {
            require_can('tasks', 'delete');

            $input = read_json();
            if (!$input)
                json_err('Dữ liệu không hợp lệ');

            $id = to_int($input['id'] ?? 0, 0);
            if ($id <= 0)
                json_err('Thiếu id');

            $pdo->beginTransaction();

            if (table_exists($pdo, 'task_files')) {
                $st = $pdo->prepare("SELECT id, file_path FROM task_files WHERE task_id=?");
                $st->execute([$id]);
                $files = $st->fetchAll(PDO::FETCH_ASSOC);

                $pdo->prepare("DELETE FROM task_files WHERE task_id=?")->execute([$id]);

                foreach ($files as $f) {
                    $path = __DIR__ . '/../' . ltrim((string) $f['file_path'], '/');
                    if (is_file($path))
                        @unlink($path);
                }
            }

            $st2 = $pdo->prepare("DELETE FROM task_items WHERE id=?");
            $st2->execute([$id]);
            if ($st2->rowCount() === 0) {
                $pdo->rollBack();
                json_err('Không tìm thấy công việc');
            }

            $tbl = assignee_table($pdo);
            if ($tbl !== '') {
                $pdo->prepare("DELETE FROM `$tbl` WHERE task_id=?")->execute([$id]);
            }

            log_activity('delete', 'tasks', 'task_items', $id, "Xóa công việc #$id");

            $pdo->commit();
            json_ok();
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            json_err('Lỗi xóa công việc: ' . $e->getMessage(), 500);
        }
        break;

    case 'files_list':
        try {
            $taskId = to_int($_GET['task_id'] ?? 0, 0);
            if ($taskId <= 0)
                json_err('Thiếu task_id');

            if (!can_access_task($pdo, $taskId, $userId, $isAdmin)) {
                json_err('FORBIDDEN', 403);
            }

            if (!table_exists($pdo, 'task_files'))
                json_ok([]);

            $st = $pdo->prepare("
        SELECT id, attachment_type_id, file_path, created_at
        FROM task_files
        WHERE task_id=?
        ORDER BY id DESC
      ");
            $st->execute([$taskId]);
            json_ok($st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            json_err('Lỗi load files: ' . $e->getMessage(), 500);
        }
        break;

    case 'file_upload':
        try {
            require_can('tasks', 'update');

            $taskId = to_int($_POST['task_id'] ?? 0, 0);
            if ($taskId <= 0)
                json_err('Thiếu task_id');

            if (!can_access_task($pdo, $taskId, $userId, $isAdmin)) {
                json_err('FORBIDDEN', 403);
            }

            if (!table_exists($pdo, 'task_files'))
                json_err('Thiếu bảng task_files', 500);

            if (!isset($_FILES['file']))
                json_err('Thiếu file');
            $f = $_FILES['file'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
                json_err('Upload thất bại');

            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xlsx'];
            if (!in_array($ext, $allowed, true))
                json_err('File không hợp lệ');

            $uploadDir = __DIR__ . '/../uploads/tasks/' . $taskId;
            ensure_dir($uploadDir);

            $base = safe_filename(pathinfo($f['name'], PATHINFO_FILENAME));
            $newName = $base . '_' . date('Ymd_His') . '.' . $ext;
            $absPath = $uploadDir . '/' . $newName;
            if (!move_uploaded_file($f['tmp_name'], $absPath))
                json_err('Không thể lưu file');

            $relPath = 'uploads/tasks/' . $taskId . '/' . $newName;

            // ✅ schema task_files attachment_type_id NOT NULL
            $attachmentTypeId = to_int($_POST['attachment_type_id'] ?? 0, 0);
            if ($attachmentTypeId <= 0)
                $attachmentTypeId = 1;

            $pdo->prepare("
        INSERT INTO task_files (task_id, attachment_type_id, file_path, created_at)
        VALUES (?, ?, ?, NOW())
      ")->execute([$taskId, $attachmentTypeId, $relPath]);

            log_activity('create', 'tasks', 'task_files', (int) $pdo->lastInsertId(), "Upload file task #$taskId");

            json_ok(['file_path' => $relPath]);
        } catch (Throwable $e) {
            json_err('Lỗi upload file: ' . $e->getMessage(), 500);
        }
        break;

    case 'file_delete':
        try {
            require_can('tasks', 'update');

            $input = read_json();
            if (!$input)
                json_err('Dữ liệu không hợp lệ');

            $fileId = to_int($input['id'] ?? 0, 0);
            $taskId = to_int($input['task_id'] ?? 0, 0);
            if ($fileId <= 0 || $taskId <= 0)
                json_err('Thiếu dữ liệu');

            if (!can_access_task($pdo, $taskId, $userId, $isAdmin)) {
                json_err('FORBIDDEN', 403);
            }

            $st = $pdo->prepare("SELECT file_path FROM task_files WHERE id=? AND task_id=? LIMIT 1");
            $st->execute([$fileId, $taskId]);
            $path = (string) $st->fetchColumn();
            if ($path === '')
                json_err('Không tìm thấy file');

            $pdo->prepare("DELETE FROM task_files WHERE id=? AND task_id=?")->execute([$fileId, $taskId]);

            $abs = __DIR__ . '/../' . ltrim($path, '/');
            if (is_file($abs))
                @unlink($abs);

            log_activity('delete', 'tasks', 'task_files', $fileId, "Xóa file task #$taskId");
            json_ok();
        } catch (Throwable $e) {
            json_err('Lỗi xóa file: ' . $e->getMessage(), 500);
        }
        break;

    default:
        json_err('Invalid action');
}
