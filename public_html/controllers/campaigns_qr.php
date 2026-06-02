<?php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/activity_log.php';

auth_guard();

// ❗ chỉ người có quyền quản lý QR mới được vào
if (!can('attendance', 'create')) {
    http_response_code(403);
    die("Forbidden");
}

$cid = (int) ($_GET['campaign_id'] ?? 0);
if (!$cid) {
    die("Thiếu ID phong trào.");
}

// Lấy phong trào
$stm = $pdo->prepare("SELECT * FROM campaigns WHERE id=? LIMIT 1");
$stm->execute([$cid]);
$c = $stm->fetch(PDO::FETCH_ASSOC);

if (!$c) {
    die("Không tìm thấy phong trào.");
}

// ✅ LẤY ĐẦY ĐỦ DỮ LIỆU QR
$stm = $pdo->prepare("
    SELECT 
        id,
        code,
        session,
        lat,
        lng,
        address,
        starts_at,
        expires_at,
        created_at
    FROM attendance_events
    WHERE campaign_id = ?
    ORDER BY id DESC
");
$stm->execute([$cid]);
$events = $stm->fetchAll(PDO::FETCH_ASSOC);

// Load view
include __DIR__ . '/../views/campaigns_qr.php';
