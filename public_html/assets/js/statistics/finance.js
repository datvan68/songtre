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
    if (!window.META) {
      try {
        const res = await fetch("controllers/finance.php?action=meta");
        const json = await res.json();
        if (json.ok) {
          window.META = json.data;
        }
      } catch (e) {
        console.error("Lỗi tải META trong statistics/finance:", e);
      }
    }

    const schoolYears = window.META?.school_years || [];
    const semesters = window.META?.semesters || [];
    const departments = window.META?.departments || [];
    const courses = window.META?.courses || [];

    const unpaidState = {
      page: 1,
      pageSize: 10,
      rows: [],
      total: 0,
      totalPages: 1,
      itemName: "",
      schoolYearId: "",
      semester: "",
      deptId: "",
      courseId: "",
      classText: "",
      targetType: "tat_ca"
    };

    const syOptions = `<option value="">Tất cả năm học</option>` + schoolYears.map(sy => `<option value="${sy.id}">${esc(sy.year_label || sy.name)}</option>`).join("");
    const semOptions = `<option value="">Tất cả học kỳ</option>` + semesters.map(sem => `<option value="${sem.code || sem.id}">${esc(sem.label || sem.name)}</option>`).join("");

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
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
          <span class="font-semibold text-gray-900">Chi tiết các phiếu giao dịch</span>
          <div class="flex items-center gap-2">
            <button id="finBtnExcelDetail" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl flex items-center gap-1.5 transition">
              <i data-lucide="download" class="w-3.5 h-3.5"></i> Xuất Excel
            </button>
            <select id="finPageSize" class="border rounded-lg px-2 py-1 text-xs">
              <option value="10">10 dòng / trang</option>
              <option value="15">15 dòng / trang</option>
              <option value="20">20 dòng / trang</option>
              <option value="50">50 dòng / trang</option>
            </select>
          </div>
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

      <!-- Box Theo dõi thành viên chưa đóng tiền -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between mb-4 border-b pb-3 border-gray-50 flex-wrap gap-2">
          <div>
            <h3 class="font-semibold text-gray-900 text-lg flex items-center gap-2">
              <i data-lucide="alert-circle" class="w-5 h-5 text-rose-500"></i> Theo dõi thành viên chưa đóng tiền
            </h3>
            <p class="text-xs text-gray-500 mt-0.5">Chọn một khoản thu cụ thể để đối chiếu danh sách các cá nhân chưa đóng.</p>
          </div>
          <button id="unpaidBtnExport" disabled
            class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 text-sm font-semibold flex items-center gap-2 transition disabled:opacity-50 disabled:cursor-not-allowed">
            <i data-lucide="download" class="w-4 h-4"></i> Xuất Excel chưa đóng
          </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Khoản thu <span class="text-rose-500">*</span></label>
            <select id="unpaidIncomeItem" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="">-- Chọn khoản thu --</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Năm học</label>
            <select id="unpaidSchoolYear" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
              ${syOptions}
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Học kỳ</label>
            <select id="unpaidSemester" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
              ${semOptions}
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phân loại</label>
            <select id="unpaidTargetType" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="tat_ca">Tất cả</option>
              <option value="doan_vien">Đoàn viên</option>
              <option value="thanh_nien">Thanh niên</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Khoa / Phòng</label>
            <select id="unpaidDept" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="">Tất cả khoa</option>
              ${departments.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join("")}
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Khóa</label>
            <select id="unpaidCourse" class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
              <option value="">Tất cả khóa</option>
              ${courses.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join("")}
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Lớp</label>
            <input id="unpaidClassText" type="text" placeholder="Gõ tên lớp..." class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
          </div>
        </div>

        <div class="flex justify-end gap-2 mb-4">
          <button id="unpaidBtnSearch" class="px-5 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">Tìm kiếm</button>
          <button id="unpaidBtnReset" class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">Làm mới</button>
        </div>

        <div id="unpaid-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải danh sách thành viên chưa đóng...</div>
        
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr class="text-left font-semibold">
                <th class="px-4 py-3 text-center w-[8%]">STT</th>
                <th class="px-4 py-3 text-left w-[25%]">Họ và tên</th>
                <th class="px-4 py-3 text-center w-[15%]">MSSV</th>
                <th class="px-4 py-3 text-left w-[15%]">Lớp</th>
                <th class="px-4 py-3 text-left w-[22%]">Khoa / Phòng</th>
                <th class="px-4 py-3 text-center w-[15%]">Phân loại</th>
              </tr>
            </thead>
            <tbody id="unpaid-body" class="divide-y divide-gray-100">
              <tr>
                <td colspan="6" class="px-4 py-10 text-center text-gray-500 italic">Vui lòng chọn khoản thu và bấm Tìm kiếm.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div id="unpaid-pagination" class="mt-4"></div>
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
    const btnExcelDetail = panelEl.querySelector("#finBtnExcelDetail");
    if (btnExcelDetail) {
      btnExcelDetail.addEventListener("click", () => window.exportFinanceReport());
    }

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

    // -------------------------------------------------
    // LOGIC CHO BẢNG CHƯA ĐÓNG TIỀN
    // -------------------------------------------------
    const unpaidItemSel = panelEl.querySelector("#unpaidIncomeItem");
    const unpaidSchoolYearSel = panelEl.querySelector("#unpaidSchoolYear");
    const unpaidSemesterSel = panelEl.querySelector("#unpaidSemester");
    const unpaidDeptSel = panelEl.querySelector("#unpaidDept");
    const unpaidCourseSel = panelEl.querySelector("#unpaidCourse");
    const unpaidClassInput = panelEl.querySelector("#unpaidClassText");
    const unpaidTargetSel = panelEl.querySelector("#unpaidTargetType");
    const unpaidBtnSearch = panelEl.querySelector("#unpaidBtnSearch");
    const unpaidBtnReset = panelEl.querySelector("#unpaidBtnReset");
    const unpaidBtnExport = panelEl.querySelector("#unpaidBtnExport");
    const unpaidBody = panelEl.querySelector("#unpaid-body");
    const unpaidPag = panelEl.querySelector("#unpaid-pagination");
    const unpaidLoading = panelEl.querySelector("#unpaid-loading");

    // Load danh sách khoản thu
    async function loadUnpaidIncomeItems() {
      try {
        const res = await tryJson(`${BASE_API}?action=get_income_items`);
        if (res && res.ok && res.items) {
          unpaidItemSel.innerHTML = '<option value="">-- Chọn khoản thu --</option>' + 
            res.items.map(item => `<option value="${esc(item.name)}" data-target="${esc(item.target_type)}">${esc(item.name)}</option>`).join("");
        }
      } catch (e) {
        console.error("Lỗi loadUnpaidIncomeItems:", e);
      }
    }

    // Tải dữ liệu thành viên chưa đóng
    async function runUnpaidReport() {
      const itemName = unpaidItemSel.value;
      if (!itemName) {
        alert("Vui lòng chọn khoản thu");
        return;
      }

      unpaidState.itemName = itemName;
      unpaidState.schoolYearId = unpaidSchoolYearSel.value;
      unpaidState.semester = unpaidSemesterSel.value;
      unpaidState.deptId = unpaidDeptSel.value;
      unpaidState.courseId = unpaidCourseSel.value;
      unpaidState.classText = unpaidClassInput.value.trim();
      unpaidState.targetType = unpaidTargetSel.value;

      if (unpaidLoading) unpaidLoading.classList.remove("hidden");

      const params = {
        action: "unpaid_members",
        item_name: unpaidState.itemName,
        school_year_id: unpaidState.schoolYearId,
        semester: unpaidState.semester,
        department_id: unpaidState.deptId,
        course_id: unpaidState.courseId,
        class_text: unpaidState.classText,
        target_type: unpaidState.targetType,
        page: unpaidState.page,
        page_size: unpaidState.pageSize
      };

      const url = `${BASE_API}?${qs(params)}`;
      const res = await tryJson(url);

      if (unpaidLoading) unpaidLoading.classList.add("hidden");

      if (!res || !res.ok) {
        unpaidBody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-red-500">${esc(res?.error || "Không thể tải dữ liệu")}</td></tr>`;
        unpaidPag.innerHTML = "";
        unpaidBtnExport.disabled = true;
        return;
      }

      unpaidState.rows = res.rows || [];
      const paging = res.paging || {};
      unpaidState.total = paging.total || 0;
      unpaidState.totalPages = paging.total_pages || 1;

      renderUnpaidTable();
      createIcons();
    }

    function renderUnpaidTable() {
      unpaidBtnExport.disabled = unpaidState.rows.length === 0;

      if (unpaidState.rows.length === 0) {
        unpaidBody.innerHTML = `<tr><td colspan="6" class="px-4 py-10 text-center text-emerald-600 font-semibold italic">Tất cả thành viên đã hoàn thành đóng tiền!</td></tr>`;
        unpaidPag.innerHTML = "";
        return;
      }

      const startIdx = (unpaidState.page - 1) * unpaidState.pageSize;

      unpaidBody.innerHTML = unpaidState.rows.map((r, i) => {
        const typeTone = r.member_type === "Đoàn viên" ? "green" : (r.member_type === "Thanh niên" ? "sky" : "gray");
        return `
          <tr class="border-t hover:bg-gray-50 transition">
            <td class="px-4 py-3 text-center text-gray-500">${startIdx + i + 1}</td>
            <td class="px-4 py-3 font-semibold text-gray-900">${esc(r.fullname)}</td>
            <td class="px-4 py-3 text-center font-mono text-gray-600">${esc(r.mssv || "-")}</td>
            <td class="px-4 py-3 text-gray-700 font-medium">${esc(r.class_name)}</td>
            <td class="px-4 py-3 text-gray-600">${esc(r.department_name || "-")}</td>
            <td class="px-4 py-3 text-center">${pill({ text: r.member_type, tone: typeTone })}</td>
          </tr>
        `;
      }).join("");

      // Render pagination
      if (unpaidState.totalPages > 1) {
        unpaidPag.innerHTML = `
          <div class="flex items-center justify-center gap-2 text-sm pt-4 border-t border-gray-100">
            <button class="px-2 py-1 border rounded hover:bg-gray-100" id="unpaidPrevBtn">‹</button>
            <span class="text-gray-600">Trang ${unpaidState.page} / ${unpaidState.totalPages}</span>
            <button class="px-2 py-1 border rounded hover:bg-gray-100" id="unpaidNextBtn">›</button>
          </div>
        `;
        
        const prevBtn = unpaidPag.querySelector("#unpaidPrevBtn");
        const nextBtn = unpaidPag.querySelector("#unpaidNextBtn");

        if (unpaidState.page === 1) prevBtn.disabled = true;
        if (unpaidState.page === unpaidState.totalPages) nextBtn.disabled = true;

        prevBtn.onclick = () => {
          if (unpaidState.page > 1) {
            unpaidState.page--;
            runUnpaidReport();
          }
        };

        nextBtn.onclick = () => {
          if (unpaidState.page < unpaidState.totalPages) {
            unpaidState.page++;
            runUnpaidReport();
          }
        };
      } else {
        unpaidPag.innerHTML = "";
      }
    }

    // Auto set Target Type khi thay đổi Khoản thu
    unpaidItemSel.addEventListener("change", () => {
      const opt = unpaidItemSel.options[unpaidItemSel.selectedIndex];
      const target = opt ? opt.dataset.target : "";
      if (target === "doan_vien" || target === "thanh_nien") {
        unpaidTargetSel.value = target;
      } else {
        unpaidTargetSel.value = "tat_ca";
      }
    });

    unpaidBtnSearch.onclick = () => {
      unpaidState.page = 1;
      runUnpaidReport();
    };

    unpaidBtnReset.onclick = () => {
      unpaidItemSel.value = "";
      unpaidSchoolYearSel.value = "";
      unpaidSemesterSel.value = "";
      unpaidDeptSel.value = "";
      unpaidCourseSel.value = "";
      unpaidClassInput.value = "";
      unpaidTargetSel.value = "tat_ca";

      unpaidState.page = 1;
      unpaidState.rows = [];
      unpaidState.total = 0;
      unpaidState.totalPages = 1;
      unpaidState.itemName = "";
      unpaidState.schoolYearId = "";
      unpaidState.semester = "";
      unpaidState.deptId = "";
      unpaidState.courseId = "";
      unpaidState.classText = "";

      unpaidBody.innerHTML = `<tr><td colspan="6" class="px-4 py-10 text-center text-gray-500 italic">Vui lòng chọn khoản thu và bấm Tìm kiếm.</td></tr>`;
      unpaidPag.innerHTML = "";
      unpaidBtnExport.disabled = true;
    };

    unpaidBtnExport.onclick = () => {
      if (!unpaidState.itemName) return;
      const params = {
        action: "export_unpaid_members",
        item_name: unpaidState.itemName,
        school_year_id: unpaidState.schoolYearId,
        semester: unpaidState.semester,
        department_id: unpaidState.deptId,
        course_id: unpaidState.courseId,
        class_text: unpaidState.classText,
        target_type: unpaidState.targetType
      };
      window.location.href = `${BASE_API}?${qs(params)}`;
    };

    // Load data
    await loadUnpaidIncomeItems();

    await runReport(panelEl);
    createIcons();
  }
})();
