// assets/js/statistics/notifications.js
(() => {
  if (window.__STATS_NOTIFICATIONS_READY__) return;
  window.__STATS_NOTIFICATIONS_READY__ = true;

  // ======================
  // API
  // ======================
  const BASE_API = "controllers/statistics/notifications.php";

  // ======================
  // STATE
  // ======================
  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "total", // name | total | read | unread | last_at
    sortDir: "desc",  // asc | desc
    search: "",
    onlyUnread: false,
    groupBy: "user",  // user | day
    rows: [],
    summary: null,
    lastError: "",
  };

  // register module for core statistics.js
  window.StatsModules = window.StatsModules || {};
  window.StatsModules.notifications = async (panelEl) => {
    await renderNotifications(panelEl);
  };

  // ======================
  // UTIL
  // ======================
  const fmt = (n) => Number(n || 0).toLocaleString("vi-VN");
  const num = (n) => (Number.isFinite(Number(n)) ? Number(n) : 0);

  function esc(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function createIcons() {
    try {
      if (window.lucide && typeof window.lucide.createIcons === "function") {
        window.lucide.createIcons();
      }
    } catch (e) { }
  }

  function qs(params) {
    const p = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      p.set(k, String(v));
    });
    return p.toString();
  }

  async function tryJson(url) {
    try {
      const res = await fetch(url, {
        method: "GET",
        credentials: "include",
        cache: "no-store",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      const ct = (res.headers.get("content-type") || "").toLowerCase();
      const text = await res.text();

      if (ct.includes("application/json")) {
        const json = JSON.parse(text);
        if (!res.ok && json && typeof json === "object") {
          return { ok: false, message: json.message || json.error || `HTTP ${res.status}` };
        }
        return json;
      }

      let sample = text.replace(/\s+/g, " ").slice(0, 220);
      return {
        ok: false,
        message: `Non-JSON response (HTTP ${res.status}). Có thể redirect login / sai đường dẫn / PHP warning.`,
        debug: { url, status: res.status, contentType: ct || "(none)", sample },
      };
    } catch (e) {
      return { ok: false, message: `Network error: ${e?.message || String(e)}` };
    }
  }

  function pill({ text, tone }) {
    const map = {
      gray: "bg-gray-100 text-gray-700",
      green: "bg-emerald-100 text-emerald-800",
      yellow: "bg-amber-100 text-amber-800",
      red: "bg-rose-100 text-rose-800",
      sky: "bg-sky-100 text-sky-800",
      indigo: "bg-indigo-100 text-indigo-800",
      violet: "bg-violet-100 text-violet-800",
    };
    const cls = map[tone] || map.gray;
    return `<span class="px-2 py-1 rounded-lg text-xs font-semibold ${cls}">${esc(text)}</span>`;
  }

  function safeRate(a, b) {
    a = num(a); b = num(b);
    if (b <= 0) return 0;
    return Math.round((a / b) * 100);
  }

  function parseDateInput(id) {
    const v = document.getElementById(id)?.value || "";
    return v ? v : "";
  }

  // ======================
  // EXPORT
  // ======================
  window.exportNotificationsReport = function exportNotificationsReport() {
    const filters = getFiltersFromUI();
    const url =
      `${BASE_API}?` +
      qs({
        action: "export_notifications_report",
        ...filters,
      });
    window.location.href = url;
  };

  // ======================
  // FILTERS
  // ======================
  function getFiltersFromUI() {
    return {
      date_from: parseDateInput("notiDateFrom"),
      date_to: parseDateInput("notiDateTo"),
      status: document.getElementById("notiStatus")?.value || "all", // all|read|unread
      group_by: document.getElementById("notiGroupBy")?.value || "user",
      q: (document.getElementById("notiSearch")?.value || "").trim(),
    };
  }

  function normalizeRow(r, groupBy) {
    // backend recommended fields:
    // group_by=user: user_fullname, username, total, read_count, unread_count, last_at
    // group_by=day:  day, total, read_count, unread_count, last_at
    const total = num(r.total ?? 0);
    const read = num(r.read_count ?? r.read ?? 0);
    const unread = num(r.unread_count ?? r.unread ?? 0);
    const lastAt = r.last_at ?? "";

    let name = "";
    let sub1 = "";

    if (groupBy === "user") {
      name = r.user_fullname ?? r.fullname ?? r.name ?? "(Không rõ)";
      sub1 = r.username ?? "";
    } else {
      name = r.day ?? r.date ?? "-";
      sub1 = "";
    }

    return {
      name: String(name ?? ""),
      sub1: String(sub1 ?? ""),
      total,
      read,
      unread,
      lastAt: String(lastAt ?? ""),
      raw: r,
    };
  }

  async function fetchNotificationsReport(filters) {
    const url = `${BASE_API}?${qs({ action: "notifications_report", ...filters })}`;
    const json = await tryJson(url);

    if (!json?.ok) {
      const msg = json?.message || json?.error || "Không thể tải dữ liệu";
      const dbg = json?.debug
        ? `\n[DEBUG] ${json.debug.status} | ${json.debug.contentType}\n${json.debug.sample}`
        : "";
      return { ok: false, message: msg + dbg };
    }

    const groupBy = filters.group_by || "user";
    const rows = (json.rows || json.data?.rows || json.data || []).map((r) =>
      normalizeRow(r, groupBy)
    );

    const summary =
      json.summary ||
      json.data?.summary || {
        total: json.total ?? null,
        read: json.read_count ?? null,
        unread: json.unread_count ?? null,
        unique_users: json.unique_users ?? null,
      };

    return { ok: true, rows, summary };
  }

  // ======================
  // VIEW PIPELINE
  // ======================
  function filteredSortedRows() {
    const q = state.search.trim().toLowerCase();
    let rows = Array.isArray(state.rows) ? [...state.rows] : [];

    if (q) {
      rows = rows.filter((r) => {
        const hay = `${r.name} ${r.sub1}`.toLowerCase();
        return hay.includes(q);
      });
    }

    if (state.onlyUnread) {
      rows = rows.filter((r) => num(r.unread) > 0);
    }

    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "name") return a.name.localeCompare(b.name, "vi") * dir;
      if (key === "read") return (a.read - b.read) * dir;
      if (key === "unread") return (a.unread - b.unread) * dir;
      if (key === "last_at") return String(a.lastAt).localeCompare(String(b.lastAt)) * dir;
      return (a.total - b.total) * dir;
    });

    return rows;
  }

  function renderPagination(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="notiGotoPage(1)">«</button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="notiGotoPage(${state.currentPage - 1})"
          ${state.currentPage === 1 ? "disabled" : ""}>
          ‹
        </button>

        <input id="notiPageInput" type="number" min="1" max="${totalPages}" value="${state.currentPage}"
          class="w-16 text-center border rounded px-2 py-1"
          onkeydown="if(event.key==='Enter') notiGotoPage(this.value)" />

        <span class="text-gray-500">/ ${totalPages}</span>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="notiGotoPage(${state.currentPage + 1})"
          ${state.currentPage === totalPages ? "disabled" : ""}>
          ›
        </button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="notiGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  // ======================
  // RENDER PARTS
  // ======================
  function kpiCard({ icon, color, label, value, hint }) {
    return `
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500">${esc(label)}</p>
            <p class="mt-1 text-3xl font-bold text-gray-900 leading-none">${esc(value)}</p>
            ${hint ? `<p class="mt-2 text-xs text-gray-500">${hint}</p>` : ""}
          </div>
          <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-${color}-50">
            <i data-lucide="${icon}" class="w-5 h-5 text-${color}-700"></i>
          </div>
        </div>
      </div>
    `;
  }

  function renderError(panelEl, message) {
    const box = panelEl.querySelector("#noti-error");
    if (!box) return;

    if (!message) {
      box.innerHTML = "";
      return;
    }

    box.innerHTML = `
      <div class="p-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 text-sm">
        <div class="font-semibold">Không thể tải thống kê thông báo</div>
        <div class="mt-1">${esc(message)}</div>
        <div class="mt-2 text-xs text-rose-700">
          Yêu cầu backend:
          <code class="px-1 bg-white/60 border rounded">notifications.php?action=notifications_report</code>
        </div>
      </div>
    `;
  }

  function renderKPI(panelEl) {
    const STATS = window.STATS || {};
    const fallbackTotal = num(STATS.total_notifications); // nếu bạn có field này trong STATS

    const s = state.summary || {};
    const total = s.total != null ? num(s.total) : fallbackTotal;
    const read = s.read != null ? num(s.read) : 0;
    const unread = s.unread != null ? num(s.unread) : 0;
    const uniqUsers = s.unique_users != null ? num(s.unique_users) : null;

    const readRate = total > 0 ? `${safeRate(read, total)}%` : "0%";

    const el = panelEl.querySelector("#noti-kpi");
    if (!el) return;

    el.innerHTML = `
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        ${kpiCard({
      icon: "bell",
      color: "indigo",
      label: "Tổng thông báo",
      value: fmt(total),
      hint: "COUNT(notifications)",
    })}
        ${kpiCard({
      icon: "check-circle-2",
      color: "emerald",
      label: "Đã đọc",
      value: fmt(read),
      hint: "is_read=1",
    })}
        ${kpiCard({
      icon: "bell-ring",
      color: "rose",
      label: "Chưa đọc",
      value: fmt(unread),
      hint: "is_read=0",
    })}
        ${kpiCard({
      icon: "percent",
      color: "sky",
      label: "Tỷ lệ đã đọc",
      value: readRate,
      hint: "read / total",
    })}
        ${kpiCard({
      icon: "users",
      color: "violet",
      label: "Unique user",
      value: uniqUsers != null ? fmt(uniqUsers) : "-",
      hint: uniqUsers == null ? "Backend nên trả unique_users" : "COUNT(DISTINCT user_id)",
    })}
        ${kpiCard({
      icon: state.groupBy === "user" ? "user" : "calendar-days",
      color: "amber",
      label: "Nhóm theo",
      value: state.groupBy === "user" ? "User" : "Ngày",
      hint: "group_by",
    })}
      </div>
    `;
  }

  function renderInsights(panelEl, rowsAll) {
    const el = panelEl.querySelector("#noti-insights");
    if (!el) return;

    if (!rowsAll.length) {
      el.innerHTML = "";
      return;
    }

    const topTotal = [...rowsAll].sort((a, b) => b.total - a.total).slice(0, 5);
    const topUnread = [...rowsAll].sort((a, b) => b.unread - a.unread).slice(0, 5);

    const labelLeft = state.groupBy === "user" ? "Top người nhận (tổng)" : "Top ngày (tổng)";
    const labelRight = state.groupBy === "user" ? "Top chưa đọc (unread)" : "Top ngày unread";

    el.innerHTML = `
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">${esc(labelLeft)}</div>
            ${pill({ text: "Top 5", tone: "indigo" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topTotal.map((r) => `
              <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                <div class="min-w-0">
                  <div class="font-medium text-gray-900 truncate">${esc(r.name)}</div>
                  ${r.sub1 ? `<div class="text-xs text-gray-500 truncate">${esc(r.sub1)}</div>` : ""}
                </div>
                <div class="shrink-0 text-sm font-bold text-gray-900">${fmt(r.total)}</div>
              </div>
            `).join("")}
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">${esc(labelRight)}</div>
            ${pill({ text: "Top 5", tone: "red" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topUnread.map((r) => {
      const tone = r.unread > 0 ? "red" : "gray";
      return `
                <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                  <div class="min-w-0">
                    <div class="font-medium text-gray-900 truncate">${esc(r.name)}</div>
                    ${r.sub1 ? `<div class="text-xs text-gray-500 truncate">${esc(r.sub1)}</div>` : ""}
                  </div>
                  <div class="shrink-0">${pill({ text: `Unread: ${fmt(r.unread)}`, tone })}</div>
                </div>
              `;
    }).join("")}
          </div>
          <div class="mt-3 text-xs text-gray-500">
            Gợi ý: nếu cần “tỷ lệ unread”, backend có thể trả thêm (unread/total) theo từng nhóm.
          </div>
        </div>
      </div>
    `;
  }

  function bindSortHeaders(panelEl) {
    panelEl.querySelectorAll("[data-noti-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.notiSort;
        if (!k) return;

        if (state.sortKey === k) {
          state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        } else {
          state.sortKey = k;
          state.sortDir = k === "name" ? "asc" : "desc";
        }
        state.currentPage = 1;
        renderTable(panelEl);
      };
    });
  }

  function renderTable(panelEl) {
    const head = panelEl.querySelector("#noti-head");
    const body = panelEl.querySelector("#noti-body");
    const pag = panelEl.querySelector("#noti-pagination");
    if (!head || !body || !pag) return;

    const rowsFiltered = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rowsFiltered.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rowsFiltered.slice(start, start + state.pageSize);

    const isUser = state.groupBy === "user";

    head.innerHTML = `
      <th class="px-4 py-2 text-center w-[8%]">STT</th>
      <th class="px-4 py-2 text-left w-[28%]" data-noti-sort="name">${isUser ? "Người nhận" : "Ngày"}</th>
      <th class="px-4 py-2 text-left w-[12%]">${isUser ? "Username" : ""}</th>
      <th class="px-4 py-2 text-right w-[8%]" data-noti-sort="total">Tổng</th>
      <th class="px-4 py-2 text-right w-[9%]" data-noti-sort="read">Đã đọc</th>
      <th class="px-4 py-2 text-right w-[9%]" data-noti-sort="unread">Chưa đọc</th>
      <th class="px-4 py-2 text-center w-[18%]" data-noti-sort="last_at">Lần cuối</th>
    `;

    if (!rowsFiltered.length) {
      body.innerHTML = `
        <tr>
          <td colspan="7" class="px-4 py-10 text-center text-gray-500 italic">
            Không có dữ liệu phù hợp bộ lọc hiện tại.
          </td>
        </tr>
      `;
      pag.innerHTML = "";
      bindSortHeaders(panelEl);
      return;
    }

    body.innerHTML = display.map((r, i) => {
      const readRate = r.total > 0 ? safeRate(r.read, r.total) : 0;
      const rateTone = readRate >= 80 ? "green" : readRate >= 50 ? "yellow" : "red";
      return `
        <tr class="border-t hover:bg-gray-50 transition">
          <td class="px-4 py-2 text-center text-gray-500">${start + i + 1}</td>

          <td class="px-4 py-2 font-medium">
            <div class="text-gray-900">${esc(r.name)}</div>
            ${pill({ text: `Read ${readRate}%`, tone: rateTone })}
          </td>

          <td class="px-4 py-2 text-gray-700">${isUser ? esc(r.sub1 || "-") : ""}</td>

          <td class="px-4 py-2 text-right font-bold text-gray-900">${fmt(r.total)}</td>
          <td class="px-4 py-2 text-right font-semibold text-emerald-700">${fmt(r.read)}</td>
          <td class="px-4 py-2 text-right font-semibold ${r.unread > 0 ? "text-rose-700" : "text-gray-500"}">${fmt(r.unread)}</td>
          <td class="px-4 py-2 text-center text-gray-600 text-sm">${esc(r.lastAt || "-")}</td>
        </tr>
      `;
    }).join("");

    pag.innerHTML = totalPages > 1 ? renderPagination(totalPages) : "";
    bindSortHeaders(panelEl);
  }

  // ======================
  // MAIN PIPELINE
  // ======================
  async function runReport(panelEl) {
    renderError(panelEl, "");
    const loading = panelEl.querySelector("#noti-loading");
    if (loading) loading.classList.remove("hidden");

    const filters = getFiltersFromUI();
    state.groupBy = filters.group_by || "user";
    state.search = (filters.q || "").trim();

    const result = await fetchNotificationsReport(filters);

    if (loading) loading.classList.add("hidden");

    if (!result.ok) {
      state.rows = [];
      state.summary = null;
      state.lastError = result.message || "Không thể tải dữ liệu";
      renderError(panelEl, state.lastError);
      renderKPI(panelEl);
      renderInsights(panelEl, []);
      renderTable(panelEl);
      createIcons();
      return;
    }

    state.rows = result.rows || [];
    state.summary = result.summary || {};
    state.lastError = "";
    state.currentPage = 1;

    renderKPI(panelEl);
    renderInsights(panelEl, filteredSortedRows());
    renderTable(panelEl);
    createIcons();
  }

  async function renderNotifications(panelEl) {
    panelEl.innerHTML = `
      <div class="mb-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900">Thông báo</h2>
            <p class="mt-1 text-sm text-gray-500">Thống kê theo thời gian và nhóm (user/ngày), kèm lọc trạng thái đã đọc/chưa đọc.</p>
          </div>
          <div class="hidden md:flex items-center gap-2">
            <button id="notiBtnExport"
              class="px-4 py-2 rounded-xl border border-indigo-600 text-indigo-700 bg-white hover:bg-indigo-50 text-sm font-semibold">
              <span class="inline-flex items-center gap-2">
                <i data-lucide="download" class="w-4 h-4"></i>
                Export
              </span>
            </button>
          </div>
        </div>
      </div>

      <div id="noti-error" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày</label>
            <input id="notiDateFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày</label>
            <input id="notiDateTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Nhóm theo</label>
            <select id="notiGroupBy" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="user">Theo user</option>
              <option value="day">Theo ngày</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Trạng thái</label>
            <select id="notiStatus" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="read">Đã đọc</option>
              <option value="unread">Chưa đọc</option>
            </select>
          </div>

          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm</label>
            <input id="notiSearch" type="text"
              placeholder="Nhập tên/username..."
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Hiển thị</label>
            <select id="notiPageSize" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="10">10 dòng / trang</option>
              <option value="15">15 dòng / trang</option>
              <option value="20">20 dòng / trang</option>
              <option value="30">30 dòng / trang</option>
              <option value="50">50 dòng / trang</option>
            </select>
          </div>

          <div class="flex items-end gap-2 xl:col-span-2">
            <button id="notiBtnRun"
              class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
              Tải dữ liệu
            </button>
            <button id="notiBtnReset"
              class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
              Reset
            </button>
          </div>

          <div class="xl:col-span-3 flex items-end">
            <div class="flex items-center gap-2">
              <input id="notiOnlyUnread" type="checkbox" class="w-4 h-4"/>
              <label for="notiOnlyUnread" class="text-sm text-gray-700">Chỉ hiển thị dòng có Unread &gt; 0</label>
            </div>
          </div>
        </div>
      </div>

      <div id="noti-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê...</div>

      <div id="noti-kpi" class="mb-4"></div>
      <div id="noti-insights" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <div class="overflow-x-auto mb-4">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="noti-head"></tr>
            </thead>
            <tbody id="noti-body"></tbody>
          </table>
        </div>
        <div id="noti-pagination"></div>
      </div>
    `;

    // bind events
    const groupBy = panelEl.querySelector("#notiGroupBy");
    const status = panelEl.querySelector("#notiStatus");
    const search = panelEl.querySelector("#notiSearch");
    const onlyUnread = panelEl.querySelector("#notiOnlyUnread");
    const pageSize = panelEl.querySelector("#notiPageSize");
    const btnRun = panelEl.querySelector("#notiBtnRun");
    const btnReset = panelEl.querySelector("#notiBtnReset");
    const btnExport = panelEl.querySelector("#notiBtnExport");

    pageSize.value = String(state.pageSize);

    btnRun.addEventListener("click", async () => {
      state.search = (search.value || "").trim();
      state.onlyUnread = !!onlyUnread.checked;
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      await runReport(panelEl);
    });

    btnReset.addEventListener("click", async () => {
      panelEl.querySelector("#notiDateFrom").value = "";
      panelEl.querySelector("#notiDateTo").value = "";
      groupBy.value = "user";
      status.value = "all";
      search.value = "";
      onlyUnread.checked = false;
      pageSize.value = "10";

      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "total";
      state.sortDir = "desc";
      state.search = "";
      state.onlyUnread = false;
      state.groupBy = "user";
      state.rows = [];
      state.summary = null;
      state.lastError = "";

      renderError(panelEl, "");
      renderKPI(panelEl);
      renderInsights(panelEl, []);
      renderTable(panelEl);
      createIcons();
    });

    btnExport.addEventListener("click", () => {
      window.exportNotificationsReport?.();
    });

    // reactive
    groupBy.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });
    status.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });

    search.addEventListener("input", () => {
      state.search = (search.value || "").trim();
      state.currentPage = 1;
      renderInsights(panelEl, filteredSortedRows());
      renderTable(panelEl);
    });

    onlyUnread.addEventListener("change", () => {
      state.onlyUnread = !!onlyUnread.checked;
      state.currentPage = 1;
      renderInsights(panelEl, filteredSortedRows());
      renderTable(panelEl);
    });

    pageSize.addEventListener("change", () => {
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      state.currentPage = 1;
      renderTable(panelEl);
    });

    // global pagination hook
    window.notiGotoPage = function notiGotoPage(page) {
      page = parseInt(page, 10);
      if (isNaN(page) || page < 1) page = 1;
      state.currentPage = page;
      renderTable(panelEl);
    };

    // load once
    await runReport(panelEl);
    createIcons();
  }
})();
