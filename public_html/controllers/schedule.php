<?php
// controllers/schedule.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

/* ================= JSON HELPERS ================= */
function json_ok($data = null)
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_error($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function forbid()
{
    json_error('FORBIDDEN', 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

/* ============================================================
   ACTION SWITCH
============================================================ */
switch ($action) {

    /* =====================================================
     * LIST – DÙNG CHUNG CHO CALENDAR + LIST VIEW
     * ===================================================== */
    case 'list':
        if (!can('schedule', 'view')) {
            forbid();
        }

        try {
            // ❗ CHỈ LẤY ĐÃ DUYỆT
            $stmt = $pdo->prepare("
  SELECT
    s.*,
    u.fullname AS creator_name
  FROM schedule s
  LEFT JOIN users u ON u.id = s.created_by
  WHERE s.status = 'approved'
  ORDER BY s.start_date ASC
");

            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $events = [];
            foreach ($rows as $r) {
                $events[] = [
                    'id' => (int) $r['id'],
                    'title' => $r['title'],
                    'start' => $r['start_date'],
                    'end' => $r['end_date'],
                    'extendedProps' => [
                        'department' => $r['department'],
                        'description' => $r['description'],
                        'location' => $r['location'],
                        'participants' => $r['participants'],
                    ]
                ];
            }

            echo json_encode($events, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
    case 'pending_count':
        if (!can('schedule', 'review')) {
            forbid();
        }

        try {
            $stmt = $pdo->query("
SELECT COUNT(*)
FROM schedule
WHERE status IN ('pending', 'update_pending', 'delete_pending')
        ");

            echo json_encode([
                'ok' => true,
                'count' => (int) $stmt->fetchColumn()
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'my_pending':
        if (!can('schedule', 'create')) {
            forbid();
        }

        try {
            $stmt = $pdo->prepare("
            SELECT
                s.*,
                m.fullname AS creator_name
            FROM schedule s
            LEFT JOIN members m ON m.user_id = s.created_by
WHERE s.created_by = ?
  AND s.status IN ('pending', 'update_pending', 'delete_pending', 'rejected')
            ORDER BY s.start_date ASC
        ");

            $stmt->execute([$userId]);

            json_ok([
                'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);

        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }
        break;


    case 'my_pending_count':
        if (!can('schedule', 'create')) {
            forbid();
        }

        try {
            $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM schedule
            WHERE status = 'pending'
              AND created_by = ?
        ");
            $stmt->execute([$userId]);

            echo json_encode([
                'ok' => true,
                'count' => (int) $stmt->fetchColumn()
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;


    /* =====================================================
     * CREATE
     * ===================================================== */
    case 'create':
        if (!can('schedule', 'create')) {
            forbid();
        }

        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $depart = trim($_POST['department'] ?? '');
        $loc = trim($_POST['location'] ?? '');
        $part = trim($_POST['participants'] ?? '');
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '') ?: null;

        if ($title === '' || $start === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Thiếu tiêu đề hoặc thời gian bắt đầu']);
            exit;
        }

        try {
            $canReview = can('schedule', 'review');
            $status = $canReview ? 'approved' : 'pending';

            $stmt = $pdo->prepare("
                INSERT INTO schedule
                    (title, description, department, location, participants, start_date, end_date, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

            $stmt->execute([
                $title,
                $desc,
                $depart,
                $loc,
                $part,
                $start,
                $end,
                $status,
                $userId
            ]);



            log_activity(
                'create',
                'schedule',
                'Lịch công tác',
                null,
                ($status === 'approved'
                    ? 'Thêm & tự duyệt lịch: '
                    : 'Thêm lịch chờ duyệt: ') . $title
            );


            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    /* =====================================================
     * UPDATE
     * ===================================================== */
    case 'update':
        if (!can('schedule', 'update')) {
            forbid();
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            json_error('ID không hợp lệ');
        }

        // Lấy lịch gốc
        $stmt = $pdo->prepare("
        SELECT id, title, created_by
        FROM schedule
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$old) {
            json_error('Không tìm thấy lịch');
        }

        $isReviewer = can('schedule', 'review');

        // ❌ Không phải reviewer → chỉ sửa được lịch của chính mình
        if (!$isReviewer && (int) $old['created_by'] !== (int) $userId) {
            forbid();
        }

        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $depart = trim($_POST['department'] ?? '');
        $loc = trim($_POST['location'] ?? '');
        $part = trim($_POST['participants'] ?? '');
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '') ?: null;

        if ($title === '' || $start === '') {
            json_error('Thiếu tiêu đề hoặc thời gian');
        }

        // ⭐ logic duyệt
        $newStatus = $isReviewer ? 'approved' : 'update_pending';

        $stmt = $pdo->prepare("
        UPDATE schedule
        SET
            title = ?,
            description = ?,
            department = ?,
            location = ?,
            participants = ?,
            start_date = ?,
            end_date = ?,
            status = ?
        WHERE id = ?
    ");

        $stmt->execute([
            $title,
            $desc,
            $depart,
            $loc,
            $part,
            $start,
            $end,
            $newStatus,
            $id
        ]);

        log_activity(
            'update',
            'schedule',
            'Lịch công tác',
            $id,
            ($isReviewer
                ? 'Cập nhật & tự duyệt lịch: '
                : 'Cập nhật lịch, chờ duyệt lại: '
            ) . $old['title']
        );

        json_ok();
        break;


    /* =====================================================
     * DELETE
     * ===================================================== */
    case 'delete':
        if (!can('schedule', 'delete')) {
            forbid();
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('ID không hợp lệ');
        }

        $stmt = $pdo->prepare("
        SELECT id, title, created_by, status
        FROM schedule
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            json_error('Không tìm thấy lịch');
        }

        $isReviewer = can('schedule', 'review');

        // user thường chỉ được xin xóa lịch của mình
        if (!$isReviewer && (int) $row['created_by'] !== (int) $userId) {
            forbid();
        }

        // reviewer → xóa thẳng
        if ($isReviewer) {
            $pdo->prepare("DELETE FROM schedule WHERE id=?")->execute([$id]);

            log_activity(
                'delete',
                'schedule',
                'Lịch công tác',
                $id,
                'Xóa lịch: ' . $row['title']
            );

            json_ok();
        }

        // user thường → xin xóa
        if ($row['status'] === 'delete_pending') {
            json_error('Lịch đã đang chờ duyệt xóa');
        }

        $pdo->prepare("
        UPDATE schedule
        SET status = 'delete_pending'
        WHERE id = ?
    ")->execute([$id]);

        log_activity(
            'delete_request',
            'schedule',
            'Lịch công tác',
            $id,
            'Yêu cầu xóa lịch: ' . $row['title']
        );

        json_ok(['pending_delete' => true]);
        break;




    case 'pending':
        if (!can('schedule', 'review')) {
            forbid();
        }

        try {
            $stmt = $pdo->query("
SELECT
  s.*,
  m.fullname AS creator_name
FROM schedule s
LEFT JOIN members m ON m.user_id = s.created_by
WHERE s.status IN ('pending', 'update_pending', 'delete_pending')
ORDER BY s.start_date ASC

        ");

            echo json_encode([
                'ok' => true,
                'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }



    case 'approve':
        if (!can('schedule', 'review')) {
            forbid();
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID không hợp lệ']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
UPDATE schedule
SET status = 'approved'
WHERE id = ?
  AND status IN ('pending', 'update_pending')

        ");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Lịch không tồn tại hoặc đã được xử lý");
            }

            log_activity(
                'review',
                'schedule',
                'Lịch công tác',
                $id,
                'Duyệt lịch công tác'
            );

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'reject':
        if (!can('schedule', 'review'))
            forbid();

        $id = (int) ($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if ($id <= 0)
            json_error('ID không hợp lệ');

        $stmt = $pdo->prepare("
        UPDATE schedule
        SET status = 'rejected',
            reject_note = ?
        WHERE id = ?
          AND status IN ('pending','update_pending')
    ");
        $stmt->execute([$note, $id]);

        if (!$stmt->rowCount()) {
            json_error('Không thể từ chối');
        }

        log_activity(
            'review',
            'schedule',
            'Lịch công tác',
            $id,
            'Từ chối lịch: ' . ($note ?: 'Không ghi lý do')
        );

        json_ok();
        break;

    case 'get':
        if (!can('schedule', 'view'))
            forbid();

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'ID không hợp lệ']);
            exit;
        }

        if (!can('schedule', 'review')) {
            $stmt = $pdo->prepare("
        SELECT *
        FROM schedule
        WHERE id = ? AND status = 'approved'
        LIMIT 1
    ");
        } else {
            $stmt = $pdo->prepare("
  SELECT
    s.*,
    u.fullname AS creator_name
  FROM schedule s
  LEFT JOIN users u ON u.id = s.created_by
  WHERE s.id = ?
  LIMIT 1
");

        }

        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['error' => 'Không tìm thấy lịch']);
            exit;
        }

        echo json_encode($row, JSON_UNESCAPED_UNICODE);
        break;

    case 'approve_delete':
        if (!can('schedule', 'review')) {
            forbid();
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0)
            json_error('ID không hợp lệ');

        $stmt = $pdo->prepare("
        SELECT title FROM schedule
        WHERE id = ? AND status = 'delete_pending'
    ");
        $stmt->execute([$id]);
        $title = $stmt->fetchColumn();

        if (!$title) {
            json_error('Lịch không tồn tại hoặc đã xử lý');
        }

        $pdo->prepare("DELETE FROM schedule WHERE id=?")->execute([$id]);

        log_activity(
            'review',
            'schedule',
            'Lịch công tác',
            $id,
            'Duyệt xóa lịch: ' . $title
        );

        json_ok();

    case 'reject_delete':
        if (!can('schedule', 'review'))
            forbid();

        $id = (int) ($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');

        if ($id <= 0)
            json_error('ID không hợp lệ');

        $stmt = $pdo->prepare("
        UPDATE schedule
        SET status = 'approved',
            reject_note = ?
        WHERE id = ?
          AND status = 'delete_pending'
    ");
        $stmt->execute([$note, $id]);

        if (!$stmt->rowCount()) {
            json_error('Không thể từ chối xóa');
        }

        log_activity(
            'review',
            'schedule',
            'Lịch công tác',
            $id,
            'Từ chối xóa lịch: ' . ($note ?: 'Không ghi lý do')
        );

        json_ok();


    /* =====================================================
     * DEFAULT
     * ===================================================== */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
