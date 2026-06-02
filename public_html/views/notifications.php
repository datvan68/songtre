<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
auth_guard();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$isAdmin = is_admin();

try {
    switch ($action) {

        // === Lấy danh sách thông báo
        case 'list':
            if ($isAdmin) {
                $stmt = $pdo->query("
          SELECT id, message, link, created_at, is_read
          FROM notifications
          WHERE user_id IS NULL
          ORDER BY id DESC
        ");
            } else {
                $stmt = $pdo->prepare("
          SELECT id, message, link, created_at, is_read
          FROM notifications
          WHERE user_id = ?
          ORDER BY id DESC
        ");
                $stmt->execute([$user_id]);
            }
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // === Lấy thông báo mới nhất
        case 'latest':
            if ($isAdmin) {
                $stmt = $pdo->query("
          SELECT id, message, link
          FROM notifications
          WHERE user_id IS NULL
          ORDER BY id DESC LIMIT 1
        ");
            } else {
                $stmt = $pdo->prepare("
          SELECT id, message, link
          FROM notifications
          WHERE user_id = ?
          ORDER BY id DESC LIMIT 1
        ");
                $stmt->execute([$user_id]);
            }
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
            break;

        // === Đếm số lượng chưa đọc
        case 'count_unread':
            if ($isAdmin) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id IS NULL AND is_read = 0");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$user_id]);
            }
            echo json_encode(['count' => (int) $stmt->fetchColumn()]);
            break;

        // === Đánh dấu tất cả đã đọc
        case 'mark_all_read':
            if ($isAdmin)
                $pdo->exec("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0");
            else {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$user_id]);
            }
            echo json_encode(['ok' => true]);
            break;

        // === Đánh dấu 1 thông báo đã đọc
        case 'mark_single':
            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id && $user_id) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
                $stmt->execute([$id, $user_id]);
                echo json_encode(['success' => true]);
            } else {
                throw new Exception("Thiếu ID hoặc user");
            }
            break;

        // === Xóa 1 thông báo
        case 'delete':
            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id && $user_id) {
                if ($isAdmin) {
                    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
                    $stmt->execute([$id]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
                    $stmt->execute([$id, $user_id]);
                }
                echo json_encode(['success' => true]);
            } else {
                throw new Exception("Thiếu ID hoặc user");
            }
            break;

        // === Xóa nhiều thông báo
        case 'delete_selected':
            $ids = $_POST['ids'] ?? '';
            if (!$ids)
                throw new Exception("Thiếu danh sách ID");

            $idArray = array_map('intval', explode(',', $ids));
            if (!count($idArray))
                throw new Exception("Không có thông báo hợp lệ");

            // xây placeholder (?, ?, ?, ...)
            $in = implode(',', array_fill(0, count($idArray), '?'));

            if ($isAdmin) {
                // admin: xoá noti admin (user_id IS NULL)
                $sql = "DELETE FROM notifications 
                WHERE id IN ($in) 
                AND is_read = 1 
                AND (user_id IS NULL OR user_id = ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([...$idArray, $user_id]);

            } else {
                // user: chỉ được xoá noti của mình
                $sql = "DELETE FROM notifications 
                WHERE id IN ($in) 
                AND is_read = 1 
                AND user_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([...$idArray, $user_id]);
            }

            echo json_encode(['success' => true]);
            break;


        // === Tạo thông báo mới (dành cho controller khác gọi)
        case 'create':
            $uid = $_POST['user_id'] ?? null;
            $message = $_POST['message'] ?? '';
            $link = $_POST['link'] ?? '';

            // XÓA domain/IP
            // Ví dụ: http://localhost/... -> /... 
            //        http://192.168.x.x/... -> /...
            //        https://domain.com/... -> /...
            $link = preg_replace('/https?:\/\/[^\/]+/i', '', $link);

            // NẾU KHÔNG BẮT ĐẦU BẰNG "/" -> tự thêm vào
            if ($link && $link[0] !== '/') {
                $link = '/' . $link;
            }

            // RỖNG = NULL
            if (trim($link) === '') {
                $link = null;
            }

            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
            $stmt->execute([$uid, $message, $link]);
            echo json_encode(['status' => 'created']);
            break;


        // === Mặc định
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
