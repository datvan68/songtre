<?php
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

auth_guard();
if (!can('statistics', 'view')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

function clean_output_buffers() {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

if ($action === 'violations_report') {
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $treatment = $_GET['treatment'] ?? ''; // Hình thức xử lý
    $q = trim($_GET['q'] ?? '');

    $where = "WHERE 1";
    $params = [];

    if ($dateFrom !== '') {
        $where .= " AND v.created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where .= " AND v.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($treatment !== '') {
        $where .= " AND v.treatment = ?";
        $params[] = $treatment;
    }
    if ($q !== '') {
        $where .= " AND (m.fullname LIKE ? OR m.mssv LIKE ? OR v.reason LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    // 1. KPI summary
    $kpi = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_violations,
            COUNT(DISTINCT v.member_id) AS total_violators,
            COUNT(DISTINCT CASE WHEN v.treatment = 'Cảnh cáo' THEN v.id END) AS warning_count,
            COUNT(DISTINCT CASE WHEN v.treatment = 'Khiển trách' THEN v.id END) AS reprimand_count
        FROM violations v
        JOIN members m ON m.id = v.member_id
        $where
    ");
    $kpi->execute($params);
    $kpiRow = $kpi->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'total_violations' => (int)($kpiRow['total_violations'] ?? 0),
        'total_violators' => (int)($kpiRow['total_violators'] ?? 0),
        'warning_count' => (int)($kpiRow['warning_count'] ?? 0),
        'reprimand_count' => (int)($kpiRow['reprimand_count'] ?? 0)
    ];

    // 2. Top violators
    $topViolatorsStmt = $pdo->prepare("
        SELECT m.fullname, m.mssv, COUNT(v.id) AS total
        FROM violations v
        JOIN members m ON m.id = v.member_id
        $where
        GROUP BY v.member_id, m.fullname, m.mssv
        ORDER BY total DESC
        LIMIT 5
    ");
    $topViolatorsStmt->execute($params);
    $topViolators = $topViolatorsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Top departments
    $topDeptsStmt = $pdo->prepare("
        SELECT d.name AS dept_name, COUNT(v.id) AS total
        FROM violations v
        JOIN members m ON m.id = v.member_id
        LEFT JOIN classes c ON c.id = m.class_id
        LEFT JOIN departments d ON d.id = c.department_id
        $where
        GROUP BY c.department_id, d.name
        ORDER BY total DESC
        LIMIT 5
    ");
    $topDeptsStmt->execute($params);
    $topDepts = $topDeptsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Detailed rows
    $listStmt = $pdo->prepare("
        SELECT 
            v.*, 
            m.fullname AS member_name, 
            m.mssv,
            cl.name AS class_name,
            u.fullname AS creator_name
        FROM violations v
        JOIN members m ON m.id = v.member_id
        LEFT JOIN classes cl ON cl.id = m.class_id
        LEFT JOIN users u ON u.id = v.created_by
        $where
        ORDER BY v.created_at DESC, v.id DESC
    ");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'summary' => $summary,
        'top_violators' => $topViolators,
        'top_departments' => $topDepts,
        'rows' => $rows
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'export_violations_report') {
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $treatment = $_GET['treatment'] ?? '';
    $q = trim($_GET['q'] ?? '');

    $where = "WHERE 1";
    $params = [];

    if ($dateFrom !== '') {
        $where .= " AND v.created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where .= " AND v.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($treatment !== '') {
        $where .= " AND v.treatment = ?";
        $params[] = $treatment;
    }
    if ($q !== '') {
        $where .= " AND (m.fullname LIKE ? OR m.mssv LIKE ? OR v.reason LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    $listStmt = $pdo->prepare("
        SELECT 
            v.*, 
            m.fullname AS member_name, 
            m.mssv,
            cl.name AS class_name,
            u.fullname AS creator_name
        FROM violations v
        JOIN members m ON m.id = v.member_id
        LEFT JOIN classes cl ON cl.id = m.class_id
        LEFT JOIN users u ON u.id = v.created_by
        $where
        ORDER BY v.created_at DESC, v.id DESC
    ");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/../../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';

    $excelData = [
        ['THỐNG KÊ VI PHẠM KỶ LUẬT'],
        [],
        ['STT', 'MSSV', 'Họ tên', 'Lớp', 'Lý do vi phạm', 'Hình thức xử lý', 'Ngày ghi nhận', 'Người ghi nhận']
    ];

    foreach ($rows as $index => $r) {
        $excelData[] = [
            $index + 1,
            $r['mssv'],
            $r['member_name'],
            $r['class_name'] ?: '-',
            $r['reason'],
            $r['treatment'],
            $r['created_at'],
            $r['creator_name'] ?: '-'
        ];
    }

    clean_output_buffers();
    Shuchkin\SimpleXLSXGen::fromArray($excelData)->downloadAs('thong_ke_vi_pham.xlsx');
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
exit;
