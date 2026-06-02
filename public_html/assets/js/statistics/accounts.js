// assets/js/statistics/accounts.js
(() => {
  if (window.__STATS_ACCOUNTS_READY__) return;
  window.__STATS_ACCOUNTS_READY__ = true;

  // ======================
  // API
  // ======================
  const BASE_API = "controllers/statistics/accounts.php";

  // ======================
  // STATE
  // ======================
  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "created_at", // fullname | username | role | mode | custom_perm_count | last_at | created_at
    sortDir: "desc",       // asc | desc
    search: "",
    roleId: "",
    mode: "all",           // all | role | custom
    onlyUnlinked: false,   // chưa liên kết members
    rows: [],
    summary: null,
    lastError: "",
  };

  // register for core statistics.js
  window.StatsModules = window.StatsModules || {};
  window.StatsModules.accounts = async (panelEl) => {
    await renderAccounts(panelEl);
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
        message: `Non-JSON response (HTTP ${res.status}). Có thể bị redirect login / sai đường dẫn / PHP warning.`,
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

  // ======================
  // OPTIONS
  // ======================
  async function loadRoleOptions() {
    const sel = document.getElementById("accRole");
    if (!sel) return;

    const url = `${BASE_API}?${qs({ action: "role_options" })}`;
    const json = await tryJson(url);

    if (!json?.ok) {
      sel.innerHTML = `<option value="">-- Tất cả role --</option>`;
      return;
    }

    sel.innerHTML =
      `<option value="">-- Tất cả role --</option>` +
      (json.data || []).map((r) => `<option value="${esc(r.id)}">${esc(r.name)}</option>`).join("");
  }

  // ======================
  // FETCH REPORT
  // ======================
  function getFiltersFromUI() {
    return {
      role_id: document.getElementById("accRole")?.value || "",
      mode: document.getElementById("accMode")?.value || "all",
      q: (document.getElementById("accSearch")?.value || "").trim(),
      created_from: document.getElementById("accFrom")?.value || "",
      created_to: document.getElementById("accTo")?.value || "",
      only_unlinked: document.getElementById("accOnlyUnlinked")?.checked ? 1 : 0,
    };
  }

  function normalizeRow(r) {
    return {
      user_id: num(r.user_id || r.id),
      fullname: String(r.user_fullname ?? r.fullname ?? ""),
      username: String(r.username ?? ""),
      mssv: String(r.mssv ?? ""),
      role_name: String(r.role_name ?? ""),
      role_id: String(r.role_id ?? ""),
      mode: String(r.permissions_mode ?? r.mode ?? ""),
      custom_perm_count: num(r.custom_perm_count ?? 0),
      activity_count: num(r.activity_count ?? 0),
      last_at: String(r.last_at ?? ""),
      created_at: String(r.created_at ?? ""),
      has_member: num(r.has_member ?? 0),
      raw: r,
    };
  }

  async function fetchAccountsReport(filters) {
    const url = `${BASE_API}?${qs({ action: "accounts_report", ...filters })}`;
    const json = await tryJson(url);

    if (!json?.ok) {
      const msg = json?.message || json?.error || "Không thể tải dữ liệu";
      const dbg = json?.debug
        ? `\n[DEBUG] ${json.debug.status} | ${json.debug.contentType}\n${json.debug.sample}`
        : "";
      return { ok: false, message: msg + dbg };
    }

    const rows = (json.rows || json.data?.rows || json.data || []).map(normalizeRow);
    const summary = json.summary || json.data?.summary || null;
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
        const hay = `${r.fullname} ${r.username} ${r.mssv} ${r.role_name}`.toLowerCase();
        return hay.includes(q);
      });
    }

    if (state.onlyUnlinked) {
      rows = rows.filter((r) => !num(r.has_member));
    }

    if (state.roleId) {
      rows = rows.filter((r) => String(r.role_id || "") === String(state.roleId));
    }

    if (state.mode && state.mode !== "all") {
      rows = rows.filter((r) => String(r.mode) === String(state.mode));
    }

    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "fullname") return a.fullname.localeCompare(b.fullname, "vi") * dir;
      if (key === "username") return a.username.localeCompare(b.username, "vi") * dir;
      if (key === "role") return a.role_name.localeCompare(b.role_name, "vi") * dir;
      if (key === "mode") return a.mode.localeCompare(b.mode, "vi") * dir;
      if (key === "custom_perm_count") return (a.custom_perm_count - b.custom_perm_count) * dir;
      if (key === "last_at") return String(a.last_at).localeCompare(String(b.last_at)) * dir;
      // default created_at
      return String(a.created_at).localeCompare(String(b.created_at)) * dir;
    });

    return rows;
  }

  function renderPagination(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="accGotoPage(1)">«</button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="accGotoPage(${state.currentPage - 1})"
          ${state.currentPage === 1 ? "disabled" : ""}>
          ‹
        </button>

        <input id="accPageInput" type="number" min="1" max="${totalPages}" value="${state.currentPage}"
          class="w-16 text-center border rounded px-2 py-1"
          onkeydown="if(event.key==='Enter') accGotoPage(this.value)" />

        <span class="text-gray-500">/ ${totalPages}</span>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="accGotoPage(${state.currentPage + 1})"
          ${state.currentPage === totalPages ? "disabled" : ""}>
          ›
        </button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="accGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  // ======================
  // RENDER PARTS
  // ======================
  function renderError(panelEl, message) {
    const box = panelEl.querySelector("#acc-error");
    if (!box) return;

    if (!message) {
      box.innerHTML = "";
      return;
    }

    box.innerHTML = `
      <div class="p-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 text-sm">
        <div class="font-semibold">Không thể tải thống kê tài khoản</div>
        <div class="mt-1">${esc(message)}</div>
        <div class="mt-2 text-xs text-rose-700">
          Yêu cầu backend có endpoint:
          <code class="px-1 bg-white/60 border rounded">accounts.php?action=accounts_report</code>
        </div>
      </div>
    `;
  }

  function renderKPI(panelEl) {
    const el = panelEl.querySelector("#acc-kpi");
    if (!el) return;

    const s = state.summary || {};
    const total = s.total_users != null ? num(s.total_users) : num((window.STATS || {}).total_users);
    const customUsers = num(s.custom_users);
    const roleUsers = num(s.role_users);
    const linked = num(s.linked_members);
    const unlinked = num(s.unlinked_members);
    const totalCustomPerms = num(s.total_custom_permissions);
    const totalLogs = num(s.total_activity_logs);

    const customRate = total > 0 ? Math.round((customUsers / total) * 100) : 0;
    const linkRate = total > 0 ? Math.round((linked / total) * 100) : 0;

    el.innerHTML = `
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        ${kpiCard({
          icon: "key-round",
          color: "indigo",
          label: "Tổng tài khoản",
          value: fmt(total),
          hint: "COUNT(users)",
        })}
        ${kpiCard({
          icon: "sliders-horizontal",
          color: "violet",
          label: "Chế độ Custom",
          value: fmt(customUsers),
          hint: `Tỷ lệ: ${customRate}%`,
        })}
        ${kpiCard({
          icon: "shield-check",
          color: "sky",
          label: "Chế độ Role",
          value: fmt(roleUsers),
          hint: "permissions_mode=role",
        })}
        ${kpiCard({
          icon: "users",
          color: "emerald",
          label: "Đã liên kết thành viên",
          value: fmt(linked),
          hint: `Tỷ lệ: ${linkRate}%`,
        })}
        ${kpiCard({
          icon: "user-x",
          color: "amber",
          label: "Chưa liên kết",
          value: fmt(unlinked),
          hint: "members.user_id IS NULL",
        })}
        ${kpiCard({
          icon: "badge-check",
          color: "rose",
          label: "Custom perms (tổng)",
          value: fmt(totalCustomPerms),
          hint: totalLogs ? `Activity logs: ${fmt(totalLogs)}` : "SUM(user_permissions)",
        })}
      </div>
    `;
  }

  function renderInsights(panelEl, rowsAll) {
    const el = panelEl.querySelector("#acc-insights");
    if (!el) return;

    if (!rowsAll.length) {
      el.innerHTML = "";
      return;
    }

    // top role
    const byRole = new Map();
    rowsAll.forEach((r) => {
      const k = r.role_name || "(Chưa gán role)";
      byRole.set(k, (byRole.get(k) || 0) + 1);
    });
    const topRoles = [...byRole.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5);

    // top custom perms
    const topCustom = [...rowsAll].sort((a, b) => b.custom_perm_count - a.custom_perm_count).slice(0, 5);

    el.innerHTML = `
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">Phân bổ theo Role (Top)</div>
            ${pill({ text: "Top 5", tone: "indigo" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topRoles
              .map(([name, c]) => {
                return `
                  <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                    <div class="min-w-0">
                      <div class="font-medium text-gray-900 truncate">${esc(name)}</div>
                    </div>
                    <div class="shrink-0 text-sm font-bold text-gray-900">${fmt(c)}</div>
                  </div>
                `;
              })
              .join("")}
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">Top gán quyền Custom</div>
            ${pill({ text: "Top 5", tone: "rose" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topCustom
              .map((r) => {
                const tone = r.custom_perm_count >= 10 ? "red" : r.custom_perm_count >= 5 ? "yellow" : "gray";
                const sub = [r.role_name, r.mssv || r.username].filter(Boolean).join(" · ");
                return `
                  <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                    <div class="min-w-0">
                      <div class="font-medium text-gray-900 truncate">${esc(r.fullname || r.username)}</div>
                      <div class="text-xs text-gray-500 truncate">${esc(sub)}</div>
                    </div>
                    <div class="shrink-0">
                      ${pill({ text: `Perm: ${fmt(r.custom_perm_count)}`, tone })}
                    </div>
                  </div>
                `;
              })
              .join("")}
          </div>
          <div class="mt-3 text-xs text-gray-500">
            Gợi ý: nếu muốn “độ phức tạp quyền”, có thể tính thêm tổng quyền effective (role_permissions + user_permissions).
          </div>
        </div>
      </div>
    `;
  }

  function bindSortHeaders(panelEl) {
    panelEl.querySelectorAll("[data-acc-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.accSort;
        if (!k) return;

        if (state.sortKey === k) {
          state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        } else {
          state.sortKey = k;
          state.sortDir = (k === "fullname" || k === "username" || k === "role" || k === "mode") ? "asc" : "desc";
        }
        state.currentPage = 1;
        renderTable(panelEl);
      };
    });
  }

  function renderTable(panelEl) {
    const head = panelEl.querySelector("#acc-head");
    const body = panelEl.querySelector("#acc-body");
    const pag = panelEl.querySelector("#acc-pagination");
    if (!head || !body || !pag) return;

    const rowsFiltered = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rowsFiltered.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rowsFiltered.slice(start, start + state.pageSize);

    head.innerHTML = `
      <th class="px-4 py-2 text-center w-[6%]">STT</th>
      <th class="px-4 py-2 text-left w-[22%]" data-acc-sort="fullname">Họ tên</th>
      <th class="px-4 py-2 text-left w-[14%]" data-acc-sort="username">Tài khoản</th>
      <th class="px-4 py-2 text-left w-[14%]" data-acc-sort="role">Role</th>
      <th class="px-4 py-2 text-left w-[10%]" data-acc-sort="mode">Chế độ</th>
      <th class="px-4 py-2 text-right w-[10%]" data-acc-sort="custom_perm_count">Custom perms</th>
      <th class="px-4 py-2 text-center w-[12%]" data-acc-sort="last_at">Hoạt động cuối</th>
      <th class="px-4 py-2 text-center w-[12%]" data-acc-sort="created_at">Tạo lúc</th>
    `;

    if (!rowsFiltered.length) {
      body.innerHTML = `
        <tr>
          <td colspan="8" class="px-4 py-10 text-center text-gray-500 italic">
            Không có dữ liệu phù hợp bộ lọc hiện tại.
          </td>
        </tr>
      `;
      pag.innerHTML = "";
      bindSortHeaders(panelEl);
      return;
    }

    body.innerHTML = display
      .map((r, i) => {
        const modeTone = r.mode === "custom" ? "violet" : "sky";
        const linkTone = r.has_member ? "green" : "amber";

        const full = r.fullname || r.username || "(Không rõ)";
        const sub = [
          r.mssv ? `MSSV: ${r.mssv}` : "",
          r.has_member ? "" : "Chưa liên kết members",
        ].filter(Boolean).join(" · ");

        return `
          <tr class="border-t hover:bg-gray-50 transition">
            <td class="px-4 py-2 text-center text-gray-500">${start + i + 1}</td>

            <td class="px-4 py-2">
              <div class="font-medium text-gray-900">${esc(full)}</div>
              ${sub ? `<div class="text-xs text-gray-500 mt-0.5">${esc(sub)}</div>` : ""}
              <div class="mt-1 flex gap-2 flex-wrap">
                ${pill({ text: r.has_member ? "Linked" : "Unlinked", tone: linkTone })}
              </div>
            </td>

            <td class="px-4 py-2 text-gray-800">${esc(r.username || "-")}</td>
            <td class="px-4 py-2 text-gray-800">${esc(r.role_name || "(Chưa gán)")}</td>

            <td class="px-4 py-2">
              ${pill({ text: r.mode || "-", tone: modeTone })}
            </td>

            <td class="px-4 py-2 text-right font-semibold ${r.custom_perm_count > 0 ? "text-rose-700" : "text-gray-500"}">
              ${fmt(r.custom_perm_count)}
            </td>

            <td class="px-4 py-2 text-center text-gray-600 text-sm">${esc(r.last_at || "-")}</td>
            <td class="px-4 py-2 text-center text-gray-600 text-sm">${esc(r.created_at || "-")}</td>
          </tr>
        `;
      })
      .join("");

    pag.innerHTML = totalPages > 1 ? renderPagination(totalPages) : "";
    bindSortHeaders(panelEl);
  }

  // ======================
  // EXPORT
  // ======================
  window.exportAccountsReport = function exportAccountsReport() {
    const f = getFiltersFromUI();
    const url = `${BASE_API}?${qs({ action: "export_accounts_report", ...f })}`;
    window.location.href = url;
  };

  // ======================
  // RUN REPORT
  // ======================
  async function runReport(panelEl) {
    renderError(panelEl, "");
    const loading = panelEl.querySelector("#acc-loading");
    if (loading) loading.classList.remove("hidden");

    const filters = getFiltersFromUI();

    // keep state
    state.search = filters.q || "";
    state.roleId = filters.role_id || "";
    state.mode = filters.mode || "all";
    state.onlyUnlinked = !!filters.only_unlinked;

    const result = await fetchAccountsReport(filters);

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

  // ======================
  // MAIN RENDER
  // ======================
  async function renderAccounts(panelEl) {
    panelEl.innerHTML = `
      <div class="mb-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900">Tài khoản & Phân quyền</h2>
            <p class="mt-1 text-sm text-gray-500">
              Thống kê tài khoản (role/custom), liên kết members, và mức độ gán quyền.
            </p>
          </div>
          <div class="hidden md:flex items-center gap-2">
            <button id="accBtnExport"
              class="px-4 py-2 rounded-xl border border-indigo-600 text-indigo-700 bg-white hover:bg-indigo-50 text-sm font-semibold">
              <span class="inline-flex items-center gap-2">
                <i data-lucide="download" class="w-4 h-4"></i>
                Export
              </span>
            </button>
          </div>
        </div>
      </div>

      <div id="acc-error" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Role</label>
            <select id="accRole" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả role --</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Chế độ quyền</label>
            <select id="accMode" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="role">Theo Role</option>
              <option value="custom">Custom</option>
            </select>
          </div>

          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm</label>
            <input id="accSearch" type="text"
              placeholder="Tên / username / MSSV / role..."
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày (tạo)</label>
            <input id="accFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày (tạo)</label>
            <input id="accTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Hiển thị</label>
            <select id="accPageSize" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="10">10 dòng / trang</option>
              <option value="15">15 dòng / trang</option>
              <option value="20">20 dòng / trang</option>
              <option value="30">30 dòng / trang</option>
              <option value="50">50 dòng / trang</option>
            </select>
          </div>

          <div class="flex items-end gap-2 xl:col-span-2">
            <button id="accBtnRun"
              class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
              Tải dữ liệu
            </button>
            <button id="accBtnReset"
              class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
              Reset
            </button>
          </div>

          <div class="xl:col-span-3 flex items-end">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
              <input id="accOnlyUnlinked" type="checkbox" class="w-4 h-4"/>
              Chỉ hiển thị tài khoản chưa liên kết members
            </label>
          </div>
        </div>
      </div>

      <div id="acc-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê...</div>

      <div id="acc-kpi" class="mb-4"></div>
      <div id="acc-insights" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <div class="overflow-x-auto mb-4">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="acc-head"></tr>
            </thead>
            <tbody id="acc-body"></tbody>
          </table>
        </div>
        <div id="acc-pagination"></div>
      </div>
    `;

    // bind events
    const role = panelEl.querySelector("#accRole");
    const mode = panelEl.querySelector("#accMode");
    const search = panelEl.querySelector("#accSearch");
    const from = panelEl.querySelector("#accFrom");
    const to = panelEl.querySelector("#accTo");
    const onlyUnlinked = panelEl.querySelector("#accOnlyUnlinked");
    const pageSize = panelEl.querySelector("#accPageSize");
    const btnRun = panelEl.querySelector("#accBtnRun");
    const btnReset = panelEl.querySelector("#accBtnReset");
    const btnExport = panelEl.querySelector("#accBtnExport");

    pageSize.value = String(state.pageSize);

    btnRun.addEventListener("click", async () => {
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      await runReport(panelEl);
    });

    btnReset.addEventListener("click", async () => {
      // reset UI
      role.value = "";
      mode.value = "all";
      search.value = "";
      from.value = "";
      to.value = "";
      onlyUnlinked.checked = false;
      pageSize.value = "10";

      // reset state
      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "created_at";
      state.sortDir = "desc";
      state.search = "";
      state.roleId = "";
      state.mode = "all";
      state.onlyUnlinked = false;
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
      window.exportAccountsReport?.();
    });

    // reactive light
    role.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });
    mode.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });
    onlyUnlinked.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });

    search.addEventListener("input", () => {
      state.search = (search.value || "").trim();
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
    window.accGotoPage = function accGotoPage(page) {
      page = parseInt(page, 10);
      if (isNaN(page) || page < 1) page = 1;
      state.currentPage = page;
      renderTable(panelEl);
    };

    // init
    await loadRoleOptions();
    await runReport(panelEl);
    createIcons();
  }
})();
