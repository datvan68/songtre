<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
if (ob_get_length())
    ob_clean();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

function json_ok($data = [])
{
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function json_err($msg = 'ERROR', $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!$userId)
    json_err('UNAUTHORIZED', 401);

/**
 * ✅ Auto tạo user_profiles nếu chưa có
 * (để tài khoản cũ không bị fail)
 */
$stmt = $pdo->prepare("SELECT 1 FROM user_profiles WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$hasProfile = (bool) $stmt->fetchColumn();

if (!$hasProfile) {
    // chỉ insert nếu user có fullname (đúng yêu cầu của Toro)
    $stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $fullname = $stmt->fetchColumn();

    if ($fullname && trim($fullname) !== '') {
        $stmt = $pdo->prepare("
      INSERT INTO user_profiles (user_id, birth, phone, email, address, created_at, updated_at)
      VALUES (?, NULL, NULL, NULL, NULL, NOW(), NOW())
    ");
        $stmt->execute([$userId]);
    }
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'get') {

    $stmt = $pdo->prepare("
  SELECT 
    u.id,
    u.username,
    u.fullname,
    u.avatar_url AS user_avatar,
    up.avatar_url AS profile_avatar,
    up.birth,
    up.phone,
    up.email,
    up.address
  FROM users u
  LEFT JOIN user_profiles up ON up.user_id = u.id
  WHERE u.id = ?
  LIMIT 1
");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $avatar = $row['profile_avatar'] ?: $row['user_avatar'];

    json_ok([
        'user' => [
            'id' => $row['id'],
            'username' => $row['username'],
            'fullname' => $row['fullname'],
            'avatar_url' => $avatar,
        ],
        'profile' => [
            'birth' => $row['birth'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'address' => $row['address'],
            'avatar_url' => $row['profile_avatar'],
        ]
    ]);

}

/* ===========================
   UPDATE PROFILE (user_profiles)
=========================== */
if ($action === 'update_profile') {

    $birth = trim($_POST['birth'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $avatarUrl = trim($_POST['avatar_url'] ?? '');

    // normalize empty -> NULL
    $birth = ($birth === '') ? null : $birth;
    $phone = ($phone === '') ? null : $phone;
    $email = ($email === '') ? null : $email;
    $address = ($address === '') ? null : $address;

    // ✅ avatar: empty -> NULL
    $avatarUrl = ($avatarUrl === '') ? null : $avatarUrl;

    // ✅ chặn base64
    if ($avatarUrl && strpos($avatarUrl, 'data:image') === 0) {
        json_err('Không hỗ trợ ảnh base64');
    }

    // ✅ validate URL nếu có
    if ($avatarUrl && !filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
        json_err('URL ảnh không hợp lệ');
    }

    try {
        $pdo->beginTransaction();

        // ✅ update avatar vào users
        $stmt = $pdo->prepare("UPDATE users SET avatar_url = ? WHERE id = ? LIMIT 1");
        $stmt->execute([$avatarUrl, $userId]);

        // ✅ đảm bảo tồn tại record user_profiles
        $stmt = $pdo->prepare("SELECT 1 FROM user_profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $exists = (bool) $stmt->fetchColumn();

        if (!$exists) {
            $stmt = $pdo->prepare("
        INSERT INTO user_profiles (user_id, birth, phone, email, address, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
      ");
            $stmt->execute([$userId, $birth, $phone, $email, $address]);
        } else {
            $stmt = $pdo->prepare("
        UPDATE user_profiles
        SET birth = ?, phone = ?, email = ?, address = ?, updated_at = NOW()
        WHERE user_id = ?
        LIMIT 1
      ");
            $stmt->execute([$birth, $phone, $email, $address, $userId]);
        }

        $pdo->commit();
        json_ok(['msg' => 'Updated']);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        json_err('Lỗi cập nhật: ' . $e->getMessage(), 500);
    }
}

/* ===========================
   CHANGE PASSWORD (đã fix theo cấu trúc DB của bạn)
=========================== */
if ($action === 'change_password') {

    $oldPass = trim($_POST['old_password'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');

    if (empty($oldPass) || empty($newPass)) {
        json_err('Vui lòng nhập đầy đủ mật khẩu');
    }
    if (strlen($newPass) < 6) {
        json_err('Mật khẩu mới phải ít nhất 6 ký tự');
    }

    try {
        // Lấy mật khẩu hiện tại (cột password_hash)
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $currentHash = $stmt->fetchColumn();

        if (!$currentHash || !password_verify($oldPass, $currentHash)) {
            json_err('Mật khẩu cũ không đúng');
        }

        // Hash mật khẩu mới
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);

        // Update (chỉ cập nhật password_hash, vì bảng users không có updated_at)
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
        $stmt->execute([$newHash, $userId]);

        json_ok(['msg' => 'Password changed successfully']);

    } catch (Exception $e) {
        json_err('Lỗi đổi mật khẩu: ' . $e->getMessage(), 500);
    }
}


json_err('Action không hợp lệ');
