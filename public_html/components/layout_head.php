<?php /* components/layout_head.php */
$isGuest = empty($_SESSION['user_id']);
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
// đảm bảo có $pdo để đọc app_settings
require_once __DIR__ . '/../config/db.php';

function app_setting(PDO $pdo, string $k, string $default = ''): string
{
  try {
    $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null)
      return $default;
    return (string) $v;
  } catch (Throwable $e) {
    return $default;
  }
}

// đọc cấu hình OA
$oaEnabled = (int) app_setting($pdo, 'zalo_oa_enabled', '0');
$oaId = trim(app_setting($pdo, 'zalo_oa_id', ''));
$oaWelcome = trim(app_setting($pdo, 'zalo_oa_welcome', 'Rất vui khi được hỗ trợ bạn!'));
$oaAuto = (int) app_setting($pdo, 'zalo_oa_autopopup', '0');

$oaValid = ($oaEnabled === 1 && $oaId !== '' && preg_match('/^\d+$/', $oaId));
?>
<script>
  window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf']) ?>;
</script>
<!DOCTYPE html>
<html lang="vi">

<script>
  window.BASE_URL = <?= json_encode(BASE_URL) ?>;
</script>



<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hệ thống Quản lý Đoàn viên</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap"
    rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#1E40AF', secondary: '#3B82F6', accent: { red: '#DA1E28', green: '#0E9F6E', yellow: '#F59E0B' },
            text: '#111827', subtext: '#6B7280', bg: '#F3F4F6', card: '#FFFFFF'
          },
          fontFamily: { heading: ['Be Vietnam Pro', 'sans-serif'], body: ['Inter', 'sans-serif'] }
        }
      }


    }
  </script>


  <style>
    #notiMenu {
      position: absolute;
      top: 100%;
      right: 0;
      margin-top: 8px;
      z-index: 50;
    }

    .shadow-card {
      box-shadow: 0 1px 3px rgba(0, 0, 0, .1), 0 1px 2px rgba(0, 0, 0, .06)
    }

    .shadow-card-hover {
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .1), 0 2px 4px -1px rgba(0, 0, 0, .06)
    }

    .hover-lift {
      transition: all .2s
    }

    .hover-lift:hover {
      transform: translateY(-2px)
    }

    .grid-container {
      margin: 0 auto;
      padding: 0 24px
    }

    @media (max-width:768px) {
      .grid-container {
        padding: 0 16px
      }
    }

    .table-responsive {
      display: block;
      width: 100%;
      overflow-x: auto;
    }

    @keyframes notifyFadeIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .notify-fade-in {
      animation: notifyFadeIn .25s ease-out;
    }
  </style>

</head>

<script src="<?= BASE_URL ?>assets/js/app.js?v=<?= time() ?>"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>
  lucide.createIcons();
</script>


<body class="bg-bg font-body text-text">

  <!-- GLOBAL NOTIFY MODAL -->
  <div id="notifyModal" class="fixed inset-0 hidden bg-black/40 z-[9999] flex items-center justify-center ">
    <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-sm text-center notify-fade-in">
      <h3 id="notifyTitle" class="text-lg font-semibold mb-3"></h3>
      <p id="notifyMessage" class="text-gray-700 mb-5"></p>
      <button id="notifyClose" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Đóng</button>
    </div>
  </div>

  <!-- GLOBAL CONFIRM MODAL (reusable for all confirmations, replaces native confirm()) -->
  <?php include __DIR__ . '/confirm_modal.php'; ?>

  <script src="<?= BASE_URL ?>assets/js/action-guard.js"></script>
  <script src="<?= BASE_URL ?>assets/js/auth.js?v=<?= time() ?>"></script>

  <style>
    /* ép widget luôn góc phải dưới */
    #zaloChatWidget {
      position: fixed !important;
      right: 20px !important;
      left: auto !important;
      bottom: 20px !important;
      z-index: 999999 !important;
    }
  </style>

  <script>
    (function () {
      // chống init lại (tránh nhân đôi nếu layout bị include lại)
      if (window.__ZALO_WIDGET_INITED__) return;
      window.__ZALO_WIDGET_INITED__ = true;

      function mountZalo() {
        // xoá trùng (nếu có)
        document.querySelectorAll('.zalo-chat-widget').forEach((el, i) => {
          if (i > 0) el.remove();
        });

        // tạo widget theo đúng snippet bạn gửi
        let w = document.querySelector('.zalo-chat-widget');
        if (!w) {
          w = document.createElement('div');
          w.id = 'zaloChatWidget';
          w.className = 'zalo-chat-widget';
          w.setAttribute('data-oaid', '1670103838041335253');
          w.setAttribute('data-welcome-message', 'Rất vui khi được hỗ trợ bạn!');
          w.setAttribute('data-autopopup', '0');
          w.setAttribute('data-width', '');
          w.setAttribute('data-height', '');
          document.body.appendChild(w);
        } else {
          // nếu tồn tại mà chưa có id thì gán id để css ép góc
          if (!w.id) w.id = 'zaloChatWidget';
        }

        // load sdk đúng 1 lần (theo đúng snippet)
        if (!document.querySelector('script[src="https://sp.zalo.me/plugins/sdk.js"]')) {
          const s = document.createElement('script');
          s.src = 'https://sp.zalo.me/plugins/sdk.js';
          s.async = true;
          document.body.appendChild(s);
        }
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountZalo);
      } else {
        mountZalo();
      }
    })();
  </script>


</body>