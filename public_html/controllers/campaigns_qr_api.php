<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/activity_log.php';


header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Ho_Chi_Minh');



$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ======================================================
   0) REVERSE GEOCODE – KHÔNG CẦN LOGIN
====================================================== */
if ($action === 'reverse_geocode') {

    $lat = $_GET['lat'] ?? '';
    $lng = $_GET['lng'] ?? '';

    if ($lat === '' || $lng === '') {
        echo json_encode(['ok' => 0, 'error' => 'missing lat/lng']);
        exit;
    }

    $ua = "QR-Attendance-System/1.0 (contact: admin@localhost)";

    $url = "https://nominatim.openstreetmap.org/reverse?" . http_build_query([
        'format' => 'json',
        'lat' => $lat,
        'lon' => $lng,
        'zoom' => 18
    ]);

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: $ua\r\n"
        ]
    ];

    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);

    if ($res === false) {
        echo json_encode(['ok' => 0, 'error' => 'nominatim_failed']);
        exit;
    }

    $data = json_decode($res, true);

    echo json_encode([
        'ok' => 1,
        'display_name' => $data['display_name'] ?? ''
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

auth_guard(); // chỉ cần đăng nhập

/* ======================================================
   JSON HELPERS
====================================================== */
function json_error($msg, $status = 400)
{
    http_response_code($status);
    echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($data = [])
{
    echo json_encode(array_merge(['ok' => 1], $data), JSON_UNESCAPED_UNICODE);
    exit;
}



/* ======================================================
   1) TẠO QR MỚI
====================================================== */
if ($action === 'create') {
    if (!can('attendance', 'create'))
        json_error('Forbidden', 403);
    global $pdo;

    $cid = (int) ($_POST['campaign_id'] ?? 0);
    if (!$cid)
        json_error("Thiếu campaign_id");

    $lat = isset($_POST['lat']) ? (float) $_POST['lat'] : null;
    $lng = isset($_POST['lng']) ? (float) $_POST['lng'] : null;
    $address = trim($_POST['address'] ?? '');

    if ($lat === null || $lng === null) {
        json_error("Chưa chọn vị trí điểm danh trên bản đồ!");
    }

    $start = trim($_POST['starts_at'] ?? '');
    $exp = trim($_POST['expires_at'] ?? '');

    if ($start === '')
        json_error("Vui lòng chọn thời gian bắt đầu");
    if ($exp === '')
        json_error("Vui lòng chọn thời gian hết hạn");

    $starts_at = date("Y-m-d H:i:s", strtotime($start));
    $expires_at = date("Y-m-d H:i:s", strtotime($exp));

    if ($starts_at === '1970-01-01 00:00:00')
        json_error("Thời gian bắt đầu không hợp lệ");
    if ($expires_at === '1970-01-01 00:00:00')
        json_error("Thời gian hết hạn không hợp lệ");


    if (strtotime($starts_at) >= strtotime($expires_at)) {
        json_error("Thời gian bắt đầu phải trước thời gian hết hạn");
    }

    // session theo starts_at
    $h = (int) date('H', strtotime($starts_at));
    if ($h >= 5 && $h < 12)
        $session = 'morning';
    elseif ($h < 18)
        $session = 'afternoon';
    else
        $session = 'evening';

    $code = "EVT_" . bin2hex(random_bytes(8));
    $now = date("Y-m-d H:i:s");

    $stm = $pdo->prepare("
        INSERT INTO attendance_events
        (campaign_id, session, code, starts_at, expires_at, lat, lng, address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stm->execute([
        $cid,
        $session,
        $code,
        $starts_at,
        $expires_at,
        $lat,
        $lng,
        $address ?: null,
        $now
    ]);

    log_activity(
        'create',
        'Điểm danh',
        'QR điểm danh',
        null,
        'Tạo QR điểm danh cho phong trào ID ' . $cid . ' (' . $session . ')'
    );

    json_ok([
        "code" => $code,
        "session" => $session
    ]);
}




/* ======================================================
   2) GIA HẠN QR
====================================================== */
if ($action === 'extend') {
    if (!can('attendance', 'update'))
        json_error('Forbidden', 403);
    global $pdo;

    $id = (int) ($_POST['id'] ?? 0);
    $exp = trim($_POST['expires_at'] ?? '');

    if (!$id)
        json_error("Thiếu ID");
    if ($exp === '')
        json_error("Vui lòng nhập thời gian mới");

    $expires_at = date("Y-m-d H:i:s", strtotime($exp));
    if (!$expires_at || $expires_at === '1970-01-01 00:00:00') {
        json_error("Thời gian không hợp lệ");
    }

    $pdo->prepare("
        UPDATE attendance_events
        SET expires_at = ?
        WHERE id = ?
    ")->execute([$expires_at, $id]);

    json_ok(["expires_at" => $expires_at]);
}




/* ======================================================
   3) XÓA QR
====================================================== */
if ($action === 'delete') {
    if (!can('attendance', 'delete')) {
        json_error('Forbidden', 403);
    }

    global $pdo;

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id)
        json_error("Thiếu ID QR");
    $q = $pdo->prepare("SELECT campaign_id FROM attendance_events WHERE id=?");
    $q->execute([$id]);
    $cid = $q->fetchColumn();
    try {
        $stm = $pdo->prepare("DELETE FROM attendance_events WHERE id=?");
        $stm->execute([$id]);

    } catch (Exception $e) {
        // trường hợp foreign key hoặc lỗi DB khác
        json_error("Không thể xóa: " . $e->getMessage());
    }
    $qCamp = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
    $qCamp->execute([$cid]);
    $title = $qCamp->fetchColumn();
    log_activity(
        'delete',
        'Điểm danh',
        'QR điểm danh',
        null,
        'Xóa QR điểm danh của phong trào: ' . $title
    );
    json_ok(["message" => "Đã xóa"]);
}



/* ======================================================
   ACTION SAI
====================================================== */
json_error("Bad action", 404);
