<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

auth_guard('admin');

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

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

    global $pdo;

    $cid = (int) ($_POST['campaign_id'] ?? 0);
    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;
    $exp = trim($_POST['expires_at'] ?? '');
    $session = $_POST['session'] ?? 'morning';

    if (!$cid)
        json_error("Thiếu campaign_id");
    if ($exp === '')
        json_error("Vui lòng chọn thời gian hết hạn");
    if (!$lat || !$lng)
        json_error("Không lấy được vị trí GPS!");

    $expires_at = date("Y-m-d H:i:s", strtotime($exp));

    $code = "EVT_" . bin2hex(random_bytes(8));
    $now = date("Y-m-d H:i:s");

    $stm = $pdo->prepare("
        INSERT INTO attendance_events
        (campaign_id, session, code, lat, lng, expires_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    try {
        $stm->execute([$cid, $session, $code, $lat, $lng, $expires_at, $now]);
    } catch (Exception $e) {
        json_error("DB ERROR: " . $e->getMessage());
    }

    json_ok([
        "code" => $code,
        "expires_at" => $expires_at,
        "lat" => $lat,
        "lng" => $lng,
        "session" => $session
    ]);
}



/* ======================================================
   2) GIA HẠN QR
====================================================== */
if ($action === 'extend') {

    global $pdo;

    $id = (int) ($_POST['id'] ?? 0);
    $exp = trim($_POST['expires_at'] ?? '');

    if (!$id)
        json_error("Thiếu ID");
    if ($exp === '')
        json_error("Vui lòng nhập thời gian mới");

    $expires_at = date("Y-m-d H:i:s", strtotime($exp));

    $stm = $pdo->prepare("
        UPDATE attendance_events
        SET expires_at = ?
        WHERE id = ?
    ");

    try {
        $stm->execute([$expires_at, $id]);
    } catch (Exception $e) {
        json_error("DB ERROR: " . $e->getMessage());
    }

    json_ok(["expires_at" => $expires_at]);
}



/* ======================================================
   3) XÓA QR
====================================================== */
if ($action === 'delete') {

    global $pdo;

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id)
        json_error("Thiếu ID QR");

    try {
        $stm = $pdo->prepare("DELETE FROM attendance_events WHERE id=?");
        $stm->execute([$id]);

    } catch (Exception $e) {
        // trường hợp foreign key hoặc lỗi DB khác
        json_error("Không thể xóa: " . $e->getMessage());
    }

    json_ok(["message" => "Đã xóa"]);
}



/* ======================================================
   ACTION SAI
====================================================== */
json_error("Bad action", 404);
