// assets/js/statistics/statistics.js
(() => {
  // ======================
  // UTIL
  // ======================
  const fmt = (n) => Number(n || 0).toLocaleString("vi-VN");
  const safeNum = (n) => Number(n || 0);

  const safeDiv = (a, b) => {
    a = safeNum(a);
    b = safeNum(b);
    return b > 0 ? a / b : 0;
  };

  const pct = (part, total) => {
    part = safeNum(part);
    total = safeNum(total);
    const p = total > 0 ? (part / total) * 100 : 0;
    return Math.round(p);
  };

  function kpiCard({ icon, color, value, label, hint }) {
    return `
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-500">${label}</p>
            <p class="mt-1 text-3xl font-bold text-gray-900 leading-none">${value}</p>
            ${hint ? `<p class="mt-2 text-xs text-gray-500">${hint}</p>` : ""}
          </div>
          <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-${color}-50">
            <i data-lucide="${icon}" class="w-5 h-5 text-${color}-600"></i>
          </div>
        </div>
      </div>
    `;
  }

  function actionCard({ icon, title, desc, href, tab, color }) {
    const link =
      href || (tab ? `index.php?p=statistics&tab=${encodeURIComponent(tab)}` : "#");
    const jumpAttr = tab ? `data-jump-tab="${tab}"` : "";

    return `
      <a href="${link}" ${jumpAttr}
        class="group bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition">
        <div class="flex items-start gap-3">
          <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-${color}-50">
            <i data-lucide="${icon}" class="w-5 h-5 text-${color}-700"></i>
          </div>
          <div class="flex-1">
            <div class="flex items-center justify-between gap-3">
              <p class="font-semibold text-gray-900">${title}</p>
              <i data-lucide="arrow-right" class="w-4 h-4 text-gray-400 group-hover:text-gray-700 transition"></i>
            </div>
            <p class="mt-1 text-sm text-gray-500">${desc}</p>
          </div>
        </div>
      </a>
    `;
  }

  function createIcons() {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons();
    }
  }

  // ======================
  // OVERVIEW RENDER
  // ======================
  function renderOverview() {
    const el = document.getElementById("tab-overview");
    if (!el) return;

    const s = window.STATS || {};

    const totalMembers = safeNum(s.total_members);
    const totalYouth = safeNum(s.total_youth);
    const totalPeople = totalMembers + totalYouth;

    const totalCampaigns = safeNum(s.total_campaigns);
    const totalRegistrations = safeNum(s.total_registrations);
    const totalAttendance = safeNum(s.total_attendance);
    const totalNominations = safeNum(s.total_nominations);
    const totalUsers = safeNum(s.total_users);

    const pMembers = pct(totalMembers, totalPeople);
    const pYouth = pct(totalYouth, totalPeople);

    const avgRegPerCampaign = safeDiv(totalRegistrations, totalCampaigns);
    const avgAttendPerCampaign = safeDiv(totalAttendance, totalCampaigns);
    const attendVsReg = pct(totalAttendance, totalRegistrations);
    const nominationPer100 = totalPeople > 0 ? (totalNominations / totalPeople) * 100 : 0;

    const refreshedAt = new Date().toLocaleString("vi-VN");

    el.innerHTML = `
      <div class="mb-8">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900">Tổng quan hệ thống</h2>
            <p class="mt-1 text-sm text-gray-500">Cập nhật lúc: ${refreshedAt}</p>
          </div>

          <div class="hidden md:flex items-center gap-2">
            <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition"
              data-jump-tab="campaigns">
              Xem phong trào
            </button>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-8">
        ${kpiCard({ icon: "users", color: "indigo", value: fmt(totalMembers), label: "Tổng đoàn viên" })}
        ${kpiCard({ icon: "user-plus", color: "sky", value: fmt(totalYouth), label: "Tổng thanh niên" })}
        ${kpiCard({ icon: "flag", color: "emerald", value: fmt(totalCampaigns), label: "Tổng phong trào" })}
        ${kpiCard({ icon: "clipboard-list", color: "violet", value: fmt(totalRegistrations), label: "Tổng lượt đăng ký" })}
        ${kpiCard({ icon: "qr-code", color: "amber", value: fmt(totalAttendance), label: "Tổng lượt điểm danh" })}
        ${kpiCard({ icon: "shield-check", color: "cyan", value: fmt(totalUsers), label: "Tổng tài khoản" })}
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
          <div class="flex items-center justify-between">
            <p class="font-semibold text-gray-900">Cơ cấu thành viên</p>
            <span class="text-xs text-gray-500">Tổng: ${fmt(totalPeople)}</span>
          </div>

          <div class="mt-5 space-y-4">
            <div>
              <div class="flex items-center justify-between text-sm">
                <span class="text-gray-700 font-medium">Đoàn viên</span>
                <span class="text-gray-600">${fmt(totalMembers)} (${pMembers}%)</span>
              </div>
              <div class="mt-2 h-2 rounded-full bg-gray-100 overflow-hidden">
                <div class="h-full bg-indigo-600" style="width:${pMembers}%;"></div>
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between text-sm">
                <span class="text-gray-700 font-medium">Thanh niên</span>
                <span class="text-gray-600">${fmt(totalYouth)} (${pYouth}%)</span>
              </div>
              <div class="mt-2 h-2 rounded-full bg-gray-100 overflow-hidden">
                <div class="h-full bg-sky-600" style="width:${pYouth}%;"></div>
              </div>
            </div>

            <div class="pt-3 border-t">
              <p class="text-xs text-gray-500">
                Kiểm tra quy ước phân loại: <code class="px-1 bg-gray-50 border rounded">members.type</code>.
              </p>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
          <p class="font-semibold text-gray-900">Chỉ số suy ra</p>

          <div class="mt-5 space-y-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-sm font-medium text-gray-800">TB đăng ký / phong trào</p>
                <p class="text-xs text-gray-500">Tổng đăng ký chia tổng phong trào</p>
              </div>
              <p class="text-lg font-bold text-gray-900">${avgRegPerCampaign.toFixed(1)}</p>
            </div>

            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-sm font-medium text-gray-800">TB điểm danh / phong trào</p>
                <p class="text-xs text-gray-500">Tổng điểm danh chia tổng phong trào</p>
              </div>
              <p class="text-lg font-bold text-gray-900">${avgAttendPerCampaign.toFixed(1)}</p>
            </div>

            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-sm font-medium text-gray-800">Tỷ lệ điểm danh / đăng ký</p>
                <p class="text-xs text-gray-500">Có thể >100% nếu log nhiều lần</p>
              </div>
              <p class="text-lg font-bold text-gray-900">${attendVsReg}%</p>
            </div>

            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-sm font-medium text-gray-800">Đề cử / 100 người</p>
                <p class="text-xs text-gray-500">Chuẩn hoá theo quy mô</p>
              </div>
              <p class="text-lg font-bold text-gray-900">${nominationPer100.toFixed(1)}</p>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
          <p class="font-semibold text-gray-900">Tình trạng dữ liệu</p>

          <div class="mt-5 space-y-3 text-sm">
            ${totalCampaigns === 0
        ? `<div class="p-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800">
                     Chưa có phong trào. Thống kê liên quan sẽ trống.
                   </div>`
        : `<div class="p-3 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800">
                     Đã có phong trào: ${fmt(totalCampaigns)}.
                   </div>`
      }

            ${totalUsers === 0
        ? `<div class="p-3 rounded-xl border border-rose-200 bg-rose-50 text-rose-800">
                     Chưa có tài khoản người dùng. Kiểm tra bảng users/dữ liệu seed.
                   </div>`
        : `<div class="p-3 rounded-xl border border-sky-200 bg-sky-50 text-sky-800">
                     Tài khoản người dùng: ${fmt(totalUsers)}.
                   </div>`
      }

            <div class="p-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-700">
              Gợi ý: bổ sung thống kê “30 ngày gần nhất” để theo dõi xu hướng.
            </div>
          </div>
        </div>
      </div>

      <div class="mb-3 flex items-center justify-between">
        <h3 class="text-base font-bold text-gray-900">Truy cập nhanh phân hệ</h3>
        <span class="text-xs text-gray-500 hidden md:inline">Điều hướng sang các tab thống kê</span>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        ${actionCard({ icon: "users", color: "indigo", title: "Đoàn viên", desc: "Thống kê đoàn viên/thanh niên", tab: "members" })}
        ${actionCard({ icon: "flag", color: "emerald", title: "Phong trào – Chiến dịch", desc: "Theo lớp/khoa, xuất Excel", tab: "campaigns" })}
        ${actionCard({ icon: "qr-code", color: "amber", title: "Điểm danh – QR", desc: "Thống kê điểm danh", tab: "attendance" })}
        ${actionCard({ icon: "nominations", color: "rose", title: "Thi đua – Khen thưởng", desc: "Tổng hợp thi đua", tab: "nominations" })}
        ${actionCard({ icon: "bell", color: "sky", title: "Thông báo", desc: "Thống kê thông báo", tab: "notifications" })}
        ${actionCard({ icon: "shield-check", color: "cyan", title: "Tài khoản & Phân quyền", desc: "Thống kê tài khoản", tab: "accounts" })}
        ${actionCard({ icon: "calendar-days", color: "violet", title: "Lịch công tác", desc: "Thống kê lịch", tab: "schedule" })}
        ${actionCard({ icon: "package", color: "indigo", title: "Thiết bị – Đồ dùng", desc: "Thống kê kho", tab: "inventory" })}
        ${actionCard({ icon: "scroll-text", color: "gray", title: "Nhật ký hoạt động", desc: "Thống kê logs", tab: "logs" })}
      </div>
    `;

    // jump tab nội bộ (không reload)
    el.querySelectorAll("[data-jump-tab]").forEach((a) => {
      a.addEventListener("click", (e) => {
        e.preventDefault();
        activateTab(a.dataset.jumpTab, true);
      });
    });

    createIcons();
  }

  // ======================
  // PLACEHOLDER
  // ======================
  function renderPlaceholder(tabName) {
    const panel = document.querySelector(`[data-tab-panel="${tabName}"]`);
    if (!panel) return;

    panel.innerHTML = `
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <p class="font-semibold text-gray-900">Đang phát triển</p>
        <p class="mt-1 text-sm text-gray-500">
          Phân hệ <b>${tabName}</b> sẽ được bổ sung thống kê chi tiết.
        </p>
      </div>
    `;
  }

  // ======================
  // TAB + URL
  // ======================
  function getTabFromURL() {
    const params = new URLSearchParams(window.location.search);
    return params.get("tab");
  }

  function setTabToURL(tab) {
    const url = new URL(window.location);
    url.searchParams.set("tab", tab);
    if (tab !== "campaigns") url.searchParams.delete("campaign_id");
    window.history.pushState({}, "", url);
  }

  async function activateTab(name, push = true) {
    const tabs = document.querySelectorAll(".tab-btn");
    const panels = document.querySelectorAll("[data-tab-panel]");
    if (!tabs.length || !panels.length) return;

    tabs.forEach((btn) => {
      const active = btn.dataset.tab === name;
      btn.classList.toggle("bg-indigo-600", active);
      btn.classList.toggle("text-white", active);
      btn.classList.toggle("bg-gray-100", !active);
      btn.classList.toggle("text-gray-700", !active);
    });

    panels.forEach((p) => {
      p.classList.toggle("hidden", p.dataset.tabPanel !== name);
    });

    if (push) setTabToURL(name);

    // overview
    if (name === "overview") {
      renderOverview();
      return;
    }

    // campaigns
    if (name === "campaigns") {
      if (typeof window.renderCampaigns === "function") {
        await window.renderCampaigns();
      } else {
        renderPlaceholder("campaigns");
      }
      createIcons();
      return;
    }

    // ===== ưu tiên StatsModules =====
    const mod = window.StatsModules?.[name];
    if (typeof mod === "function") {
      const wrap = document.querySelector(`[data-tab-panel="${name}"]`);
      if (wrap) {
        await mod(wrap);
        createIcons();
        return;
      }
    }

    // ===== fallback: window.render_<tab> hoặc window.renderTab =====
    const fn1 = window[`render_${name}`];
    const fn2 = window[`render${name.charAt(0).toUpperCase()}${name.slice(1)}`];

    if (typeof fn1 === "function") {
      await fn1();
      createIcons();
      return;
    }
    if (typeof fn2 === "function") {
      await fn2();
      createIcons();
      return;
    }

    renderPlaceholder(name);
    createIcons();
  }

  window.activateTab = activateTab;

  // ======================
  // INIT
  // ======================
  document.addEventListener("DOMContentLoaded", () => {
    const tabs = document.querySelectorAll(".tab-btn");
    if (!tabs.length) return;

    tabs.forEach((btn) => {
      btn.addEventListener("click", () => activateTab(btn.dataset.tab, true));
    });

    const initTab = getTabFromURL();
    const validTabs = Array.from(tabs).map((b) => b.dataset.tab);

    if (initTab && validTabs.includes(initTab)) {
      activateTab(initTab, false);
    } else {
      activateTab("overview", false);
    }

    window.addEventListener("popstate", () => {
      const t = getTabFromURL() || "overview";
      activateTab(t, false);
    });
  });
})();
