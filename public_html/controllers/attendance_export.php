<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';
use Shuchkin\SimpleXLSXGen;


auth_guard();

if (!can('attendance', 'print')) {
    http_response_code(403);
    exit('Forbidden');
}

// Lấy campaign ID
$cid = (int) ($_GET['campaign_id'] ?? 0);
if ($cid <= 0) {
    die("Thiếu campaign_id");
}

// Lấy tên phong trào
$stm = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
$stm->execute([$cid]);
$camp = $stm->fetch();
$campaignTitle = $camp['title'] ?? "Phong trào";

// Lấy danh sách điểm danh
$idsStr = $_GET['ids'] ?? '';
$ids = [];
if ($idsStr !== '') {
    $ids = array_map('intval', array_filter(explode(',', $idsStr)));
}

if (!empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stm = $pdo->prepare("
        SELECT 
            COALESCE(m.fullname, u.username) AS fullname,
            COALESCE(m.class_name, '') AS class_name,
            l.time,
            l.session
        FROM attendance_logs l
        JOIN users u ON u.id = l.user_id
        LEFT JOIN members m ON m.user_id = u.id
        WHERE l.id IN ($in)
        ORDER BY l.time DESC
    ");
    $stm->execute($ids);
} else {
    $stm = $pdo->prepare("
        SELECT 
            COALESCE(m.fullname, u.username) AS fullname,
            COALESCE(m.class_name, '') AS class_name,
            l.time,
            l.session
        FROM attendance_logs l
        JOIN users u ON u.id = l.user_id
        LEFT JOIN members m ON m.user_id = u.id
        WHERE l.campaign_id = ?
        ORDER BY l.time DESC
    ");
    $stm->execute([$cid]);
}
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);


// Header file
$data = [
    ['Tên', 'Lớp', 'Thời gian', 'Buổi']
];

// Ghi dữ liệu
foreach ($rows as $r) {

    // Buổi
    switch ($r['session']) {
        case 'morning':
            $session = 'Sáng';
            break;
        case 'afternoon':
            $session = 'Chiều';
            break;
        case 'evening':
            $session = 'Tối';
            break;
        default:
            $session = 'Không xác định';
    }

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
