// assets/js/statistics/inventory.js
(() => {
  if (window.__STATS_INVENTORY_READY__) return;
  window.__STATS_INVENTORY_READY__ = true;

  window.StatsModules = window.StatsModules || {};
  const BASE_API = "controllers/statistics/inventory.php";

  const state = {
    pageSize: 10,
    currentPage: 1,
    sortKey: "borrow_date", // item | borrower | qty | borrow_date | deadline | status
    sortDir: "desc",
    rows: [],
    summary: null,
    options: { categories: [], departments: [] },

    // filters
    q: "",
    status: "all", // borrowing | returned | overdue | all
    type: "all", // equipment | item | all
    categoryId: "",
    departmentId: "",
    dateFrom: "",
    dateTo: "",
    onlyOverdue: false,
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
    } catch (e) { }
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
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      });

      const ct = (res.headers.get("content-type") || "").toLowerCase();
      const text = await res.text();

      if (ct.includes("application/json")) return JSON.parse(text);

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
    return `<span class="px-2 py-1 rounded-lg text-xs font-semibold ${map[tone] || map.gray
      }">${esc(text)}</span>`;
  }

  function statusMeta(st) {
    const m = {
      borrowing: { label: "Đang mượn", tone: "amber", icon: "hand" },
      returned: { label: "Đã trả", tone: "green", icon: "check-circle" },
      overdue: { label: "Quá hạn", tone: "red", icon: "alert-triangle" },
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
      inventory_id: num(r.inventory_id),
      item_code: String(r.item_code || ""),
      item_name: String(r.item_name || ""),
      item_type: String(r.item_type || ""),
      category_name: String(r.category_name || ""),
      dept_name: String(r.dept_name || ""),
      borrower_name: String(r.borrower_name || ""),
      borrower_unit: String(r.borrower_unit || ""),
      quantity: num(r.quantity),
      borrow_date: String(r.borrow_date || ""),
      return_deadline: String(r.return_deadline || ""),
      return_date: String(r.return_date || ""),
      status: String(r.status || ""),
      days_late: num(r.days_late || 0),
      purpose: String(r.purpose || ""),
      note: String(r.note || ""),
      created_by: num(r.created_by || 0),
      created_by_name: String(r.created_by_name || ""),
      raw: r,
    };
  }

  function getFilters(panelEl) {
    return {
      q: (panelEl.querySelector("#invQ")?.value || "").trim(),
      status: panelEl.querySelector("#invStatus")?.value || "all",
      type: panelEl.querySelector("#invType")?.value || "all",
      category_id: panelEl.querySelector("#invCategory")?.value || "",
      department_id: panelEl.querySelector("#invDept")?.value || "",
      date_from: panelEl.querySelector("#invFrom")?.value || "",
      date_to: panelEl.querySelector("#invTo")?.value || "",
      only_overdue: panelEl.querySelector("#invOnlyOverdue")?.checked ? 1 : 0,
    };
  }

  async function loadOptions(panelEl) {
    const json = await tryJson(`${BASE_API}?${qs({ action: "inventory_options" })}`);
    if (!json?.ok) return;

    state.options.categories = json.categories || [];
    state.options.departments = json.departments || [];

    const catSel = panelEl.querySelector("#invCategory");
    const deptSel = panelEl.querySelector("#invDept");

    if (catSel) {
      catSel.innerHTML =
        `<option value="">-- Tất cả danh mục --</option>` +
        state.options.categories
          .map((c) => `<option value="${esc(c.id)}">${esc(c.name)}</option>`)
          .join("");
    }

    if (deptSel) {
      deptSel.innerHTML =
        `<option value="">-- Tất cả đơn vị quản lý --</option>` +
        state.options.departments
          .map((d) => {
            const type = d.type ? ` (${d.type})` : "";
            return `<option value="${esc(d.id)}">${esc(d.name)}${esc(type)}</option>`;
          })
          .join("");
    }
  }

  async function fetchReport(filters) {
    const json = await tryJson(`${BASE_API}?${qs({ action: "inventory_report", ...filters })}`);
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
        const hay = `${r.item_code} ${r.item_name} ${r.borrower_name} ${r.borrower_unit} ${r.purpose} ${r.note} ${r.category_name} ${r.dept_name}`.toLowerCase();
        return hay.includes(q);
      });
    }

    if (state.status && state.status !== "all") {
      rows = rows.filter((r) => String(r.status) === String(state.status));
    }

    if (state.type && state.type !== "all") {
      rows = rows.filter((r) => String(r.item_type) === String(state.type));
    }

    if (state.categoryId) {
      rows = rows.filter((r) => String(r.raw?.category_id || "") === String(state.categoryId));
    }

    if (state.departmentId) {
      rows = rows.filter((r) => String(r.raw?.department_id || "") === String(state.departmentId));
    }

    if (state.onlyOverdue) {
      rows = rows.filter((r) => r.status === "overdue");
    }

    // sorting
    const dir = state.sortDir === "asc" ? 1 : -1;
    const key = state.sortKey;

    rows.sort((a, b) => {
      if (key === "item") return `${a.item_code} ${a.item_name}`.localeCompare(`${b.item_code} ${b.item_name}`, "vi") * dir;
      if (key === "borrower") return a.borrower_name.localeCompare(b.borrower_name, "vi") * dir;
      if (key === "qty") return (a.quantity - b.quantity) * dir;
      if (key === "deadline") return String(a.return_deadline).localeCompare(String(b.return_deadline)) * dir;
      if (key === "status") return String(a.status).localeCompare(String(b.status)) * dir;
      // default borrow_date
      return String(a.borrow_date).localeCompare(String(b.borrow_date)) * dir;
    });

    return rows;
  }

  function renderPagination(totalPages) {
    return `
      <div class="flex items-center justify-center gap-2 text-sm">
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="invGotoPage(1)">«</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="invGotoPage(${state.currentPage - 1})" ${state.currentPage === 1 ? "disabled" : ""}>‹</button>

        <input id="invPageInput" type="number" min="1" max="${totalPages}" value="${state.currentPage}"
          class="w-16 text-center border rounded px-2 py-1"
          onkeydown="if(event.key==='Enter') invGotoPage(this.value)" />

        <span class="text-gray-500">/ ${totalPages}</span>

        <button class="px-2 py-1 border rounded hover:bg-gray-100"
          onclick="invGotoPage(${state.currentPage + 1})" ${state.currentPage === totalPages ? "disabled" : ""}>›</button>
        <button class="px-2 py-1 border rounded hover:bg-gray-100" onclick="invGotoPage(${totalPages})">»</button>
      </div>
    `;
  }

  function renderError(panelEl, msg) {
    const el = panelEl.querySelector("#inv-error");
    if (!el) return;
    el.innerHTML = msg
      ? `
        <div class="p-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 text-sm">
          <div class="font-semibold">Không thể tải thống kê Thiết bị / Đồ dùng</div>
          <div class="mt-1">${esc(msg)}</div>
        </div>`
      : "";
  }

  function renderKPI(panelEl) {
    const el = panelEl.querySelector("#inv-kpi");
    if (!el) return;

    const s = state.summary || {};
    const totalItems = num(s.total_items);
    const totalQty = num(s.total_quantity);
    const totalBorrowedQty = num(s.total_borrowed_quantity);
    const availableQty = num(s.available_quantity);
    const brokenQty = num(s.broken_quantity);
    const stockQty = num(s.stock_quantity);

    const totalBorrowRecords = num(s.total_borrows);
    const borrowing = num(s.borrowing);
    const overdue = num(s.overdue);
    const returned = num(s.returned);

    el.innerHTML = `
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        ${kpiCard({
      label: "Tổng thiết bị / đồ dùng",
      value: fmt(totalItems),
      hint: "COUNT(inventory_items)",
      icon: "package",
      color: "indigo",
    })}
        ${kpiCard({
      label: "Tổng số lượng",
      value: fmt(totalQty),
      hint: "SUM(total_quantity)",
      icon: "layers",
      color: "sky",
    })}
        ${kpiCard({
      label: "Đang cho mượn (SL)",
      value: fmt(totalBorrowedQty),
      hint: "SUM(borrowed_quantity)",
      icon: "hand",
      color: "amber",
    })}
        ${kpiCard({
      label: "Có thể dùng (SL)",
      value: fmt(availableQty),
      hint: "status=available",
      icon: "badge-check",
      color: "emerald",
    })}
        ${kpiCard({
      label: "Kho / Dự trữ (SL)",
      value: fmt(stockQty),
      hint: "status=stock",
      icon: "archive",
      color: "violet",
    })}
        ${kpiCard({
      label: "Hỏng (SL)",
      value: fmt(brokenQty),
      hint: "status=broken",
      icon: "wrench",
      color: "rose",
    })}
      </div>

      <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="text-sm text-gray-500">Tổng lượt mượn (log)</div>
          <div class="mt-1 text-2xl font-bold text-gray-900">${fmt(totalBorrowRecords)}</div>
          <div class="mt-2 text-xs text-gray-500">COUNT(inventory_borrows)</div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="text-sm text-gray-500">Đang mượn</div>
          <div class="mt-1 text-2xl font-bold text-gray-900">${fmt(borrowing)}</div>
          <div class="mt-2">${pill("borrowing", "amber")}</div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="text-sm text-gray-500">Quá hạn</div>
          <div class="mt-1 text-2xl font-bold text-gray-900">${fmt(overdue)}</div>
          <div class="mt-2">${pill("overdue", "red")}</div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="text-sm text-gray-500">Đã trả</div>
          <div class="mt-1 text-2xl font-bold text-gray-900">${fmt(returned)}</div>
          <div class="mt-2">${pill("returned", "green")}</div>
        </div>
      </div>
    `;
  }

  function renderInsights(panelEl) {
    const el = panelEl.querySelector("#inv-insights");
    if (!el) return;

    const s = state.summary || {};
    const topItems = Array.isArray(s.top_items) ? s.top_items.slice(0, 5) : [];
    const topOverdue = Array.isArray(s.top_overdue_borrowers)
      ? s.top_overdue_borrowers.slice(0, 5)
      : [];
    const byCategory = Array.isArray(s.by_category) ? s.by_category.slice(0, 8) : [];
    const byDept = Array.isArray(s.by_department) ? s.by_department.slice(0, 8) : [];

    const warn = [];
    const totalQty = num(s.total_quantity);
    const totalBorrowedQty = num(s.total_borrowed_quantity);
    if (totalBorrowedQty > totalQty && totalQty > 0) {
      warn.push("borrowed_quantity lớn hơn total_quantity (dữ liệu có thể lệch đồng bộ).");
    }

    el.innerHTML = `
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">Top thiết bị mượn nhiều</div>
            ${pill("Top 5", "indigo")}
          </div>
          <div class="mt-3 space-y-2">
            ${topItems.length
        ? topItems
          .map(
            (x) => `
                        <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                          <div class="min-w-0">
                            <div class="font-medium text-gray-900 truncate">${esc(
              x.item_label || "(Không rõ)"
            )}</div>
                            <div class="text-xs text-gray-500 truncate">Borrow logs: ${fmt(x.borrow_count)} · Qty: ${fmt(
              x.borrow_qty
            )}</div>
                          </div>
                          <div class="shrink-0 text-sm font-bold text-gray-900">${fmt(x.borrow_count)}</div>
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
            <div class="font-semibold text-gray-900">Top người mượn quá hạn</div>
            ${pill("Top 5", "rose")}
          </div>
          <div class="mt-3 space-y-2">
            ${topOverdue.length
        ? topOverdue
          .map(
            (x) => `
                        <div class="flex items-center justify-between gap-3 p-2 rounded-xl hover:bg-gray-50">
                          <div class="min-w-0">
                            <div class="font-medium text-gray-900 truncate">${esc(
              x.borrower_name || "(Không rõ)"
            )}</div>
                            <div class="text-xs text-gray-500 truncate">Quá hạn: ${fmt(x.overdue_count)} · SL: ${fmt(
              x.overdue_qty
            )}</div>
                          </div>
                          <div class="shrink-0 text-sm font-bold text-rose-700">${fmt(x.overdue_count)}</div>
                        </div>
                      `
          )
          .join("")
        : `<div class="text-sm text-gray-500 italic">Không có quá hạn.</div>`
      }
          </div>
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="font-semibold text-gray-900">Theo danh mục</div>
          <div class="mt-3 space-y-2">
            ${byCategory.length
        ? byCategory
          .map((x) => {
            const pct = num(s.total_items) ? Math.round((num(x.total_items) / num(s.total_items)) * 100) : 0;
            return `
                        <div class="p-2 rounded-xl hover:bg-gray-50">
                          <div class="flex items-center justify-between">
                            <div class="font-medium text-gray-900 truncate">${esc(x.category_name || "(Không rõ)")}</div>
                            <div class="text-sm font-semibold text-gray-900">${fmt(x.total_items)}</div>
                          </div>
                          <div class="mt-2 h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-indigo-500" style="width:${pct}%;"></div>
                          </div>
                          <div class="mt-1 text-xs text-gray-500">${pct}%</div>
                        </div>
                      `;
          })
          .join("")
        : `<div class="text-sm text-gray-500 italic">Chưa có dữ liệu.</div>`
      }
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="font-semibold text-gray-900">Theo đơn vị quản lý</div>
          <div class="mt-3 space-y-2">
            ${byDept.length
        ? byDept
          .map((x) => {
            const pct = num(s.total_items) ? Math.round((num(x.total_items) / num(s.total_items)) * 100) : 0;
            return `
                        <div class="p-2 rounded-xl hover:bg-gray-50">
                          <div class="flex items-center justify-between">
                            <div class="font-medium text-gray-900 truncate">${esc(x.dept_name || "(Không rõ)")}</div>
                            <div class="text-sm font-semibold text-gray-900">${fmt(x.total_items)}</div>
                          </div>
                          <div class="mt-2 h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-sky-500" style="width:${pct}%;"></div>
                          </div>
                          <div class="mt-1 text-xs text-gray-500">${pct}%</div>
                        </div>
                      `;
          })
          .join("")
        : `<div class="text-sm text-gray-500 italic">Chưa có dữ liệu.</div>`
      }
          </div>
        </div>
      </div>

      ${warn.length
        ? `
        <div class="mt-4 p-4 rounded-2xl border border-amber-200 bg-amber-50 text-amber-800 text-sm">
          <div class="font-semibold">Cảnh báo dữ liệu</div>
          <ul class="list-disc pl-5 mt-2 space-y-1">
            ${warn.map((w) => `<li>${esc(w)}</li>`).join("")}
          </ul>
        </div>
      `
        : ""
      }
    `;
  }

  function bindSort(panelEl) {
    panelEl.querySelectorAll("[data-inv-sort]").forEach((th) => {
      th.style.cursor = "pointer";
      th.onclick = () => {
        const k = th.dataset.invSort;
        if (!k) return;

        if (state.sortKey === k) state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        else {
          state.sortKey = k;
          state.sortDir = (k === "item" || k === "borrower" || k === "status") ? "asc" : "desc";
        }
        state.currentPage = 1;
        renderTable(panelEl);
      };
    });
  }

  function renderTable(panelEl) {
    const head = panelEl.querySelector("#inv-head");
    const body = panelEl.querySelector("#inv-body");
    const pag = panelEl.querySelector("#inv-pagination");
    if (!head || !body || !pag) return;

    const rows = filteredSortedRows();
    const totalPages = Math.max(1, Math.ceil(rows.length / state.pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    const start = (state.currentPage - 1) * state.pageSize;
    const display = rows.slice(start, start + state.pageSize);

    head.innerHTML = `
      <th class="px-4 py-2 text-center w-[6%]">STT</th>
      <th class="px-4 py-2 text-left min-w-[260px]" data-inv-sort="item">Thiết bị / đồ dùng</th>
      <th class="px-4 py-2 text-left min-w-[170px]">Danh mục / Đơn vị</th>
      <th class="px-4 py-2 text-left min-w-[200px]" data-inv-sort="borrower">Người mượn</th>
      <th class="px-4 py-2 text-center w-[90px]" data-inv-sort="qty">SL</th>
      <th class="px-4 py-2 text-center min-w-[140px]" data-inv-sort="borrow_date">Ngày mượn</th>
      <th class="px-4 py-2 text-center min-w-[140px]" data-inv-sort="deadline">Hạn trả</th>
      <th class="px-4 py-2 text-center min-w-[120px]" data-inv-sort="status">Trạng thái</th>
      <th class="px-4 py-2 text-left min-w-[160px]">Người tạo</th>
    `;

    if (!rows.length) {
      body.innerHTML = `
        <tr>
          <td colspan="9" class="px-4 py-10 text-center text-gray-500 italic">
            Không có dữ liệu mượn/trả phù hợp bộ lọc hiện tại.
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
        const itemLabel = `${r.item_code ? r.item_code + " - " : ""}${r.item_name || "(Không rõ)"}`;
        const typeLabel = r.item_type === "equipment" ? "Thiết bị" : r.item_type === "item" ? "Đồ dùng" : (r.item_type || "-");

        const subLeft = [
          r.category_name ? `DM: ${r.category_name}` : "",
          r.dept_name ? `ĐV: ${r.dept_name}` : "",
          typeLabel ? `Loại: ${typeLabel}` : "",
        ].filter(Boolean).join(" · ");

        const subBorrower = [
          r.borrower_unit ? `Đơn vị: ${r.borrower_unit}` : "",
          r.purpose ? `Mục đích: ${r.purpose}` : "",
        ].filter(Boolean).join(" · ");

        const lateLine =
          r.status === "overdue" && r.days_late > 0
            ? `<div class="text-xs text-rose-700 mt-0.5">Trễ ${fmt(r.days_late)} ngày</div>`
            : "";

        return `
          <tr class="border-t hover:bg-gray-50 transition">
            <td class="px-4 py-2 text-center text-gray-500">${start + i + 1}</td>

            <td class="px-4 py-2">
              <div class="font-medium text-gray-900">${esc(itemLabel)}</div>
              <div class="text-xs text-gray-500 mt-0.5">${esc(subLeft || "-")}</div>
            </td>

            <td class="px-4 py-2 text-gray-800">
              <div class="text-sm">${esc(r.category_name || "(Chưa phân loại)")}</div>
              <div class="text-xs text-gray-500">${esc(r.dept_name || "(Chưa gán đơn vị)")}</div>
            </td>

            <td class="px-4 py-2">
              <div class="font-medium text-gray-900">${esc(r.borrower_name || "(Không rõ)")}</div>
              ${subBorrower ? `<div class="text-xs text-gray-500 mt-0.5 line-clamp-2">${esc(subBorrower)}</div>` : ""}
            </td>

            <td class="px-4 py-2 text-center font-semibold text-gray-900">${fmt(r.quantity)}</td>
            <td class="px-4 py-2 text-center text-gray-700 text-sm">${esc(r.borrow_date || "-")}</td>
            <td class="px-4 py-2 text-center text-gray-700 text-sm">${esc(r.return_deadline || "-")}${lateLine}</td>

            <td class="px-4 py-2 text-center">
              ${pill(sm.label, sm.tone)}
              ${r.return_date ? `<div class="text-xs text-gray-500 mt-1">Trả: ${esc(r.return_date)}</div>` : ""}
            </td>

            <td class="px-4 py-2 text-gray-800">
              <div class="font-medium">${esc(r.created_by_name || "(Không rõ)")}</div>
              <div class="text-xs text-gray-500">ID: ${esc(r.created_by || 0)}</div>
            </td>
          </tr>
        `;
      })
      .join("");

    pag.innerHTML = totalPages > 1 ? renderPagination(totalPages) : "";
    bindSort(panelEl);
  }

  window.exportInventoryReport = function exportInventoryReport() {
    const panel = document.querySelector('[data-tab-panel="inventory"]') || document;
    const f = getFilters(panel);
    const url = `${BASE_API}?${qs({ action: "export_inventory_report", ...f })}`;
    window.location.href = url;
  };

  async function run(panelEl) {
    renderError(panelEl, "");
    const loading = panelEl.querySelector("#inv-loading");
    if (loading) loading.classList.remove("hidden");

    const f = getFilters(panelEl);
    state.q = f.q || "";
    state.status = f.status || "all";
    state.type = f.type || "all";
    state.categoryId = f.category_id || "";
    state.departmentId = f.department_id || "";
    state.dateFrom = f.date_from || "";
    state.dateTo = f.date_to || "";
    state.onlyOverdue = !!f.only_overdue;

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
            <h2 class="text-xl font-bold text-gray-900">Thiết bị / Đồ dùng</h2>
            <p class="mt-1 text-sm text-gray-500">
              Thống kê tồn kho và mượn/trả: danh mục, đơn vị quản lý, trạng thái và quá hạn.
            </p>
          </div>
          <div class="hidden md:flex items-center gap-2">
            <button id="invExport"
              class="px-4 py-2 rounded-xl border border-indigo-600 text-indigo-700 bg-white hover:bg-indigo-50 text-sm font-semibold">
              <span class="inline-flex items-center gap-2">
                <i data-lucide="download" class="w-4 h-4"></i>
                Export
              </span>
            </button>
          </div>
        </div>
      </div>

      <div id="inv-error" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-7 gap-3">
          <div class="xl:col-span-2">
            <label class="block text-sm font-medium text-gray-600 mb-1">Tìm kiếm</label>
            <input id="invQ" type="text" placeholder="Mã / tên thiết bị, người mượn, đơn vị mượn, mục đích..."
              class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Trạng thái mượn</label>
            <select id="invStatus" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="borrowing">Đang mượn</option>
              <option value="overdue">Quá hạn</option>
              <option value="returned">Đã trả</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Loại</label>
            <select id="invType" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="all">Tất cả</option>
              <option value="equipment">Thiết bị</option>
              <option value="item">Đồ dùng</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Danh mục</label>
            <select id="invCategory" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả danh mục --</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đơn vị quản lý</label>
            <select id="invDept" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="">-- Tất cả đơn vị quản lý --</option>
            </select>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-7 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Từ ngày</label>
            <input id="invFrom" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Đến ngày</label>
            <input id="invTo" type="date" class="w-full border rounded-lg px-3 py-2 text-sm"/>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Hiển thị</label>
            <select id="invPageSize" class="w-full border rounded-lg px-3 py-2 text-sm">
              <option value="10">10 dòng / trang</option>
              <option value="15">15 dòng / trang</option>
              <option value="20">20 dòng / trang</option>
              <option value="30">30 dòng / trang</option>
              <option value="50">50 dòng / trang</option>
            </select>
          </div>

          <div class="xl:col-span-2 flex items-end gap-2">
            <button id="invRun"
              class="w-full px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
              Tải dữ liệu
            </button>
            <button id="invReset"
              class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold">
              Reset
            </button>
          </div>

          <div class="xl:col-span-2 flex items-end">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
              <input id="invOnlyOverdue" type="checkbox" class="w-4 h-4"/>
              Chỉ hiển thị quá hạn
            </label>
          </div>
        </div>
      </div>

      <div id="inv-loading" class="hidden mb-4 text-sm text-gray-500">Đang tải thống kê...</div>

      <div id="inv-kpi" class="mb-4"></div>
      <div id="inv-insights" class="mb-4"></div>

      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
        <div class="overflow-x-auto mb-4">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr id="inv-head"></tr>
            </thead>
            <tbody id="inv-body"></tbody>
          </table>
        </div>
        <div id="inv-pagination"></div>
      </div>
    `;

    // events
    const $ = (id) => panelEl.querySelector(`#${id}`);

    const q = $("invQ");
    const st = $("invStatus");
    const type = $("invType");
    const cat = $("invCategory");
    const dept = $("invDept");
    const from = $("invFrom");
    const to = $("invTo");
    const onlyOverdue = $("invOnlyOverdue");
    const pageSize = $("invPageSize");
    const runBtn = $("invRun");
    const resetBtn = $("invReset");
    const exportBtn = $("invExport");

    pageSize.value = String(state.pageSize);

    window.invGotoPage = function invGotoPage(p) {
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
      st.value = "all";
      type.value = "all";
      cat.value = "";
      dept.value = "";
      from.value = "";
      to.value = "";
      onlyOverdue.checked = false;
      pageSize.value = "10";

      state.pageSize = 10;
      state.currentPage = 1;
      state.sortKey = "borrow_date";
      state.sortDir = "desc";

      state.q = "";
      state.status = "all";
      state.type = "all";
      state.categoryId = "";
      state.departmentId = "";
      state.dateFrom = "";
      state.dateTo = "";
      state.onlyOverdue = false;

      renderError(panelEl, "");
      renderKPI(panelEl);
      renderInsights(panelEl);
      renderTable(panelEl);
      createIcons();
    });

    exportBtn?.addEventListener("click", () => window.exportInventoryReport?.());

    // reactive nhẹ
    q.addEventListener("input", () => {
      state.q = (q.value || "").trim();
      state.currentPage = 1;
      renderTable(panelEl);
    });

    [st, type, cat, dept, from, to, onlyOverdue].forEach((el) => {
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

    await loadOptions(panelEl);
    await run(panelEl);
    createIcons();
  }

  window.StatsModules.inventory = (panelEl) => render(panelEl);
})();
