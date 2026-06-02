// assets/js/statistics/schedule.js
(() => {
  if (window.__STATS_SCHEDULE_READY__) return;
  window.__STATS_SCHEDULE_READY__ = true;

  window.StatsModules = window.StatsModules || {};
  const BASE_API = "controllers/statistics/schedule.php";

  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "start_date", // title | department | status | creator | start_date | end_date
    sortDir: "desc",
    rows: [],
    summary: null,

    // filters
    q: "",
    status: "all",
    dateFrom: "",
    dateTo: "",
    dept: "",
    creatorId: "",
    upcomingOnly: false, // chỉ lịch sắp tới
  };

  const fmt = (n) => Number(n || 0).toLocaleString("vi-VN");
  const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

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
      window.lucide?.createIcons?.();
    } catch (e) {}
  }

  function qs(params) {
    const p = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v === undefined || v === null || v === "") return;
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

      return {
        ok: false,
        message:
          "Non-JSON response (có thể bị redirect login / PHP warning / sai đường dẫn).",
        debug: {
          status: res.status,
          contentType: ct || "(none)",
          sample: text.replace(/\s+/g, " ").slice(0, 220),
        },
      };
    } catch (e) {
      return { ok: false, message: `Network error: ${e?.message || String(e)}` };
    }
  }

  function pill(text, tone = "gray") {
    const map = {
      gray: "bg-gray-100 text-gray-700",
      green: "bg-emerald-100 text-emerald-800",
      yellow: "bg-amber-100 text-amber-800",
      red: "bg-rose-100 text-rose-800",
      sky: "bg-sky-100 text-sky-800",
      indigo: "bg-indigo-100 text-indigo-800",
      violet: "bg-violet-100 text-violet-800",
    };
    return `<span class="px-2 py-1 rounded-lg text-xs font-semibold ${
      map[tone] || map.gray
    }">${esc(text)}</span>`;
  }

  function statusMeta(st) {
    // table schedule.status:
    // pending | approved | update_pending | delete_pending | rejected
    const m = {
      pending: { label: "Chờ duyệt", tone: "yellow", icon: "clock" },
      approved: { label: "Đã duyệt", tone: "green", icon: "badge-check" },
      update_pending: { label: "Chờ cập nhật", tone: "violet", icon: "file-pen-line" },
      delete_pending: { label: "Chờ xoá", tone: "red", icon: "trash-2" },
      rejected: { label: "Từ chối", tone: "red", icon: "x-circle" },
    };
    return m[st] || { label: st || "-", tone: "gray", icon: "help-circle" };
  }

  function kpiCard({ label, value, hint, icon, color }) {
    return `
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between gap-4">
          <div>
            <div class="text-sm text-gray-500">${esc(label)}</div>
            <div class="mt-1 text-3xl font-bold text-gray-900 leading-none">${esc(
              value
            )}</div>
            ${hint ? `<div class="mt-2 text-xs text-gray-500">${hint}</div>` : ""}
          </div>
          <div class="w-11 h-11 rounded-xl bg-${color}-50 flex items-center justify-center">
            <i data-lucide="${icon}" class="w-5 h-5 text-${color}-700"></i>
          </div>
        </div>
      </div>
    `;
  }

  function normalizeRow(r) {
    return {
      id: num(r.id),
      title: String(r.title || ""),
      department: String(r.department || ""),
      location: String(r.location || ""),
      participants: String(r.participants || ""),
      start_date: String(r.start_date || ""),
      end_date: String(r.end_date || ""),
      status: String(r.status || ""),
      reject_note: String(r.reject_note || ""),
      created_by: num(r.created_by || 0),
      creator_name: String(r.creator_name || ""),
      raw: r,
    };
  }

  async function loadCreatorOptions(panelEl) {
    const sel = panelEl.querySelector("#schCreator");
    if (!sel) return;

    const json = await tryJson(`${BASE_API}?${qs({ action: "creator_options" })}`);
    if (!json?.ok) {
      sel.innerHTML = `<option value="">-- Tất cả người tạo --</option>`;
      return;
    }

    sel.innerHTML =
      `<option value="">-- Tất cả người tạo --</option>` +
      (json.data || [])
        .map((u) => `<option value="${esc(u.id)}">${esc(u.name)}</option>`)
        .join("");
  }

  function getFilters(panelEl) {
    return {
      q: (panelEl.querySelector("#schQ")?.value || "").trim(),
      status: panelEl.querySelector("#schStatus")?.value || "all",
      date_from: panelEl.querySelector("#schFrom")?.value || "",
      date_to: panelEl.querySelector("#schTo")?.value || "",
      dept: (panelEl.querySelector("#schDept")?.value || "").trim(),
      creator_id: panelEl.querySelector("#schCreator")?.value || "",
      upcoming_only: panelEl.querySelector("#schUpcomingOnly")?.checked ? 1 : 0,
    };
  }

  async function fetchReport(filters) {
    const json = await tryJson(`${BASE_API}?${qs({ action: "schedule_report", ...filters })}`);
    if (!json?.ok) return { ok: false, message: json?.message || "Không thể tải dữ liệu" };
    return {
      ok: true,
      rows: (json.rows || []).map(normalizeRow),
      summary: json.summary || {},
    };
  }

  function filteredSortedRows() {
    let rows = [...(state.rows || [])];

    const q = state.q.trim().toLowerCase();
    if (q) {
      rows = rows.filter((r) => {
        const hay = `${r.title} ${r.department} ${r.location} ${r.participants} ${r.creator_name}`.toLowerCase();
        return hay.includes(q);
      });
    }

    if (state.status && state.status !== "all") {
      rows = rows.filter((r) => String(r.status) === String(state.status));
    }
    if (state.dept) {
      const d = state.dept.toLowerCase();
      rows = rows.filter((r) => String(r.department || "").toLowerCase().includes(d));
    }
    if (state.creatorId) {
      rows = rows.filter((r) => String(r.created_by) === String(state.creatorId));
    }

    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "title") return a.title.localeCompare(b.title, "vi") * dir;
      if (key === "department") return a.department.localeCompare(b.department, "vi") * dir;
      if (key === "status") return a.status.localeCompare(b.status, "vi") * dir;
      if (key === "creator") return a.creator_name.localeCompare(b.creator_name, "vi") * dir;
      if (key === "end_date") return String(a.end_date).localeCompare(String(b.end_date)) * dir;
      // default start_date
      return String(a.start_date).localeCompare(String(b.start_date)) * dir;
    });

    return rows;
  }

  function renderPagination(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="schGotoPage(1)">«</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="schGotoPage(${state.currentPage - 1})" ${state.currentPage === 1 ? "disabled" : ""}>‹</button>

        <input id="schPageInput" type="number" min="1" max="${totalPages}" value="${state.currentPage}"
          class="w-16 text-center border rounded px-2 py-1"
          onkeydown="if(event.key==='Enter') schGotoPage(this.value)" />

        <span class="text-gray-500">/ ${totalPages}</span>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="schGotoPage(${state.currentPage + 1})" ${state.currentPage === totalPages ? "disabled" : ""}>›</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="schGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  function renderError(panelEl, msg) {
    const el = panelEl.querySelector("#sch-error");
    if (!el) return;
    if (!msg) {
      el.innerHTML = "";
      return;
    }
    el.innerHTML = `
      <div class="p-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 text-sm">
        <div class="font-semibold">Không thể tải thống kê lịch công tác</div>
        <div class="mt-1">${esc(msg)}</div>
      </div>
    `;
  }

  function renderKPI(panelEl) {
    const el = panelEl.querySelector("#sch-kpi");
    if (!el) return;

    const s = state.summary || {};
    const total = num(s.total);
    const pending = num(s.pending);
    const approved = num(s.approved);
    const rejected = num(s.rejected);
    const updatePending = num(s.update_pending);
    const deletePending = num(s.delete_pending);
    const upcoming7 = num(s.upcoming_7);

    el.innerHTML = `
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        ${kpiCard({
          label: "Tổng lịch công tác",
          value: fmt(total),
          hint: "COUNT(schedule)",
          icon: "calendar",
          color: "indigo",
        })}
        ${kpiCard({
          label: "Đã duyệt",
          value: fmt(approved),
          hint: "status=approved",
          icon: "badge-check",
          color: "emerald",
        })}
        ${kpiCard({
          label: "Chờ duyệt",
          value: fmt(pending),
          hint: "status=pending",
          icon: "clock",
          color: "amber",
        })}
        ${kpiCard({
          label: "Chờ cập nhật",
          value: fmt(updatePending),
          hint: "status=update_pending",
          icon: "file-pen-line",
          color: "violet",
        })}
        ${kpiCard({
          label: "Chờ xoá",
          value: fmt(deletePending),
          hint: "status=delete_pending",
          icon: "trash-2",
          color: "rose",
        })}
        ${kpiCard({
          label: "Sắp diễn ra (7 ngày)",
          value: fmt(upcoming7),
          hint: "start_date trong 7 ngày tới",
          icon: "calendar-clock",
          color: "sky",
        })}
      </div>
    `;
  }

  function renderInsights(panelEl) {
    const el = panelEl.querySelector("#sch-insights");
    if (!el) return;

    const s = state.summary || {};
    const topCreators = Array.isArray(s.top_creators) ? s.top_creators.slice(0, 5) : [];
    const topDepts = Array.isArray(s.top_departments) ? s.top_departments.slice(0, 5) : [];

    if (!topCreators.length && !topDepts.length) {
      el.innerHTML = "";
      return;
    }

    el.innerHTML = `
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">Top người tạo lịch</div>
            ${pill("Top 5", "indigo")}
          </div>
          <div class="mt-3 space-y-2">
            ${
              topCreators.length
                ? topCreators
                    .map(
                      (x) => `
                        <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                          <div class="min-w-0">
                            <div class="font-medium text-gray-900 truncate">${esc(x.name || "(Không rõ)")}</div>
                            <div class="text-xs text-gray-500 truncate">User ID: ${esc(x.user_id)}</div>
                          </div>
                          <div class="shrink-0 text-sm font-bold text-gray-900">${fmt(x.total)}</div>
                        </div>
                      `
                    )
                    .join("")
                : `<div class="text-sm text-gray-500 italic">Chưa có dữ liệu.</div>`
            }
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">Top đơn vị / khoa / phòng</div>
            ${pill("Top 5", "sky")}
          </div>
          <div class="mt-3 space-y-2">
            ${
              topDepts.length
                ? topDepts
                    .map(
                      (x) => `
                        <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                          <div class="min-w-0">
                            <div class="font-medium text-gray-900 truncate">${esc(x.department || "(Không rõ)")}</div>
                          </div>
                          <div class="shrink-0 text-sm font-bold text-gray-900">${fmt(x.total)}</div>
                        </div>
                      `
                    )
                    .join("")
                : `<div class="text-sm text-gray-500 italic">Chưa có dữ liệu.</div>`
            }
          </div>
          <div class="mt-3 text-xs text-gray-500">
            Gợi ý: nếu muốn chuẩn hoá theo bảng departments, có thể đổi schedule.department sang department_id.
          </div>
        </div>
      </div>
    `;
  }

  function bindSort(panelEl) {
    panelEl.querySelectorAll("[data-sch-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.schSort;
        if (!k) return;

        if (state.sortKey === k) state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        else {
          state.sortKey = k;
          state.sortDir = (k === "title" || k === "department" || k === "creator" || k === "status") ? "asc" : "desc";
        }
        state.currentPage = 1;
        renderTable(panelEl);
      };
    });
  }

  function renderTable(panelEl) {
    const head = panelEl.querySelector("#sch-head");
    const body = panelEl.querySelector("#sch-body");
    const pag = panelEl.querySelector("#sch-pagination");
    if (!head || !body || !pag) return;

    const rows = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rows.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rows.slice(start, start + state.pageSize);

    head.innerHTML = `
      <th class="px-4 py-2 text-center w-[6%]">STT</th>
      <th class="px-4 py-2 text-left w-[28%]" data-sch-sort="title">Tiêu đề</th>
      <th class="px-4 py-2 text-left w-[16%]" data-sch-sort="department">Đơn vị</th>
      <th class="px-4 py-2 text-center w-[14%]" data-sch-sort="start_date">Bắt đầu</th>
      <th class="px-4 py-2 text-center w-[14%]" data-sch-sort="end_date">Kết thúc</th>
      <th class="px-4 py-2 text-center w-[10%]" data-sch-sort="status">Trạng thái</th>
      <th class="px-4 py-2 text-left w-[12%]" data-sch-sort="creator">Người tạo</th>
    `;

    if (!rows.length) {
      body.innerHTML = `
        <tr>
          <td colspan="7" class="px-4 py-10 text-center text-gray-500 italic">
            Không có lịch phù hợp bộ lọc hiện tại.
          </td>
        </tr>
      `;
      pag.innerHTML = "";
      bindSort(panelEl);
      return;
    }

    body.innerHTML = display
      .map((r, i) => {
        const sm = statusMeta(r.status);
        const sub = [
          r.location ? `Địa điểm: ${r.location}` : "",
          r.participants ? `Thành phần: ${r.participants}` : "",
          r.reject_note && r.status === "rejected" ? `Lý do: ${r.reject_note}` : "",
        ]
          .filter(Boolean)
          .join(" · ");

        return `
          <tr class="border-t hover:bg-gray-50 transition">
            <td class="px-4 py-2 text-center text-gray-500">${start + i + 1}</td>

            <td class="px-4 py-2">
              <div class="font-medium text-gray-900">${esc(r.title || "(Không có tiêu đề)")}</div>
              ${
                sub
                  ? `<div class="text-xs text-gray-500 mt-0.5 line-clamp-2">${esc(sub)}</div>`
                  : ""
              }
            </td>

            <td class="px-4 py-2 text-gray-800">${esc(r.department || "-")}</td>
            <td class="px-4 py-2 text-center text-gray-700 text-sm">${esc(r.start_date || "-")}</td>
            <td class="px-4 py-2 text-center text-gray-700 text-sm">${esc(r.end_date || "-")}</td>

            <td class="px-4 py-2 text-center">
              ${pill(sm.label, sm.tone)}
            </td>

            <td class="px-4 py-2 text-gray-800">
              <div class="font-medium">${esc(r.creator_name || "(Không rõ)")}</div>
              <div class="text-xs text-gray-500">ID: ${esc(r.created_by || 0)}</div>
            </td>
          </tr>
        `;
      })
      .join("");

    pag.innerHTML = totalPages > 1 ? renderPagination(totalPages) : "";
    bindSort(panelEl);
  }

  window.exportScheduleReport = function exportScheduleReport() {
    const panel = document.querySelector('[data-tab-panel="schedule"]') || document;
    const f = getFilters(panel);
    const url = `${BASE_API}?${qs({ action: "export_schedule_report", ...f })}`;
    window.location.href = url;
  };

  async function run(panelEl) {
    renderError(panelEl, "");
    const loading = panelEl.querySelector("#sch-loading");
    if (loading) loading.classList.remove("hidden");

    const f = getFilters(panelEl);
    state.q = f.q || "";
    state.status = f.status || "all";
    state.dateFrom = f.date_from || "";
    state.dateTo = f.date_to || "";
    state.dept = f.dept || "";
    state.creatorId = f.creator_id || "";
    state.upcomingOnly = !!f.upcoming_only;

    const result = await fetchReport(f);

    if (loading) loading.classList.add("hidden");

    if (!result.ok) {
      state.rows = [];
      state.summary = null;
      renderError(panelEl, result.message || "Không thể tải dữ liệu");
      renderKPI(panelEl);
      renderInsights(panelEl);
      renderTable(panelEl);
      createIcons();
      return;
    }

    state.rows = result.rows || [];
    state.summary = result.summary || {};
    state.currentPage = 1;

    renderKPI(panelEl);
    renderInsights(panelEl);
    renderTable(panelEl);
    createIcons();
  }

  async function render(panelEl) {
    panelEl.innerHTML = `
      <div class="mb-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900">Lịch công tác</h2>
            <p class="mt-1 text-sm text-gray-500">
              Thống kê theo trạng thái, đơn vị, người tạo và giai đoạn thời gian.
            </p>
          </div>
          <div class="hidden md:flex items-center gap-2">
            <button id="schExport"
              class="px-4 py-2 rounded-xl border border-indigo-600 text-indigo-700 bg-white hover:bg-indigo-50 text-sm font-semibold">
              <span class="inline-flex items-center gap-2">
                <i data-lucide="download" class="w-4 h-4"></i>
                Export
              </span>
            </button>
          </div>
        </div>
      </div>

      <div id="sch-error" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-7 gap-3">
          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm</label>
            <input id="schQ" type="text" placeholder="Tiêu đề / đơn vị / địa điểm / thành phần / người tạo..."
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Trạng thái</label>
            <select id="schStatus" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="approved">Đã duyệt</option>
              <option value="pending">Chờ duyệt</option>
              <option value="update_pending">Chờ cập nhật</option>
              <option value="delete_pending">Chờ xoá</option>
              <option value="rejected">Từ chối</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày</label>
            <input id="schFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày</label>
            <input id="schTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đơn vị</label>
            <input id="schDept" type="text" placeholder="VD: Khoa CNTT / Phòng CTSV..."
              class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Người tạo</label>
            <select id="schCreator" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả người tạo --</option>
            </select>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-7 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Hiển thị</label>
            <select id="schPageSize" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="10">10 dòng / trang</option>
              <option value="15">15 dòng / trang</option>
              <option value="20">20 dòng / trang</option>
              <option value="30">30 dòng / trang</option>
              <option value="50">50 dòng / trang</option>
            </select>
          </div>

          <div class="xl:col-span-2 flex items-end gap-2">
            <button id="schRun"
              class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
              Tải dữ liệu
            </button>
            <button id="schReset"
              class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
              Reset
            </button>
          </div>

          <div class="xl:col-span-4 flex items-end">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
              <input id="schUpcomingOnly" type="checkbox" class="w-4 h-4"/>
              Chỉ hiển thị lịch sắp tới (start_date >= hiện tại)
            </label>
          </div>
        </div>
      </div>

      <div id="sch-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê...</div>

      <div id="sch-kpi" class="mb-4"></div>
      <div id="sch-insights" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <div class="overflow-x-auto mb-4">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="sch-head"></tr>
            </thead>
            <tbody id="sch-body"></tbody>
          </table>
        </div>
        <div id="sch-pagination"></div>
      </div>
    `;

    // bind events
    const $ = (id) => panelEl.querySelector(`#${id}`);
    const q = $("schQ");
    const status = $("schStatus");
    const from = $("schFrom");
    const to = $("schTo");
    const dept = $("schDept");
    const creator = $("schCreator");
    const upcomingOnly = $("schUpcomingOnly");
    const pageSize = $("schPageSize");
    const runBtn = $("schRun");
    const resetBtn = $("schReset");
    const exportBtn = $("schExport") || $("schExport"); // safe
    const exportBtn2 = $("schExport") || panelEl.querySelector("#schExport");

    pageSize.value = String(state.pageSize);

    window.schGotoPage = function schGotoPage(p) {
      p = parseInt(p, 10);
      if (!p || p < 1) p = 1;
      state.currentPage = p;
      renderTable(panelEl);
    };

    runBtn.addEventListener("click", async () => {
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      await run(panelEl);
    });

    resetBtn.addEventListener("click", async () => {
      q.value = "";
      status.value = "all";
      from.value = "";
      to.value = "";
      dept.value = "";
      creator.value = "";
      upcomingOnly.checked = false;
      pageSize.value = "10";

      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "start_date";
      state.sortDir = "desc";
      state.rows = [];
      state.summary = null;

      state.q = "";
      state.status = "all";
      state.dateFrom = "";
      state.dateTo = "";
      state.dept = "";
      state.creatorId = "";
      state.upcomingOnly = false;

      renderError(panelEl, "");
      renderKPI(panelEl);
      renderInsights(panelEl);
      renderTable(panelEl);
      createIcons();
    });

    const exportBtnEl = panelEl.querySelector("#schExport");
    exportBtnEl?.addEventListener("click", () => window.exportScheduleReport?.());

    // reactive (nhẹ)
    q.addEventListener("input", () => {
      state.q = (q.value || "").trim();
      state.currentPage = 1;
      renderTable(panelEl);
    });

    [status, from, to, dept, creator, upcomingOnly].forEach((el) => {
      el.addEventListener("change", async () => {
        state.currentPage = 1;
        await run(panelEl);
      });
    });

    pageSize.addEventListener("change", () => {
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      state.currentPage = 1;
      renderTable(panelEl);
    });

    await loadCreatorOptions(panelEl);
    await run(panelEl);
    createIcons();
  }

  window.StatsModules.schedule = (panelEl) => render(panelEl);
})();
