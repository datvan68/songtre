// assets/js/statistics/finance.js
(() => {
  if (window.__STATS_FINANCE_READY__) return;
  window.__STATS_FINANCE_READY__ = true;

  const BASE_API = "controllers/statistics/finance.php";

  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "trans_date",
    sortDir: "desc",
    search: "",
    type: "", // income|expense|all
    schoolYearId: "",
    semester: "",
    rows: [],
    summary: null,
    topIncome: [],
    topExpense: [],
    lastError: "",
  };

  window.StatsModules = window.StatsModules || {};
  window.StatsModules.finance = async (panelEl) => {
    await renderFinance(panelEl);
  };

  const fmt = (n) => Number(n || 0).toLocaleString("vi-VN") + " đ";
  const fmtQty = (n) => Number(n || 0).toLocaleString("vi-VN") + " lượt";
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

  window.exportFinanceReport = function exportFinanceReport() {
    const filters = getFiltersFromUI();
    const url = `${BASE_API}?` + qs({ action: "export_finance_report", ...filters });
    window.location.href = url;
  };

  function getFiltersFromUI() {
    return {
      date_from: document.getElementById("finDateFrom")?.value || "",
      date_to: document.getElementById("finDateTo")?.value || "",
      type: document.getElementById("finType")?.value || "",
      school_year_id: document.getElementById("finSchoolYear")?.value || "",
      semester: document.getElementById("finSemester")?.value || "",
      q: (document.getElementById("finSearch")?.value || "").trim(),
    };
  }

  async function fetchFinanceReport(filters) {
    const url = `${BASE_API}?${qs({ action: "finance_report", ...filters })}`;
    const json = await tryJson(url);
    if (!json?.ok) {
      return { ok: false, message: json?.error || "Không thể tải dữ liệu" };
    }
    return {
      ok: true,
      rows: json.rows || [],
      summary: json.summary || {},
      topIncome: json.top_income || [],
      topExpense: json.top_expense || [],
    };
  }

  function filteredSortedRows() {
    const q = state.search.trim().toLowerCase();
    let rows = [...state.rows];

    if (q) {
      rows = rows.filter((r) => {
        const hay = `${r.item_name} ${r.code} ${r.payer_name} ${r.payee_name} ${r.description}`.toLowerCase();
        return hay.includes(q);
      });
    }

    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "item_name") return a.item_name.localeCompare(b.item_name, "vi") * dir;
      if (key === "code") return a.code.localeCompare(b.code) * dir;
      if (key === "amount") return (num(a.amount) - num(b.amount)) * dir;
      if (key === "trans_date") return a.trans_date.localeCompare(b.trans_date) * dir;
      return (a.id - b.id) * dir;
    });

    return rows;
  }

  function renderKPI(panelEl) {
    const s = state.summary || {};
    const totalIncome = num(s.total_income);
    const totalExpense = num(s.total_expense);
    const balance = num(s.balance);

    const balanceTone = balance >= 0 ? "emerald" : "rose";

    const el = panelEl.querySelector("#fin-kpi");
    if (!el) return;

    el.innerHTML = `
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Tổng thu</p>
              <p class="mt-1 text-2xl font-bold text-emerald-600 leading-none">${fmt(totalIncome)}</p>
              <p class="mt-2 text-xs text-gray-400">${fmtQty(s.income_count)}</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-emerald-50">
              <i data-lucide="trending-up" class="w-5 h-5 text-emerald-600"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Tổng chi</p>
              <p class="mt-1 text-2xl font-bold text-rose-600 leading-none">${fmt(totalExpense)}</p>
              <p class="mt-2 text-xs text-gray-400">${fmtQty(s.expense_count)}</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-rose-50">
              <i data-lucide="trending-down" class="w-5 h-5 text-rose-600"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Quỹ tồn hiện tại</p>
              <p class="mt-1 text-2xl font-bold text-${balanceTone}-600 leading-none">${fmt(balance)}</p>
              <p class="mt-2 text-xs text-gray-400">Thu - Chi</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-${balanceTone}-50">
              <i data-lucide="wallet" class="w-5 h-5 text-${balanceTone}-600"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Lượt giao dịch thu</p>
              <p class="mt-1 text-2xl font-bold text-gray-900 leading-none">${fmtQty(s.income_count)}</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-indigo-50">
              <i data-lucide="plus-circle" class="w-5 h-5 text-indigo-600"></i>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-500">Lượt giao dịch chi</p>
              <p class="mt-1 text-2xl font-bold text-gray-900 leading-none">${fmtQty(s.expense_count)}</p>
            </div>
            <div class="w-11 h-11 flex items-center justify-center rounded-xl bg-amber-50">
              <i data-lucide="minus-circle" class="w-5 h-5 text-amber-600"></i>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function renderInsights(panelEl) {
    const el = panelEl.querySelector("#fin-insights");
    if (!el) return;

    if (!state.topIncome.length && !state.topExpense.length) {
      el.innerHTML = "";
      return;
    }

    el.innerHTML = `
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="font-semibold text-gray-900 flex items-center gap-2">
            <i data-lucide="arrow-up-right" class="w-5 h-5 text-emerald-600"></i> Top 5 Khoản thu lớn nhất
          </p>
          <div class="mt-3 space-y-2">
            ${state.topIncome.map(r => `
              <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50 transition">
                <span class="font-medium text-gray-700 truncate">${esc(r.item_name)}</span>
                <span class="font-bold text-emerald-600 shrink-0">${fmt(num(r.total))}</span>
              </div>
            `).join("")}
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="font-semibold text-gray-900 flex items-center gap-2">
            <i data-lucide="arrow-down-left" class="w-5 h-5 text-rose-600"></i> Top 5 Khoản chi lớn nhất
          </p>
          <div class="mt-3 space-y-2">
            ${state.topExpense.map(r => `
              <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50 transition">
                <span class="font-medium text-gray-700 truncate">${esc(r.item_name)}</span>
                <span class="font-bold text-rose-600 shrink-0">${fmt(num(r.total))}</span>
              </div>
            `).join("")}
          </div>
        </div>
      </div>
    `;
  }

  function renderTable(panelEl) {
    const head = panelEl.querySelector("#fin-head");
    const body = panelEl.querySelector("#fin-body");
    const pag = panelEl.querySelector("#fin-pagination");
    if (!head || !body || !pag) return;

    const rowsFiltered = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rowsFiltered.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rowsFiltered.slice(start, start + state.pageSize);

    head.innerHTML = `
      <th class="px-4 py-3 text-center w-[8%]">STT</th>
      <th class="px-4 py-3 text-left w-[12%]" data-fin-sort="code">Mã phiếu</th>
      <th class="px-4 py-3 text-left w-[25%]" data-fin-sort="item_name">Khoản thu/chi</th>
      <th class="px-4 py-3 text-center w-[10%]">Loại</th>
      <th class="px-4 py-3 text-right w-[15%]" data-fin-sort="amount">Số tiền</th>
      <th class="px-4 py-3 text-center w-[12%]" data-fin-sort="trans_date">Ngày GD</th>
      <th class="px-4 py-3 text-left w-[18%]">Người nộp/nhận</th>
    `;

    if (!rowsFiltered.length) {
      body.innerHTML = `<tr><td colspan="7" class="px-4 py-10 text-center text-gray-500 italic">Không tìm thấy dữ liệu.</td></tr>`;
      pag.innerHTML = "";
      bindSortHeaders(panelEl);
      return;
    }

    body.innerHTML = display.map((r, i) => {
      const typePill = r.type === "income" ? pill({ text: "Thu", tone: "green" }) : pill({ text: "Chi", tone: "red" });
      const amountCls = r.type === "income" ? "text-emerald-700 font-bold" : "text-rose-700 font-bold";
      const targetName = r.type === "income" ? r.payer_name : r.payee_name;

      return `
        <tr class="border-t hover:bg-gray-50 transition">
          <td class="px-4 py-3 text-center text-gray-500">${start + i + 1}</td>
          <td class="px-4 py-3 font-mono font-medium">${esc(r.code)}</td>
          <td class="px-4 py-3">
            <div class="font-medium text-gray-900">${esc(r.item_name)}</div>
            <div class="text-xs text-gray-400 truncate w-60">${esc(r.description || "")}</div>
          </td>
          <td class="px-4 py-3 text-center">${typePill}</td>
          <td class="px-4 py-3 text-right ${amountCls}">${fmt(num(r.amount))}</td>
          <td class="px-4 py-3 text-center text-gray-600">${esc(r.trans_date)}</td>
          <td class="px-4 py-3 text-gray-700 text-sm font-medium">${esc(targetName || "-")}</td>
        </tr>
      `;
    }).join("");

    pag.innerHTML = totalPages > 1 ? renderPaginationHtml(totalPages) : "";
    bindSortHeaders(panelEl);
  }

  function renderPaginationHtml(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm pt-4 border-t border-gray-100">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="finGotoPage(1)">«</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="finGotoPage(${state.currentPage - 1})" ${state.currentPage === 1 ? "disabled" : ""}>‹</button>
        <input type="number" min="1" max="${totalPages}" value="${state.currentPage}" class="w-16 text-center border rounded px-2 py-1" onkeydown="if(event.key==='Enter') finGotoPage(this.value)" />
        <span class="text-gray-500">/ ${totalPages}</span>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="finGotoPage(${state.currentPage + 1})" ${state.currentPage === totalPages ? "disabled" : ""}>›</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="finGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  function bindSortHeaders(panelEl) {
    panelEl.querySelectorAll("[data-fin-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.finSort;
        if (!k) return;
        if (state.sortKey === k) {
          state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        } else {
          state.sortKey = k;
          state.sortDir = k === "item_name" ? "asc" : "desc";
        }
        state.currentPage = 1;
        renderTable(panelEl);
      };
    });
  }

  async function runReport(panelEl) {
    const loading = panelEl.querySelector("#fin-loading");
    if (loading) loading.classList.remove("hidden");

    const filters = getFiltersFromUI();
    state.search = (filters.q || "").trim();

    const res = await fetchFinanceReport(filters);
    if (loading) loading.classList.add("hidden");

    if (!res.ok) {
      state.rows = [];
      state.summary = null;
      state.topIncome = [];
      state.topExpense = [];
      state.lastError = res.message;
      alert(state.lastError);
      return;
    }

    state.rows = res.rows;
    state.summary = res.summary;
    state.topIncome = res.topIncome;
    state.topExpense = res.topExpense;
    state.currentPage = 1;

    renderKPI(panelEl);
    renderInsights(panelEl);
    renderTable(panelEl);
    createIcons();
  }

  async function renderFinance(panelEl) {
    const schoolYears = window.META?.school_years || [];
    const semesters = window.META?.semesters || [];

    const syOptions = `<option value="">Tất cả năm học</option>` + schoolYears.map(sy => `<option value="${sy.id}">${esc(sy.name)}</option>`).join("");
    const semOptions = `<option value="">Tất cả học kỳ</option>` + semesters.map(sem => `<option value="${sem.id}">${esc(sem.name)}</option>`).join("");

    panelEl.innerHTML = `
      <div class="mb-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
              <i data-lucide="wallet" class="w-6 h-6 text-indigo-600"></i> Thống kê Quản lý Thu - Chi
            </h2>
            <p class="mt-1 text-sm text-gray-500">Xem tổng hợp số tiền quỹ đoàn, chi hoạt động, top giao dịch và chi tiết các phiếu giao dịch.</p>
          </div>
          <button id="finBtnExport"
            class="px-4 py-2 rounded-xl border border-emerald-600 text-emerald-700 bg-white hover:bg-emerald-50 text-sm font-semibold flex items-center gap-2 transition">
            <i data-lucide="download" class="w-4 h-4"></i> Xuất báo cáo
          </button>
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày</label>
            <input id="finDateFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày</label>
            <input id="finDateTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Năm học</label>
            <select id="finSchoolYear" class="w-full border rounded-lg px-3 py-2 text-sm">${syOptions}</select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Học kỳ</label>
            <select id="finSemester" class="w-full border rounded-lg px-3 py-2 text-sm">${semOptions}</select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Loại giao dịch</label>
            <select id="finType" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">Tất cả</option>
              <option value="income">Thu</option>
              <option value="expense">Chi</option>
            </select>
          </div>
        </div>

        <div class="mt-4 flex flex-col md:flex-row gap-3 items-end">
          <div class="flex-1 w-full">
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm nâng cao</label>
            <input id="finSearch" type="text" placeholder="Tìm theo mã phiếu, tên khoản, mô tả, người nộp..." class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>
          <div class="flex gap-2 w-full md:w-auto shrink-0">
            <button id="finBtnRun" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">Tải báo cáo</button>
            <button id="finBtnReset" class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">Làm mới</button>
          </div>
        </div>
      </div>

      <div id="fin-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê Thu - Chi...</div>
      <div id="fin-kpi" class="mb-6"></div>
      <div id="fin-insights" class="mb-6"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
          <span class="font-semibold text-gray-900">Chi tiết các phiếu giao dịch</span>
          <select id="finPageSize" class="border rounded-lg px-2 py-1 text-xs">
            <option value="10">10 dòng / trang</option>
            <option value="15">15 dòng / trang</option>
            <option value="20">20 dòng / trang</option>
            <option value="50">50 dòng / trang</option>
          </select>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="fin-head"></tr>
            </thead>
            <tbody id="fin-body" class="divide-y divide-gray-100"></tbody>
          </table>
        </div>
        <div id="fin-pagination" class="mt-4"></div>
      </div>
    `;

    // bind events
    const btnRun = panelEl.querySelector("#finBtnRun");
    const btnReset = panelEl.querySelector("#finBtnReset");
    const btnExport = panelEl.querySelector("#finBtnExport");
    const search = panelEl.querySelector("#finSearch");
    const pageSize = panelEl.querySelector("#finPageSize");

    btnRun.addEventListener("click", () => runReport(panelEl));
    btnReset.addEventListener("click", () => {
      panelEl.querySelector("#finDateFrom").value = "";
      panelEl.querySelector("#finDateTo").value = "";
      panelEl.querySelector("#finSchoolYear").value = "";
      panelEl.querySelector("#finSemester").value = "";
      panelEl.querySelector("#finType").value = "";
      search.value = "";
      pageSize.value = "10";

      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "trans_date";
      state.sortDir = "desc";
      state.search = "";
      state.rows = [];
      state.summary = null;
      state.topIncome = [];
      state.topExpense = [];

      runReport(panelEl);
    });

    btnExport.addEventListener("click", () => window.exportFinanceReport());

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

    window.finGotoPage = function finGotoPage(p) {
      p = parseInt(p, 10);
      if (isNaN(p) || p < 1) p = 1;
      state.currentPage = p;
      renderTable(panelEl);
    };

    await runReport(panelEl);
    createIcons();
  }
})();
