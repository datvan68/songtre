<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

function forbidden()
{
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Lấy label theo id để log (hỗ trợ nhiều bảng có cột khác nhau).
 */
function getLabelById(PDO $pdo, string $table, int $id): string
{
    if ($id <= 0) return 'Không rõ';

    // map table -> column label
    $allowed = [
        'reward_positions' => 'name',
        'chidoan_groups'   => 'name',
        'departments'      => 'name',
        'reward_titles'    => 'name',
        'school_years'     => 'year_label',
    ];

    if (!isset($allowed[$table])) return 'Không rõ';

    $col = $allowed[$table];

    $stmt = $pdo->prepare("SELECT {$col} FROM {$table} WHERE id=? LIMIT 1");
    $stmt->execute([$id]);

    $val = $stmt->fetchColumn();
    return $val ? (string)$val : 'Không rõ';
}

function validateSchoolYearLabel(string $label): bool
{
    // Format: 2025-2026
    return (bool)preg_match('/^\d{4}\-\d{4}$/', $label);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* =========================
   ACL: reward_units
========================= */
$viewActions = [
    'list_positions',
    'list_groups',
    'list_departments',
    'list_chidoans',
    'list_units',
    'list_titles',

    // ✅ NEW: school_years
    'list_school_years',
];

$createActions = [
    'add_position',
    'add_group',
    'add_department',
    'add_chidoan',
    'add_title',

    // ✅ NEW: school_years
    'add_school_year',
];

$updateActions = [
    'update_position',
    'update_group',
    'update_department',
    'update_chidoan',
    'update_title',
    'toggle_title',

    // ✅ NEW: school_years
    'update_school_year',
    'toggle_school_year',
];

$deleteActions = [
    'delete_position',
    'delete_group',
    'delete_department',
    'delete_chidoan',
    'delete_title',

    // ✅ NEW: school_years
    'delete_school_year',
];

if (in_array($action, $viewActions) && !can('reward_units', 'view')) {
    forbidden();
}
if (in_array($action, $createActions) && !can('reward_units', 'create')) {
    forbidden();
}
if (in_array($action, $updateActions) && !can('reward_units', 'update')) {
    forbidden();
}
if (in_array($action, $deleteActions) && !can('reward_units', 'delete')) {
    forbidden();
}

/* ======================================================
   ✅ TOGGLE TITLE (reward_titles)
====================================================== */
if ($action === 'toggle_title') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => 0, 'error' => 'Thiếu danh hiệu'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1) lấy trạng thái + tên trước
    $stmt = $pdo->prepare("
        SELECT name, is_active
        FROM reward_titles
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => 0, 'error' => 'Danh hiệu không tồn tại'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) toggle
    $pdo->prepare("
        UPDATE reward_titles
        SET is_active = IF(is_active = 1, 0, 1)
        WHERE id = ?
    ")->execute([$id]);

    // 3) log
    log_activity(
        'update',
        'reward_units',
        'Danh hiệu',
        null,
        ($row['is_active'] ? 'Ẩn' : 'Hiện') . ' danh hiệu: ' . $row['name']
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ======================================================
   ✅ 0) NĂM HỌC (school_years) - NEW
====================================================== */

/* LIST */
if ($action === 'list_school_years') {
    echo json_encode(
        $pdo->query("
            SELECT id, year_label, is_active, created_at, updated_at
            FROM school_years
            ORDER BY year_label DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/* ADD */
if ($action === 'add_school_year') {
    $year_label = trim($_POST['year_label'] ?? '');

    if ($year_label === '') {
        echo json_encode(['ok' => 0, 'error' => 'Năm học không được trống'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!validateSchoolYearLabel($year_label)) {
        echo json_encode(['ok' => 0, 'error' => 'Năm học phải đúng format: 2025-2026'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->prepare("
            INSERT INTO school_years(year_label, is_active)
            VALUES (?, 1)
        ")->execute([$year_label]);

        log_activity(
            'create',
            'reward_units',
            'Năm học',
            null,
            'Thêm năm học: ' . $year_label
        );

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Năm học đã tồn tại'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* UPDATE */
if ($action === 'update_school_year') {
    $id = (int)($_POST['id'] ?? 0);
    $year_label = trim($_POST['year_label'] ?? '');

    if ($id <= 0 || $year_label === '') {
        echo json_encode(['ok' => 0, 'error' => 'Dữ liệu không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!validateSchoolYearLabel($year_label)) {
        echo json_encode(['ok' => 0, 'error' => 'Năm học phải đúng format: 2025-2026'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->prepare("
            UPDATE school_years
            SET year_label=?
            WHERE id=?
        ")->execute([$year_label, $id]);

        log_activity(
            'update',
            'reward_units',
            'Năm học',
            null,
            'Cập nhật năm học: ' . $year_label
        );

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể cập nhật (có thể bị trùng năm học)'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* TOGGLE ACTIVE */
if ($action === 'toggle_school_year') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => 0, 'error' => 'Thiếu năm học'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT year_label, is_active
        FROM school_years
        WHERE id=?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => 0, 'error' => 'Năm học không tồn tại'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("
        UPDATE school_years
        SET is_active = IF(is_active=1,0,1)
        WHERE id=?
    ")->execute([$id]);

    log_activity(
        'update',
        'reward_units',
        'Năm học',
        null,
        ($row['is_active'] ? 'Ẩn' : 'Hiện') . ' năm học: ' . $row['year_label']
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* DELETE */
if ($action === 'delete_school_year') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => 0, 'error' => 'Thiếu năm học'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Chặn xoá nếu đang được dùng trong campaigns (an toàn dữ liệu)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE school_year_id=?");
    $stmt->execute([$id]);
    $used = (int)$stmt->fetchColumn();

    if ($used > 0) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể xoá: năm học đang được dùng trong phong trào'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $label = getLabelById($pdo, 'school_years', $id);

        $pdo->prepare("DELETE FROM school_years WHERE id=?")->execute([$id]);

        log_activity(
            'delete',
            'reward_units',
            'Năm học',
            null,
            'Xóa năm học: ' . $label
        );

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể xoá năm học'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ======================================================
   1) CHỨC VỤ (reward_positions)
====================================================== */

/* LIST */
if ($action === 'list_positions') {
    echo json_encode(
        $pdo->query("
            SELECT * 
            FROM reward_positions 
            ORDER BY sort_order ASC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/* ADD */
if ($action === 'add_position') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Tên chức vụ không được trống'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("
        INSERT INTO reward_positions(name, sort_order)
        VALUES (?, ?)
    ")->execute([
        $name,
        (int)($_POST['sort_order'] ?? 0)
    ]);

    log_activity('create', 'reward_units', 'Chức vụ', null, 'Thêm chức vụ: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* UPDATE */
if ($action === 'update_position') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($id <= 0 || $name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Dữ liệu không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("
        UPDATE reward_positions
        SET name=?, sort_order=?
        WHERE id=?
    ")->execute([
        $name,
        (int)($_POST['sort_order'] ?? 0),
        $id
    ]);

    log_activity('update', 'reward_units', 'Chức vụ', null, 'Cập nhật chức vụ: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* DELETE */
if ($action === 'delete_position') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => 0, 'error' => 'Thiếu dữ liệu'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $name = getLabelById($pdo, 'reward_positions', $id);

        $pdo->prepare("DELETE FROM reward_positions WHERE id=?")->execute([$id]);

        log_activity('delete', 'reward_units', 'Chức vụ', null, 'Xóa chức vụ: ' . $name);

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể xoá: chức vụ đang được sử dụng'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ======================================================
   2) NHÓM CHI ĐOÀN (chidoan_groups)
====================================================== */

/* LIST */
if ($action === 'list_groups') {
    echo json_encode(
        $pdo->query("
            SELECT * 
            FROM chidoan_groups 
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_ASSOC),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/* ADD */
if ($action === 'add_group') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Tên nhóm không được trống'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("INSERT INTO chidoan_groups(name) VALUES (?)")->execute([$name]);

    log_activity('create', 'reward_units', 'Nhóm chi đoàn', null, 'Thêm nhóm chi đoàn: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* UPDATE */
if ($action === 'update_group') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($id <= 0 || $name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Dữ liệu không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("UPDATE chidoan_groups SET name=? WHERE id=?")->execute([$name, $id]);

    log_activity('update', 'reward_units', 'Nhóm chi đoàn', null, 'Cập nhật nhóm chi đoàn: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* DELETE */
if ($action === 'delete_group') {
    $id = (int)($_POST['id'] ?? 0);

    try {
        $name = getLabelById($pdo, 'chidoan_groups', $id);

        $pdo->prepare("DELETE FROM chidoan_groups WHERE id=?")->execute([$id]);

        log_activity('delete', 'reward_units', 'Nhóm chi đoàn', null, 'Xóa nhóm chi đoàn: ' . $name);

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể xoá: nhóm đang có chi đoàn'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ======================================================
   4) PHÒNG BAN (departments - type='phong')
====================================================== */

/* LIST */
if ($action === 'list_departments') {
    echo json_encode(
        $pdo->query("
            SELECT id, name
            FROM departments
            WHERE type = 'phong'
            ORDER BY id DESC, name ASC
        ")->fetchAll(PDO::FETCH_ASSOC),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/* ADD */
if ($action === 'add_department') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Tên phòng ban không được trống'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("INSERT INTO departments(name, type) VALUES (?, 'phong')")->execute([$name]);

    log_activity('create', 'reward_units', 'Phòng ban', null, 'Thêm phòng ban: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* UPDATE */
if ($action === 'update_department') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($id <= 0 || $name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Dữ liệu không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("
        UPDATE departments
        SET name=?
        WHERE id=? AND type='phong'
    ")->execute([$name, $id]);

    log_activity('update', 'reward_units', 'Phòng ban', null, 'Cập nhật phòng ban: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* DELETE */
if ($action === 'delete_department') {
    $id = (int)($_POST['id'] ?? 0);

    // check FK
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM chidoans
        WHERE unit_id = ? AND unit_type = 'phong'
    ");
    $stmt->execute([$id]);

    if ((int)$stmt->fetchColumn() > 0) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể xoá: phòng ban đang có chi đoàn'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $name = getLabelById($pdo, 'departments', $id);

        $pdo->prepare("DELETE FROM departments WHERE id=? AND type='phong'")->execute([$id]);

        log_activity('delete', 'reward_units', 'Phòng ban', null, 'Xóa phòng ban: ' . $name);

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể xoá phòng ban'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ======================================================
   3) CHI ĐOÀN (chidoans)
====================================================== */

/* LIST */
if ($action === 'list_chidoans') {
    $stmt = $pdo->query("
        SELECT 
            c.id,
            c.group_id,
            c.unit_id,
            c.unit_type,
            g.name AS group_name,
            CONCAT(
              CASE 
                WHEN d.type = 'khoa' THEN 'Khoa '
                WHEN d.type = 'phong' THEN 'Phòng '
                ELSE ''
              END,
              d.name
            ) AS display_name
        FROM chidoans c
        JOIN chidoan_groups g ON g.id = c.group_id
        JOIN departments d 
          ON d.id = c.unit_id 
         AND d.type = c.unit_type
        WHERE c.is_active = 1
        ORDER BY g.name ASC, d.name ASC
    ");

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ADD */
if ($action === 'add_chidoan') {
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $unitType = $_POST['unit_type'] ?? '';
    $groupId = (int)($_POST['group_id'] ?? 0);

    if (!$unitId || !$unitType || !$groupId) {
        echo json_encode(['ok' => 0, 'error' => 'Thiếu dữ liệu'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // kiểm tra đơn vị tồn tại
    $stmt = $pdo->prepare("
        SELECT 1
        FROM departments
        WHERE id = ? AND type = ?
    ");
    $stmt->execute([$unitId, $unitType]);

    if (!$stmt->fetchColumn()) {
        echo json_encode(['ok' => 0, 'error' => 'Đơn vị không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("
        INSERT INTO chidoans (group_id, unit_type, unit_id, is_active)
        VALUES (?, ?, ?, 1)
    ")->execute([$groupId, $unitType, $unitId]);

    log_activity(
        'create',
        'reward_units',
        'Chi đoàn',
        null,
        'Thêm chi đoàn cho đơn vị ' . ($unitType === 'khoa' ? 'Khoa' : 'Phòng')
    );

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* UPDATE */
if ($action === 'update_chidoan') {
    $id = (int)($_POST['id'] ?? 0);
    $groupId = (int)($_POST['group_id'] ?? 0);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $unitType = $_POST['unit_type'] ?? '';

    if (!$id || !$groupId || !$unitId || !$unitType) {
        echo json_encode(['ok' => 0, 'error' => 'Dữ liệu không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // kiểm tra đơn vị tồn tại
    $stmt = $pdo->prepare("SELECT 1 FROM departments WHERE id=? AND type=?");
    $stmt->execute([$unitId, $unitType]);

    if (!$stmt->fetchColumn()) {
        echo json_encode(['ok' => 0, 'error' => 'Đơn vị không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // lấy tên chi đoàn cũ để log
    $stmtName = $pdo->prepare("
        SELECT 
            CONCAT(
                g.name, ' - ',
                CASE 
                    WHEN d.type = 'khoa' THEN 'Khoa '
                    WHEN d.type = 'phong' THEN 'Phòng '
                    ELSE ''
                END,
                d.name
            ) AS display_name
        FROM chidoans c
        JOIN chidoan_groups g ON g.id = c.group_id
        JOIN departments d ON d.id = c.unit_id AND d.type = c.unit_type
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmtName->execute([$id]);
    $oldName = $stmtName->fetchColumn() ?: 'Chi đoàn không rõ';

    $pdo->prepare("
        UPDATE chidoans
        SET group_id=?, unit_type=?, unit_id=?
        WHERE id=?
    ")->execute([$groupId, $unitType, $unitId, $id]);

    log_activity('update', 'reward_units', 'Chi đoàn', null, 'Cập nhật chi đoàn: ' . $oldName);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* DELETE */
if ($action === 'delete_chidoan') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['ok' => 0, 'error' => 'Thiếu ID chi đoàn'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // lấy tên chi đoàn trước khi xoá
        $stmt = $pdo->prepare("
            SELECT 
                CONCAT(
                    g.name, ' - ',
                    CASE 
                        WHEN d.type = 'khoa' THEN 'Khoa '
                        WHEN d.type = 'phong' THEN 'Phòng '
                        ELSE ''
                    END,
                    d.name
                ) AS display_name
            FROM chidoans c
            JOIN chidoan_groups g ON g.id = c.group_id
            JOIN departments d ON d.id = c.unit_id AND d.type = c.unit_type
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $displayName = $stmt->fetchColumn() ?: 'Chi đoàn không rõ';

        $pdo->prepare("DELETE FROM chidoans WHERE id=?")->execute([$id]);

        log_activity('delete', 'reward_units', 'Chi đoàn', null, 'Xóa chi đoàn: ' . $displayName);

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Không thể xoá chi đoàn'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ======================================================
   ĐƠN VỊ (KHOA + PHÒNG BAN)
====================================================== */
if ($action === 'list_units') {
    $units = $pdo->query("
        SELECT id, name, type
        FROM departments
        WHERE type IN ('khoa', 'phong')
        ORDER BY type ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($units, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ======================================================
   5) DANH HIỆU ĐỀ NGHỊ (reward_titles)
====================================================== */

/* LIST */
if ($action === 'list_titles') {
    echo json_encode(
        $pdo->query("
            SELECT *
            FROM reward_titles
            ORDER BY sort_order ASC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

/* ADD */
if ($action === 'add_title') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Tên danh hiệu không được trống'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("
        INSERT INTO reward_titles(name, sort_order)
        VALUES (?, ?)
    ")->execute([
        $name,
        (int)($_POST['sort_order'] ?? 0)
    ]);

    log_activity('create', 'reward_units', 'Danh hiệu', null, 'Thêm danh hiệu: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* UPDATE */
if ($action === 'update_title') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($id <= 0 || $name === '') {
        echo json_encode(['ok' => 0, 'error' => 'Dữ liệu không hợp lệ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->prepare("
        UPDATE reward_titles
        SET name=?, sort_order=?
        WHERE id=?
    ")->execute([
        $name,
        (int)($_POST['sort_order'] ?? 0),
        $id
    ]);

    log_activity('update', 'reward_units', 'Danh hiệu', null, 'Cập nhật danh hiệu: ' . $name);

    echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    exit;
}

/* DELETE */
if ($action === 'delete_title') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['ok' => 0, 'error' => 'Thiếu danh hiệu'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $name = getLabelById($pdo, 'reward_titles', $id);

        $pdo->prepare("DELETE FROM reward_titles WHERE id=?")->execute([$id]);

        log_activity('delete', 'reward_units', 'Danh hiệu', null, 'Xóa danh hiệu: ' . $name);

        echo json_encode(['ok' => 1], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['ok' => 0, 'error' => 'Danh hiệu đã được dùng trong hồ sơ'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ======================================================
   ❌ ACTION KHÔNG HỢP LỆ
====================================================== */
echo json_encode(['ok' => 0, 'error' => 'Bad action'], JSON_UNESCAPED_UNICODE);
exit;
