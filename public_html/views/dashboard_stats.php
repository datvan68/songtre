<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
auth_guard();

header('Content-Type: application/json; charset=utf-8');

try {
  // 🔹 Đếm số phong trào có đăng ký chưa chấm điểm
  $pendingCampaigns = $pdo->query("
    SELECT COUNT(*)
    FROM registrations
    WHERE status = 'approved'
  ")->fetchColumn();

  // 🔹 Đếm số đề nghị khen thưởng chưa duyệt
  $pendingNominations = $pdo->query("
    SELECT COUNT(*) 
    FROM nominations 
    WHERE status='pending'
  ")->fetchColumn();

  echo json_encode([
    "ok" => true,
    "pending_campaigns" => (int)$pendingCampaigns,
    "pending_nominations" => (int)$pendingNominations
  ]);
} catch (Exception $e) {
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
}
