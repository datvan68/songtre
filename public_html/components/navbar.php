<?php
$u = null;

if (!empty($_SESSION['user_id'])) {
  $stmt = $pdo->prepare("
  SELECT 
    u.id,
    u.username,
    u.fullname AS user_full_name,
    u.avatar_url,
    u.role_id,
    r.name AS role_name,

    m.fullname AS member_full_name

  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  LEFT JOIN members m ON m.user_id = u.id

  WHERE u.id = ?
  LIMIT 1
");
  $stmt->execute([$_SESSION['user_id']]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  $displayName =
    !empty($u['member_full_name'])
    ? $u['member_full_name']
    : (!empty($u['user_full_name'])
      ? $u['user_full_name']
      : $u['username']);

}

$isGuest = ($u === null);
$isAdmin = (!$isGuest && $u['role_name'] === 'admin');

$userId = $_SESSION['user_id'] ?? 0;

$isBCH = false;
if ($userId) {
  $stmt = $pdo->prepare("SELECT 1 FROM user_profiles WHERE user_id = ? LIMIT 1");
  $stmt->execute([$userId]);
  $isBCH = (bool) $stmt->fetchColumn();
}

$profilePage = $isBCH ? 'bch_account' : 'account';
?>




<nav class="bg-card shadow-card border-b h-16 w-full relative z-50">
  <div class="grid-container">
    <div class="flex items-center justify-between h-16">
      <!-- Logo + tên trường -->
      <div class="flex items-center space-x-4">
        <button id="btnOpenSidebar" class="md:hidden p-2 rounded-lg hover:bg-gray-100" aria-label="Mở menu">
          <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <div class="w-8 h-8 rounded-lg overflow-hidden flex items-center justify-center">
          <img src="<?= BASE_URL ?>assets/images/logo_doan.png" alt="Logo Đoàn" class="w-full h-full object-contain">
        </div>

        <span class="font-heading font-semibold text-lg mt-1">
          Sóng trẻ Nam Sài Gòn
        </span>
      </div>

      <!-- Thanh chức năng bên phải -->
      <div class="flex items-center space-x-4 mr-4">
        <!-- Ô tìm kiếm
        <div class="hidden md:block relative">
          <input type="text" placeholder="Tìm kiếm..."
            class="border rounded-lg px-3 py-2 pl-9 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
            id="navbarSearch" />
          <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" stroke-width="2"
            viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M21 21l-4.35-4.35m0 0A7.5 7.5 0 104.5 4.5a7.5 7.5 0 0012.15 12.15z" />
          </svg>
        </div> -->
        <?php if (!$isGuest): ?>
          <!-- Thông báo -->
          <div class="relative z-50">
            <button id="btnNoti" class="relative p-2 hover:bg-gray-100 rounded-lg"
              data-role="<?= htmlspecialchars($u['role_name'] ?? 'guest') ?>">
              <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
              </svg>
              <span id="notiBadge"
                class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs rounded-full px-1 hidden">3</span>
            </button>

            <!-- Dropdown thông báo -->
            <div id="notiMenu"
              class="hidden absolute right-0 mt-2 w-64 bg-white border rounded-lg shadow-lg overflow-hidden z-[9999]">
              <div class="px-4 py-2 font-semibold border-b bg-gray-50">Thông báo</div>
              <div id="notiList" class="max-h-64 overflow-y-auto">
                <div class="px-4 py-2 text-sm text-gray-600 border-b">Không có thông báo mới</div>
              </div>
              <div class="text-center text-sm text-primary py-2 cursor-pointer hover:underline">Xem tất cả</div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Tài khoản người dùng -->
        <?php if ($isGuest): ?>
          <!-- Guest: chỉ hiện nút đăng nhập -->
          <button onclick="openLoginModal()" class=" px-4 py-2 rounded-lg bg-primary text-white hover:bg-primary-dark
            transition">
            Đăng nhập
          </button>
        <?php else: ?>

          <!-- User: menu tài khoản -->
          <div class="relative">
            <button id="btnUserMenu" class="flex items-center gap-2 p-2 hover:bg-gray-100 rounded-lg">

              <div class="w-8 h-8 rounded-full overflow-hidden bg-primary
              flex items-center justify-center shrink-0">
                <?php if (!empty($u['avatar_url'])): ?>
                  <img src="<?= htmlspecialchars($u['avatar_url']) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                  <span class="text-white text-sm font-medium">
                    <?= strtoupper(substr($u['username'], 0, 2)) ?>
                  </span>
                <?php endif; ?>
              </div>

              <span class="hidden md:block font-medium">
                <?= htmlspecialchars($displayName) ?>
              </span>

              <!-- ✅ MŨI TÊN XUỐNG -->
              <svg class="w-4 h-4 text-gray-500 transition-transform" fill="none" stroke="currentColor" stroke-width="1.5"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6" />
              </svg>
            </button>


            <div id="userMenu" class="hidden absolute right-0 mt-2 w-48 bg-white border rounded-lg shadow-lg">
              <a href="index.php?p=<?= htmlspecialchars($profilePage) ?>"
                class="block px-4 py-2 text-sm hover:bg-gray-100">
                Hồ sơ cá nhân
              </a>

              <a href="<?= BASE_URL ?>controllers/auth.php?action=logout"
                class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                Đăng xuất
              </a>
            </div>

          </div>
        <?php endif; ?>
        
      </div>
    </div>
  </div>
</nav>

<?php if (!empty($_SESSION['user_id'])): ?>
  <script src="<?= BASE_URL ?>assets/js/notifications.js?v=<?= time() ?>"></script>
<?php endif; ?>

<style>
  #btnUserMenu.open svg {
    transform: rotate(180deg);
  }
</style>

<?php if (!$isGuest && (($u['role_name'] ?? '') === 'banchaphanh')): ?>
  <script>
    document.addEventListener("DOMContentLoaded", async () => {
      const currentPage = new URLSearchParams(window.location.search).get("p") || "dashboard";
      if (currentPage !== "dashboard") return;

      // ✅ chỉ chặn trong 1 lần load (refresh là hiện lại)
      if (window.__DASH_DUTY_NOTICE_SHOWN__) return;
      window.__DASH_DUTY_NOTICE_SHOWN__ = true;

      try {
        const res = await api("controllers/duty.php?action=check_need_register");
        const j = await res.json();
        if (!j?.ok) return;

        const data = j.data || {};
        if (data.locked) return;
        if (data.need !== true) return;

        const msg = `
          <div class="text-center space-y-4">
            <p class="text-lg font-semibold text-primary">
              Vui lòng đăng ký lịch trực
            </p>

            <p class="text-sm text-gray-600">
              Bạn chưa đăng ký lịch rảnh và lịch học cho tuần trực sắp tới.
              Hãy vào trang Lịch trực để đăng ký.
            </p>

            <div class="flex justify-center gap-3 mt-4">
              <button
                class="px-4 py-2 bg-primary hover:bg-primary-dark text-white rounded-lg"
                onclick="location.href='index.php?p=duty'">
                Đi đăng ký
              </button>

              <button
                class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-lg"
                onclick="closeModal?.()">
                Để sau
              </button>
            </div>
          </div>
        `;

        modal(msg, "Thông báo", "medium");
      } catch (err) {
        console.error("Dashboard duty notice error:", err);
      }
    });
  </script>
<?php endif; ?>

<script>
  document.addEventListener("DOMContentLoaded", () => {
    const btnOpenSidebar = document.getElementById("btnOpenSidebar");
    if (btnOpenSidebar) {
      btnOpenSidebar.addEventListener("click", () => {
        if (window.openSidebar) window.openSidebar();
      });
    }
  });
</script>

<?php if (is_admin()): ?>
  <script>
    document.addEventListener("DOMContentLoaded", async () => {
      const currentPage = new URLSearchParams(window.location.search).get("p");
      if (currentPage !== "dashboard") return; // ✅ chỉ hiện ở trang dashboard

      try {
        const res = await api("controllers/dashboard_stats.php");
        const data = await res.json();

        if (data.ok && (data.pending_attendance_users > 0 || data.pending_nominations > 0)) {
          const msg = `
        <div class="text-center space-y-4 ">
          <p class="text-lg font-semibold text-primary">📢 Báo cáo nhanh</p>
          ${data.pending_campaigns > 0
            ? `<p>Có <b>${data.pending_campaigns}</b> phong trào <span class="text-yellow-600 font-semibold">chưa đánh giá</span>.</p>`
            : ""}
          ${data.pending_nominations > 0
            ? `<p>Có <b>${data.pending_nominations}</b> đề nghị <span class="text-yellow-600 font-semibold">chưa duyệt</span>.</p>`
            : ""}
          <div class="flex justify-center gap-3 mt-4">
            ${data.pending_campaigns > 0
            ? `<button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"
                        onclick="location.href='index.php?p=campaigns&tab=registered'">
                  📋 Xem phong trào
                </button>`
            : ""}
            ${data.pending_nominations > 0
            ? `<button class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg"
                        onclick="location.href='index.php?p=nominations&tab=list'">
                  🏅 Xem khen thưởng
                </button>`
            : ""}
          </div>
        </div>
      `;
          modal(msg, "Thông báo nhanh", "medium");
        }
      } catch (err) {
        console.error("Không lấy được báo cáo:", err);
      }
    });
  </script>
<?php endif; ?>

<script>
  document.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("btnUserMenu");
    const menu = document.getElementById("userMenu");

    if (!btn || !menu) return;

    btn.addEventListener("click", (e) => {
      e.stopPropagation();              // ⛔ chặn lan ra document
      menu.classList.toggle("hidden");
      btn.classList.toggle("open");
    });

    menu.addEventListener("click", (e) => {
      e.stopPropagation();              // ⛔ chặn luôn trong menu
    });

    document.addEventListener("click", () => {
      menu.classList.add("hidden");
      btn.classList.remove("open");
    });
  });
</script>