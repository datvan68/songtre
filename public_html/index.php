<?php

// ===== CENTRAL ERROR REPORTING (production safe) =====
$displayErrors = false;
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (in_array(strtoupper($k), ['ENVIRONMENT', 'APP_ENV', 'ENV'])) {
                $displayErrors = in_array(strtolower($v), ['development', 'dev', 'local', 'debug']);
                break;
            }
        }
    }
}
ini_set('display_errors', $displayErrors ? '1' : '0');
ini_set('display_startup_errors', $displayErrors ? '1' : '0');
error_reporting(E_ALL);

require __DIR__ . '/config/security.php'; // session_start()
require __DIR__ . '/middleware/rate_limit.php';
// chống spam refresh / scan path
rate_limit('page_view', 300, 60);

require __DIR__ . '/config/base_url.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/config/auth.php';



$p = $_GET['p'] ?? '';

if ($p === '') {
  header("Location: " . BASE_URL . "?p=dashboard");
  exit;
}

// ===== ROUTE ĐẶC BIỆT: QR / PUBLIC ENTRY =====
if ($p === 'campaigns_check') {
  require __DIR__ . '/controllers/campaigns_check.php';
  exit;
}

// Các trang cho phép CHƯA login
$publicPages = [
  'campaigns_check',

  // 👇 cho guest xem
  'dashboard',
  'nominations',
  'campaigns',
  'leaderboard',
  'baocaophongtrao'
];


$allowOtpPage = ($p === 'admin_otp' && !empty($_SESSION['otp_pending']));

if (empty($_SESSION['user_id']) && !in_array($p, $publicPages, true) && !$allowOtpPage) {
  header("Location: index.php?p=dashboard");
  exit;
}





// Nếu AJAX request → không load layout, chỉ trả view (view sẽ tự xuất JSON rồi exit)
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
  $viewFile = __DIR__ . '/views/' . $p . '.php';
  if (is_file($viewFile)) {
    include $viewFile;
    exit;
  }
}

/* --- Layout Head --- */
include __DIR__ . '/components/layout_head.php';
?>

<!-- WRAPPER CHÍNH -->
<div class="flex w-full min-h-screen min-w-0"> <!-- ⭐ thêm min-w-0 -->

  <!-- SIDEBAR -->
  <?php include __DIR__ . '/components/sidebar.php'; ?>

  <!-- RIGHT CỘT: NAVBAR + MAIN -->
  <div class="flex-1 flex flex-col min-w-0"> <!-- ⭐ thêm min-w-0 -->

    <!-- NAVBAR -->
    <?php include __DIR__ . '/components/navbar.php'; ?>

    <!-- MAIN -->
    <main class="flex-1 bg-bg min-h-screen p-4 overflow-x-hidden min-w-0"> <!-- ⭐ thêm min-w-0 -->

      <?php
      if ($p === 'logout') {
        header('Location: ' . BASE_URL . 'controllers/auth.php?action=logout');
        exit;
      }
      switch ($p) {
        case 'dashboard':
          include __DIR__ . '/views/dashboard.php';
          break;
        case 'baocaophongtrao':
          include __DIR__ . '/views/baocaophongtrao.php';
          break;
        case 'campaigns':
          include __DIR__ . '/views/campaigns.php';
          break;
        case 'titles':
          include __DIR__ . '/views/titles.php';
          break;
        case 'nominations':
          include __DIR__ . '/views/nominations.php';
          break;
        case 'members':
          include __DIR__ . '/views/members.php';
          break;
        case 'permissions':
          include __DIR__ . '/views/permissions.php';
          break;
        case 'leaderboard':
          include __DIR__ . '/views/leaderboard.php';
          break;
        case 'account':
          include __DIR__ . '/views/account.php';
          break;
        case 'bch_account':
          include __DIR__ . '/views/bch_account.php';
          break;
        case 'settings':
          include __DIR__ . '/views/settings.php';
          break;
        case 'schedule':
          include __DIR__ . '/views/schedule.php';
          break;
        case 'roles':
          include __DIR__ . '/views/roles.php';
          break;
        case 'backup_restore':
          include __DIR__ . '/views/backup_restore.php';
          break;
        case 'departments':
          include __DIR__ . '/views/departments.php';
          break;
        case 'activity_logs':
          include __DIR__ . '/views/activity_logs.php';
          break;
        case 'reward_units':
          include __DIR__ . '/views/reward_units.php';
          break;
        case 'inventory':
          require __DIR__ . '/views/inventory.php';
          break;
        case 'finance':
          require __DIR__ . '/views/finance.php';
          break;
        case 'statistics':
          require __DIR__ . '/views/statistics.php';
          break;
        case 'campaigns_qr':
          require __DIR__ . '/controllers/campaigns_qr.php';
          break;
        case 'attend_list':
          require __DIR__ . '/views/attend_list.php';
          break;
        case 'duty':
          require __DIR__ . '/views/duty/index.php';
          break;
        case 'scoring':
          require __DIR__ . '/views/scoring.php';
          break;
        case 'achievements':
          require __DIR__ . '/views/achievements.php';
          break;
        case 'award_suggest':
          require __DIR__ . '/views/award_suggest.php';
          break;
        case 'bch_security_otp':
          require __DIR__ . '/views/bch_security_otp.php';
          break;
        case 'tasks':
          require __DIR__ . '/views/tasks/index.php';
          break;
        case 'user_lookup':
          require __DIR__ . '/views/user_lookup.php';
          break;
        case 'violations':
          require __DIR__ . '/views/violations.php';
          break;

        default:
          http_response_code(404);
          echo "<section class='p-6'>404 - Không tìm thấy trang</section>";
      }
      ?>

    </main>

  </div> <!-- end RIGHT COLUMN -->

</div> <!-- end WRAPPER -->