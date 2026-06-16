<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

auth_guard();
$user = auth_user();
$userId = $user['id'] ?? null;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* =========================
   HELPERS
========================= */
function json_error($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($data = [])
{
    echo json_encode(['ok' => 1] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function forbidden()
{
    http_response_code(403);
    echo json_encode([
        'ok' => 0,
        'error' => 'Forbidden'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_output_buffers()
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function get_borrow_points(PDO $pdo, $mssv)
{
    if (empty($mssv)) {
        return 10;
    }

    $stmt = $pdo->prepare("
        SELECT return_deadline, return_date, status 
        FROM inventory_borrows 
        WHERE borrower_name LIKE ?
    ");
    $stmt->execute(["$mssv%"]);
    $borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $initialPoints = 10;
    $deducted = 0;
    $today = date('Y-m-d');

    foreach ($borrows as $b) {
        $deadline = $b['return_deadline'];
        $actualReturn = ($b['status'] === 'returned') ? $b['return_date'] : $today;

        if ($deadline && $actualReturn > $deadline) {
            $diff = strtotime($actualReturn) - strtotime($deadline);
            $days = floor($diff / (60 * 60 * 24));
            if ($days > 7) {
                $deducted += floor(($days - 1) / 7);
            }
        }
    }
    return max(0, $initialPoints - $deducted);
}

/* =========================
   MEMBER SEARCH (AUTOCOMPLETE MSSV)
========================= */
if ($action === 'member_search') {
    if (!can('inventory', 'view'))
        forbidden();

    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        json_ok(['data' => []]);
    }

    $stmt = $pdo->prepare("
        SELECT
            m.mssv,
            m.fullname,
            c.name AS class_name
        FROM members m
        LEFT JOIN classes c ON c.id = m.class_id
        WHERE
            m.mssv LIKE ?
            OR m.fullname LIKE ?
        ORDER BY m.mssv
        LIMIT 10
    ");

    $like = "%$q%";
    $stmt->execute([$like, $like]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($members as &$m) {
        $m['borrow_points'] = get_borrow_points($pdo, $m['mssv']);
    }

    json_ok([
        'data' => $members
    ]);
}

/* =========================
   STATS
========================= */
if ($action === 'stats') {
    if (!can('inventory', 'view'))
        forbidden();

    $row = $pdo->query("
        SELECT
          COUNT(*) AS total_items,
          SUM(total_quantity) AS total_quantity,
          SUM(borrowed_quantity) AS total_borrowed,
          SUM(CASE WHEN status='available' THEN total_quantity ELSE 0 END) AS available_quantity,
          SUM(CASE WHEN status='broken' THEN total_quantity ELSE 0 END) AS broken_quantity
        FROM inventory_items
    ")->fetch(PDO::FETCH_ASSOC);

    $borrowMonth = $pdo->query("
        SELECT COUNT(*) 
        FROM inventory_borrows
        WHERE MONTH(borrow_date)=MONTH(CURDATE())
          AND YEAR(borrow_date)=YEAR(CURDATE())
    ")->fetchColumn();

    json_ok([
        'total_items' => (int) $row['total_items'],
        'total_quantity' => (int) $row['total_quantity'],
        'total_borrowed' => (int) $row['total_borrowed'],
        'available_quantity' => (int) $row['available_quantity'],
        'broken_quantity' => (int) $row['broken_quantity'],
        'borrow_month' => (int) $borrowMonth
    ]);
}

if ($action === 'departments') {
    $rows = $pdo->query("
        SELECT id, name
        FROM departments
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'rows' => $rows
    ]);
    exit;
}

/* =========================
   FILTER OPTIONS
========================= */
if ($action === 'filters') {
    if (!can('inventory', 'view'))
        forbidden();

    $cats = $pdo->query("
        SELECT id, name
        FROM inventory_categories
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $types = [
        ['id' => 'equipment', 'name' => 'Thiết bị'],
        ['id' => 'item', 'name' => 'Đồ dùng']
    ];

    json_ok([
        'categories' => $cats,
        'types' => $types
    ]);
}


/* =========================
   LIST INVENTORY (PAGINATED)
========================= */
if ($action === 'list') {
    if (!can('inventory', 'view'))
        forbidden();

    $q = trim($_GET['q'] ?? '');
    $type = $_GET['type'] ?? '';
    $category = $_GET['category'] ?? '';

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, (int) ($_GET['per_page'] ?? 10));
    $offset = ($page - 1) * $perPage;

    $where = "WHERE 1";
    $params = [];

    if ($q !== '') {
        $where .= " AND (i.code LIKE ? OR i.name LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($type !== '') {
        $where .= " AND i.type=?";
        $params[] = $type;
    }
    if ($category !== '') {
        $where .= " AND i.category_id=?";
        $params[] = (int) $category;
    }


    $total = $pdo->prepare("
        SELECT COUNT(*) 
        FROM inventory_items i
        $where
    ");
    $total->execute($params);
    $totalRows = (int) $total->fetchColumn();

    $stmt = $pdo->prepare("
    SELECT 
        i.*,
        c.id   AS category_id,
        c.name AS category,
        d.name AS department_name
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON c.id = i.category_id
    LEFT JOIN departments d ON d.id = i.department_id
    $where
    ORDER BY i.created_at DESC
    LIMIT $perPage OFFSET $offset
");

    $stmt->execute($params);

    json_ok([
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $totalRows
    ]);
}

/* =========================
   HISTORY (BORROW LOG)
========================= */
if ($action === 'history') {
    if (!can('inventory', 'view'))
        forbidden();

    $q = trim($_GET['q'] ?? '');
    $status = $_GET['status'] ?? '';
    $inventoryId = (int) ($_GET['inventory_id'] ?? 0);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, (int) ($_GET['per_page'] ?? 10));
    $offset = ($page - 1) * $perPage;

    $where = "WHERE 1";
    $params = [];

    // Lọc theo inventory_id nếu có
    if ($inventoryId > 0) {
        $where .= " AND b.inventory_id = ?";
        $params[] = $inventoryId;
    }

    // 🔍 SEARCH
    if ($q !== '') {
        $where .= " AND (
            i.code LIKE ?
            OR i.name LIKE ?
            OR b.borrower_name LIKE ?
        )";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    // 🔄 STATUS FILTER
    if ($status === 'borrowing') {
        $where .= " AND b.status = 'borrowing'";
    } elseif ($status === 'returned') {
        $where .= " AND b.status = 'returned'";
    } elseif ($status === 'overdue') {
        $where .= " AND b.status = 'borrowing'
                    AND b.return_deadline IS NOT NULL
                    AND b.return_deadline < CURDATE()";
    }

    // TOTAL
    $stmtTotal = $pdo->prepare("
        SELECT COUNT(*)
        FROM inventory_borrows b
        JOIN inventory_items i ON i.id = b.inventory_id
        $where
    ");
    $stmtTotal->execute($params);
    $total = (int) $stmtTotal->fetchColumn();

    // LIMIT SQL
    $limitSql = "";
    if ($inventoryId <= 0) {
        $limitSql = "LIMIT $perPage OFFSET $offset";
    }

    // DATA
    $stmt = $pdo->prepare("
    SELECT
      b.*,
      i.code,
      i.name,
      m.mssv,

      -- lấy lớp từ đoàn viên
      c.name AS class_name

    FROM inventory_borrows b
    JOIN inventory_items i ON i.id = b.inventory_id

    -- JOIN đoàn viên theo MSSV
    LEFT JOIN members m
      ON b.borrower_name LIKE CONCAT(m.mssv, '%')

    LEFT JOIN classes c
      ON c.id = m.class_id

    $where
    ORDER BY
      (b.status='borrowing' AND b.return_deadline < CURDATE()) DESC,
      b.borrow_date DESC
    $limitSql
");

    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['borrow_points'] = !empty($r['mssv']) ? get_borrow_points($pdo, $r['mssv']) : null;
    }

    json_ok([
        'rows' => $rows,
        'total' => $total
    ]);
}


/* =========================
   CREATE INVENTORY (AUTO CODE)
========================= */
if ($action === 'create') {
    if (!can('inventory', 'create'))
        forbidden();

    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $totalQty = (int) ($_POST['total_quantity'] ?? 0);
    $status = $_POST['status'] ?? 'available';
    $note = trim($_POST['note'] ?? '');

    if ($name === '' || $type === '' || !$categoryId || $totalQty <= 0) {
        json_error("Thiếu hoặc sai dữ liệu bắt buộc");
    }

    try {
        $pdo->beginTransaction();

        // ✅ Sinh mã: TB001, TB002,...
        $next = (int) $pdo->query("
            SELECT IFNULL(MAX(CAST(SUBSTRING(code, 3) AS UNSIGNED)), 0) + 1
            FROM inventory_items
            WHERE code LIKE 'TB%'
        ")->fetchColumn();

        $code = 'TB' . str_pad($next, 3, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO inventory_items
                (code, name, type, category_id, total_quantity, status, note)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $code,
            $name,
            $type,
            $categoryId,
            $totalQty,
            $status,
            $note
        ]);

        $id = $pdo->lastInsertId();

        log_activity(
            'create',
            'inventory',
            'inventory_items',
            $id,
            "Thêm thiết bị $code - $name"
        );

        $pdo->commit();
        json_ok();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error($e->getMessage());
    }
}




/* =========================
   UPDATE INVENTORY
========================= */
if ($action === 'update') {
    if (!can('inventory', 'update'))
        forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id)
        json_error("ID không hợp lệ");

    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $totalQty = (int) ($_POST['total_quantity'] ?? 0);
    $status = $_POST['status'] ?? 'available';
    $note = trim($_POST['note'] ?? '');

    if ($name === '' || $type === '' || !$categoryId || $totalQty <= 0) {
        json_error("Thiếu hoặc sai dữ liệu");
    }

    try {
        $pdo->beginTransaction();

        // Lấy mã thiết bị để log
        $stmt = $pdo->prepare("
            SELECT code
            FROM inventory_items
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $code = $stmt->fetchColumn();

        if (!$code) {
            throw new Exception("Thiết bị không tồn tại");
        }

        $pdo->prepare("
            UPDATE inventory_items
            SET
                name = ?,
                type = ?,
                category_id = ?,
                total_quantity = ?,
                status = ?,
                note = ?
            WHERE id = ?
        ")->execute([
                    $name,
                    $type,
                    $categoryId,
                    $totalQty,
                    $status,
                    $note,
                    $id
                ]);

        log_activity(
            'update',
            'inventory',
            'inventory_items',
            $id,
            "Cập nhật thiết bị $code"
        );

        $pdo->commit();
        json_ok();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error($e->getMessage());
    }
}




/* =========================
   DELETE INVENTORY
========================= */
if ($action === 'delete') {
    if (!can('inventory', 'delete'))
        forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id)
        json_error("ID không hợp lệ");

    try {
        $pdo->beginTransaction();

        // Không cho xóa nếu đang có lượt mượn
        $check = $pdo->prepare("
            SELECT borrowed_quantity
            FROM inventory_items
            WHERE id=? FOR UPDATE
        ");
        $check->execute([$id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception("Thiết bị không tồn tại");
        }

        if ((int) $row['borrowed_quantity'] > 0) {
            throw new Exception("Thiết bị đang được mượn, không thể xóa");
        }

        $pdo->prepare("
            DELETE FROM inventory_items
            WHERE id=?
        ")->execute([$id]);

        log_activity('delete', 'inventory', 'inventory', $id, "Xóa thiết bị ID=$id");

        $pdo->commit();
        json_ok();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error($e->getMessage());
    }
}
if ($action === 'categories') {
    $rows = $pdo->query("
        SELECT id, name
        FROM inventory_categories
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    json_ok(['rows' => $rows]);
}
if ($action === 'category_create') {
    if (!can('inventory', 'create'))
        forbidden();

    $name = trim($_POST['name'] ?? '');
    if (!$name)
        json_error("Tên danh mục không được rỗng");

    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory_categories (name)
            VALUES (?)
        ");
        $stmt->execute([$name]);

        log_activity(
            'create',
            'inventory',
            'inventory_categories',
            null,
            "Tạo danh mục: $name"
        );

        json_ok();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error("Danh mục đã tồn tại");
        }
        json_error($e->getMessage());
    }
}

if ($action === 'category_update') {
    if (!can('inventory', 'update'))
        forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if (!$id || !$name)
        json_error("Thiếu dữ liệu");

    $stmt = $pdo->prepare("UPDATE inventory_categories SET name=? WHERE id=?");
    $stmt->execute([$name, $id]);
    log_activity(
        'update',
        'inventory',
        'inventory_categories',
        null,
        "Cập nhật danh mục: $name"
    );

    json_ok();
}
if ($action === 'category_delete') {
    if (!can('inventory', 'delete'))
        forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id)
        json_error("ID không hợp lệ");

    // chặn xóa nếu đang dùng
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM inventory_items WHERE category_id=?
    ");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        json_error("Danh mục đang được sử dụng");
    }

    // ✅ LẤY TÊN TRƯỚC KHI XÓA
    $stmt = $pdo->prepare("
        SELECT name FROM inventory_categories WHERE id=?
    ");
    $stmt->execute([$id]);
    $catName = $stmt->fetchColumn();

    if (!$catName) {
        json_error("Danh mục không tồn tại");
    }

    // ❌ XÓA SAU
    $pdo->prepare("
        DELETE FROM inventory_categories WHERE id=?
    ")->execute([$id]);

    log_activity(
        'delete',
        'inventory',
        'inventory_categories',
        $id,
        "Xóa danh mục: $catName"
    );

    json_ok();
}


/* =========================
   BORROW INVENTORY
========================= */
if ($action === 'borrow') {
    if (!can('inventory', 'update'))
        forbidden();

    $inventoryId = (int) ($_POST['inventory_id'] ?? 0);
    $qty = (int) ($_POST['quantity'] ?? 0);
    $borrower = trim($_POST['borrower_name'] ?? '');
    $unit = trim($_POST['borrower_unit'] ?? '');
    $borrowDate = $_POST['borrow_date'] ?? date('Y-m-d');
    $deadline = $_POST['return_deadline'] ?? null;
    $purpose = trim($_POST['purpose'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if (!$inventoryId || !$borrower || $qty <= 0) {
        json_error("Dữ liệu mượn không hợp lệ");
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
        SELECT id, status, total_quantity, borrowed_quantity
        FROM inventory_items
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$inventoryId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item['status'] === 'broken') {
            json_error("Thiết bị đang hỏng / bảo trì, không thể mượn");
        }

        if (!$item || ($item['total_quantity'] - $item['borrowed_quantity']) < $qty) {
            throw new Exception("Không đủ số lượng để mượn");
        }

        $pdo->prepare("
            UPDATE inventory_items
            SET borrowed_quantity = borrowed_quantity + ?
            WHERE id=?
        ")->execute([$qty, $inventoryId]);

        $pdo->prepare("
            INSERT INTO inventory_borrows
            (inventory_id,borrower_name,borrower_unit,quantity,borrow_date,return_deadline,purpose,note,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
                    $inventoryId,
                    $borrower,
                    $unit,
                    $qty,
                    $borrowDate,
                    $deadline,
                    $purpose,
                    $note,
                    $userId
                ]);

        log_activity('borrow', 'inventory', 'inventory', $inventoryId, "Mượn $qty");

        $pdo->commit();
        json_ok();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error($e->getMessage());
    }
}

/* =========================
   RETURN INVENTORY
========================= */
if ($action === 'return') {
    if (!can('inventory', 'update'))
        forbidden();

    $borrowId = (int) ($_POST['borrow_id'] ?? 0);
    if (!$borrowId)
        json_error("ID mượn không hợp lệ");

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT * FROM inventory_borrows
            WHERE id=? AND status='borrowing'
            FOR UPDATE
        ");
        $stmt->execute([$borrowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row)
            throw new Exception("Không tìm thấy lượt mượn");

        $pdo->prepare("
            UPDATE inventory_borrows
            SET status='returned', return_date=CURDATE()
            WHERE id=?
        ")->execute([$borrowId]);

        $pdo->prepare("
            UPDATE inventory_items
            SET borrowed_quantity = borrowed_quantity - ?
            WHERE id=?
        ")->execute([$row['quantity'], $row['inventory_id']]);

        log_activity('return', 'inventory', 'inventory', $row['inventory_id'], "Trả {$row['quantity']}");

        $pdo->commit();
        json_ok();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error($e->getMessage());
    }
}

/* =========================
   EXPORT EXCEL
========================= */
if ($action === 'export_inventory') {
    if (!can('inventory', 'view'))
        forbidden();

    $q = trim($_GET['q'] ?? '');
    $type = $_GET['type'] ?? '';
    $category = $_GET['category'] ?? '';

    $where = "WHERE 1";
    $params = [];

    if ($q !== '') {
        $where .= " AND (i.code LIKE ? OR i.name LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($type !== '') {
        $where .= " AND i.type=?";
        $params[] = $type;
    }
    if ($category !== '') {
        $where .= " AND i.category_id=?";
        $params[] = (int) $category;
    }

    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            c.name AS category_name,
            d.name AS department_name
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON c.id = i.category_id
        LEFT JOIN departments d ON d.id = i.department_id
        $where
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';
    
    $excelData = [
        ['DANH SÁCH THIẾT BỊ VÀ ĐỒ DÙNG'],
        [],
        ['STT', 'Mã thiết bị', 'Tên thiết bị', 'Loại', 'Danh mục', 'Tổng số lượng', 'Đang mượn', 'Sẵn có', 'Trạng thái', 'Ghi chú']
    ];

    $types = [
        'equipment' => 'Thiết bị',
        'item' => 'Đồ dùng'
    ];

    foreach ($rows as $index => $r) {
        $available = $r['total_quantity'] - $r['borrowed_quantity'];
        $statusText = 'Còn';
        if ($r['status'] === 'broken') {
            $statusText = 'Hỏng / Bảo trì';
        } elseif ($available <= 0) {
            $statusText = 'Hết';
        }

        $excelData[] = [
            $index + 1,
            $r['code'],
            $r['name'],
            $types[$r['type']] ?? $r['type'] ?? '-',
            $r['category_name'] ?? '-',
            (int)$r['total_quantity'],
            (int)$r['borrowed_quantity'],
            $available,
            $statusText,
            $r['note'] ?: '-'
        ];
    }

    clean_output_buffers();
    Shuchkin\SimpleXLSXGen::fromArray($excelData)->downloadAs('danh_sach_thiet_bi.xlsx');
    exit;
}

if ($action === 'export_history') {
    if (!can('inventory', 'view'))
        forbidden();

    $q = trim($_GET['q'] ?? '');
    $status = $_GET['status'] ?? '';

    $where = "WHERE 1";
    $params = [];

    if ($q !== '') {
        $where .= " AND (
            i.code LIKE ?
            OR i.name LIKE ?
            OR b.borrower_name LIKE ?
        )";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }

    if ($status === 'borrowing') {
        $where .= " AND b.status = 'borrowing'";
    } elseif ($status === 'returned') {
        $where .= " AND b.status = 'returned'";
    } elseif ($status === 'overdue') {
        $where .= " AND b.status = 'borrowing'
                    AND b.return_deadline IS NOT NULL
                    AND b.return_deadline < CURDATE()";
    }

    $stmt = $pdo->prepare("
        SELECT
          b.*,
          i.code,
          i.name,
          c.name AS class_name
        FROM inventory_borrows b
        JOIN inventory_items i ON i.id = b.inventory_id
        LEFT JOIN members m ON b.borrower_name LIKE CONCAT(m.mssv, '%')
        LEFT JOIN classes c ON c.id = m.class_id
        $where
        ORDER BY
          (b.status='borrowing' AND b.return_deadline < CURDATE()) DESC,
          b.borrow_date DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';

    $excelData = [
        ['LỊCH SỬ MƯỢN TRẢ THIẾT BỊ'],
        [],
        ['STT', 'Mã thiết bị', 'Tên thiết bị', 'Người mượn', 'Lớp / Đơn vị', 'Số lượng mượn', 'Ngày mượn', 'Hạn trả', 'Ngày trả', 'Trạng thái']
    ];

    foreach ($rows as $index => $r) {
        $borrower = $r['borrower_name'];
        if (strpos($borrower, '–') !== false) {
            $parts = explode('–', $borrower);
            $borrower = trim(implode('–', array_slice($parts, 1)));
        }

        $overdue = $r['status'] === 'borrowing' && $r['return_deadline'] && (date('Y-m-d') > $r['return_deadline']);
        $statusText = 'Đã trả';
        if ($r['status'] === 'borrowing') {
            $statusText = $overdue ? 'Quá hạn' : 'Chưa trả';
        }

        $excelData[] = [
            $index + 1,
            $r['code'],
            $r['name'],
            $borrower,
            $r['class_name'] ?: $r['borrower_unit'] ?: '-',
            (int)$r['quantity'],
            $r['borrow_date'],
            $r['return_deadline'] ?: '-',
            $r['return_date'] ?: '-',
            $statusText
        ];
    }

    clean_output_buffers();
    Shuchkin\SimpleXLSXGen::fromArray($excelData)->downloadAs('lich_su_muon_tra_thiet_bi.xlsx');
    exit;
}

if ($action === 'export_category') {
    if (!can('inventory', 'view'))
        forbidden();

    $stmt = $pdo->query("
        SELECT 
            c.id, 
            c.name, 
            COUNT(i.id) AS total_items
        FROM inventory_categories c
        LEFT JOIN inventory_items i ON i.category_id = c.id
        GROUP BY c.id, c.name
        ORDER BY c.name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';

    $excelData = [
        ['DANH MỤC THIẾT BỊ'],
        [],
        ['STT', 'Tên danh mục', 'Số lượng thiết bị liên kết']
    ];

    foreach ($rows as $index => $r) {
        $excelData[] = [
            $index + 1,
            $r['name'],
            (int)$r['total_items']
        ];
    }

    clean_output_buffers();
    Shuchkin\SimpleXLSXGen::fromArray($excelData)->downloadAs('danh_muc_thiet_bi.xlsx');
    exit;
}

json_error("Action không hợp lệ", 404);
