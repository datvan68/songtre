<?php
// display_errors controlled centrally in index.php / bootstrap
error_reporting(E_ALL);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);

// ❌ KHÔNG dùng auth_guard() cho API
if (empty($_SESSION['user_id'])) {
  echo json_encode([
    'success' => false,
    'error' => 'Chưa đăng nhập'
  ]);
  exit;
}

try {

  /* =====================================================
     1) USER ĐĂNG KÝ PHONG TRÀO
  ===================================================== */
  if ($action === 'register') {
    $user_id = $_SESSION['user_id'] ?? 0;
    $cid = (int) ($_POST['campaign_id'] ?? 0);

    if (!$cid)
      throw new Exception("Thiếu ID phong trào");

    // Kiểm tra đã đăng ký chưa
    $check = $pdo->prepare("
            SELECT status 
            FROM registrations 
            WHERE user_id=? AND campaign_id=?
        ");
    $check->execute([$user_id, $cid]);
    $reg = $check->fetch(PDO::FETCH_ASSOC);

    if ($reg) {
      switch ($reg['status']) {
        case 'approved':
          $msg = "Bạn đã đăng ký và đang tham gia phong trào này.";
          break;
        case 'excellent':
          $msg = "Bạn đã HOÀN THÀNH XUẤT SẮC phong trào này.";
          break;
        case 'good':
          $msg = "Bạn đã HOÀN THÀNH TỐT phong trào này.";
          break;
        case 'incomplete':
          $msg = "Bạn đã tham gia phong trào này.";
          break;
        default:
          $msg = "Bạn đã đăng ký phong trào này rồi.";
      }

      throw new Exception($msg);
    }

    // THÊM ĐĂNG KÝ MỚI (auto duyệt)
    $stmt = $pdo->prepare("
            INSERT INTO registrations (user_id, campaign_id, registered_at, status)
            VALUES (?, ?, NOW(), 'approved')
        ");
    $stmt->execute([$user_id, $cid]);

    echo json_encode([
      'success' => true,
      'message' => 'Đăng ký thành công! Bạn đã được duyệt tham gia.'
    ]);
    exit;
  }


  /* =====================================================
       2) ADMIN REVIEW + AUTO BONUS
  ===================================================== */
  if ($action === 'review' && is_admin()) {

    $id = (int) $_POST['id'];
    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    $valid_status = ['excellent', 'good', 'incomplete'];
    if (!in_array($status, $valid_status))
      throw new Exception("Trạng thái không hợp lệ");

    // Lấy thông tin đăng ký
    $stmt = $pdo->prepare("SELECT user_id, campaign_id FROM registrations WHERE id=?");
    $stmt->execute([$id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg)
      throw new Exception("Không tìm thấy đăng ký");

    // Lấy điểm gốc phong trào
    $stmt2 = $pdo->prepare("SELECT score, title FROM campaigns WHERE id=?");
    $stmt2->execute([$reg['campaign_id']]);
    $camp = $stmt2->fetch(PDO::FETCH_ASSOC);

    $baseScore = (int) ($camp['score'] ?? 0);
    $ctitle = $camp['title'] ?? 'phong trào';

    // Tính điểm bonus
    switch ($status) {
      case 'excellent':
        $userScore = round($baseScore * 1.2);
        break;
      case 'good':
        $userScore = $baseScore;
        break;
      default:
        $userScore = 0;
    }


    // Update DB
    $pdo->prepare("
            UPDATE registrations
            SET status=?, note=?, score=?
            WHERE id=?
        ")->execute([$status, $note, $userScore, $id]);

    // Gửi thông báo
    switch ($status) {
      case 'excellent':
        $msg = "Bạn đã HOÀN THÀNH XUẤT SẮC phong trào '<b>{$ctitle}</b>' và nhận được <b>{$userScore} điểm</b> (Bonus 20%).";
        break;
      case 'good':
        $msg = "Bạn đã HOÀN THÀNH TỐT '<b>{$ctitle}</b>' và nhận được <b>{$userScore} điểm</b>.";
        break;
      case 'incomplete':
        $msg = "Bạn CHƯA HOÀN THÀNH '<b>{$ctitle}</b>' (0 điểm).";
        break;
      default:
        $msg = "Cập nhật trạng thái phong trào.";
    }


    $pdo->prepare("
            INSERT INTO notifications (message, user_id, link)
            VALUES (?, ?, ?)
        ")->execute([$msg, $reg['user_id'], "index.php?p=campaigns&tab=registered"]);

    echo json_encode(['success' => true, 'message' => 'Đã đánh giá & áp dụng bonus']);
    exit;
  }


  /* =====================================================
         3) ADMIN CHẤM ĐIỂM THỦ CÔNG
  ===================================================== */
  if ($action === 'score' && is_admin()) {

    $id = (int) $_POST['id'];
    $score = (int) ($_POST['score'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    // Lấy thông tin đăng ký
    $info = $pdo->prepare("SELECT user_id, campaign_id FROM registrations WHERE id=?");
    $info->execute([$id]);
    $reg = $info->fetch(PDO::FETCH_ASSOC);

    if (!$reg)
      throw new Exception("Không tìm thấy đăng ký để chấm điểm");

    // Lấy tên phong trào
    $stm2 = $pdo->prepare("SELECT title FROM campaigns WHERE id=?");
    $stm2->execute([$reg['campaign_id']]);
    $ctitle = $stm2->fetchColumn() ?: 'phong trào';

    // Update điểm
    $pdo->prepare("UPDATE registrations SET score=?, note=? WHERE id=?")
      ->execute([$score, $note, $id]);

    // Thông báo
    $msg = "⭐ Bạn đã được chấm <b>{$score} điểm</b> cho phong trào <b>{$ctitle}</b>.";

    $pdo->prepare("
            INSERT INTO notifications (message, user_id, link)
            VALUES (?, ?, ?)
        ")->execute([$msg, $reg['user_id'], "index.php?p=campaigns&tab=registered"]);

    echo json_encode(['success' => true, 'message' => 'Đã chấm điểm']);
    exit;
  }


  /* =====================================================
       4) ĐẾM SỐ NGƯỜI CHƯA REVIEW (ADMIN)
  ===================================================== */
  if ($action === 'pending_count') {

    if (!is_admin()) {
      echo json_encode([
        'success' => true,
        'count' => 0
      ]);
      exit;
    }

    $stmt = $pdo->query("
    SELECT COUNT(*) 
    FROM registrations 
    WHERE status = 'approved'
  ");

    echo json_encode([
      'success' => true,
      'count' => (int) $stmt->fetchColumn()
    ]);
    exit;
  }



  throw new Exception("Hành động không hợp lệ");

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine()
  ]);
  exit;
}
