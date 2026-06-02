<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

auth_guard();
header('Content-Type: application/json; charset=utf-8');

try {
  // ✅ Chỉ admin mới xem báo cáo nhanh
  if (!is_admin()) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "FORBIDDEN"], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* =====================================================
     1) Đếm số user đã quét QR nhưng CHƯA được chấm điểm
     -> attendance_logs có record = đã quét
     -> score NULL hoặc 0 = chưa chấm
  ===================================================== */
  $pendingAttendanceUsers = (int)$pdo->query("
    SELECT COUNT(DISTINCT al.user_id)
    FROM attendance_logs al
    WHERE (al.score IS NULL OR al.score = 0)
  ")->fetchColumn();

  /* =====================================================
     2) Nếu Toro vẫn muốn đếm theo phong trào (campaign)
     -> Có ít nhất 1 người quét QR nhưng chưa chấm
  ===================================================== */
  $pendingCampaigns = (int)$pdo->query("
    SELECT COUNT(DISTINCT al.campaign_id)
    FROM attendance_logs al
    WHERE (al.score IS NULL OR al.score = 0)
  ")->fetchColumn();

  // 🔹 Đếm số đề nghị khen thưởng chưa duyệt (giữ nguyên)
  $pendingNominations = (int)$pdo->query("
    SELECT COUNT(*)
    FROM nominations
    WHERE status = 'pending'
  ")->fetchColumn();

  echo json_encode([
    "ok" => true,

    // ✅ Cái Toro cần (theo user)
    "pending_attendance_users" => $pendingAttendanceUsers,

    // ✅ Nếu muốn giữ logic cũ hiển thị "phong trào"
    "pending_campaigns" => $pendingCampaigns,

    "pending_nominations" => $pendingNominations
  ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  echo json_encode(["ok" => false, "error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
