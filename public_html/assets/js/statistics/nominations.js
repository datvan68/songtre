// assets/js/statistics/nominations.js
(() => {
  if (window.__STATS_AWARDS_READY__) return;
  window.__STATS_AWARDS_READY__ = true;

  // ======================
  // API
  // ======================
  const BASE_API = "controllers/statistics/nominations.php";

  // ======================
  // STATE
  // ======================
  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "total", // name | total | approved | rejected | last_at
    sortDir: "desc", // asc | desc
    search: "",
    onlyRejected: false,
    groupBy: "title", // title | nominee | proposer | class | dept
    rows: [],
    summary: null,
    lastError: "",
  };

  // Register module for core statistics.js
  window.StatsModules = window.StatsModules || {};
  window.StatsModules.nominations = async (panelEl) => {
    await renderAwards(panelEl);
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
    } catch (e) {}
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

  function rate(a, b) {
    a = num(a);
    b = num(b);
    if (b <= 0) return 0;
    return Math.round((a / b) * 100);
  }

  function parseDateInput(id) {
    const v = document.getElementById(id)?.value || "";
    return v ? v : "";
  }

  // ======================
  // OPTIONS
  // ======================
  async function loadSchoolYears(panelEl) {
    const select = panelEl.querySelector("#awYear");
    if (!select) return;

    const url = `${BASE_API}?${qs({ action: "school_year_options" })}`;
    const json = await tryJson(url);

    if (!json?.ok) {
      select.innerHTML = `<option value="">-- Tất cả --</option>`;
      return;
    }

    // value dùng year_label để filter nominations.school_year (varchar)
    const years = json.data || [];
    select.innerHTML =
      `<option value="">-- Tất cả --</option>` +
      years.map((y) => `<option value="${esc(y.year_label)}">${esc(y.year_label)}</option>`).join("");
  }

  async function loadTitleOptions(panelEl) {
    const select = panelEl.querySelector("#awTitle");
    if (!select) return;

    const url = `${BASE_API}?${qs({ action: "title_options" })}`;
    const json = await tryJson(url);

    if (!json?.ok) {
      select.innerHTML = `<option value="">-- Tất cả danh hiệu --</option>`;
      return;
    }

    const items = json.data || [];
    select.innerHTML =
      `<option value="">-- Tất cả danh hiệu --</option>` +
      items
        .map((t) => {
          const label = t.grp ? `${t.name} (${t.grp})` : t.name;
          return `<option value="${esc(t.id)}">${esc(label)}</option>`;
        })
        .join("");
  }

  // ======================
  // EXPORT
  // ======================
  window.exportNominationsReport = function exportNominationsReport() {
    const year = document.getElementById("awYear")?.value || "";
    const titleId = document.getElementById("awTitle")?.value || "";
    const status = document.getElementById("awStatus")?.value || "all";
    const type = document.getElementById("awType")?.value || "all";
    const groupBy = document.getElementById("awGroupBy")?.value || "title";

    const dateFrom = parseDateInput("awDateFrom");
    const dateTo = parseDateInput("awDateTo");

    const url =
      `${BASE_API}?` +
      qs({
        action: "export_nominations_report",
        school_year: year,
        title_id: titleId,
        status: status,
        type: type,
        group_by: groupBy,
        date_from: dateFrom,
        date_to: dateTo,
      });

    window.location.href = url;
  };

  // ======================
  // REPORT
  // ======================
  function getFiltersFromUI(panelEl) {
    return {
      school_year: panelEl.querySelector("#awYear")?.value || "",
      title_id: panelEl.querySelector("#awTitle")?.value || "",
      status: panelEl.querySelector("#awStatus")?.value || "all",
      type: panelEl.querySelector("#awType")?.value || "all",
      group_by: panelEl.querySelector("#awGroupBy")?.value || "title",
      date_from: parseDateInput("awDateFrom"),
      date_to: parseDateInput("awDateTo"),
    };
  }

  function normalizeRow(r) {
    const total = num(r.total ?? 0);
    const approved = num(r.approved ?? 0);
    const rejected = num(r.rejected ?? 0);
    const pending = num(r.pending ?? (total - approved - rejected));
    const lastAt = r.last_at ?? "";

    return {
      name: String(r.name ?? "-"),
      info: String(r.info ?? ""),
      total,
      approved,
      rejected,
      pending,
      lastAt: String(lastAt ?? ""),
      raw: r,
    };
  }

  async function fetchNominationsReport(filters) {
    const url = `${BASE_API}?${qs({ action: "nominations_report", ...filters })}`;
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
        const hay = `${r.name} ${r.info}`.toLowerCase();
        return hay.includes(q);
      });
    }

    if (state.onlyRejected) {
      rows = rows.filter((r) => num(r.rejected) > 0);
    }

    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "name") return a.name.localeCompare(b.name, "vi") * dir;
      if (key === "approved") return (a.approved - b.approved) * dir;
      if (key === "rejected") return (a.rejected - b.rejected) * dir;
      if (key === "last_at") return String(a.lastAt).localeCompare(String(b.lastAt)) * dir;
      return (a.total - b.total) * dir;
    });

    return rows;
  }

  function renderPagination(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="awGotoPage(1)">«</button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="awGotoPage(${state.currentPage - 1})"
          ${state.currentPage === 1 ? "disabled" : ""}>
          ‹
        </button>

        <input id="awPageInput" type="number" min="1" max="${totalPages}" value="${state.currentPage}"
          class="w-16 text-center border rounded px-2 py-1"
          onkeydown="if(event.key==='Enter') awGotoPage(this.value)" />

        <span class="text-gray-500">/ ${totalPages}</span>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="awGotoPage(${state.currentPage + 1})"
          ${state.currentPage === totalPages ? "disabled" : ""}>
          ›
        </button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="awGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  // ======================
  // RENDER UI
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
    const box = panelEl.querySelector("#aw-error");
    if (!box) return;

    if (!message) {
      box.innerHTML = "";
      return;
    }

    box.innerHTML = `
      <div class="p-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 text-sm">
        <div class="font-semibold">Không thể tải thống kê thi đua / đề cử</div>
        <div class="mt-1">${esc(message)}</div>
        <div class="mt-2 text-xs text-rose-700">
          Yêu cầu backend có endpoint:
          <code class="px-1 bg-white/60 border rounded">nominations.php?action=nominations_report</code>
        </div>
      </div>
    `;
  }

  function renderKPI(panelEl) {
    const s = state.summary || {};
    const total = s.total != null ? num(s.total) : num((window.STATS || {}).total_nominations);
    const approved = s.approved != null ? num(s.approved) : 0;
    const rejected = s.rejected != null ? num(s.rejected) : 0;
    const pending = s.pending != null ? num(s.pending) : Math.max(0, total - approved - rejected);

    const uniqNominees = s.unique_nominees != null ? num(s.unique_nominees) : null;
    const uniqTitles = s.unique_titles != null ? num(s.unique_titles) : null;

    const approvedRate = total > 0 ? `${rate(approved, total)}%` : "0%";

    const el = panelEl.querySelector("#aw-kpi");
    if (!el) return;

    el.innerHTML = `
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        ${kpiCard({ icon: "award", color: "indigo", label: "Tổng hồ sơ", value: fmt(total), hint: "COUNT(nominations)" })}
        ${kpiCard({ icon: "check-circle-2", color: "emerald", label: "Đã duyệt", value: fmt(approved), hint: "status=approved" })}
        ${kpiCard({ icon: "clock-3", color: "amber", label: "Đang chờ", value: fmt(pending), hint: "status=pending" })}
        ${kpiCard({ icon: "x-circle", color: "rose", label: "Từ chối", value: fmt(rejected), hint: "status=rejected" })}
        ${kpiCard({ icon: "percent", color: "sky", label: "Tỷ lệ duyệt", value: approvedRate, hint: "approved/total" })}
        ${kpiCard({
          icon: "layers",
          color: "violet",
          label: "Unique",
          value: uniqNominees != null ? fmt(uniqNominees) : "-",
          hint:
            uniqNominees == null && uniqTitles == null
              ? "Cần backend trả unique_nominees/unique_titles"
              : `${uniqNominees != null ? `Người: ${fmt(uniqNominees)}` : ""}${uniqTitles != null ? ` · Danh hiệu: ${fmt(uniqTitles)}` : ""}`,
        })}
      </div>
    `;
  }

  function renderInsights(panelEl, rowsAll) {
    const el = panelEl.querySelector("#aw-insights");
    if (!el) return;

    if (!rowsAll.length) {
      el.innerHTML = "";
      return;
    }

    const topTotal = [...rowsAll].sort((a, b) => b.total - a.total).slice(0, 5);
    const topRejected = [...rowsAll].sort((a, b) => b.rejected - a.rejected).slice(0, 5);

    const label =
      state.groupBy === "title"
        ? "Top danh hiệu"
        : state.groupBy === "nominee"
        ? "Top người được đề cử"
        : state.groupBy === "proposer"
        ? "Top người đề cử"
        : state.groupBy === "class"
        ? "Top lớp"
        : "Top khoa/phòng";

    el.innerHTML = `
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">${esc(label)} (theo tổng hồ sơ)</div>
            ${pill({ text: "Top 5", tone: "indigo" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topTotal
              .map((r) => {
                const appr = r.total > 0 ? rate(r.approved, r.total) : 0;
                const tone = appr >= 70 ? "green" : appr >= 40 ? "yellow" : "red";
                return `
                  <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                    <div class="min-w-0">
                      <div class="font-medium text-gray-900 truncate">${esc(r.name)}</div>
                      ${r.info ? `<div class="text-xs text-gray-500 truncate">${esc(r.info)}</div>` : ""}
                      <div class="mt-1">${pill({ text: `Duyệt ${appr}%`, tone })}</div>
                    </div>
                    <div class="shrink-0 text-sm font-bold text-gray-900">${fmt(r.total)}</div>
                  </div>
                `;
              })
              .join("")}
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">Top bị từ chối</div>
            ${pill({ text: "Top 5", tone: "red" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topRejected
              .map((r) => {
                const tone = r.rejected > 0 ? "red" : "gray";
                return `
                  <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                    <div class="min-w-0">
                      <div class="font-medium text-gray-900 truncate">${esc(r.name)}</div>
                      ${r.info ? `<div class="text-xs text-gray-500 truncate">${esc(r.info)}</div>` : ""}
                    </div>
                    <div class="shrink-0">${pill({ text: `Từ chối: ${fmt(r.rejected)}`, tone })}</div>
                  </div>
                `;
              })
              .join("")}
          </div>
          <div class="mt-3 text-xs text-gray-500">
            Nếu muốn “tỷ lệ từ chối” chuẩn theo nhóm, dùng <code class="px-1 bg-gray-50 border rounded">rejected/total</code>.
          </div>
        </div>
      </div>
    `;
  }

  function bindSortHeaders(panelEl) {
    panelEl.querySelectorAll("[data-aw-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.awSort;
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
    const head = panelEl.querySelector("#aw-head");
    const body = panelEl.querySelector("#aw-body");
    const pag = panelEl.querySelector("#aw-pagination");
    if (!head || !body || !pag) return;

    const rowsFiltered = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rowsFiltered.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rowsFiltered.slice(start, start + state.pageSize);

    let colB = "Đối tượng";
    let colC = "Thông tin";
    if (state.groupBy === "title") { colB = "Danh hiệu"; colC = "Nhóm"; }
    if (state.groupBy === "nominee") { colB = "Người được đề cử"; colC = "Lớp / Khoa"; }
    if (state.groupBy === "proposer") { colB = "Người đề cử"; colC = "Chức vụ/ghi chú"; }
    if (state.groupBy === "class") { colB = "Lớp"; colC = "Khoa"; }
    if (state.groupBy === "dept") { colB = "Khoa/Phòng"; colC = "Ghi chú"; }

    // 7 cột theo tinh thần export
    head.innerHTML = `
      <th class="px-4 py-2 text-center w-[6%]">STT</th>
      <th class="px-4 py-2 text-left w-[28%]" data-aw-sort="name">${esc(colB)}</th>
      <th class="px-4 py-2 text-left w-[15%]">${esc(colC)}</th>
      <th class="px-4 py-2 text-right w-[8%]" data-aw-sort="total">Tổng</th>
      <th class="px-4 py-2 text-right w-[8%]" data-aw-sort="approved">Duyệt</th>
      <th class="px-4 py-2 text-right w-[8%]" data-aw-sort="rejected">Từ chối</th>
      <th class="px-4 py-2 text-center w-[18%]" data-aw-sort="last_at">Lần cuối</th>
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

    body.innerHTML = display
      .map((r, i) => {
        const apprRate = r.total > 0 ? rate(r.approved, r.total) : 0;
        const apprTone = apprRate >= 70 ? "green" : apprRate >= 40 ? "yellow" : "red";
        const pendText = `Chờ: ${fmt(r.pending)}`;

        return `
          <tr class="border-t hover:bg-gray-50 transition">
            <td class="px-4 py-2 text-center text-gray-500">${start + i + 1}</td>

            <td class="px-4 py-2 font-medium">
              <div class="text-gray-900">${esc(r.name)}</div>
              ${r.info ? `<div class="text-xs text-gray-500 mt-0.5 truncate">${esc(r.info)}</div>` : ""}
              <div class="mt-1 flex flex-wrap gap-2">
                ${pill({ text: `Duyệt ${apprRate}%`, tone: apprTone })}
                ${pill({ text: pendText, tone: "amber" })}
              </div>
            </td>

            <td class="px-4 py-2 text-gray-700">${esc(r.info || "-")}</td>

            <td class="px-4 py-2 text-right font-bold text-gray-900">${fmt(r.total)}</td>
            <td class="px-4 py-2 text-right font-semibold text-emerald-700">${fmt(r.approved)}</td>
            <td class="px-4 py-2 text-right font-semibold ${r.rejected > 0 ? "text-rose-700" : "text-gray-500"}">${fmt(r.rejected)}</td>
            <td class="px-4 py-2 text-center text-gray-600 text-sm">${esc(r.lastAt || "-")}</td>
          </tr>
        `;
      })
      .join("");

    pag.innerHTML = totalPages > 1 ? renderPagination(totalPages) : "";
    bindSortHeaders(panelEl);
  }

  // ======================
  // MAIN PIPELINE
  // ======================
  async function runReport(panelEl) {
    renderError(panelEl, "");
    const loading = panelEl.querySelector("#aw-loading");
    if (loading) loading.classList.remove("hidden");

    const filters = getFiltersFromUI(panelEl);
    state.groupBy = filters.group_by || "title";

    const result = await fetchNominationsReport(filters);

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
  // RENDER ROOT
  // ======================
  async function renderAwards(panelEl) {
    panelEl.innerHTML = `
      <div class="mb-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900">Thi đua – Khen thưởng</h2>
            <p class="mt-1 text-sm text-gray-500">
              Thống kê hồ sơ <code class="px-1 bg-gray-50 border rounded">nominations</code> theo lọc và nhóm.
            </p>
          </div>
          <div class="hidden md:flex items-center gap-2">
            <button id="awBtnExport"
              class="px-4 py-2 rounded-xl border border-indigo-600 text-indigo-700 bg-white hover:bg-indigo-50 text-sm font-semibold">
              <span class="inline-flex items-center gap-2">
                <i data-lucide="download" class="w-4 h-4"></i>
                Export
              </span>
            </button>
          </div>
        </div>
      </div>

      <div id="aw-error" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Năm học</label>
            <select id="awYear" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả --</option>
            </select>
          </div>

          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Danh hiệu</label>
            <select id="awTitle" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả danh hiệu --</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày</label>
            <input id="awDateFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày</label>
            <input id="awDateTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Nhóm theo</label>
            <select id="awGroupBy" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="title">Theo danh hiệu</option>
              <option value="nominee">Theo người được đề cử</option>
              <option value="proposer">Theo người đề cử</option>
              <option value="class">Theo lớp</option>
              <option value="dept">Theo khoa/phòng</option>
            </select>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Trạng thái</label>
            <select id="awStatus" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="pending">Chờ duyệt</option>
              <option value="approved">Đã duyệt</option>
              <option value="rejected">Từ chối</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Loại</label>
            <select id="awType" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="self">Tự đề cử</option>
              <option value="other">Đề cử người khác</option>
              <option value="collective">Tập thể</option>
            </select>
          </div>

          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm</label>
            <input id="awSearch" type="text"
              placeholder="Nhập tên/danh hiệu/lớp/khoa..."
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Hiển thị</label>
            <select id="awPageSize" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="10">10 dòng / trang</option>
              <option value="15">15 dòng / trang</option>
              <option value="20">20 dòng / trang</option>
              <option value="30">30 dòng / trang</option>
              <option value="50">50 dòng / trang</option>
            </select>
          </div>

          <div class="flex items-end gap-2">
            <button id="awBtnRun"
              class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
              Tải dữ liệu
            </button>
            <button id="awBtnReset"
              class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
              Reset
            </button>
          </div>
        </div>

        <div class="mt-4 flex items-center gap-2">
          <input id="awOnlyRejected" type="checkbox" class="w-4 h-4"/>
          <label for="awOnlyRejected" class="text-sm text-gray-700">Chỉ hiển thị dòng có Từ chối &gt; 0</label>
        </div>
      </div>

      <div id="aw-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê...</div>

      <div id="aw-kpi" class="mb-4"></div>
      <div id="aw-insights" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <div class="overflow-x-auto mb-4">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="aw-head"></tr>
            </thead>
            <tbody id="aw-body"></tbody>
          </table>
        </div>
        <div id="aw-pagination"></div>
      </div>
    `;

    // bind events
    const year = panelEl.querySelector("#awYear");
    const title = panelEl.querySelector("#awTitle");
    const groupBy = panelEl.querySelector("#awGroupBy");
    const status = panelEl.querySelector("#awStatus");
    const type = panelEl.querySelector("#awType");
    const search = panelEl.querySelector("#awSearch");
    const onlyRejected = panelEl.querySelector("#awOnlyRejected");
    const pageSize = panelEl.querySelector("#awPageSize");
    const btnRun = panelEl.querySelector("#awBtnRun");
    const btnReset = panelEl.querySelector("#awBtnReset");
    const btnExport = panelEl.querySelector("#awBtnExport");

    pageSize.value = String(state.pageSize);

    btnRun.addEventListener("click", async () => {
      state.search = (search.value || "").trim();
      state.onlyRejected = !!onlyRejected.checked;
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      await runReport(panelEl);
    });

    btnReset.addEventListener("click", async () => {
      year.value = "";
      title.value = "";
      groupBy.value = "title";
      status.value = "all";
      type.value = "all";
      search.value = "";
      onlyRejected.checked = false;
      pageSize.value = "10";
      panelEl.querySelector("#awDateFrom").value = "";
      panelEl.querySelector("#awDateTo").value = "";

      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "total";
      state.sortDir = "desc";
      state.search = "";
      state.onlyRejected = false;
      state.groupBy = "title";
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
      window.exportNominationsReport?.();
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
    type.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });
    title.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });
    year.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });

    search.addEventListener("input", () => {
      state.search = (search.value || "").trim();
      state.currentPage = 1;
      renderInsights(panelEl, filteredSortedRows());
      renderTable(panelEl);
    });

    onlyRejected.addEventListener("change", () => {
      state.onlyRejected = !!onlyRejected.checked;
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
    window.awGotoPage = function awGotoPage(page) {
      page = parseInt(page, 10);
      if (isNaN(page) || page < 1) page = 1;
      state.currentPage = page;
      renderTable(panelEl);
    };

    // init options
    await loadSchoolYears(panelEl);
    await loadTitleOptions(panelEl);

    // load once
    await runReport(panelEl);
    createIcons();
  }
})();
