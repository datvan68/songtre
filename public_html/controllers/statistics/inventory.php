<?php
// controllers/statistics/inventory.php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

auth_guard();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

function json_ok($arr = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $arr), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_filters()
{
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? 'all'); // borrowing|returned|overdue|all
    $type = trim($_GET['type'] ?? 'all'); // equipment|item|all
    $categoryId = trim($_GET['category_id'] ?? '');
    $departmentId = trim($_GET['department_id'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $onlyOverdue = (string) ($_GET['only_overdue'] ?? '0') === '1' ? 1 : 0;

    $allowedStatus = ['all', 'borrowing', 'returned', 'overdue'];
    if (!in_array($status, $allowedStatus, true))
        $status = 'all';

    $allowedType = ['all', 'equipment', 'item'];
    if (!in_array($type, $allowedType, true))
        $type = 'all';

    return [
        'q' => $q,
        'status' => $status,
        'type' => $type,
        'category_id' => $categoryId,
        'department_id' => $departmentId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'only_overdue' => $onlyOverdue,
    ];
}

function build_where($filters, &$params)
{
    $where = "1=1";

    // type filter (inventory_items.type)
    if (!empty($filters['type']) && $filters['type'] !== 'all') {
        $where .= " AND i.type = ? ";
        $params[] = $filters['type'];
    }

    // category filter
    if (!empty($filters['category_id'])) {
        $where .= " AND i.category_id = ? ";
        $params[] = (int) $filters['category_id'];
    }

    // department filter
    if (!empty($filters['department_id'])) {
        $where .= " AND i.department_id = ? ";
        $params[] = (int) $filters['department_id'];
    }

    // date range by borrow_date
    if (!empty($filters['date_from'])) {
        $where .= " AND b.borrow_date >= ? ";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where .= " AND b.borrow_date <= ? ";
        $params[] = $filters['date_to'];
    }

    // computed status (overdue if deadline < today and not returned)
    if (!empty($filters['only_overdue'])) {
        $where .= " AND (b.return_date IS NULL AND b.return_deadline < CURDATE()) ";
    } elseif (!empty($filters['status']) && $filters['status'] !== 'all') {
        if ($filters['status'] === 'overdue') {
            $where .= " AND (b.return_date IS NULL AND b.return_deadline < CURDATE()) ";
        } else {
            // borrowing / returned use stored status OR derived
            $where .= " AND (CASE WHEN b.return_date IS NULL AND b.return_deadline < CURDATE() THEN 'overdue' ELSE b.status END) = ? ";
            $params[] = $filters['status'];
        }
    }

    if (!empty($filters['q'])) {
        $like = '%' . $filters['q'] . '%';
        $where .= " AND (
            i.code LIKE ?
            OR i.name LIKE ?
            OR c.name LIKE ?
            OR d.name LIKE ?
            OR b.borrower_name LIKE ?
            OR b.borrower_unit LIKE ?
            OR b.purpose LIKE ?
            OR b.note LIKE ?
        ) ";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    return $where;
}

function get_report($pdo, $filters)
{
    // rows (borrow logs)
    $params = [];
    $where = build_where($filters, $params);

    $sql = "
      SELECT
        b.id,
        b.inventory_id,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.category_id,
        i.department_id,
        c.name AS category_name,
        d.name AS dept_name,
        b.borrower_name,
        b.borrower_unit,
        b.quantity,
        DATE_FORMAT(b.borrow_date, '%Y-%m-%d') AS borrow_date,
        DATE_FORMAT(b.return_deadline, '%Y-%m-%d') AS return_deadline,
        DATE_FORMAT(b.return_date, '%Y-%m-%d') AS return_date,
        (CASE
          WHEN b.return_date IS NULL AND b.return_deadline < CURDATE() THEN 'overdue'
          ELSE b.status
        END) AS status,
        (CASE
          WHEN b.return_date IS NULL AND b.return_deadline < CURDATE() THEN DATEDIFF(CURDATE(), b.return_deadline)
          ELSE 0
        END) AS days_late,
        b.purpose,
        b.note,
        b.created_by,
        COALESCE(NULLIF(m.fullname,''), NULLIF(u.fullname,''), NULLIF(u.username,''), '(Không rõ)') AS created_by_name
      FROM inventory_borrows b
      INNER JOIN inventory_items i ON i.id = b.inventory_id
      LEFT JOIN inventory_categories c ON c.id = i.category_id
      LEFT JOIN departments d ON d.id = i.department_id
      LEFT JOIN users u ON u.id = b.created_by
      LEFT JOIN members m ON m.user_id = u.id
      WHERE $where
      ORDER BY b.borrow_date DESC, b.id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // stock summary from inventory_items
    $sqlStock = "
      SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(total_quantity),0) AS total_quantity,
        COALESCE(SUM(borrowed_quantity),0) AS total_borrowed_quantity,
        COALESCE(SUM(CASE WHEN status='available' THEN total_quantity ELSE 0 END),0) AS available_quantity,
        COALESCE(SUM(CASE WHEN status='stock' THEN total_quantity ELSE 0 END),0) AS stock_quantity,
        COALESCE(SUM(CASE WHEN status='broken' THEN total_quantity ELSE 0 END),0) AS broken_quantity
      FROM inventory_items
    ";
    $stock = $pdo->query($sqlStock)->fetch(PDO::FETCH_ASSOC) ?: [];

    // borrow summary WITH filters
    $params2 = [];
    $where2 = build_where($filters, $params2);
    $sqlBorrow = "
      SELECT
        COUNT(*) AS total_borrows,
        SUM(CASE WHEN (CASE WHEN b.return_date IS NULL AND b.return_deadline < CURDATE() THEN 'overdue' ELSE b.status END)='borrowing' THEN 1 ELSE 0 END) AS borrowing,
        SUM(CASE WHEN (CASE WHEN b.return_date IS NULL AND b.return_deadline < CURDATE() THEN 'overdue' ELSE b.status END)='overdue' THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN (CASE WHEN b.return_date IS NULL AND b.return_deadline < CURDATE() THEN 'overdue' ELSE b.status END)='returned' THEN 1 ELSE 0 END) AS returned
      FROM inventory_borrows b
      INNER JOIN inventory_items i ON i.id = b.inventory_id
      LEFT JOIN inventory_categories c ON c.id = i.category_id
      LEFT JOIN departments d ON d.id = i.department_id
      WHERE $where2
    ";
    $st2 = $pdo->prepare($sqlBorrow);
    $st2->execute($params2);
    $borrowSum = $st2->fetch(PDO::FETCH_ASSOC) ?: [];

    // top items by borrow count (WITH filters)
    $params3 = [];
    $where3 = build_where($filters, $params3);
    $sqlTop = "
      SELECT
        b.inventory_id,
        CONCAT(i.code, ' - ', i.name) AS item_label,
        COUNT(*) AS borrow_count,
        COALESCE(SUM(b.quantity),0) AS borrow_qty
      FROM inventory_borrows b
      INNER JOIN inventory_items i ON i.id = b.inventory_id
      LEFT JOIN inventory_categories c ON c.id = i.category_id
      LEFT JOIN departments d ON d.id = i.department_id
      WHERE $where3
      GROUP BY b.inventory_id, item_label
      ORDER BY borrow_count DESC, borrow_qty DESC
      LIMIT 10
    ";
    $st3 = $pdo->prepare($sqlTop);
    $st3->execute($params3);
    $topItems = $st3->fetchAll(PDO::FETCH_ASSOC);

    // top overdue borrowers (WITH filters but force overdue)
    $params4 = [];
    $filtersOver = $filters;
    $filtersOver['status'] = 'overdue';
    $filtersOver['only_overdue'] = 0;
    $where4 = build_where($filtersOver, $params4);

    $sqlTopOver = "
      SELECT
        COALESCE(NULLIF(TRIM(b.borrower_name),''),'(Không rõ)') AS borrower_name,
        COUNT(*) AS overdue_count,
        COALESCE(SUM(b.quantity),0) AS overdue_qty
      FROM inventory_borrows b
      INNER JOIN inventory_items i ON i.id = b.inventory_id
      LEFT JOIN inventory_categories c ON c.id = i.category_id
      LEFT JOIN departments d ON d.id = i.department_id
      WHERE $where4
      GROUP BY borrower_name
      ORDER BY overdue_count DESC, overdue_qty DESC
      LIMIT 10
    ";
    $st4 = $pdo->prepare($sqlTopOver);
    $st4->execute($params4);
    $topOverdue = $st4->fetchAll(PDO::FETCH_ASSOC);

    // by_category (stock distribution)
    $sqlCat = "
      SELECT
        COALESCE(NULLIF(c.name,''),'(Không rõ)') AS category_name,
        COUNT(*) AS total_items
      FROM inventory_items i
      LEFT JOIN inventory_categories c ON c.id = i.category_id
      GROUP BY category_name
      ORDER BY total_items DESC
      LIMIT 50
    ";
    $byCategory = $pdo->query($sqlCat)->fetchAll(PDO::FETCH_ASSOC);

    // by_department (stock distribution)
    $sqlDept = "
      SELECT
        COALESCE(NULLIF(d.name,''),'(Không rõ)') AS dept_name,
        COUNT(*) AS total_items
      FROM inventory_items i
      LEFT JOIN departments d ON d.id = i.department_id
      GROUP BY dept_name
      ORDER BY total_items DESC
      LIMIT 50
    ";
    $byDept = $pdo->query($sqlDept)->fetchAll(PDO::FETCH_ASSOC);

    $summary = array_merge($stock, $borrowSum);
    $summary['top_items'] = $topItems;
    $summary['top_overdue_borrowers'] = $topOverdue;
    $summary['by_category'] = $byCategory;
    $summary['by_department'] = $byDept;

    return [$rows, $summary];
}

/* ======================
   ACTIONS
====================== */

if ($action === 'inventory_options') {
    try {
        $cats = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $depts = $pdo->query("SELECT id, name, type FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['categories' => $cats, 'departments' => $depts]);
    } catch (Throwable $e) {
        json_err("Không thể tải options: " . $e->getMessage(), 500);
    }
}

if ($action === 'inventory_report') {
    $filters = get_filters();
    try {
        [$rows, $summary] = get_report($pdo, $filters);
        json_ok(['rows' => $rows, 'summary' => $summary]);
    } catch (Throwable $e) {
        json_err("Không thể tải thống kê: " . $e->getMessage(), 500);
    }
}

if ($action === 'export_inventory_report') {
    $filters = get_filters();

    try {
        [$rows, $summary] = get_report($pdo, $filters);
    } catch (Throwable $e) {
        json_err("Không thể export: " . $e->getMessage(), 500);
    }

    // ===== Map tiếng Việt =====
    $mapType = function ($t) {
        $t = (string) $t;
        if ($t === 'equipment')
            return 'Thiết bị';
        if ($t === 'item')
            return 'Đồ dùng';
        return ($t !== '' ? $t : '—');
    };

    $mapStatus = function ($s) {
        $s = (string) $s;
        if ($s === 'borrowing')
            return 'Đang mượn';
        if ($s === 'returned')
            return 'Đã trả';
        if ($s === 'overdue')
            return 'Quá hạn';
        return ($s !== '' ? $s : '—');
    };

    // ===== XLSX =====
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("Thiet bi - Do dung");

    // Cột export "đầy đủ"
    $headers = [
        'STT',
        'Mã thiết bị/đồ dùng',
        'Tên thiết bị/đồ dùng',
        'Loại',
        'Danh mục',
        'Đơn vị quản lý',
        'Người mượn',
        'Đơn vị mượn',
        'Số lượng',
        'Ngày mượn',
        'Hạn trả',
        'Ngày trả',
        'Trạng thái',
        'Mục đích',
        'Ghi chú',
        'Người tạo',
    ];
    $colCount = count($headers);              // 16
    $endCol = Coordinate::stringFromColumnIndex($colCount); // P

    // ===== Header trái/phải theo tổng số cột =====
    // Chia đôi: Left = A..H, Right = I..P (vì 16 cột)
    $leftEndIdx = (int) ceil($colCount / 2);       // 8
    $leftEndCol = Coordinate::stringFromColumnIndex($leftEndIdx); // H
    $rightStartIdx = $leftEndIdx + 1;             // 9
    $rightStartCol = Coordinate::stringFromColumnIndex($rightStartIdx); // I

    $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
    $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
    $dateLine = "Ngày " . date('d') . " tháng " . date('m') . " năm " . date('Y');

    // Left block: A1 : H4
    $sheet->setCellValue("A1", $orgLeft);
    $sheet->mergeCells("A1:{$leftEndCol}4");
    $sheet->getStyle("A1:{$leftEndCol}4")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Right title: I1 : P3
    $sheet->setCellValue("{$rightStartCol}1", $orgRight);
    $sheet->mergeCells("{$rightStartCol}1:{$endCol}3");
    $sheet->getStyle("{$rightStartCol}1:{$endCol}3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'underline' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);

    // Right date line: I4 : P4
    $sheet->setCellValue("{$rightStartCol}4", $dateLine);
    $sheet->mergeCells("{$rightStartCol}4:{$endCol}4");
    $sheet->getStyle("{$rightStartCol}4:{$endCol}4")->applyFromArray([
        'font' => ['italic' => true, 'size' => 12],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    // Row heights
    $sheet->getRowDimension(1)->setRowHeight(20.5);
    $sheet->getRowDimension(2)->setRowHeight(15.75);
    $sheet->getRowDimension(3)->setRowHeight(15.75);
    $sheet->getRowDimension(4)->setRowHeight(32.25);

    // ===== Title + filter + summary (đều tiếng Việt) =====
    $sheet->setCellValue("A6", "BÁO CÁO THỐNG KÊ THIẾT BỊ / ĐỒ DÙNG (MƯỢN - TRẢ)");
    $sheet->mergeCells("A6:{$endCol}6");
    $sheet->getStyle("A6")->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $statusVi = ($filters['status'] === 'all') ? 'Tất cả' : $mapStatus($filters['status']);
    $typeVi = ($filters['type'] === 'all') ? 'Tất cả' : $mapType($filters['type']);

    $sheet->setCellValue(
        "A7",
        "Bộ lọc: Trạng thái={$statusVi} | Loại={$typeVi} | Chỉ quá hạn=" . ($filters['only_overdue'] ? 'Có' : 'Không')
    );
    $sheet->mergeCells("A7:{$endCol}7");

    $sheet->setCellValue(
        "A8",
        "Thời gian: " . ($filters['date_from'] ?: '---') . " → " . ($filters['date_to'] ?: '---')
        . " | Danh mục ID: " . ($filters['category_id'] ?: '---')
        . " | Đơn vị ID: " . ($filters['department_id'] ?: '---')
        . " | Từ khóa: " . ($filters['q'] ?: '---')
    );
    $sheet->mergeCells("A8:{$endCol}8");
    $sheet->getStyle("A7:A8")->getFont()->setSize(10);

    // Summary line (tuỳ chọn, nhưng hữu ích)
    $totalBorrows = (int) ($summary['total_borrows'] ?? 0);
    $borrowing = (int) ($summary['borrowing'] ?? 0);
    $overdue = (int) ($summary['overdue'] ?? 0);
    $returned = (int) ($summary['returned'] ?? 0);

    $sheet->setCellValue("A9", "Tổng lượt mượn: {$totalBorrows} | Đang mượn: {$borrowing} | Quá hạn: {$overdue} | Đã trả: {$returned}");
    $sheet->mergeCells("A9:{$endCol}9");
    $sheet->getStyle("A9")->getFont()->setSize(10);

    // ===== Table =====
    $rowStart = 11;
    $sheet->fromArray($headers, null, "A{$rowStart}");

    $sheet->getStyle("A{$rowStart}:{$endCol}{$rowStart}")->getFont()->setBold(true);
    $sheet->getStyle("A{$rowStart}:{$endCol}{$rowStart}")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');
    $sheet->getStyle("A{$rowStart}:{$endCol}{$rowStart}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A{$rowStart}:{$endCol}{$rowStart}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    $r = $rowStart + 1;
    $i = 1;

    foreach ($rows as $row) {
        $itemCode = (string) ($row['item_code'] ?? '');
        $itemName = (string) ($row['item_name'] ?? '');
        $type = $mapType($row['item_type'] ?? '');
        $cat = (string) ($row['category_name'] ?? '');
        $dept = (string) ($row['dept_name'] ?? '');
        $borrower = (string) ($row['borrower_name'] ?? '');
        $borrowerUnit = (string) ($row['borrower_unit'] ?? '');
        $qty = (int) ($row['quantity'] ?? 0);
        $borrowDate = (string) ($row['borrow_date'] ?? '');
        $deadline = (string) ($row['return_deadline'] ?? '');
        $returnDate = (string) ($row['return_date'] ?? '');
        $status = $mapStatus($row['status'] ?? '');
        $purpose = (string) ($row['purpose'] ?? '');
        $note = (string) ($row['note'] ?? '');
        $createdBy = (string) ($row['created_by_name'] ?? '');

        $sheet->setCellValue("A{$r}", $i++);
        $sheet->setCellValue("B{$r}", $itemCode);
        $sheet->setCellValue("C{$r}", $itemName);
        $sheet->setCellValue("D{$r}", $type);
        $sheet->setCellValue("E{$r}", $cat);
        $sheet->setCellValue("F{$r}", $dept);
        $sheet->setCellValue("G{$r}", $borrower);
        $sheet->setCellValue("H{$r}", $borrowerUnit);
        $sheet->setCellValue("I{$r}", $qty);
        $sheet->setCellValue("J{$r}", $borrowDate);
        $sheet->setCellValue("K{$r}", $deadline);
        $sheet->setCellValue("L{$r}", $returnDate);
        $sheet->setCellValue("M{$r}", $status);
        $sheet->setCellValue("N{$r}", $purpose);
        $sheet->setCellValue("O{$r}", $note);
        $sheet->setCellValue("P{$r}", $createdBy);

        $r++;
    }

    // Borders full
    if ($r > $rowStart + 1) {
        $sheet->getStyle("A{$rowStart}:{$endCol}" . ($r - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    // Wrap cho cột dài
    $sheet->getStyle("C{$rowStart}:C" . ($r - 1))->getAlignment()->setWrapText(true); // Tên
    $sheet->getStyle("N{$rowStart}:N" . ($r - 1))->getAlignment()->setWrapText(true); // Mục đích
    $sheet->getStyle("O{$rowStart}:O" . ($r - 1))->getAlignment()->setWrapText(true); // Ghi chú

    // Canh giữa một số cột
    $sheet->getStyle("A{$rowStart}:A" . ($r - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("I{$rowStart}:I" . ($r - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("J{$rowStart}:M" . ($r - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Auto size
    foreach (range('A', $endCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // ===== Output =====
    $filename = "bao_cao_thiet_bi_do_dung_" . date('Ymd_His') . ".xlsx";
    if (ob_get_length())
        ob_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

json_err("Unknown action", 400);
