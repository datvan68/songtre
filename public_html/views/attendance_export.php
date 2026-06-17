<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';
use Shuchkin\SimpleXLSXGen;


auth_guard();

// Lấy campaign ID
$cid = (int)($_GET['campaign_id'] ?? 0);
if ($cid <= 0) {
    die("Thiếu campaign_id");
}

// Lấy tên phong trào
$stm = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
$stm->execute([$cid]);
$camp = $stm->fetch();
$campaignTitle = $camp['title'] ?? "Phong trào";

// Lấy danh sách điểm danh
$stm = $pdo->prepare("
    SELECT 
        m.fullname,
        m.class_name,
        l.time,
        l.session
    FROM attendance_logs l
    JOIN members m ON m.user_id = l.user_id
    WHERE l.campaign_id = ?
    ORDER BY l.time DESC
");
$stm->execute([$cid]);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

// Header file
$data = [
    ['Tên', 'Lớp', 'Thời gian', 'Buổi']
];

// Ghi dữ liệu
foreach ($rows as $r) {

    // Buổi
    $sessionMap = [
        'morning' => 'Sáng',
        'afternoon' => 'Chiều',
        'evening' => 'Tối'
    ];
    $session = $sessionMap[$r['session']] ?? 'Không xác định';

    // Thời gian
    $time = date("d/m/Y H:i", strtotime($r['time']));

    $data[] = [
        $r['fullname'],
        $r['class_name'],
        $time,
        $session
    ];
}

// Xuất file
$filename = "diem_danh_" . preg_replace('/\s+/', '_', $campaignTitle) . ".xlsx";
SimpleXLSXGen::fromArray($data)->downloadAs($filename);
exit;
