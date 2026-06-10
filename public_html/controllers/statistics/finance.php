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

if ($action === 'finance_report') {
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $type = $_GET['type'] ?? ''; // income | expense | empty
    $schoolYearId = $_GET['school_year_id'] ?? '';
    $semester = $_GET['semester'] ?? '';

    $where = "WHERE 1";
    $params = [];

    if ($dateFrom !== '') {
        $where .= " AND trans_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= " AND trans_date <= ?";
        $params[] = $dateTo;
    }
    if ($type !== '') {
        $where .= " AND type = ?";
        $params[] = $type;
    }
    if ($schoolYearId !== '') {
        $where .= " AND school_year_id = ?";
        $params[] = (int)$schoolYearId;
    }
    if ($semester !== '') {
        $where .= " AND semester = ?";
        $params[] = $semester;
    }

    // 1. KPI summary
    $kpi = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
            COUNT(CASE WHEN type='income' THEN 1 END) AS income_count,
            COUNT(CASE WHEN type='expense' THEN 1 END) AS expense_count
        FROM finance_transactions
        $where
    ");
    $kpi->execute($params);
    $kpiRow = $kpi->fetch(PDO::FETCH_ASSOC);

    $totalIncome = (float)($kpiRow['total_income'] ?? 0);
    $totalExpense = (float)($kpiRow['total_expense'] ?? 0);
    $balance = $totalIncome - $totalExpense;

    $summary = [
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'balance' => $balance,
        'income_count' => (int)($kpiRow['income_count'] ?? 0),
        'expense_count' => (int)($kpiRow['expense_count'] ?? 0)
    ];

    // 2. Top items (Insights)
    // Top 5 thu
    $topIncomeStmt = $pdo->prepare("
        SELECT item_name, SUM(amount) AS total
        FROM finance_transactions
        $where AND type = 'income'
        GROUP BY item_name
        ORDER BY total DESC
        LIMIT 5
    ");
    $topIncomeStmt->execute($params);
    $topIncome = $topIncomeStmt->fetchAll(PDO::FETCH_ASSOC);

    // Top 5 chi
    $topExpenseStmt = $pdo->prepare("
        SELECT item_name, SUM(amount) AS total
        FROM finance_transactions
        $where AND type = 'expense'
        GROUP BY item_name
        ORDER BY total DESC
        LIMIT 5
    ");
    $topExpenseStmt->execute($params);
    $topExpense = $topExpenseStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Detailed rows
    $listStmt = $pdo->prepare("
        SELECT t.*, u.fullname AS creator_name
        FROM finance_transactions t
        LEFT JOIN users u ON u.id = t.created_by
        $where
        ORDER BY t.trans_date DESC, t.id DESC
    ");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'summary' => $summary,
        'top_income' => $topIncome,
        'top_expense' => $topExpense,
        'rows' => $rows
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'export_finance_report') {
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $type = $_GET['type'] ?? '';
    $schoolYearId = $_GET['school_year_id'] ?? '';
    $semester = $_GET['semester'] ?? '';

    $where = "WHERE 1";
    $params = [];

    if ($dateFrom !== '') {
        $where .= " AND trans_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= " AND trans_date <= ?";
        $params[] = $dateTo;
    }
    if ($type !== '') {
        $where .= " AND type = ?";
        $params[] = $type;
    }
    if ($schoolYearId !== '') {
        $where .= " AND school_year_id = ?";
        $params[] = (int)$schoolYearId;
    }
    if ($semester !== '') {
        $where .= " AND semester = ?";
        $params[] = $semester;
    }

    $listStmt = $pdo->prepare("
        SELECT t.*, u.fullname AS creator_name
        FROM finance_transactions t
        LEFT JOIN users u ON u.id = t.created_by
        $where
        ORDER BY t.trans_date DESC, t.id DESC
    ");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/../../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';

    $excelData = [
        ['STT', 'Mã phiếu', 'Tên khoản thu/chi', 'Loại', 'Số tiền', 'Ngày giao dịch', 'Người nộp/nhận', 'Học kỳ', 'Người lập phiếu', 'Mô tả']
    ];

    $typeLabels = [
        'income' => 'Thu',
        'expense' => 'Chi'
    ];

    foreach ($rows as $index => $r) {
        $excelData[] = [
            $index + 1,
            $r['code'],
            $r['item_name'],
            $typeLabels[$r['type']] ?? $r['type'],
            (float)$r['amount'],
            $r['trans_date'],
            $r['type'] === 'income' ? $r['payer_name'] : $r['payee_name'],
            $r['semester'],
            $r['creator_name'] ?: '-',
            $r['description'] ?: '-'
        ];
    }

    clean_output_buffers();
    Shuchkin\SimpleXLSXGen::fromArray($excelData)->downloadAs('thong_ke_thu_chi.xlsx');
    exit;
}

if ($action === 'get_income_items') {
    $stmt = $pdo->query("
        SELECT id, name, COALESCE(target_type, 'tat_ca') AS target_type
        FROM finance_items
        WHERE type = 'income' AND is_active = 1
        ORDER BY name ASC
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'unpaid_members') {
    $itemName = trim((string)($_GET['item_name'] ?? $_POST['item_name'] ?? ''));
    if ($itemName === '') {
        echo json_encode(['ok' => false, 'error' => 'Vui lòng chọn khoản thu'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $schoolYearId = $_GET['school_year_id'] ?? $_POST['school_year_id'] ?? '';
    $semester = $_GET['semester'] ?? $_POST['semester'] ?? '';
    $deptId = $_GET['department_id'] ?? $_POST['department_id'] ?? '';
    $courseId = $_GET['course_id'] ?? $_POST['course_id'] ?? '';
    $classText = trim((string)($_GET['class_text'] ?? $_POST['class_text'] ?? ''));
    $targetType = trim((string)($_GET['target_type'] ?? $_POST['target_type'] ?? 'tat_ca'));

    $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
    $pageSize = max(5, min(100, (int)($_GET['page_size'] ?? $_POST['page_size'] ?? 10)));

    $where = "WHERE 1=1";
    $params = [];

    if ($deptId !== '') {
        $where .= " AND c.department_id = ?";
        $params[] = (int)$deptId;
    }
    if ($courseId !== '') {
        $where .= " AND c.course_id = ?";
        $params[] = (int)$courseId;
    }
    if ($classText !== '') {
        $where .= " AND c.name LIKE ?";
        $params[] = '%' . $classText . '%';
    }

    if ($targetType === 'doan_vien') {
        $where .= " AND LOWER(CAST(m.type AS CHAR)) IN ('member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan')";
    } elseif ($targetType === 'thanh_nien') {
        $where .= " AND LOWER(CAST(m.type AS CHAR)) IN ('youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh')";
    }

    $subQueryPersonalParams = [$itemName];
    $subQueryPersonalWhere = "";
    if ($schoolYearId !== '') {
        $subQueryPersonalWhere .= " AND t.school_year_id = ?";
        $subQueryPersonalParams[] = (int)$schoolYearId;
    }
    if ($semester !== '') {
        $subQueryPersonalWhere .= " AND t.semester = ?";
        $subQueryPersonalParams[] = $semester;
    }

    $subQueryClassParams = [$itemName];
    $subQueryClassWhere = "";
    if ($schoolYearId !== '') {
        $subQueryClassWhere .= " AND t.school_year_id = ?";
        $subQueryClassParams[] = (int)$schoolYearId;
    }
    if ($semester !== '') {
        $subQueryClassWhere .= " AND t.semester = ?";
        $subQueryClassParams[] = $semester;
    }

    $countSql = "
        SELECT COUNT(*)
        FROM members m
        JOIN classes c ON c.id = m.class_id
        $where
        AND NOT EXISTS (
            SELECT 1 
            FROM finance_transaction_participants ftp
            JOIN finance_transactions t ON t.id = ftp.transaction_id
            WHERE ftp.member_id = m.id
              AND t.type = 'income'
              AND t.item_name = ?
              $subQueryPersonalWhere
        )
        AND NOT EXISTS (
            SELECT 1
            FROM finance_transactions t
            WHERE t.type = 'income'
              AND t.class_text = c.name
              AND t.item_name = ?
              $subQueryClassWhere
        )
    ";

    $allParams = array_merge($params, $subQueryPersonalParams, $subQueryClassParams);

    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($allParams);
    $total = (int)$stmtCount->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;

    $listSql = "
        SELECT 
            m.id,
            m.fullname,
            m.mssv,
            c.name AS class_name,
            d.name AS department_name,
            co.name AS course_name,
            CASE 
                WHEN LOWER(CAST(m.type AS CHAR)) IN ('member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan') THEN 'Đoàn viên'
                WHEN LOWER(CAST(m.type AS CHAR)) IN ('youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh') THEN 'Thanh niên'
                ELSE 'Khác'
            END AS member_type
        FROM members m
        JOIN classes c ON c.id = m.class_id
        LEFT JOIN departments d ON d.id = c.department_id
        LEFT JOIN courses co ON co.id = c.course_id
        $where
        AND NOT EXISTS (
            SELECT 1 
            FROM finance_transaction_participants ftp
            JOIN finance_transactions t ON t.id = ftp.transaction_id
            WHERE ftp.member_id = m.id
              AND t.type = 'income'
              AND t.item_name = ?
              $subQueryPersonalWhere
        )
        AND NOT EXISTS (
            SELECT 1
            FROM finance_transactions t
            WHERE t.type = 'income'
              AND t.class_text = c.name
              AND t.item_name = ?
              $subQueryClassWhere
        )
        ORDER BY d.name ASC, co.name DESC, c.name ASC, m.fullname ASC
        LIMIT $pageSize OFFSET $offset
    ";

    $stmtList = $pdo->prepare($listSql);
    $stmtList->execute($allParams);
    $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'rows' => $rows,
        'paging' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $totalPages
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'export_unpaid_members') {
    $itemName = trim((string)($_GET['item_name'] ?? ''));
    if ($itemName === '') {
        http_response_code(400);
        echo 'Thiếu tên khoản thu';
        exit;
    }

    $schoolYearId = $_GET['school_year_id'] ?? '';
    $semester = $_GET['semester'] ?? '';
    $deptId = $_GET['department_id'] ?? '';
    $courseId = $_GET['course_id'] ?? '';
    $classText = trim((string)($_GET['class_text'] ?? ''));
    $targetType = trim((string)($_GET['target_type'] ?? 'tat_ca'));

    $where = "WHERE 1=1";
    $params = [];

    if ($deptId !== '') {
        $where .= " AND c.department_id = ?";
        $params[] = (int)$deptId;
    }
    if ($courseId !== '') {
        $where .= " AND c.course_id = ?";
        $params[] = (int)$courseId;
    }
    if ($classText !== '') {
        $where .= " AND c.name LIKE ?";
        $params[] = '%' . $classText . '%';
    }

    if ($targetType === 'doan_vien') {
        $where .= " AND LOWER(CAST(m.type AS CHAR)) IN ('member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan')";
    } elseif ($targetType === 'thanh_nien') {
        $where .= " AND LOWER(CAST(m.type AS CHAR)) IN ('youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh')";
    }

    $subQueryPersonalParams = [$itemName];
    $subQueryPersonalWhere = "";
    if ($schoolYearId !== '') {
        $subQueryPersonalWhere .= " AND t.school_year_id = ?";
        $subQueryPersonalParams[] = (int)$schoolYearId;
    }
    if ($semester !== '') {
        $subQueryPersonalWhere .= " AND t.semester = ?";
        $subQueryPersonalParams[] = $semester;
    }

    $subQueryClassParams = [$itemName];
    $subQueryClassWhere = "";
    if ($schoolYearId !== '') {
        $subQueryClassWhere .= " AND t.school_year_id = ?";
        $subQueryClassParams[] = (int)$schoolYearId;
    }
    if ($semester !== '') {
        $subQueryClassWhere .= " AND t.semester = ?";
        $subQueryClassParams[] = $semester;
    }

    $listSql = "
        SELECT 
            m.fullname,
            m.mssv,
            c.name AS class_name,
            d.name AS department_name,
            co.name AS course_name,
            CASE 
                WHEN LOWER(CAST(m.type AS CHAR)) IN ('member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan') THEN 'Đoàn viên'
                WHEN LOWER(CAST(m.type AS CHAR)) IN ('youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh') THEN 'Thanh niên'
                ELSE 'Khác'
            END AS member_type
        FROM members m
        JOIN classes c ON c.id = m.class_id
        LEFT JOIN departments d ON d.id = c.department_id
        LEFT JOIN courses co ON co.id = c.course_id
        $where
        AND NOT EXISTS (
            SELECT 1 
            FROM finance_transaction_participants ftp
            JOIN finance_transactions t ON t.id = ftp.transaction_id
            WHERE ftp.member_id = m.id
              AND t.type = 'income'
              AND t.item_name = ?
              $subQueryPersonalWhere
        )
        AND NOT EXISTS (
            SELECT 1
            FROM finance_transactions t
            WHERE t.type = 'income'
              AND t.class_text = c.name
              AND t.item_name = ?
              $subQueryClassWhere
        )
        ORDER BY d.name ASC, co.name DESC, c.name ASC, m.fullname ASC
    ";

    $allParams = array_merge($params, $subQueryPersonalParams, $subQueryClassParams);

    $stmtList = $pdo->prepare($listSql);
    $stmtList->execute($allParams);
    $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/../../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';

    $excelData = [
        ['STT', 'Họ và tên', 'MSSV', 'Lớp', 'Khoa / Phòng', 'Khóa', 'Phân loại']
    ];

    foreach ($rows as $index => $r) {
        $excelData[] = [
            $index + 1,
            $r['fullname'],
            $r['mssv'] ?: '-',
            $r['class_name'],
            $r['department_name'] ?: '-',
            $r['course_name'] ?: '-',
            $r['member_type']
        ];
    }

    clean_output_buffers();
    $filename = 'danh_sach_chua_dong_' . preg_replace('/[^a-zA-Z0-9]/', '_', $itemName) . '.xlsx';
    Shuchkin\SimpleXLSXGen::fromArray($excelData)->downloadAs($filename);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
exit;
