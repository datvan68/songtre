<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

auth_guard();
header('Content-Type: application/json; charset=utf-8');

$user = auth_user();
$userId = (int)($user['id'] ?? 0);

$canReviewAny =
    can('nominations', 'review')
 || can('schedule', 'review')
 || can('campaigns', 'update');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // =====================================================
        // LIST
        // =====================================================
        case 'list':

            if ($canReviewAny) {
                // ✅ thấy cả thông báo hệ thống + cá nhân
                $stmt = $pdo->prepare("
                    SELECT id, message, link, created_at, is_read
                    FROM notifications
                    WHERE (user_id IS NULL OR user_id = ?)
                    ORDER BY id DESC
                ");
                $stmt->execute([$userId]);
            } else {
                // notification cá nhân
                $stmt = $pdo->prepare("
                    SELECT id, message, link, created_at, is_read
                    FROM notifications
                    WHERE user_id = ?
                    ORDER BY id DESC
                ");
                $stmt->execute([$userId]);
            }

            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // =====================================================
        // LATEST
        // =====================================================
        case 'latest':

            if ($canReviewAny) {
                $stmt = $pdo->prepare("
                    SELECT id, message, link
                    FROM notifications
                    WHERE (user_id IS NULL OR user_id = ?)
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, message, link
                    FROM notifications
                    WHERE user_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
            }

            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
            break;

        // =====================================================
        // COUNT UNREAD
        // =====================================================
        case 'count_unread':

            if ($canReviewAny) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM notifications
                    WHERE (user_id IS NULL OR user_id = ?)
                      AND is_read = 0
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM notifications
                    WHERE user_id = ?
                      AND is_read = 0
                ");
                $stmt->execute([$userId]);
            }

            echo json_encode(['count' => (int)$stmt->fetchColumn()]);
            break;

        // =====================================================
        // MARK ALL READ
        // =====================================================
        case 'mark_all_read':

            if ($canReviewAny) {
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE (user_id IS NULL OR user_id = ?)
                      AND is_read = 0
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE user_id = ?
                      AND is_read = 0
                ");
                $stmt->execute([$userId]);
            }

            echo json_encode(['ok' => true]);
            break;

        // =====================================================
        // MARK SINGLE
        // =====================================================
        case 'mark_single':

            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['error' => 'Missing id']);
                exit;
            }

            if ($canReviewAny) {
                // ✅ chỉ mark cái thuộc system hoặc thuộc user hiện tại
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE id = ?
                      AND (user_id IS NULL OR user_id = ?)
                ");
                $stmt->execute([$id, $userId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE id = ?
                      AND user_id = ?
                ");
                $stmt->execute([$id, $userId]);
            }

            echo json_encode(['success' => true]);
            break;

        // =====================================================
        // DELETE SINGLE
        // =====================================================
        case 'delete':

            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['error' => 'Missing id']);
                exit;
            }

            if ($canReviewAny) {
                $stmt = $pdo->prepare("
                    DELETE FROM notifications
                    WHERE id = ?
                      AND (user_id IS NULL OR user_id = ?)
                ");
                $stmt->execute([$id, $userId]);
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM notifications
                    WHERE id = ?
                      AND user_id = ?
                ");
                $stmt->execute([$id, $userId]);
            }

            echo json_encode(['success' => true]);
            break;

        // =====================================================
        // DELETE SELECTED
        // =====================================================
        case 'delete_selected':

            $ids = $_POST['ids'] ?? '';
            if (!$ids) {
                echo json_encode(['error' => 'Missing ids']);
                exit;
            }

            $idArr = array_filter(array_map('intval', explode(',', $ids)));
            if (!$idArr) {
                echo json_encode(['error' => 'Invalid ids']);
                exit;
            }

            $in = implode(',', array_fill(0, count($idArr), '?'));

            if ($canReviewAny) {
                $sql = "
                    DELETE FROM notifications
                    WHERE id IN ($in)
                      AND is_read = 1
                      AND (user_id IS NULL OR user_id = ?)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([...$idArr, $userId]);
            } else {
                $sql = "
                    DELETE FROM notifications
                    WHERE id IN ($in)
                      AND is_read = 1
                      AND user_id = ?
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([...$idArr, $userId]);
            }

            echo json_encode(['success' => true]);
            break;

        // =====================================================
        // CREATE (internal use)
        // =====================================================
        case 'create':

            $uid = $_POST['user_id'] ?? null;
            $message = trim($_POST['message'] ?? '');
            $link = trim($_POST['link'] ?? '');

            if (!$message) {
                echo json_encode(['error' => 'Missing message']);
                exit;
            }

            // strip domain
            $link = preg_replace('/https?:\/\/[^\/]+/i', '', $link);
            if ($link && $link[0] !== '/') $link = '/' . $link;
            if ($link === '') $link = null;

            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, link)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$uid, $message, $link]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
