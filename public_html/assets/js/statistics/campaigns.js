// assets/js/statistics/campaigns.js
(() => {
  if (window.__STATS_CAMPAIGNS_READY__) return;
  window.__STATS_CAMPAIGNS_READY__ = true;

  const BASE_API = "controllers/statistics/campaigns.php";

  // ======================
  // STATE
  // ======================
  let pageSize = 10;
  let currentPage = 1;
  let campaignMode = "class"; // 'class' | 'dept'

  const viewState = {
    search: "",
    bucket: "all", // all | green | yellow | red
    sortKey: "percent", // name | joined | percent | score
    sortDir: "desc", // asc | desc
    rows: [], // normalized rows of current mode
    meta: null, // campaign_overview (optional)
  };

  // expose (optional) for core loader
  window.StatsModules = window.StatsModules || {};
  window.StatsModules.campaigns = async () => window.renderCampaigns?.();

  // ======================
  // URL HELPERS
  // ======================
  function getCampaignFromURL() {
    const params = new URLSearchParams(window.location.search);
    return params.get("campaign_id");
  }

  function setCampaignToURL(campaignId) {
    const url = new URL(window.location.href);
    url.searchParams.set("tab", "campaigns");
    url.searchParams.set("campaign_id", campaignId);
    window.history.pushState({}, "", url.toString());
  }

  function clearCampaignFromURL() {
    const url = new URL(window.location.href);
    url.searchParams.set("tab", "campaigns");
    url.searchParams.delete("campaign_id");
    window.history.replaceState({}, "", url.toString());
  }

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

  function percentClass(p) {
    if (p >= 70) return "text-emerald-600";
    if (p >= 40) return "text-amber-600";
    return "text-rose-600";
  }

  function bucketOf(p) {
    if (p >= 70) return "green";
    if (p >= 40) return "yellow";
    return "red";
  }

  function parseRatio(ratio) {
    // ratio: "12/30" or "12 / 30"
    if (!ratio) return { joined: 0, total: 0 };
    const m = String(ratio).match(/(\d+)\s*\/\s*(\d+)/);
    if (!m) return { joined: 0, total: 0 };
    return { joined: num(m[1]), total: num(m[2]) };
  }

  function normalizeRow(raw) {
    const name = campaignMode === "dept" ? raw.dept_name : raw.class_name;

    // Prefer explicit fields if backend provides
    const joined =
      raw.joined_count != null ? num(raw.joined_count) : parseRatio(raw.ratio).joined;

    const total =
      raw.total_count != null ? num(raw.total_count) : parseRatio(raw.ratio).total;

    let percent = raw.percent != null ? num(raw.percent) : 0;
    if ((!percent || percent < 0) && total > 0) percent = Math.round((joined / total) * 100);

    const score = raw.score != null ? num(raw.score) : 0;

    return {
      name: String(name ?? ""),
      joined,
      total,
      percent,
      score,
      raw,
    };
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
    };
    const cls = map[tone] || map.gray;
    return `<span class="px-2 py-1 rounded-lg text-xs font-semibold ${cls}">${esc(text)}</span>`;
  }

  // ======================
  // EXPORTS (inline onclick)
  // ======================
  window.exportCampaignStats = function exportCampaignStats() {
    const campaignId = document.getElementById("campaignSelect")?.value;
    if (!campaignId) return alert("Vui lòng chọn phong trào");

    const title =
      document.querySelector("#campaignSelect option:checked")?.textContent || "";

    let action = "export_campaign_class";
    if (campaignMode === "dept") action = "export_campaign_dept";

    const year = document.getElementById("filterYear")?.value || "";
    const semester = document.getElementById("filterSemester")?.value || "";

    const url =
      `${BASE_API}?action=${action}` +
      `&campaign_id=${campaignId}` +
      `&school_year=${encodeURIComponent(year)}` +
      `&semester=${encodeURIComponent(semester)}` +
      `&title=${encodeURIComponent(title)}`;

    window.location.href = url;
  };

  window.exportCampaignSummary = function exportCampaignSummary() {
    const year = document.getElementById("filterYear")?.value || "";
    const semester = document.getElementById("filterSemester")?.value || "";

    window.location.href =
      `${BASE_API}?action=export_campaign_summary` +
      `&school_year=${encodeURIComponent(year)}` +
      `&semester=${encodeURIComponent(semester)}`;
  };

  // ======================
  // UI HELPERS
  // ======================
  function setCampaignSelectDisabled(message) {
    const select = document.getElementById("campaignSelect");
    if (!select) return;
    select.disabled = true;
    select.innerHTML = `<option value="">${esc(message)}</option>`;
  }

  function renderPagination(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="gotoPage(1)">«</button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="gotoPage(${currentPage - 1})"
          ${currentPage === 1 ? "disabled" : ""}>
          ‹
        </button>

        <input id="pageInput" type="number" min="1" max="${totalPages}" value="${currentPage}"
          class="w-16 text-center border rounded px-2 py-1"
          onkeydown="if(event.key==='Enter') gotoPage(this.value)" />

        <span class="text-gray-500">/ ${totalPages}</span>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="gotoPage(${currentPage + 1})"
          ${currentPage === totalPages ? "disabled" : ""}>
          ›
        </button>

        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="gotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  function updateCampaignTable({ headHTML, rowsHTML, totalPages }) {
    const head = document.getElementById("campaign-head");
    const body = document.getElementById("campaign-body");
    const pag = document.getElementById("campaign-pagination");
    if (!head || !body || !pag) return;

    head.innerHTML = headHTML;
    body.innerHTML = rowsHTML;

    if (!totalPages || totalPages <= 1) {
      pag.innerHTML = "";
      return;
    }
    pag.innerHTML = renderPagination(totalPages);
  }

  function resetCampaignUI(message, { clearUrl = false } = {}) {
    const select = document.getElementById("campaignSelect");
    const typeBox = document.getElementById("campaignType");

    if (clearUrl) clearCampaignFromURL();
    if (select) select.value = "";
    if (typeBox) typeBox.classList.add("hidden");

    viewState.rows = [];
    viewState.meta = null;
    viewState.search = "";
    viewState.bucket = "all";
    viewState.sortKey = "percent";
    viewState.sortDir = "desc";

    currentPage = 1;

    const summary = document.getElementById("campaign-summary");
    if (summary) summary.innerHTML = "";

    updateCampaignTable({
      headHTML: "",
      rowsHTML: `
        <tr>
          <td colspan="6" class="px-4 py-8 text-center text-gray-500 italic">
            ${esc(message)}
          </td>
        </tr>
      `,
      totalPages: 1,
    });
  }

  function initCampaignFilterState() {
    setCampaignSelectDisabled("Hãy chọn Năm học và Học kỳ để xem phong trào");
    resetCampaignUI("Hãy chọn Năm học và Học kỳ, sau đó chọn phong trào", {
      clearUrl: false,
    });
  }

  function setCampaignActive(id) {
    ["btn-campaign-class", "btn-campaign-dept"].forEach((b) => {
      const el = document.getElementById(b);
      if (!el) return;
      el.classList.toggle("bg-emerald-600", b === id);
      el.classList.toggle("text-white", b === id);
      el.classList.toggle("bg-gray-100", b !== id);
      el.classList.toggle("text-gray-700", b !== id);
    });
  }

  // ======================
  // DATA LOADERS
  // ======================
  async function loadSchoolYears() {
    const res = await fetch(`${BASE_API}?action=school_year_options`);
    const json = await res.json();
    if (!json.ok) return;

    const select = document.getElementById("filterYear");
    if (!select) return;

    select.innerHTML =
      `<option value="">-- Tất cả --</option>` +
      json.data.map((y) => `<option value="${esc(y.id)}">${esc(y.year_label)}</option>`).join("");
  }

  async function loadSemesters() {
    const res = await fetch(`${BASE_API}?action=semester_options`);
    const json = await res.json();
    if (!json.ok) return;

    const select = document.getElementById("filterSemester");
    if (!select) return;

    select.innerHTML =
      `<option value="">-- Tất cả --</option>` +
      json.data.map((s) => `<option value="${esc(s.code)}">${esc(s.label)}</option>`).join("");
  }

  async function reloadCampaignOptions() {
    const year = document.getElementById("filterYear")?.value || "";
    const semester = document.getElementById("filterSemester")?.value || "";
    const select = document.getElementById("campaignSelect");
    if (!select) return;

    if (!year || !semester) {
      setCampaignSelectDisabled("Hãy chọn Năm học và Học kỳ để xem phong trào");
      return;
    }

    const res = await fetch(
      `${BASE_API}?action=campaign_options` +
      `&school_year=${encodeURIComponent(year)}` +
      `&semester=${encodeURIComponent(semester)}`
    );

    const json = await res.json();
    if (!json.ok) {
      setCampaignSelectDisabled("Không thể tải danh sách phong trào");
      return;
    }

    const campaigns = json.data || [];
    if (campaigns.length === 0) {
      select.disabled = true;
      select.innerHTML = `<option value="">Không có phong trào cho bộ lọc đã chọn</option>`;
      return;
    }

    select.disabled = false;
    select.innerHTML =
      `<option value="">-- Chọn phong trào --</option>` +
      campaigns.map((c) => `<option value="${esc(c.id)}">${esc(c.title)}</option>`).join("");
  }

  // optional: campaign_overview
  async function loadCampaignOverview() {
    const campaignId = document.getElementById("campaignSelect")?.value;
    if (!campaignId) return;

    const year = document.getElementById("filterYear")?.value || "";
    const semester = document.getElementById("filterSemester")?.value || "";

    try {
      const res = await fetch(
        `${BASE_API}?action=campaign_overview` +
        `&campaign_id=${encodeURIComponent(campaignId)}` +
        `&school_year=${encodeURIComponent(year)}` +
        `&semester=${encodeURIComponent(semester)}`
      );
      const json = await res.json();
      if (!json.ok) return;
      viewState.meta = json.data || null;
    } catch (e) {
      // ignore if endpoint not implemented
    }
  }

  // ======================
  // VIEW PIPELINE (filter + sort + render)
  // ======================
  function getFilteredSortedRows() {
    const q = viewState.search.trim().toLowerCase();

    let rows = Array.isArray(viewState.rows) ? [...viewState.rows] : [];

    if (q) {
      rows = rows.filter((r) => r.name.toLowerCase().includes(q));
    }

    if (viewState.bucket !== "all") {
      rows = rows.filter((r) => bucketOf(r.percent) === viewState.bucket);
    }

    const dir = viewState.sortDir === "asc" ? 1 : -1;
    const key = viewState.sortKey;

    rows.sort((a, b) => {
      if (key === "name") return a.name.localeCompare(b.name, "vi") * dir;
      if (key === "joined") return (a.joined - b.joined) * dir;
      if (key === "score") return (a.score - b.score) * dir;
      // default percent
      return (a.percent - b.percent) * dir;
    });

    return rows;
  }

  function renderSummary(allRows, shownRows) {
    const el = document.getElementById("campaign-summary");
    if (!el) return;

    const totalUnits = allRows.length;
    const shownUnits = shownRows.length;

    const sumJoinedAll = allRows.reduce((s, r) => s + num(r.joined), 0);
    const sumTotalAll = allRows.reduce((s, r) => s + num(r.total), 0);
    const weightedPctAll = sumTotalAll > 0 ? Math.round((sumJoinedAll / sumTotalAll) * 100) : 0;

    const sumScoreAll = allRows.reduce((s, r) => s + num(r.score), 0);
    const avgScoreAll = totalUnits > 0 ? sumScoreAll / totalUnits : 0;

    const green = allRows.filter((r) => r.percent >= 70).length;
    const yellow = allRows.filter((r) => r.percent >= 40 && r.percent < 70).length;
    const red = allRows.filter((r) => r.percent < 40).length;

    const topByPct = allRows.length ? allRows[0] : null; // will be sorted by current view, so compute separately:
    const topPct = [...allRows].sort((a, b) => b.percent - a.percent)[0];
    const lowPct = [...allRows].sort((a, b) => a.percent - b.percent)[0];
    const topScore = [...allRows].sort((a, b) => b.score - a.score)[0];

    const meta = viewState.meta;

    const metaCards = meta
      ? `
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
          ${kpiCard({
        icon: "clipboard-list",
        color: "violet",
        label: "Đăng ký",
        value: fmt(meta.reg_total ?? 0),
        hint: meta.reg_unique != null ? `Unique: ${fmt(meta.reg_unique)}` : "",
      })}
          ${kpiCard({
        icon: "qr-code",
        color: "amber",
        label: "Điểm danh",
        value: fmt(meta.attend_total ?? 0),
        hint: meta.attend_unique != null ? `Unique: ${fmt(meta.attend_unique)}` : "",
      })}
          ${kpiCard({
        icon: "percent",
        color: "sky",
        label: "Điểm danh / Đăng ký",
        value:
          meta.reg_total > 0
            ? `${Math.round((num(meta.attend_total) / num(meta.reg_total)) * 100)}%`
            : "0%",
        hint: "Nếu >100%: có thể log nhiều lần",
      })}
        </div>
      `
      : "";

    el.innerHTML = `
      ${metaCards}

      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        ${kpiCard({
      icon: campaignMode === "dept" ? "building-2" : "graduation-cap",
      color: "indigo",
      label: campaignMode === "dept" ? "Tổng khoa/phòng" : "Tổng lớp",
      value: fmt(totalUnits),
      hint: shownUnits !== totalUnits ? `Đang hiển thị: ${fmt(shownUnits)}/${fmt(totalUnits)}` : "",
    })}
        ${kpiCard({
      icon: "users",
      color: "emerald",
      label: "Tổng tham gia",
      value: `${fmt(sumJoinedAll)}${sumTotalAll ? ` / ${fmt(sumTotalAll)}` : ""}`,
      hint: sumTotalAll ? `Weighted: ${weightedPctAll}%` : "Chưa có mẫu tổng",
    })}
        ${kpiCard({
      icon: "award",
      color: "rose",
      label: "Điểm tổng",
      value: fmt(sumScoreAll.toFixed(1)),
      hint: `TB/đơn vị: ${avgScoreAll.toFixed(1)}`,
    })}
        ${kpiCard({
      icon: "bar-chart-3",
      color: "sky",
      label: "Phân bố kết quả",
      value: `${green}/${yellow}/${red}`,
      hint: `${pill({ text: `Xanh ${green}`, tone: "green" })} ${pill({
        text: `Vàng ${yellow}`,
        tone: "yellow",
      })} ${pill({ text: `Đỏ ${red}`, tone: "red" })}`,
    })}
      </div>

      <div class="mt-4 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
          <div class="font-semibold text-gray-900">Điểm nổi bật</div>
          <div class="text-xs text-gray-500">
            Sort hiện tại: <b>${esc(viewState.sortKey)}</b> (${esc(viewState.sortDir)})
          </div>
        </div>

        <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
          <div class="p-3 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-gray-500 text-xs">Top theo %</div>
            <div class="font-semibold text-gray-900">
              ${topPct ? esc(topPct.name) : "-"}
              ${topPct ? `<span class="ml-2 ${percentClass(topPct.percent)}">${topPct.percent}%</span>` : ""}
            </div>
          </div>
          <div class="p-3 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-gray-500 text-xs">Thấp nhất theo %</div>
            <div class="font-semibold text-gray-900">
              ${lowPct ? esc(lowPct.name) : "-"}
              ${lowPct ? `<span class="ml-2 ${percentClass(lowPct.percent)}">${lowPct.percent}%</span>` : ""}
            </div>
          </div>
          <div class="p-3 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-gray-500 text-xs">Top theo điểm</div>
            <div class="font-semibold text-gray-900">
              ${topScore ? esc(topScore.name) : "-"}
              ${topScore ? `<span class="ml-2 text-indigo-700">${topScore.score.toFixed(1)}</span>` : ""}
            </div>
          </div>
        </div>
      </div>
    `;

    createIcons();
  }

  function renderControls() {
    const el = document.getElementById("campaign-controls");
    if (!el) return;

    const modeLabel = campaignMode === "dept" ? "khoa/phòng" : "lớp";

    el.innerHTML = `
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
          <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-600 mb-1">Tìm ${modeLabel}</label>
              <input id="campaignSearch" type="text"
                value="${esc(viewState.search)}"
                placeholder="Nhập tên để lọc..."
                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-600 mb-1">Nhóm kết quả</label>
              <select id="campaignBucket" class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="all">-- Tất cả --</option>
                <option value="green">Xanh (≥70%)</option>
                <option value="yellow">Vàng (40–69%)</option>
                <option value="red">Đỏ (&lt;40%)</option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-600 mb-1">Sắp xếp</label>
              <div class="flex gap-2">
                <select id="campaignSortKey" class="w-full border rounded-lg px-3 py-2 text-sm">
                  <option value="percent">% tham gia</option>
                  <option value="score">Điểm</option>
                  <option value="joined">Số tham gia</option>
                  <option value="name">Tên</option>
                </select>
                <select id="campaignSortDir" class="w-28 border rounded-lg px-3 py-2 text-sm">
                  <option value="desc">Giảm</option>
                  <option value="asc">Tăng</option>
                </select>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-end gap-2">
            <button id="btnResetView"
              class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
              Reset
            </button>
          </div>
        </div>
      </div>
    `;

    const $q = el.querySelector("#campaignSearch");
    const $b = el.querySelector("#campaignBucket");
    const $sk = el.querySelector("#campaignSortKey");
    const $sd = el.querySelector("#campaignSortDir");
    const $reset = el.querySelector("#btnResetView");

    if ($b) $b.value = viewState.bucket;
    if ($sk) $sk.value = viewState.sortKey;
    if ($sd) $sd.value = viewState.sortDir;

    const rerender = () => {
      currentPage = 1;
      renderTableFromState();
    };

    $q?.addEventListener("input", () => {
      viewState.search = $q.value || "";
      rerender();
    });
    $b?.addEventListener("change", () => {
      viewState.bucket = $b.value || "all";
      rerender();
    });
    $sk?.addEventListener("change", () => {
      viewState.sortKey = $sk.value || "percent";
      rerender();
    });
    $sd?.addEventListener("change", () => {
      viewState.sortDir = $sd.value || "desc";
      rerender();
    });
    $reset?.addEventListener("click", () => {
      viewState.search = "";
      viewState.bucket = "all";
      viewState.sortKey = "percent";
      viewState.sortDir = "desc";
      currentPage = 1;
      renderControls();
      renderTableFromState();
    });
  }

  function renderTableFromState() {
    const filtered = getFilteredSortedRows();

    // summary should reflect ALL rows (not only filtered) but show displayed count
    renderSummary(viewState.rows, filtered);
    renderControls();

    const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    if (currentPage > totalPages) currentPage = totalPages;

    const start = (currentPage - 1) * pageSize;
    const displayRows = filtered.slice(start, start + pageSize);

    const nameLabel = campaignMode === "dept" ? "Khoa" : "Lớp";

    const headHTML = `
      <th class="px-4 py-2 text-center w-[6%]">STT</th>
      <th class="px-4 py-2 text-left w-[34%]">${nameLabel}</th>
      <th class="px-4 py-2 text-center w-[18%]">Tham gia</th>
      <th class="px-4 py-2 text-center w-[16%]">Tỷ lệ %</th>
      <th class="px-4 py-2 text-right w-[16%]">Điểm</th>
      <th class="px-4 py-2 text-center w-[10%]">Nhóm</th>
    `;

    let rowsHTML = "";

    if (!filtered.length) {
      rowsHTML = `
        <tr>
          <td colspan="6" class="px-4 py-10 text-center text-gray-500 italic">
            Không có dữ liệu phù hợp bộ lọc hiện tại.
          </td>
        </tr>
      `;
    } else {
      rowsHTML = displayRows
        .map((r, i) => {
          const ratioText = r.total > 0 ? `${fmt(r.joined)}/${fmt(r.total)}` : fmt(r.joined);
          const grp = bucketOf(r.percent);
          const grpPill =
            grp === "green"
              ? pill({ text: "Xanh", tone: "green" })
              : grp === "yellow"
                ? pill({ text: "Vàng", tone: "yellow" })
                : pill({ text: "Đỏ", tone: "red" });

          return `
            <tr class="border-t hover:bg-gray-50 transition">
              <td class="px-4 py-2 text-center text-gray-500">${start + i + 1}</td>
              <td class="px-4 py-2 font-medium">${esc(r.name)}</td>
              <td class="px-4 py-2 text-center">${esc(ratioText)}</td>
              <td class="px-4 py-2 text-center font-semibold ${percentClass(r.percent)}">
                ${esc(r.percent)}%
              </td>
              <td class="px-4 py-2 text-right font-bold text-indigo-600">
                ${r.score.toFixed(1)}
              </td>
              <td class="px-4 py-2 text-center">${grpPill}</td>
            </tr>
          `;
        })
        .join("");
    }

    updateCampaignTable({ headHTML, rowsHTML, totalPages });
    createIcons();
  }

  // ======================
  // FILTER HANDLER
  // ======================
  async function handleFiltersChange() {
    const year = document.getElementById("filterYear")?.value || "";
    const semester = document.getElementById("filterSemester")?.value || "";

    resetCampaignUI("Vui lòng chọn phong trào để xem thống kê", { clearUrl: false });

    if (!year || !semester) {
      setCampaignSelectDisabled("Hãy chọn Năm học và Học kỳ để xem phong trào");
      return;
    }

    const select = document.getElementById("campaignSelect");
    if (select) select.disabled = false;

    await reloadCampaignOptions();

    const optCount = document.getElementById("campaignSelect")?.options?.length || 0;
    if (optCount <= 1) {
      setCampaignSelectDisabled("Không có phong trào cho bộ lọc đã chọn");
      return;
    }

    // restore campaign_id nếu hợp lệ
    const campaignFromURL = getCampaignFromURL();
    if (campaignFromURL) {
      const opt = select.querySelector(`option[value="${CSS.escape(campaignFromURL)}"]`);
      if (opt) {
        select.value = campaignFromURL;

        const typeBox = document.getElementById("campaignType");
        if (typeBox) typeBox.classList.remove("hidden");

        campaignMode = "class";
        currentPage = 1;

        await loadCampaignOverview(); // optional
        await renderCampaignByClass();
        return;
      } else {
        clearCampaignFromURL();
      }
    }

    resetCampaignUI("Vui lòng chọn phong trào để xem thống kê", { clearUrl: false });
  }

  // ======================
  // FETCH + SET ROWS
  // ======================
  async function fetchStats(action) {
    const campaignId = document.getElementById("campaignSelect")?.value;
    if (!campaignId) return { ok: false, message: "Chưa chọn phong trào" };

    const year = document.getElementById("filterYear")?.value || "";
    const semester = document.getElementById("filterSemester")?.value || "";

    const res = await fetch(
      `${BASE_API}?action=${action}` +
      `&campaign_id=${encodeURIComponent(campaignId)}` +
      `&school_year=${encodeURIComponent(year)}` +
      `&semester=${encodeURIComponent(semester)}`
    );

    return res.json();
  }

  async function renderCampaignByClass() {
    setCampaignActive("btn-campaign-class");

    const json = await fetchStats("campaign_class_stats");

    if (json?.empty) {
      viewState.rows = [];
      renderSummary([], []);
      renderControls();

      updateCampaignTable({
        headHTML: `
          <th class="px-4 py-2 text-center w-[6%]">STT</th>
          <th class="px-4 py-2 text-left w-[34%]">Lớp</th>
          <th class="px-4 py-2 text-center w-[18%]">Tham gia</th>
          <th class="px-4 py-2 text-center w-[16%]">Tỷ lệ %</th>
          <th class="px-4 py-2 text-right w-[16%]">Điểm</th>
          <th class="px-4 py-2 text-center w-[10%]">Nhóm</th>
        `,
        rowsHTML: `
          <tr>
            <td colspan="6" class="px-4 py-10 text-center text-gray-500 italic">
              ${esc(json.message || "Chưa có lớp phát sinh từ đoàn viên đã chấm")}
            </td>
          </tr>
        `,
        totalPages: 1,
      });
      return;
    }

    if (!json?.ok) {
      resetCampaignUI(json?.message || "Không thể tải dữ liệu", { clearUrl: false });
      return;
    }

    viewState.rows = (json.data || []).map(normalizeRow);
    currentPage = 1;
    renderTableFromState();
  }

  async function renderCampaignByDept() {
    setCampaignActive("btn-campaign-dept");

    const json = await fetchStats("campaign_dept_stats");

    if (!json?.ok) {
      resetCampaignUI(json?.message || "Không thể tải dữ liệu", { clearUrl: false });
      return;
    }

    viewState.rows = (json.data || []).map(normalizeRow);
    currentPage = 1;
    renderTableFromState();
  }

  // ======================
  // MAIN RENDER (exported)
  // ======================
  async function renderCampaigns() {
    const el = document.getElementById("tab-campaigns");
    if (!el) return;

    el.innerHTML = `<div class="text-gray-500">Đang tải phong trào...</div>`;

    try {
      el.innerHTML = `
        <div class="mb-6">
          <div class="mb-6 flex flex-col md:flex-row md:items-end justify-between gap-3">
            <div class="flex flex-col md:flex-row gap-3 w-full">

              <div class="w-full md:w-48">
                <label class="block text-sm font-medium text-gray-600 mb-1">Năm học</label>
                <select id="filterYear" class="w-full border rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Tất cả --</option>
                </select>
              </div>

              <div class="w-full md:w-40">
                <label class="block text-sm font-medium text-gray-600 mb-1">Học kỳ</label>
                <select id="filterSemester" class="w-full border rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Tất cả --</option>
                </select>
              </div>

              <div class="flex-1">
                <label class="block text-sm font-medium text-gray-600 mb-1">Chọn phong trào</label>
                <select id="campaignSelect" class="w-full border rounded-lg px-3 py-2 text-sm">
                  <option value="">-- Chọn phong trào --</option>
                </select>
              </div>

            </div>

            <div class="flex justify-end">
              <button onclick="exportCampaignSummary()"
                class="flex items-center gap-1.5 h-[38px] w-[160] px-4
                       border border-indigo-600 text-indigo-700 rounded-lg text-sm font-semibold
                       hover:bg-indigo-50 transition">
                <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
                Excel tổng hợp
              </button>
            </div>
          </div>

          <div id="campaignType" class="hidden mb-6 flex items-center justify-between gap-4">
            <div class="flex gap-2">
              <button id="btn-campaign-class"
                class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium">
                Thống kê theo lớp
              </button>

              <button id="btn-campaign-dept"
                class="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-medium hover:bg-gray-200">
                Thống kê theo khoa/Phòng
              </button>
            </div>

            <div class="flex items-center gap-2">
              <button onclick="exportCampaignStats()"
                class="flex items-center gap-1.5 px-3 py-1.5 border border-emerald-600
                       text-emerald-700 rounded-lg text-sm font-medium hover:bg-emerald-50 transition">
                <i data-lucide="download" class="w-4 h-4"></i>
                Export
              </button>

              <select id="pageSizeSelect"
                class="border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <option value="10">10 dòng / trang</option>
                <option value="15">15 dòng / trang</option>
                <option value="20">20 dòng / trang</option>
                <option value="30">30 dòng / trang</option>
                <option value="50">50 dòng / trang</option>
              </select>
            </div>
          </div>

          <!-- SUMMARY + CONTROLS -->
          <div id="campaign-summary" class="mb-4"></div>
          <div id="campaign-controls" class="mb-4"></div>

          <div id="campaign-content" class="bg-white p-6 rounded-xl shadow-sm border">
            <div class="overflow-x-auto mb-4">
              <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                  <tr id="campaign-head"></tr>
                </thead>
                <tbody id="campaign-body"></tbody>
              </table>
            </div>
            <div id="campaign-pagination"></div>
          </div>
        </div>
      `;

      createIcons();

      await loadSchoolYears();
      await loadSemesters();
      initCampaignFilterState();

      const select = document.getElementById("campaignSelect");
      const typeBox = document.getElementById("campaignType");
      const filterYear = document.getElementById("filterYear");
      const filterSemester = document.getElementById("filterSemester");

      filterYear.onchange = handleFiltersChange;
      filterSemester.onchange = handleFiltersChange;

      const pageSizeSelect = document.getElementById("pageSizeSelect");
      if (pageSizeSelect) {
        pageSizeSelect.value = String(pageSize);
        pageSizeSelect.onchange = () => {
          pageSize = parseInt(pageSizeSelect.value, 10);
          currentPage = 1;
          renderTableFromState();
        };
      }

      select.onchange = async () => {
        const id = select.value;

        if (!id) {
          clearCampaignFromURL();
          typeBox.classList.add("hidden");
          resetCampaignUI("Vui lòng chọn phong trào để xem thống kê", { clearUrl: false });
          return;
        }

        setCampaignToURL(id);

        campaignMode = "class";
        currentPage = 1;
        typeBox.classList.remove("hidden");

        viewState.meta = null;
        await loadCampaignOverview(); // optional
        await renderCampaignByClass();
      };

      document.getElementById("btn-campaign-class").onclick = async () => {
        campaignMode = "class";
        currentPage = 1;
        viewState.rows = [];
        renderSummary([], []);
        await loadCampaignOverview(); // optional (same data)
        await renderCampaignByClass();
      };

      document.getElementById("btn-campaign-dept").onclick = async () => {
        campaignMode = "dept";
        currentPage = 1;
        viewState.rows = [];
        renderSummary([], []);
        await loadCampaignOverview(); // optional
        await renderCampaignByDept();
      };

      // init try restore from URL when filters are ready
      await handleFiltersChange();
    } catch (e) {
      el.innerHTML = `<div class="text-red-600">Không thể tải danh sách phong trào</div>`;
    }
  }

  // pagination handler (inline onclick)
  window.gotoPage = function gotoPage(page) {
    page = parseInt(page, 10);
    if (isNaN(page) || page < 1) page = 1;
    currentPage = page;
    renderTableFromState();
  };

  // EXPOSE for statistics.js
  window.renderCampaigns = renderCampaigns;
})();
