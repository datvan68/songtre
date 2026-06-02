// assets/js/statistics/attendance.js
(() => {
  if (window.__STATS_ATTENDANCE_READY__) return;
  window.__STATS_ATTENDANCE_READY__ = true;

  // ======================
  // API
  // ======================
  const BASE_API = "controllers/statistics/attendance.php";
  const FALLBACK_OPTIONS_API = "controllers/statistics/campaigns.php";


  // ======================
  // STATE
  // ======================
  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "total", // name | total | ok | fail | last_at
    sortDir: "desc", // asc | desc
    search: "",
    onlyFail: false,
    groupBy: "member", // member | class | dept | campaign
    rows: [],
    summary: null,
    lastError: "",
  };

  // register module for core statistics.js
  window.StatsModules = window.StatsModules || {};
  window.StatsModules.attendance = async (panelEl) => {
    await renderAttendance(panelEl);
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

  async function tryJson(url) {
    try {
      const res = await fetch(url, {
        method: "GET",
        credentials: "include",            // chắc ăn gửi cookie
        cache: "no-store",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest", // nếu backend có xử lý AJAX
        },
      });

      const ct = (res.headers.get("content-type") || "").toLowerCase();
      const text = await res.text();

      // Nếu backend trả JSON đúng chuẩn
      if (ct.includes("application/json")) {
        const json = JSON.parse(text);
        // nếu HTTP lỗi nhưng vẫn trả JSON
        if (!res.ok && json && typeof json === "object") {
          return { ok: false, message: json.message || json.error || `HTTP ${res.status}` };
        }
        return json;
      }

      // Không phải JSON => 99% là HTML redirect/login/404/PHP warning
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


  function qs(params) {
    const p = new URLSearchParams();
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      p.set(k, String(v));
    });
    return p.toString();
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

  function safeRate(ok, total) {
    total = num(total);
    ok = num(ok);
    if (total <= 0) return 0;
    return Math.round((ok / total) * 100);
  }

  function parseDateInput(id) {
    const v = document.getElementById(id)?.value || "";
    return v ? v : "";
  }

  // ======================
  // OPTIONS LOADERS (year/semester/campaign)
  // ======================
  async function loadSchoolYears() {
    const url1 = `${BASE_API}?${qs({ action: "school_year_options" })}`;
    const url2 = `${FALLBACK_OPTIONS_API}?${qs({ action: "school_year_options" })}`;

    let json = await tryJson(url1);
    if (!json?.ok) json = await tryJson(url2);

    const select = document.getElementById("attYear");
    if (!select) return;

    if (!json?.ok) {
      select.innerHTML = `<option value="">-- Tất cả --</option>`;
      return;
    }

    select.innerHTML =
      `<option value="">-- Tất cả --</option>` +
      (json.data || [])
        .map((y) => `<option value="${esc(y.id)}">${esc(y.year_label)}</option>`)
        .join("");
  }

  async function loadSemesters() {
    const url1 = `${BASE_API}?${qs({ action: "semester_options" })}`;
    const url2 = `${FALLBACK_OPTIONS_API}?${qs({ action: "semester_options" })}`;

    let json = await tryJson(url1);
    if (!json?.ok) json = await tryJson(url2);

    const select = document.getElementById("attSemester");
    if (!select) return;

    if (!json?.ok) {
      select.innerHTML = `<option value="">-- Tất cả --</option>`;
      return;
    }

    select.innerHTML =
      `<option value="">-- Tất cả --</option>` +
      (json.data || [])
        .map((s) => `<option value="${esc(s.code)}">${esc(s.label)}</option>`)
        .join("");
  }

  async function reloadCampaignOptions() {
    const year = document.getElementById("attYear")?.value || "";
    const semester = document.getElementById("attSemester")?.value || "";

    const select = document.getElementById("attCampaign");
    if (!select) return;

    // nếu chưa chọn đủ năm/kỳ => chỉ cho “Tất cả phong trào”
    if (!year || !semester) {
      select.disabled = false;
      select.innerHTML = `<option value="">-- Tất cả phong trào --</option>`;
      return;
    }

    const url = `${FALLBACK_OPTIONS_API}?${qs({
      action: "campaign_options",
      school_year: year,
      semester: semester,
    })}`;

    const json = await tryJson(url);
    if (!json?.ok) {
      select.disabled = false;
      select.innerHTML = `<option value="">-- Tất cả phong trào --</option>`;
      return;
    }

    const campaigns = json.data || [];
    select.disabled = false;
    select.innerHTML =
      `<option value="">-- Tất cả phong trào --</option>` +
      campaigns.map((c) => `<option value="${esc(c.id)}">${esc(c.title)}</option>`).join("");
  }

  // ======================
  // EXPORT
  // ======================
  window.exportAttendanceReport = function exportAttendanceReport() {
    const year = document.getElementById("attYear")?.value || "";
    const semester = document.getElementById("attSemester")?.value || "";
    const campaignId = document.getElementById("attCampaign")?.value || "";

    const dateFrom = parseDateInput("attDateFrom");
    const dateTo = parseDateInput("attDateTo");

    const status = document.getElementById("attStatus")?.value || "all";
    const groupBy = document.getElementById("attGroupBy")?.value || "member";

    // backend optional: export_attendance_report
    const url =
      `${BASE_API}?` +
      qs({
        action: "export_attendance_report",
        school_year: year,
        semester: semester,
        campaign_id: campaignId,
        date_from: dateFrom,
        date_to: dateTo,
        status: status,
        group_by: groupBy,
      });

    window.location.href = url;
  };

  // ======================
  // REPORT FETCH + NORMALIZE
  // ======================
  function getFiltersFromUI() {
    return {
      school_year: document.getElementById("attYear")?.value || "",
      semester: document.getElementById("attSemester")?.value || "",
      campaign_id: document.getElementById("attCampaign")?.value || "",
      date_from: parseDateInput("attDateFrom"),
      date_to: parseDateInput("attDateTo"),
      status: document.getElementById("attStatus")?.value || "all", // all|ok|fail
      group_by: document.getElementById("attGroupBy")?.value || "member",
    };
  }

  function normalizeRow(r, groupBy) {
    // backend recommended fields per row:
    // - member: member_name, class_name, dept_name
    // - class: class_name
    // - dept: dept_name
    // - campaign: campaign_title
    // common: total, ok, fail, last_at (datetime)
    const total = num(r.total ?? r.count ?? 0);
    const ok = num(r.ok ?? r.ok_count ?? 0);
    const fail = num(r.fail ?? r.fail_count ?? 0);
    const lastAt = r.last_at ?? r.last_scan_at ?? r.last_time ?? "";

    let name = "";
    let sub1 = "";
    let sub2 = "";

    if (groupBy === "member") {
      name = r.member_name ?? r.full_name ?? r.name ?? "-";
      sub1 = r.class_name ?? "-";
      sub2 = r.dept_name ?? r.department_name ?? "-";
    } else if (groupBy === "class") {
      name = r.class_name ?? "-";
      sub1 = r.dept_name ?? r.department_name ?? "";
    } else if (groupBy === "dept") {
      name = r.dept_name ?? r.department_name ?? "-";
    } else if (groupBy === "campaign") {
      name = r.campaign_title ?? r.title ?? "-";
    }

    return {
      name: String(name ?? ""),
      sub1: String(sub1 ?? ""),
      sub2: String(sub2 ?? ""),
      total,
      ok,
      fail,
      lastAt: String(lastAt ?? ""),
      raw: r,
    };
  }

  async function fetchAttendanceReport(filters) {
    // required endpoint: action=attendance_report
    const url = `${BASE_API}?${qs({
      action: "attendance_report",
      ...filters,
    })}`;

    const json = await tryJson(url);
    if (!json?.ok) {
      const msg = json?.message || json?.error || "Không thể tải dữ liệu";
      // nếu có debug (non-json) thì show luôn cho dễ bắt bệnh
      const dbg = json?.debug
        ? `\n[DEBUG] ${json.debug.status} | ${json.debug.contentType}\n${json.debug.sample}`
        : "";
      return { ok: false, message: msg + dbg };
    }

    const groupBy = filters.group_by || "member";

    const rows = (json.rows || json.data?.rows || json.data || []).map((r) =>
      normalizeRow(r, groupBy)
    );

    const summary =
      json.summary ||
      json.data?.summary || {
        total: json.total ?? null,
        ok: json.ok_count ?? null,
        fail: json.fail_count ?? null,
        unique_members: json.unique_members ?? null,
        unique_campaigns: json.unique_campaigns ?? null,
      };

    return { ok: true, rows, summary };
  }

  // ======================
  // VIEW PIPELINE (filter + sort + paginate)
  // ======================
  function filteredSortedRows() {
    const q = state.search.trim().toLowerCase();

    let rows = Array.isArray(state.rows) ? [...state.rows] : [];

    if (q) {
      rows = rows.filter((r) => {
        const hay = `${r.name} ${r.sub1} ${r.sub2}`.toLowerCase();
        return hay.includes(q);
      });
    }

    if (state.onlyFail) {
      rows = rows.filter((r) => num(r.fail) > 0);
    }

    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "name") return a.name.localeCompare(b.name, "vi") * dir;
      if (key === "ok") return (a.ok - b.ok) * dir;
      if (key === "fail") return (a.fail - b.fail) * dir;
      if (key === "last_at") return (String(a.lastAt).localeCompare(String(b.lastAt))) * dir;
      // default total
      return (a.total - b.total) * dir;
    });

    return rows;
  }

  function renderPagination(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="attGotoPage(1)">«</button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="attGotoPage(${state.currentPage - 1})"
          ${state.currentPage === 1 ? "disabled" : ""}>
          ‹
        </button>

        <input id="attPageInput" type="number" min="1" max="${totalPages}" value="${state.currentPage}"
          class="w-16 text-center border rounded px-2 py-1"
          onkeydown="if(event.key==='Enter') attGotoPage(this.value)" />

        <span class="text-gray-500">/ ${totalPages}</span>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="attGotoPage(${state.currentPage + 1})"
          ${state.currentPage === totalPages ? "disabled" : ""}>
          ›
        </button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="attGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  // ======================
  // RENDER
  // ======================
  function renderKPI(panelEl) {
    const STATS = window.STATS || {};
    const fallbackTotal = num(STATS.total_attendance);

    const s = state.summary || {};

    const total = s.total != null ? num(s.total) : fallbackTotal;
    const ok = s.ok != null ? num(s.ok) : 0;
    const fail = s.fail != null ? num(s.fail) : 0;
    const uniqMembers = s.unique_members != null ? num(s.unique_members) : null;
    const uniqCampaigns = s.unique_campaigns != null ? num(s.unique_campaigns) : null;
    const okRate = total > 0 ? `${safeRate(ok, total)}%` : "0%";

    const hintUniq =
      uniqMembers == null && uniqCampaigns == null
        ? "Cần backend trả unique_members / unique_campaigns"
        : `${uniqMembers != null ? `Người: ${fmt(uniqMembers)}` : ""}${uniqCampaigns != null ? ` · Phong trào: ${fmt(uniqCampaigns)}` : ""
        }`;

    const el = panelEl.querySelector("#att-kpi");
    if (!el) return;

    el.innerHTML = `
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        ${kpiCard({
      icon: "qr-code",
      color: "indigo",
      label: "Tổng lượt điểm danh",
      value: fmt(total),
      hint: "COUNT(attendance_logs)",
    })}
        ${kpiCard({
      icon: "check-circle-2",
      color: "emerald",
      label: "Lượt OK",
      value: fmt(ok),
      hint: "status=ok (nếu có)",
    })}
        ${kpiCard({
      icon: "x-circle",
      color: "rose",
      label: "Lượt Fail",
      value: fmt(fail),
      hint: "status=fail (nếu có)",
    })}
        ${kpiCard({
      icon: "percent",
      color: "sky",
      label: "Tỷ lệ OK",
      value: okRate,
      hint: "OK / Total",
    })}
        ${kpiCard({
      icon: "users",
      color: "violet",
      label: "Unique",
      value:
        uniqMembers != null ? fmt(uniqMembers) : "-",
      hint: hintUniq,
    })}
        ${kpiCard({
      icon: "flag",
      color: "amber",
      label: "Phong trào liên quan",
      value: uniqCampaigns != null ? fmt(uniqCampaigns) : "-",
      hint: "Unique campaign_id (nếu có)",
    })}
      </div>
    `;
  }

  function renderInsights(panelEl, rowsAll) {
    const el = panelEl.querySelector("#att-insights");
    if (!el) return;

    if (!rowsAll.length) {
      el.innerHTML = "";
      return;
    }

    const topTotal = [...rowsAll].sort((a, b) => b.total - a.total).slice(0, 5);
    const topFail = [...rowsAll].sort((a, b) => b.fail - a.fail).slice(0, 5);

    const groupBy = state.groupBy;
    const label =
      groupBy === "member" ? "Top người quét" :
        groupBy === "class" ? "Top lớp" :
          groupBy === "dept" ? "Top khoa/phòng" : "Top phong trào";

    el.innerHTML = `
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">${esc(label)} (theo tổng lượt)</div>
            ${pill({ text: "Top 5", tone: "indigo" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topTotal
        .map((r, i) => {
          return `
                  <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                    <div class="min-w-0">
                      <div class="font-medium text-gray-900 truncate">${esc(r.name)}</div>
                      ${r.sub1 || r.sub2
              ? `<div class="text-xs text-gray-500 truncate">${esc(
                [r.sub1, r.sub2].filter(Boolean).join(" · ")
              )}</div>`
              : ""
            }
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
            <div class="font-semibold text-gray-900">Top phát sinh lỗi (fail)</div>
            ${pill({ text: "Top 5", tone: "red" })}
          </div>
          <div class="mt-3 space-y-2">
            ${topFail
        .map((r) => {
          const tone = r.fail > 0 ? "red" : "gray";
          return `
                  <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                    <div class="min-w-0">
                      <div class="font-medium text-gray-900 truncate">${esc(r.name)}</div>
                      ${r.sub1 || r.sub2
              ? `<div class="text-xs text-gray-500 truncate">${esc(
                [r.sub1, r.sub2].filter(Boolean).join(" · ")
              )}</div>`
              : ""
            }
                    </div>
                    <div class="shrink-0">
                      ${pill({ text: `Fail: ${fmt(r.fail)}`, tone })}
                    </div>
                  </div>
                `;
        })
        .join("")}
          </div>
          <div class="mt-3 text-xs text-gray-500">
            Nếu bạn muốn “tỷ lệ fail” chuẩn, backend nên trả thêm <code class="px-1 bg-gray-50 border rounded">fail/total</code> theo từng nhóm.
          </div>
        </div>
      </div>
    `;
  }

  function renderTable(panelEl) {
    const head = panelEl.querySelector("#att-head");
    const body = panelEl.querySelector("#att-body");
    const pag = panelEl.querySelector("#att-pagination");
    if (!head || !body || !pag) return;

    const rowsFiltered = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rowsFiltered.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rowsFiltered.slice(start, start + state.pageSize);

    const groupBy = state.groupBy;

    // columns
    let colName = "Đối tượng";
    if (groupBy === "member") colName = "Họ tên";
    if (groupBy === "class") colName = "Lớp";
    if (groupBy === "dept") colName = "Khoa/Phòng";
    if (groupBy === "campaign") colName = "Phong trào";

    head.innerHTML = `
      <th class="px-4 py-2 text-center w-[6%]">STT</th>
      <th class="px-4 py-2 text-left w-[24%]" data-att-sort="name">${esc(colName)}</th>
      ${groupBy === "member"
        ? `<th class="px-4 py-2 text-left w-[14%]">Lớp</th>
           <th class="px-4 py-2 text-left w-[18%]">Khoa</th>`
        : groupBy === "class"
          ? `<th class="px-4 py-2 text-left w-[24%]">Khoa</th>`
          : `<th class="px-4 py-2 text-left w-[24%]"></th>`}
      <th class="px-4 py-2 text-right w-[8%]" data-att-sort="total">Tổng</th>
      <th class="px-4 py-2 text-right w-[8%]" data-att-sort="ok">OK</th>
      <th class="px-4 py-2 text-right w-[8%]" data-att-sort="fail">Fail</th>
      <th class="px-4 py-2 text-center w-[24%]" data-att-sort="last_at">Lần cuối</th>
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
        const okRate = r.total > 0 ? safeRate(r.ok, r.total) : 0;
        const okTone = okRate >= 90 ? "green" : okRate >= 70 ? "yellow" : "red";

        return `
          <tr class="border-t hover:bg-gray-50 transition">
            <td class="px-4 py-2 text-center text-gray-500">${start + i + 1}</td>
            <td class="px-4 py-2 font-medium">
              <div class="text-gray-900">${esc(r.name)}</div>
              ${groupBy !== "member" && (r.sub1 || r.sub2)
            ? `<div class="text-xs text-gray-500 mt-0.5">${esc(
              [r.sub1, r.sub2].filter(Boolean).join(" · ")
            )}</div>`
            : ""
          }
              ${pill({ text: `OK ${okRate}%`, tone: okTone })}
            </td>

            ${groupBy === "member"
            ? `<td class="px-4 py-2 text-gray-700">${esc(r.sub1 || "-")}</td>
                   <td class="px-4 py-2 text-gray-700">${esc(r.sub2 || "-")}</td>`
            : groupBy === "class"
              ? `<td class="px-4 py-2 text-gray-700">${esc(r.sub1 || "-")}</td>`
              : `<td class="px-4 py-2 text-gray-700"></td>`
          }

            <td class="px-4 py-2 text-right font-bold text-gray-900">${fmt(r.total)}</td>
            <td class="px-4 py-2 text-right font-semibold text-emerald-700">${fmt(r.ok)}</td>
            <td class="px-4 py-2 text-right font-semibold ${r.fail > 0 ? "text-rose-700" : "text-gray-500"}">${fmt(r.fail)}</td>
            <td class="px-4 py-2 text-center text-gray-600 text-sm">${esc(r.lastAt || "-")}</td>
          </tr>
        `;
      })
      .join("");

    pag.innerHTML = totalPages > 1 ? renderPagination(totalPages) : "";

    bindSortHeaders(panelEl);
  }

  function bindSortHeaders(panelEl) {
    panelEl.querySelectorAll("[data-att-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.attSort;
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

  function renderError(panelEl, message) {
    const box = panelEl.querySelector("#att-error");
    if (!box) return;

    if (!message) {
      box.innerHTML = "";
      return;
    }

    box.innerHTML = `
      <div class="p-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 text-sm">
        <div class="font-semibold">Không thể tải thống kê điểm danh</div>
        <div class="mt-1">${esc(message)}</div>
        <div class="mt-2 text-xs text-rose-700">
          Yêu cầu backend có endpoint:
          <code class="px-1 bg-white/60 border rounded">attendance.php?action=attendance_report</code>
        </div>
      </div>
    `;
  }

  // ======================
  // MAIN RENDER + EVENTS
  // ======================
  async function runReport(panelEl) {
    renderError(panelEl, "");
    const loading = panelEl.querySelector("#att-loading");
    if (loading) loading.classList.remove("hidden");

    const filters = getFiltersFromUI();
    state.groupBy = filters.group_by || "member";

    const result = await fetchAttendanceReport(filters);

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

  async function renderAttendance(panelEl) {
    panelEl.innerHTML = `
      <div class="mb-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900">Điểm danh – QR</h2>
            <p class="mt-1 text-sm text-gray-500">Thống kê theo lọc (năm/kỳ/phong trào/thời gian) và nhóm (người/lớp/khoa/phong trào).</p>
          </div>
          <div class="hidden md:flex items-center gap-2">
            <button id="attBtnExport"
              class="px-4 py-2 rounded-xl border border-indigo-600 text-indigo-700 bg-white hover:bg-indigo-50 text-sm font-semibold">
              <span class="inline-flex items-center gap-2">
                <i data-lucide="download" class="w-4 h-4"></i>
                Export
              </span>
            </button>
          </div>
        </div>
      </div>

      <div id="att-error" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Năm học</label>
            <select id="attYear" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả --</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Học kỳ</label>
            <select id="attSemester" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả --</option>
            </select>
          </div>

          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Phong trào</label>
            <select id="attCampaign" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả phong trào --</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày</label>
            <input id="attDateFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày</label>
            <input id="attDateTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Nhóm theo</label>
            <select id="attGroupBy" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="member">Theo người</option>
              <option value="class">Theo lớp</option>
              <option value="dept">Theo khoa/phòng</option>
              <option value="campaign">Theo phong trào</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Trạng thái</label>
            <select id="attStatus" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="ok">OK</option>
              <option value="fail">Fail</option>
            </select>
          </div>

          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm</label>
            <input id="attSearch" type="text"
              placeholder="Nhập tên/lớp/khoa/phong trào..."
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Hiển thị</label>
            <select id="attPageSize" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="10">10 dòng / trang</option>
              <option value="15">15 dòng / trang</option>
              <option value="20">20 dòng / trang</option>
              <option value="30">30 dòng / trang</option>
              <option value="50">50 dòng / trang</option>
            </select>
          </div>

          <div class="flex items-end gap-2">
            <button id="attBtnRun"
              class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
              Tải dữ liệu
            </button>
            <button id="attBtnReset"
              class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
              Reset
            </button>
          </div>
        </div>

        <div class="mt-4 flex items-center gap-2">
          <input id="attOnlyFail" type="checkbox" class="w-4 h-4"/>
          <label for="attOnlyFail" class="text-sm text-gray-700">Chỉ hiển thị dòng có Fail &gt; 0</label>
        </div>
      </div>

      <div id="att-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê...</div>

      <div id="att-kpi" class="mb-4"></div>
      <div id="att-insights" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <div class="overflow-x-auto mb-4">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="att-head"></tr>
            </thead>
            <tbody id="att-body"></tbody>
          </table>
        </div>
        <div id="att-pagination"></div>
      </div>
    `;

    // bind events
    const year = panelEl.querySelector("#attYear");
    const sem = panelEl.querySelector("#attSemester");
    const camp = panelEl.querySelector("#attCampaign");
    const groupBy = panelEl.querySelector("#attGroupBy");
    const status = panelEl.querySelector("#attStatus");
    const search = panelEl.querySelector("#attSearch");
    const onlyFail = panelEl.querySelector("#attOnlyFail");
    const pageSize = panelEl.querySelector("#attPageSize");
    const btnRun = panelEl.querySelector("#attBtnRun");
    const btnReset = panelEl.querySelector("#attBtnReset");
    const btnExport = panelEl.querySelector("#attBtnExport");

    pageSize.value = String(state.pageSize);

    const onOptionsChange = async () => {
      await reloadCampaignOptions();
    };

    year.addEventListener("change", onOptionsChange);
    sem.addEventListener("change", onOptionsChange);

    btnRun.addEventListener("click", async () => {
      state.search = (search.value || "").trim();
      state.onlyFail = !!onlyFail.checked;
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      await runReport(panelEl);
    });

    btnReset.addEventListener("click", async () => {
      // reset UI inputs
      year.value = "";
      sem.value = "";
      groupBy.value = "member";
      status.value = "all";
      search.value = "";
      onlyFail.checked = false;
      pageSize.value = "10";
      camp.value = "";

      // reset state
      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "total";
      state.sortDir = "desc";
      state.search = "";
      state.onlyFail = false;
      state.groupBy = "member";
      state.rows = [];
      state.summary = null;
      state.lastError = "";

      await reloadCampaignOptions();
      renderError(panelEl, "");
      renderKPI(panelEl);
      renderInsights(panelEl, []);
      renderTable(panelEl);
      createIcons();
    });

    btnExport.addEventListener("click", () => {
      window.exportAttendanceReport?.();
    });

    // quick reactive (optional)
    groupBy.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });
    status.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });
    camp.addEventListener("change", async () => {
      state.currentPage = 1;
      await runReport(panelEl);
    });

    search.addEventListener("input", () => {
      state.search = (search.value || "").trim();
      state.currentPage = 1;
      renderInsights(panelEl, filteredSortedRows());
      renderTable(panelEl);
    });

    onlyFail.addEventListener("change", () => {
      state.onlyFail = !!onlyFail.checked;
      state.currentPage = 1;
      renderInsights(panelEl, filteredSortedRows());
      renderTable(panelEl);
    });

    pageSize.addEventListener("change", () => {
      state.pageSize = parseInt(pageSize.value, 10) || 10;
      state.currentPage = 1;
      renderTable(panelEl);
    });

    // global pagination hook (inline)
    window.attGotoPage = function attGotoPage(page) {
      page = parseInt(page, 10);
      if (isNaN(page) || page < 1) page = 1;
      state.currentPage = page;
      renderTable(panelEl);
    };

    // init
    await loadSchoolYears();
    await loadSemesters();
    await reloadCampaignOptions();

    // load once
    await runReport(panelEl);
    createIcons();
  }
})();
