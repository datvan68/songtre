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

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
exit;
