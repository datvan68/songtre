<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

auth_guard();
function normalizeRolePerms(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r['permission_id']] = [
            'view' => (int) $r['can_view'],
            'create' => (int) $r['can_create'],
            'update' => (int) $r['can_update'],
            'review' => (int) $r['can_review'],
            'delete' => (int) $r['can_delete'],
            'print' => (int) $r['can_print'],
        ];
    }
    ksort($out);
    return $out;
}

function forbidden()
{
    http_response_code(403);
    echo json_encode(['ok' => 0, 'error' => 'Forbidden']);
    exit;
}
$action = $_GET['action'] ?? '';
function getRoleName(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare("SELECT name FROM roles WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: 'Không rõ';
}

/* =====================================================
   LIST ROLES
===================================================== */
if ($action === 'list') {
    if (!can('roles', 'view'))
        forbidden();

    try {
        $rows = $pdo->query("
      SELECT id, name, description, is_active
      FROM roles
      ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'rows' => $rows]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* =====================================================
   CREATE ROLE
===================================================== */
if ($action === 'create') {
    if (!can('roles', 'create'))
        forbidden();

    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if (!$name) {
        echo json_encode(['ok' => false, 'error' => 'Thiếu tên role']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
      INSERT INTO roles (name, description)
      VALUES (?,?)
    ");
        $stmt->execute([$name, $desc]);

        log_activity(
            'create',
            'roles',
            'Vai trò',
            null,
            'Tạo vai trò: ' . $name
        );

        echo json_encode(['ok' => true]);


    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


/* =====================================================
   UPDATE ROLE
===================================================== */
if ($action === 'update') {
    if (!can('roles', 'update'))
        forbidden();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $active = isset($_POST['is_active']) ? 1 : 0;

    if (!$id || !$name) {
        echo json_encode(['ok' => false, 'error' => 'Thiếu dữ liệu']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
      UPDATE roles
      SET name=?, description=?, is_active=?
      WHERE id=?
    ");
        $oldName = getRoleName($pdo, $id);

        $stmt->execute([$name, $desc, $active, $id]);

        log_activity(
            'update',
            'roles',
            'Vai trò',
            null,
            'Cập nhật vai trò: ' . $oldName . ' → ' . $name
        );

        echo json_encode(['ok' => true]);


    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


/* =====================================================
   DELETE ROLE
===================================================== */
if ($action === 'delete') {
    if (!can('roles', 'delete'))
        forbidden();

    $id = (int) ($_POST['id'] ?? 0);

    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'Thiếu ID']);
        exit;
    }

    try {
        $roleName = getRoleName($pdo, $id);

        $pdo->prepare("DELETE FROM roles WHERE id=?")->execute([$id]);

        log_activity(
            'delete',
            'roles',
            'Vai trò',
            null,
            'Xóa vai trò: ' . $roleName
        );

        echo json_encode(['ok' => true]);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* =====================================================
   GET PERMISSIONS MATRIX FOR ROLE
===================================================== */
if ($action === 'permissions') {
    if (!can('roles', 'view'))
        forbidden();

    $roleId = (int) ($_GET['role_id'] ?? 0);
    if (!$roleId) {
        echo json_encode(['ok' => false, 'error' => 'Thiếu role_id']);
        exit;
    }

    try {
        $sql = "
            SELECT
            p.id,
            p.name,
            p.parent_id,

            COALESCE(rp.can_view, 0)   AS can_view,
            COALESCE(rp.can_create, 0) AS can_create,
            COALESCE(rp.can_update, 0) AS can_update,
            COALESCE(rp.can_review, 0) AS can_review,
            COALESCE(rp.can_delete, 0) AS can_delete,
            COALESCE(rp.can_print, 0)  AS can_print

            FROM permissions p
            LEFT JOIN role_permissions rp
            ON rp.permission_id = p.id
            AND rp.role_id = ?

            ORDER BY
            COALESCE(p.parent_id, p.id),
            p.parent_id IS NOT NULL,
            p.sort_order,
            p.id
            ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$roleId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'rows' => $rows]);


    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* =====================================================
   SAVE ROLE PERMISSIONS
===================================================== */
if ($action === 'save_permissions') {
    if (!can('roles', 'update'))
        forbidden();

    try {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $perms = $_POST['perms'] ?? [];

        if (!$roleId) {
            throw new Exception("Thiếu role_id");
        }

        // ===============================
        // 📌 LẤY SNAPSHOT CŨ
        // ===============================
        $oldStmt = $pdo->prepare("
            SELECT permission_id, can_view, can_create, can_update, can_review, can_delete, can_print
            FROM role_permissions
            WHERE role_id=?
        ");
        $oldStmt->execute([$roleId]);
        $oldPerms = normalizeRolePerms($oldStmt->fetchAll(PDO::FETCH_ASSOC));

        // ===============================
        // 📌 BUILD SNAPSHOT MỚI
        // ===============================
        $newRows = [];
        foreach ($perms as $pid => $p) {
            $newRows[] = [
                'permission_id' => (int) $pid,
                'can_view' => !empty($p['view']),
                'can_create' => !empty($p['create']),
                'can_update' => !empty($p['update']),
                'can_review' => !empty($p['review']),
                'can_delete' => !empty($p['delete']),
                'can_print' => !empty($p['print']),
            ];
        }
        $newPerms = normalizeRolePerms($newRows);

        // ===============================
        // 💾 SAVE
        // ===============================
        $pdo->beginTransaction();

        $pdo->prepare("DELETE FROM role_permissions WHERE role_id=?")
            ->execute([$roleId]);

        $stmt = $pdo->prepare("
            INSERT INTO role_permissions
            (role_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
            VALUES (?,?,?,?,?,?,?,?)
        ");

        foreach ($newRows as $r) {
            $stmt->execute([
                $roleId,
                $r['permission_id'],
                (int) $r['can_view'],
                (int) $r['can_create'],
                (int) $r['can_update'],
                (int) $r['can_review'],
                (int) $r['can_delete'],
                (int) $r['can_print'],
            ]);
        }

        // ===============================
        // 🧾 LOG (CHỈ KHI CÓ THAY ĐỔI)
        // ===============================
        if ($oldPerms !== $newPerms) {
            $roleName = getRoleName($pdo, $roleId);
            log_activity(
                'update',
                'roles',
                'Vai trò',
                null,
                'Cập nhật phân quyền cho vai trò: ' . $roleName
            );
        }

        $pdo->commit();
        unset($_SESSION['user_cache']);

        echo json_encode(['ok' => true]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

