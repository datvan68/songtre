<?php
$u = auth_user();
$current = $_GET['p'] ?? '';
?>

<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>components/sidebar.css?v=<?= time() ?>">

<aside id="sidebar" class="sidebar w-56 bg-white shadow-card h-screen sticky top-0 overflow-y-auto z-50">
  <div class="p-3 flex flex-col h-full">

    <!-- LOGO -->
    <div class="logo-wrap flex items-center mb-6 px-1 -ml-1">
      <div class="logo-hover-toggle cursor-pointer" id="logoToggleBtn">
        <img src="<?= BASE_URL ?>assets/images/logo_truong.png" class="logo-img">
        <div class="logo-toggle-btn">
          <i data-lucide="menu"></i>
        </div>
      </div>

      <button id="toggleSidebar" class="toggle-btn ml-auto hover:bg-gray-300">☰</button>
    </div>

    <!-- NAV - ĐÃ GIẢM KHOẢNG CÁCH -->
    <nav class="space-y-4 flex-1">
      <?php if (!$u): ?>
        <!-- Phần chưa login (đã copy nguyên từ code cũ) -->
        <div class="nav-group">
          <div class="group-title text-xs font-semibold text-gray-500 uppercase px-2 mb-1.5">
            Đoàn Thanh Niên
          </div>

          <div class="space-y-1">
            <a href="<?= BASE_URL ?>index.php?p=dashboard"
              class="menu-item <?= $current === 'dashboard' ? 'active' : '' ?>">
              <span class="icon-wrap"><i data-lucide="home"></i></span>
              <span class="label">Trang chủ</span>
              <span class="tooltip">Trang chủ</span>
            </a>

            <a href="<?= BASE_URL ?>index.php?p=campaigns"
              class="menu-item <?= $current === 'campaigns' ? 'active' : '' ?>">
              <span class="icon-wrap"><i data-lucide="target"></i></span>
              <span class="label">Phong trào</span>
              <span class="tooltip">Phong trào</span>
            </a>

            <a href="<?= BASE_URL ?>index.php?p=leaderboard"
              class="menu-item <?= $current === 'leaderboard' ? 'active' : '' ?>">
              <span class="icon-wrap"><i data-lucide="bar-chart-2"></i></span>
              <span class="label">Xếp hạng</span>
              <span class="tooltip">Xếp hạng</span>
            </a>

            <a href="<?= BASE_URL ?>index.php?p=nominations"
              class="menu-item <?= $current === 'nominations' ? 'active' : '' ?>">
              <span class="icon-wrap"><i data-lucide="medal"></i></span>
              <span class="label">Thi đua – Khen thưởng</span>
              <span class="tooltip">Thi đua – Khen thưởng</span>
            </a>

            <a href="<?= BASE_URL ?>index.php?p=baocaophongtrao"
              class="menu-item <?= $current === 'baocaophongtrao' ? 'active' : '' ?>">
              <span class="icon-wrap"><i data-lucide="flag"></i></span>
              <span class="label">Báo cáo phong trào</span>
              <span class="tooltip">Báo cáo phong trào</span>
            </a>
          </div>
        </div>

      <?php else: ?>

        <!-- TỔNG QUAN -->
        <?php $showOverview = can('dashboard', 'view') || can('statistics', 'view'); ?>
        <?php if ($showOverview): ?>
          <div class="nav-group">
            <div class="group-title text-xs font-semibold text-gray-500 uppercase px-2 mb-1.5">TỔNG QUAN</div>
            <div class="space-y-1">
              <?php if (can('dashboard', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=dashboard"
                  class="menu-item <?= $current === 'dashboard' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="home"></i></span>
                  <span class="label">Dashboard</span>
                  <span class="tooltip">Dashboard</span>
                </a>
              <?php endif; ?>
              <?php if (can('statistics', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=statistics"
                  class="menu-item <?= $current === 'statistics' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="chart-no-axes-column"></i></span>
                  <span class="label">Thống kê</span>
                  <span class="tooltip">Thống kê</span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- QUẢN LÝ TỔ CHỨC -->
        <?php $showOrg = can('members', 'view') || can('departments', 'view') || can('permissions', 'view') || can('roles', 'view') || can('user_lookup', 'view'); ?>
        <?php if ($showOrg): ?>
          <div class="nav-group">
            <div class="group-title text-xs font-semibold text-gray-500 uppercase px-2 mb-1.5">QUẢN LÝ TỔ CHỨC</div>
            <div class="space-y-1">
              <?php if (can('members', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=members" class="menu-item <?= $current === 'members' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="users"></i></span>
                  <span class="label">Quản lý đoàn viên</span>
                  <span class="tooltip">Quản lý đoàn viên</span>
                </a>
              <?php endif; ?>
              <?php if (can('departments', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=departments"
                  class="menu-item <?= $current === 'departments' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="building-2"></i></span>
                  <span class="label">Khoa / Lớp</span>
                  <span class="tooltip">Khoa / Lớp</span>
                </a>
              <?php endif; ?>
              <?php if (can('permissions', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=permissions"
                  class="menu-item <?= $current === 'permissions' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="shield"></i></span>
                  <span class="label">Quản lý tài khoản</span>
                  <span class="tooltip">Quản lý tài khoản</span>
                </a>
              <?php endif; ?>
              <?php if (can('roles', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=roles" class="menu-item <?= $current === 'roles' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="shield-check"></i></span>
                  <span class="label">Quản lý role</span>
                  <span class="tooltip">Quản lý role</span>
                </a>
              <?php endif; ?>
              <?php if (can('user_lookup', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=user_lookup"
                  class="menu-item <?= $current === 'user_lookup' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="user-search"></i></span>
                  <span class="label">Tra cứu đoàn viên</span>
                  <span class="tooltip">Tra cứu đoàn viên</span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- HOẠT ĐỘNG -->
        <?php $showActivity = can('campaigns', 'view') || can('attend_list', 'view') || can('schedule', 'view') || can('tasks', 'view') || can('duty', 'view'); ?>
        <?php if ($showActivity): ?>
          <div class="nav-group">
            <div class="group-title text-xs font-semibold text-gray-500 uppercase px-2 mb-1.5">HOẠT ĐỘNG</div>
            <div class="space-y-1">
              <?php if (can('campaigns', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=campaigns"
                  class="menu-item <?= $current === 'campaigns' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="target"></i></span>
                  <span class="label">Phong trào</span>
                  <span class="tooltip">Phong trào</span>
                </a>
              <?php endif; ?>
              <?php if (can('attend_list', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=attend_list"
                  class="menu-item <?= $current === 'attend_list' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="list-checks"></i></span>
                  <span class="label">Danh sách điểm danh</span>
                  <span class="tooltip">Danh sách điểm danh</span>
                </a>
              <?php endif; ?>
              <?php if (can('schedule', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=schedule"
                  class="menu-item <?= $current === 'schedule' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="calendar"></i></span>
                  <span class="label">Lịch công tác</span>
                  <span class="tooltip">Lịch công tác</span>
                </a>
              <?php endif; ?>
              <?php if (can('tasks', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=tasks" class="menu-item <?= $current === 'tasks' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="list-todo"></i></span>
                  <span class="label">Quản lý công việc</span>
                  <span class="tooltip">Quản lý công việc</span>
                </a>
              <?php endif; ?>
              <?php if (can('duty', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=duty" class="menu-item <?= $current === 'duty' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="calendar-check"></i></span>
                  <span class="label">Lịch trực</span>
                  <span class="tooltip">Lịch trực</span>
                </a>
              <?php endif; ?>

              <a href="<?= BASE_URL ?>index.php?p=baocaophongtrao" class="menu-item <?= $current === 'baocaophongtrao' ? 'active' : '' ?>">
                <span class="icon-wrap"><i data-lucide="flag"></i></span>
                <span class="label">Báo cáo phong trào</span>
                <span class="tooltip">Báo cáo phong trào</span>
              </a>
            </div>
          </div>
        <?php endif; ?>

        <!-- ĐÁNH GIÁ -->
        <?php $showEvaluation = can('scoring', 'view') || can('leaderboard', 'view') || can('achievements', 'view') || can('nominations', 'view') || can('violations', 'view'); ?>
        <?php if ($showEvaluation): ?>
          <div class="nav-group">
            <div class="group-title text-xs font-semibold text-gray-500 uppercase px-2 mb-1.5">ĐÁNH GIÁ</div>
            <div class="space-y-1">
              <?php if (can('scoring', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=scoring" class="menu-item <?= $current === 'scoring' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="graduation-cap"></i></span>
                  <span class="label">Tính điểm</span>
                  <span class="tooltip">Tính điểm</span>
                </a>
              <?php endif; ?>
              <?php if (can('leaderboard', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=leaderboard"
                  class="menu-item <?= $current === 'leaderboard' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="bar-chart-2"></i></span>
                  <span class="label">Xếp hạng</span>
                  <span class="tooltip">Xếp hạng</span>
                </a>
              <?php endif; ?>
              <?php if (can('achievements', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=achievements"
                  class="menu-item <?= $current === 'achievements' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="trophy"></i></span>
                  <span class="label">Thành tích</span>
                  <span class="tooltip">Thành tích</span>
                </a>
              <?php endif; ?>
              <?php if (can('nominations', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=nominations"
                  class="menu-item <?= $current === 'nominations' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="medal"></i></span>
                  <span class="label">Thi đua - Khen thưởng</span>
                  <span class="tooltip">Thi đua - Khen thưởng</span>
                </a>
              <?php endif; ?>
              <?php if (can('violations', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=violations"
                  class="menu-item <?= $current === 'violations' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="alert-triangle"></i></span>
                  <span class="label">Kỷ luật - Vi phạm</span>
                  <span class="tooltip">Kỷ luật - Vi phạm</span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- HỆ THỐNG -->
        <?php 
        $showSystem = 
          can('reward_units', 'view') || 
          ($u && is_admin()) || 
          ($u && function_exists('is_banchaphanh') && is_banchaphanh());
        ?>
        <?php if ($showSystem): ?>
          <div class="nav-group">
            <div class="group-title text-xs font-semibold text-gray-500 uppercase px-2 mb-1.5">HỆ THỐNG</div>
            <div class="space-y-1">
              <?php if (can('reward_units', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=reward_units"
                  class="menu-item <?= $current === 'reward_units' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="layers"></i></span>
                  <span class="label">Quản lý danh mục</span>
                  <span class="tooltip">Quản lý danh mục</span>
                </a>
              <?php endif; ?>
              <?php if ($u && is_admin()): ?>
                <a href="<?= BASE_URL ?>index.php?p=backup_restore"
                  class="menu-item <?= $current === 'backup_restore' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="database-backup"></i></span>
                  <span class="label">Sao lưu, cấu hình</span>
                  <span class="tooltip">Sao lưu, cấu hình</span>
                </a>
              <?php endif; ?>
              <?php if ($u && is_admin()): ?>
                <a href="<?= BASE_URL ?>index.php?p=activity_logs"
                  class="menu-item <?= $current === 'activity_logs' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="medal"></i></span>
                  <span class="label">Lịch sử hoạt động</span>
                  <span class="tooltip">Lịch sử hoạt động</span>
                </a>
              <?php endif; ?>
              <?php if ($u && function_exists('is_banchaphanh') && is_banchaphanh()): ?>
                <a href="<?= BASE_URL ?>index.php?p=bch_security_otp"
                  class="menu-item <?= $current === 'bch_security_otp' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="lock"></i></span>
                  <span class="label">Bảo mật Gmail</span>
                  <span class="tooltip">Bảo mật Gmail (OTP)</span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- CÔNG CỤ -->
        <?php $showTools = can('inventory', 'view') || can('finance', 'view') || can('award_suggest', 'view'); ?>
        <?php if ($showTools): ?>
          <div class="nav-group">
            <div class="group-title text-xs font-semibold text-gray-500 uppercase px-2 mb-1.5">CÔNG CỤ</div>
            <div class="space-y-1">
              <?php if (can('inventory', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=inventory"
                  class="menu-item <?= $current === 'inventory' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="package"></i></span>
                  <span class="label">Quản lý thiết bị</span>
                  <span class="tooltip">Quản lý thiết bị</span>
                </a>
              <?php endif; ?>
              <?php if (can('finance', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=finance" class="menu-item <?= $current === 'finance' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="wallet"></i></span>
                  <span class="label">Quản lý thu chi</span>
                  <span class="tooltip">Quản lý thu chi</span>
                </a>
              <?php endif; ?>
              <?php if (can('award_suggest', 'view')): ?>
                <a href="<?= BASE_URL ?>index.php?p=award_suggest"
                  class="menu-item <?= $current === 'award_suggest' ? 'active' : '' ?>">
                  <span class="icon-wrap"><i data-lucide="lightbulb"></i></span>
                  <span class="label">Trung tâm gợi ý</span>
                  <span class="tooltip">Trung tâm gợi ý</span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      <?php endif; ?>

    </nav>

    <!-- FOOTER AI -->
    <?php if ($u && can('ai_document', 'view')): ?>
      <div class="pt-3 mt-3 border-t border-gray-200 sidebar-footer -ml-2">
        <a href="https://doantruong.namsaigon.edu.vn/soan-thao-van-ban-hanh-chinh/#gsc.tab=0" target="_blank"
          rel="noopener"
          class="soan-van-ban-btn flex items-center gap-2 h-10 px-5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold shadow-md hover:from-blue-700 hover:to-indigo-700 hover:shadow-lg active:scale-95 transition-all duration-200">
          <i data-lucide="file-pen-line" class="w-5 h-5"></i>
          <span class="soan-label whitespace-nowrap">Tạo văn bản AI</span>
          <span class="soan-tooltip">Tạo văn bản AI</span>
        </a>
      </div>
    <?php endif; ?>

  </div>
</aside>

<div id="sidebarBackdrop" class="hidden fixed inset-0 bg-black bg-opacity-30 z-40"></div>

<script>
  (function () {
    const sidebar = document.getElementById("sidebar");
    const toggleSidebar = document.getElementById("toggleSidebar");
    const logoToggleBtn = document.getElementById("logoToggleBtn");
    const sidebarBackdrop = document.getElementById("sidebarBackdrop");

    const mql = window.matchMedia("(max-width: 768px)");
    const isMobile = () => mql.matches;

    const openSidebar = () => {
      if (!isMobile()) return;
      sidebar.classList.add("sidebar-open");
      sidebarBackdrop.classList.remove("hidden");
    };

    const closeSidebar = () => {
      sidebar.classList.remove("sidebar-open");
      sidebarBackdrop.classList.add("hidden");
    };

    window.openSidebar = openSidebar;

    toggleSidebar.addEventListener("click", () => {
      if (isMobile()) {
        if (sidebar.classList.contains("sidebar-open")) closeSidebar();
        else openSidebar();
      } else {
        sidebar.classList.toggle("sidebar-collapsed");
        localStorage.setItem("sidebarCollapsed", sidebar.classList.contains("sidebar-collapsed") ? "true" : "false");
      }
      lucide.createIcons();
    });

    logoToggleBtn.addEventListener("click", () => {
      if (sidebar.classList.contains("sidebar-collapsed")) {
        sidebar.classList.remove("sidebar-collapsed");
        localStorage.setItem("sidebarCollapsed", "false");
      } else if (isMobile()) {
        openSidebar();
      }
      lucide.createIcons();
    });

    if (!isMobile() && localStorage.getItem("sidebarCollapsed") === "true") {
      sidebar.classList.add("sidebar-collapsed");
    }

    sidebarBackdrop.addEventListener("click", closeSidebar);
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeSidebar();
    });

    mql.addEventListener("change", () => {
      if (isMobile()) {
        sidebar.classList.remove("sidebar-collapsed");
      } else {
        closeSidebar();
        if (localStorage.getItem("sidebarCollapsed") === "true") {
          sidebar.classList.add("sidebar-collapsed");
        }
      }
    });

    // mobile: click menu -> auto close
    sidebar.querySelectorAll("a.menu-item, a.soan-van-ban-btn").forEach((a) => {
      a.addEventListener("click", () => {
        if (isMobile()) closeSidebar();
      });
    });

    lucide.createIcons();
    setTimeout(() => lucide.createIcons(), 50);
  })();
  
  // FIX TOOLTIP: CHỈ HIỆN 1 CÁI + BÁM SÁT ICON + KHÔNG MẤT KHI HOVER NHANH
  (function () {
    const sidebar = document.getElementById("sidebar");
    const items = document.querySelectorAll('.menu-item');
    let timeout = null;

    function hideAllTooltips() {
      document.querySelectorAll('.tooltip').forEach(t => t.style.opacity = '0');
    }

    function positionTooltip(item) {
      const tooltip = item.querySelector('.tooltip');
      if (!tooltip || !sidebar.classList.contains('sidebar-collapsed')) return;

      hideAllTooltips();

      const rect = item.getBoundingClientRect();
      tooltip.style.top = `${rect.top + rect.height / 2}px`;
      tooltip.style.left = `${rect.right + 14}px`;
      tooltip.style.transform = 'translateY(-50%)';
      tooltip.style.opacity = '1';
    }

    items.forEach(item => {
      item.addEventListener('mouseenter', () => {
        if (timeout) clearTimeout(timeout);
        positionTooltip(item);
      });

      item.addEventListener('mouseleave', () => {
        timeout = setTimeout(() => {
          hideAllTooltips();
        }, 80);
      });
    });

    // Cập nhật vị trí khi cuộn
    sidebar.addEventListener('scroll', () => {
      const hovered = document.querySelector('.menu-item:hover');
      if (hovered) positionTooltip(hovered);
    });
  })();
</script>

<style>

</style>