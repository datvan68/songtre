// assets/js/statistics/violations.js
(() => {
  if (window.__STATS_VIOLATIONS_READY__) return;
  window.__STATS_VIOLATIONS_READY__ = true;

  const BASE_API = "controllers/statistics/violations.php";

  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "created_at",
    sortDir: "desc",
    search: "",
    treatment: "",
    rows: [],
    summary: null,
    topViolators: [],
    topDepartments: [],
    lastError: "",
  };

  window.StatsModules = window.StatsModules || {};
  window.StatsModules.violations = async (panelEl) => {
    await renderViolations(panelEl);
  };

  const fmt = (n) => Number(n || 0).toLocaleString("vi-VN") + " ca";
  const fmtPeople = (n) => Number(n || 0).toLocaleString("vi-VN") + " người";
  const num = (n) => Number(n || 0);

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
        return JSON.parse(text);
      }
      return { ok: false, message: `Lỗi tải JSON (${res.status}).` };
    } catch (e) {
      return { ok: false, message: `Lỗi kết nối: ${e.message}` };
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
    };
    return `<span class="px-2 py-0.5 rounded-lg text-xs font-semibold ${map[tone] || map.gray}">${esc(text)}</span>`;
  }

  window.exportViolationsReport = function exportViolationsReport() {
    const filters = getFiltersFromUI();
    const url = `${BASE_API}?` + qs({ action: "export_violations_report", ...filters });
    window.location.href = url;
  };

  function getFiltersFromUI() {
    return {
      date_from: document.getElementById("vioDateFrom")?.value || "",
      date_to: document.getElementById("vioDateTo")?.value || "",
      treatment: document.getElementById("vioTreatment")?.value || "",
      q: (document.getElementById("vioSearch")?.value || "").trim(),
    };
  }

  async function fetchViolationsReport(filters) {
    const url = `${BASE_API}?${qs({ action: "violations_report", ...filters })}`;
    const json = await tryJson(url);
    if (!json?.ok) {
      return { ok: false, message: json?.error || "Không thể tải dữ liệu" };
    }
    return {
      ok: true,
      rows: json.rows || [],
      summary: json.summary || {},
      topViolators: json.top_violators || [],
      topDepartments: json.top_departments || [],
    };
  }

  function filteredSortedRows() {
    const q = state.search.trim().toLowerCase();
    let rows = [...state.rows];

    if (q) {
      rows = rows.filter((r) => {
        const hay = `${r.member_name} ${r.mssv} ${r.class_name} ${r.reason} ${r.treatment}`.toLowerCase();
        return hay.includes(q);
      });
    }

    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "member_name") return a.member_name.localeCompare(b.member_name, "vi") * dir;
      if (key === "mssv") return a.mssv.localeCompare(b.mssv) * dir;
      if (key === "treatment") return a.treatment.localeCompare(b.treatment, "vi") * dir;
      if (key === "created_at") return a.created_at.localeCompare(b.created_at) * dir;
      return (a.id - b.id) * dir;
    });

    return rows;
  }

  function renderKPI(panelEl) {
    const s = state.summary || {};
    const totalViolations = num(s.total_violations);
    const totalViolators = num(s.total_violators);
    const warningCount = num(s.warning_count);
    const reprimandCount = num(s.reprimand_count);

    const el = panelEl.querySelector("#vio-kpi");
    if (!el) return;

    el.innerHTML = `
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Tổng ca vi phạm</p>
              <p class="mt-1 text-2xl font-bold text-gray-900 leading-none">${fmt(totalViolations)}</p>
              <p class="mt-2 text-xs text-gray-400">Các lỗi kỷ luật</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-indigo-50">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-indigo-600"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Đoàn viên vi phạm</p>
              <p class="mt-1 text-2xl font-bold text-gray-900 leading-none">${fmtPeople(totalViolators)}</p>
              <p class="mt-2 text-xs text-gray-400">Số cá nhân vi phạm</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-sky-50">
              <i data-lucide="users" class="w-5 h-5 text-sky-600"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Số ca Cảnh cáo</p>
              <p class="mt-1 text-2xl font-bold text-rose-600 leading-none">${fmt(warningCount)}</p>
              <p class="mt-2 text-xs text-gray-400">Mức độ nghiêm trọng</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-rose-50">
              <i data-lucide="shield-alert" class="w-5 h-5 text-rose-600"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Số ca Khiển trách</p>
              <p class="mt-1 text-2xl font-bold text-amber-600 leading-none">${fmt(reprimandCount)}</p>
              <p class="mt-2 text-xs text-gray-400">Mức độ nhắc nhở</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-amber-50">
              <i data-lucide="megaphone" class="w-5 h-5 text-amber-600"></i>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function renderInsights(panelEl) {
    const el = panelEl.querySelector("#vio-insights");
    if (!el) return;

    if (!state.topViolators.length && !state.topDepartments.length) {
      el.innerHTML = "";
      return;
    }

    el.innerHTML = `
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="font-semibold text-gray-900 flex items-center gap-2">
            <i data-lucide="user-x" class="w-5 h-5 text-rose-600"></i> Top 5 Sinh viên vi phạm nhiều nhất
          </p>
          <div class="mt-3 space-y-2">
            ${state.topViolators.map(r => `
              <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50 transition">
                <div>
                  <span class="font-medium text-gray-800">${esc(r.fullname)}</span>
                  <span class="text-xs text-gray-400 ml-1">(${esc(r.mssv)})</span>
                </div>
                <span class="font-bold text-rose-600 shrink-0">${fmt(num(r.total))}</span>
              </div>
            `).join("")}
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="font-semibold text-gray-900 flex items-center gap-2">
            <i data-lucide="building-2" class="w-5 h-5 text-indigo-600"></i> Top 5 Khoa vi phạm nhiều nhất
          </p>
          <div class="mt-3 space-y-2">
            ${state.topDepartments.map(r => `
              <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50 transition">
                <span class="font-medium text-gray-700 truncate">${esc(r.dept_name || "Không xác định")}</span>
                <span class="font-bold text-indigo-600 shrink-0">${fmt(num(r.total))}</span>
              </div>
            `).join("")}
          </div>
        </div>
      </div>
    `;
  }

  function renderTable(panelEl) {
    const head = panelEl.querySelector("#vio-head");
    const body = panelEl.querySelector("#vio-body");
    const pag = panelEl.querySelector("#vio-pagination");
    if (!head || !body || !pag) return;

    const rowsFiltered = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rowsFiltered.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rowsFiltered.slice(start, start + state.pageSize);

    head.innerHTML = `
      <th class="px-4 py-3 text-center w-[8%]">STT</th>
      <th class="px-4 py-3 text-left w-[12%]" data-vio-sort="mssv">MSSV</th>
      <th class="px-4 py-3 text-left w-[18%]" data-vio-sort="member_name">Họ và tên</th>
      <th class="px-4 py-3 text-left w-[12%]">Lớp</th>
      <th class="px-4 py-3 text-left w-[25%]">Lý do vi phạm</th>
      <th class="px-4 py-3 text-center w-[13%]" data-vio-sort="treatment">Hình thức xử lý</th>
      <th class="px-4 py-3 text-center w-[12%]" data-vio-sort="created_at">Ngày ghi nhận</th>
    `;

    if (!rowsFiltered.length) {
      body.innerHTML = `<tr><td colspan="7" class="px-4 py-10 text-center text-gray-500 italic">Không tìm thấy dữ liệu vi phạm.</td></tr>`;
      pag.innerHTML = "";
      bindSortHeaders(panelEl);
      return;
    }

    body.innerHTML = display.map((r, i) => {
      const treatTone = r.treatment === "Cảnh cáo" ? "red" : r.treatment === "Khiển trách" ? "yellow" : "gray";
      const treatPill = pill({ text: r.treatment, tone: treatTone });

      return `
        <tr class="border-t hover:bg-gray-50 transition">
          <td class="px-4 py-3 text-center text-gray-500">${start + i + 1}</td>
          <td class="px-4 py-3 font-mono font-medium">${esc(r.mssv)}</td>
          <td class="px-4 py-3 font-medium text-gray-900">${esc(r.member_name)}</td>
          <td class="px-4 py-3 text-gray-700">${esc(r.class_name || "-")}</td>
          <td class="px-4 py-3 text-gray-600 text-sm whitespace-normal break-words">${esc(r.reason)}</td>
          <td class="px-4 py-3 text-center">${treatPill}</td>
          <td class="px-4 py-3 text-center text-gray-500 text-xs">${esc(r.created_at)}</td>
        </tr>
      `;
    }).join("");

    pag.innerHTML = totalPages > 1 ? renderPaginationHtml(totalPages) : "";
    bindSortHeaders(panelEl);
  }

  function renderPaginationHtml(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm pt-4 border-t border-gray-100">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="vioGotoPage(1)">«</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="vioGotoPage(${state.currentPage - 1})" ${state.currentPage === 1 ? "disabled" : ""}>‹</button>
        <input type="number" min="1" max="${totalPages}" value="${state.currentPage}" class="w-16 text-center border rounded px-2 py-1" onkeydown="if(event.key==='Enter') vioGotoPage(this.value)" />
        <span class="text-gray-500">/ ${totalPages}</span>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="vioGotoPage(${state.currentPage + 1})" ${state.currentPage === totalPages ? "disabled" : ""}>›</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="vioGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  function bindSortHeaders(panelEl) {
    panelEl.querySelectorAll("[data-vio-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.vio-sort || th.getAttribute("data-vio-sort");
        if (!k) return;
        if (state.sortKey === k) {
          state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        } else {
          state.sortKey = k;
          state.sortDir = k === "member_name" ? "asc" : "desc";
        }
        state.currentPage = 1;
        renderTable(panelEl);
      };
    });
  }

  async function runReport(panelEl) {
    const loading = panelEl.querySelector("#vio-loading");
    if (loading) loading.classList.remove("hidden");

    const filters = getFiltersFromUI();
    state.search = (filters.q || "").trim();

    const res = await fetchViolationsReport(filters);
    if (loading) loading.classList.add("hidden");

    if (!res.ok) {
      state.rows = [];
      state.summary = null;
      state.topViolators = [];
      state.topDepartments = [];
      state.lastError = res.message;
      alert(state.lastError);
      return;
    }

    state.rows = res.rows;
    state.summary = res.summary;
    state.topViolators = res.topViolators;
    state.topDepartments = res.topDepartments;
    state.currentPage = 1;

    renderKPI(panelEl);
    renderInsights(panelEl);
    renderTable(panelEl);
    createIcons();
  }

  async function renderViolations(panelEl) {
    panelEl.innerHTML = `
      <div class="mb-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
              <i data-lucide="alert-triangle" class="w-6 h-6 text-rose-600"></i> Thống kê Kỷ luật & Vi phạm
            </h2>
            <p class="mt-1 text-sm text-gray-500">Xem tổng hợp số ca vi phạm, các trường hợp kỷ luật nặng, danh sách vi phạm nhiều nhất theo sinh viên/khoa.</p>
          </div>
          <button id="vioBtnExport"
            class="px-4 py-2 rounded-xl border border-emerald-600 text-emerald-700 bg-white hover:bg-emerald-50 text-sm font-semibold flex items-center gap-2 transition">
            <i data-lucide="download" class="w-4 h-4"></i> Xuất báo cáo
          </button>
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày</label>
            <input id="vioDateFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày</label>
            <input id="vioDateTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Hình thức xử lý</label>
            <select id="vioTreatment" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">Tất cả</option>
              <option value="Cảnh cáo">Cảnh cáo</option>
              <option value="Khiển trách">Khiển trách</option>
              <option value="Phạt lao động">Phạt lao động</option>
              <option value="Trừ điểm rèn luyện">Trừ điểm rèn luyện</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm</label>
            <input id="vioSearch" type="text" placeholder="Tìm theo tên, MSSV, lớp, lý do..." class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
        </div>

        <div class="mt-4 flex justify-end gap-2">
          <button id="vioBtnRun" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">Tải báo cáo</button>
          <button id="vioBtnReset" class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">Làm mới</button>
        </div>
      </div>

      <div id="vio-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê kỷ luật...</div>
      <div id="vio-kpi" class="mb-6"></div>
      <div id="vio-insights" class="mb-6"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
          <span class="font-semibold text-gray-900">Danh sách các trường hợp vi phạm</span>
          <select id="vioPageSize" class="border rounded-lg px-2 py-1 text-xs">
            <option value="10">10 dòng / trang</option>
            <option value="15">15 dòng / trang</option>
            <option value="20">20 dòng / trang</option>
            <option value="50">50 dòng / trang</option>
          </select>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="vio-head"></tr>
            </thead>
            <tbody id="vio-body" class="divide-y divide-gray-100"></tbody>
          </table>
        </div>
        <div id="vio-pagination" class="mt-4"></div>
      </div>
    `;

    // bind events
    const btnRun = panelEl.querySelector("#vioBtnRun");
    const btnReset = panelEl.querySelector("#vioBtnReset");
    const btnExport = panelEl.querySelector("#vioBtnExport");
    const search = panelEl.querySelector("#vioSearch");
    const pageSize = panelEl.querySelector("#vioPageSize");

    btnRun.addEventListener("click", () => runReport(panelEl));
    btnReset.addEventListener("click", () => {
      panelEl.querySelector("#vioDateFrom").value = "";
      panelEl.querySelector("#vioDateTo").value = "";
      panelEl.querySelector("#vioTreatment").value = "";
      search.value = "";
      pageSize.value = "10";

      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "created_at";
      state.sortDir = "desc";
      state.search = "";
      state.rows = [];
      state.summary = null;
      state.topViolators = [];
      state.topDepartments = [];

      runReport(panelEl);
    });

    btnExport.addEventListener("click", () => window.exportViolationsReport());

    search.addEventListener("input", () => {
      state.search = search.value.trim();
      state.currentPage = 1;
      renderTable(panelEl);
    });

    pageSize.addEventListener("change", () => {
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      state.currentPage = 1;
      renderTable(panelEl);
    });

    window.vioGotoPage = function vioGotoPage(p) {
      p = parseInt(p, 10);
      if (isNaN(p) || p < 1) p = 1;
      state.currentPage = p;
      renderTable(panelEl);
    };

    await runReport(panelEl);
    createIcons();
  }
})();
