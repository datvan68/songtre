/* global api, toast, notify */

const INVENTORY_API = "controllers/inventory.php";
const INVENTORY_TYPE_LABEL = {
  equipment: "Thiết bị",
  item: "Đồ dùng"
};

const VALID_TABS = ["inventory", "history", "category"];
const TAB_ACTIVE =
  "text-blue-600 border-b-2 border-blue-600";
const TAB_INACTIVE =
  "text-gray-500 border-b-2 border-transparent hover:text-blue-500";

let INVENTORY_CACHE = [];
let CATEGORY_CACHE = [];
let HISTORY_FILTER = {
  q: "",
  status: ""
};

let currentPage = 1;
let historyPage = 1;
let PER_PAGE = 10;
let ACTIVE_TAB = "inventory";

function isOverdue(deadline) {
  if (!deadline) return false;
  const today = new Date().toISOString().slice(0, 10);
  return today > deadline;
}
function getTabFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const tab = params.get("tab");
  return VALID_TABS.includes(tab) ? tab : "inventory";
}


/* =========================
   INIT
========================= */
document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("history-search")?.addEventListener(
    "input",
    debounce(() => {
      HISTORY_FILTER.q =
        document.getElementById("history-search").value.trim();
      historyPage = 1;
      loadHistory();
    }, 400)
  );

  document.getElementById("history-status")?.addEventListener("change", () => {
    HISTORY_FILTER.status =
      document.getElementById("history-status").value;
    historyPage = 1;
    loadHistory();
  });

  bindTabs();
  loadStats();
  loadFilters();
  loadCategories(); // ✅ thêm
  loadInventory();

  const initTab = getTabFromUrl();
  switchTab(initTab, false); // ❗ false = không pushState lần đầu

  document.getElementById("search-input")?.addEventListener("input", debounce(() => {
    currentPage = 1;
    loadInventory();
  }, 400));

  document.getElementById("filter-type")?.addEventListener("change", () => {
    currentPage = 1;
    loadInventory();
  });

  document.getElementById("filter-category")?.addEventListener("change", () => {
    currentPage = 1;
    loadInventory();
  });

  // EXPORT EXCEL BUTTONS
  document.getElementById("btn-export-inventory")?.addEventListener("click", () => {
    const q = document.getElementById("search-input").value.trim();
    const type = document.getElementById("filter-type").value;
    const category = document.getElementById("filter-category").value;
    const params = new URLSearchParams({
      action: "export_inventory",
      q, type, category
    });
    window.open(`${INVENTORY_API}?${params}`, "_blank");
  });

  document.getElementById("btn-export-history")?.addEventListener("click", () => {
    const params = new URLSearchParams({
      action: "export_history",
      q: HISTORY_FILTER.q || "",
      status: HISTORY_FILTER.status || ""
    });
    window.open(`${INVENTORY_API}?${params}`, "_blank");
  });

  document.getElementById("btn-export-category")?.addEventListener("click", () => {
    window.open(`${INVENTORY_API}?action=export_category`, "_blank");
  });
});

/* =========================
   TABS
========================= */
function bindTabs() {
  document.getElementById("tab-inventory").onclick = () => switchTab("inventory");
  document.getElementById("tab-history").onclick = () => switchTab("history");
  document.getElementById("tab-category").onclick = () => switchTab("category");
}


function switchTab(tab, push = true) {
  ACTIVE_TAB = tab;

  // section toggle
  document.getElementById("inventory-section")
    .classList.toggle("hidden", tab !== "inventory");
  document.getElementById("history-section")
    .classList.toggle("hidden", tab !== "history");
  document.getElementById("category-section")
    .classList.toggle("hidden", tab !== "category");

  // tab style reset
  ["inventory", "history", "category"].forEach(t => {
    const el = document.getElementById(`tab-${t}`);
    el.classList.remove(...TAB_ACTIVE.split(" "));
    el.classList.add(...TAB_INACTIVE.split(" "));
  });

  // tab active
  const activeTab = document.getElementById(`tab-${tab}`);
  activeTab.classList.remove(...TAB_INACTIVE.split(" "));
  activeTab.classList.add(...TAB_ACTIVE.split(" "));

  // load data
  if (tab === "history") loadHistory();
  if (tab === "category") loadCategories();

  // update URL
  if (push) {
    const params = new URLSearchParams(window.location.search);
    params.set("tab", tab);
    history.pushState(null, "", "?" + params.toString());
  }
}




/* =========================
   STATS
========================= */
async function loadStats() {
  const res = await api(`${INVENTORY_API}?action=stats`);
  const data = await res.json();

  if (!data.ok) return;

  setText("stat-total", data.total_quantity);
  setText("stat-using", data.total_quantity - data.available_quantity);
  setText("stat-borrowing", data.total_borrowed);
  setText("stat-stock", data.available_quantity);
  setText("stat-broken", data.broken_quantity);
  setText("stat-month", data.borrow_month);
}

function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val ?? 0;
}

/* =========================
   FILTERS
========================= */
async function loadFilters() {
  const res = await api(`${INVENTORY_API}?action=filters`);
  const data = await res.json();
  if (!data.ok) return;

  // CATEGORY
  const catSel = document.getElementById("filter-category");
  catSel.innerHTML = `<option value="">Danh mục...</option>`;
  data.categories.forEach(c => {
    catSel.innerHTML += `<option value="${c.id}">${c.name}</option>`;
  });

  // TYPE ✅ (BẠN THIẾU CHỖ NÀY)
  const typeSel = document.getElementById("filter-type");
  typeSel.innerHTML = `<option value="">Loại...</option>`;
  data.types.forEach(t => {
    typeSel.innerHTML += `<option value="${t.id}">${t.name}</option>`;
  });
}


/* =========================
   INVENTORY LIST
========================= */
async function loadInventory(page = currentPage) {
  currentPage = page;

  const q = document.getElementById("search-input").value.trim();
  const type = document.getElementById("filter-type").value;
  const category = document.getElementById("filter-category").value;

  const params = new URLSearchParams({
    action: "list",
    q, type, category,
    page: currentPage,
    per_page: PER_PAGE
  });

  const res = await api(`${INVENTORY_API}?${params}`);
  const data = await res.json();
  INVENTORY_CACHE = data.rows;

  if (!data.ok) return;

  renderInventory(data.rows);
  renderPagination(data.total, currentPage, loadInventory);
}

function renderInventory(rows) {
  const tbody = document.getElementById("inventory-tbody");
  tbody.innerHTML = "";

  rows.forEach((r, i) => {
    const available = r.total_quantity - r.borrowed_quantity;
    tbody.innerHTML += `
      <tr>
        <td class="px-4 py-2">${i + 1}</td>
        <td class="px-4 py-2 font-mono">${r.code}</td>
        <td class="px-4 py-2">${r.name}</td>
        <td class="px-4 py-2">${INVENTORY_TYPE_LABEL[r.type] ?? r.type ?? "-"}</td>
        <td class="px-4 py-2">${r.category}</td>
        <td class="px-4 py-2 text-sm text-gray-600 whitespace-normal break-words">${r.note ? r.note : "-"}</td>
        <td class="px-4 py-2 text-center">${r.total_quantity}</td>
        <td class="px-4 py-2 text-center">${r.borrowed_quantity}</td>
        <td class="px-4 py-2 text-center">
        ${r.status === "broken"
        ? `<span class="text-red-600 font-semibold">Hỏng / Bảo trì</span>`
        : available > 0
          ? `<span class="text-green-600">Còn</span>`
          : `<span class="text-gray-500">Hết</span>`
      }
        </td>

            <td class="px-4 py-2 text-center
           sticky right-0 z-30
           bg-white border-l">
  <div class="flex justify-center items-center gap-3">

    ${r.status !== "broken"
        ? `
          <button
            title="Mượn"
            class="text-orange-500 hover:text-orange-600"
            onclick="openBorrowModal(${r.id})">
            <i data-lucide="arrow-left-right"></i>
          </button>
        `
        : ""
      }

    <button
      title="Lịch sử mượn / trả"
      class="text-purple-500 hover:text-purple-600"
      onclick="openItemHistory(${r.id})">
      <i data-lucide="clock"></i>
    </button>

    <button
      title="Sửa"
      class="text-blue-600 hover:text-blue-700"
      onclick="openEditInventory(${r.id})">
      <i data-lucide="edit"></i>
    </button>

    <button
      title="Xóa"
      class="text-red-600 hover:text-red-700"
      onclick="openDeleteInventory(${r.id})">
      <i data-lucide="trash-2"></i>
    </button>

  </div>
</td>


      </tr>
    `;
  });
  if (window.lucide) {
    lucide.createIcons();
  }

}

async function openItemHistory(inventoryId) {
  const it = INVENTORY_CACHE.find(x => String(x.id) === String(inventoryId));
  if (!it) {
    toast("Không tìm thấy thiết bị", "error");
    return;
  }

  const res = await api(`${INVENTORY_API}?action=history&inventory_id=${inventoryId}`);
  const j = await res.json();
  if (!j.ok) {
    toast(j.error || "Không tải được lịch sử", "error");
    return;
  }

  const rows = j.rows || [];

  const wrap = document.createElement("div");
  wrap.className = "space-y-4";

  wrap.innerHTML = `
      <!-- HEADER -->
      <div class="text-sm text-gray-600">
        <div><strong>Mã:</strong> ${it.code}</div>
        <div><strong>Tên:</strong> ${it.name}</div>
      </div>

      <!-- TABLE -->
      <div class="overflow-x-auto border rounded">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-100 text-gray-700">
            <tr>
              <th class="px-4 py-2 text-left">STT</th>
              <th class="px-4 py-2 text-left">Người mượn</th>
              <th class="px-4 py-2 text-left">Lớp</th>
              <th class="px-4 py-2 text-center">SL</th>
              <th class="px-4 py-2 text-center">Ngày mượn</th>
              <th class="px-4 py-2 text-center">Hạn trả</th>
              <th class="px-4 py-2 text-center">Ngày trả</th>
              <th class="px-4 py-2 text-center">Trạng thái</th>
            </tr>
          </thead>
          <tbody>
            ${rows.length === 0
      ? `<tr>
                         <td colspan="8" class="py-6 text-center text-gray-500">
                           Chưa có lịch sử mượn – trả
                         </td>
                       </tr>`
      : rows.map((r, i) => {
        const overdue = r.status === "borrowing" && isOverdue(r.return_deadline);
        return `
                          <tr class="border-t ${overdue ? "bg-red-50" : ""}">
                            <td class="px-4 py-2">${i + 1}</td>
<td class="px-4 py-2">
  ${r.borrower_name.includes("–")
            ? r.borrower_name.split("–").slice(1).join("–").trim()
            : r.borrower_name
          }
</td>                            <td class="px-4 py-2">
                              ${r.class_name || r.borrower_unit || "-"}
                            </td>                            
                            <td class="px-4 py-2 text-center">${r.quantity}</td>
                            <td class="px-4 py-2 text-center">${r.borrow_date}</td>
                            <td class="px-4 py-2 text-center">${r.return_deadline ?? "-"}</td>
                            <td class="px-4 py-2 text-center">${r.return_date ?? "-"}</td>
                            <td class="px-4 py-2 text-center">
                              ${renderBorrowStatusBadge(r)}
                            </td>
                          </tr>
                        `;
      }).join("")
    }
          </tbody>
        </table>
      </div>

      <!-- FOOTER -->
      <div class="flex justify-end">
        <button class="px-4 py-1 border rounded" onclick="closeModal()">Đóng</button>
      </div>
    `;

  modal(wrap, "Lịch sử mượn – trả", "large");
}


/* =========================
   HISTORY
========================= */
async function loadHistory(page = historyPage) {
  historyPage = page;

  const params = new URLSearchParams({
    action: "history",
    page,
    per_page: PER_PAGE,
    q: HISTORY_FILTER.q || "",
    status: HISTORY_FILTER.status || ""
  });

  const res = await api(`${INVENTORY_API}?${params}`);
  const data = await res.json();
  if (!data.ok) return;

  const tbody = document.getElementById("history-tbody");
  tbody.innerHTML = "";

  data.rows.forEach((r, i) => {
    const overdue = r.status === "borrowing" && isOverdue(r.return_deadline);

    const statusHtml = renderBorrowStatusBadge(r);


    tbody.innerHTML += `
        <tr class="${overdue ? "bg-red-50" : ""}">
            <td class="px-4 py-2">${i + 1}</td>
            <td class="px-4 py-2 font-mono">${r.code}</td>
            <td class="px-4 py-2">${r.name}</td>
<td class="px-4 py-2">
  ${r.borrower_name.includes("–")
        ? r.borrower_name.split("–").slice(1).join("–").trim()
        : r.borrower_name
      }
</td>            <td class="px-4 py-2">
              ${r.class_name || r.borrower_unit || "-"}
            </td>
            <td class="px-4 py-2 text-center">${r.quantity}</td>
            <td class="px-4 py-2 text-center">${r.borrow_date}</td>
            <td class="px-4 py-2 text-center">${r.return_deadline ?? "-"}</td>
            <td class="px-4 py-2 text-center">${r.return_date ?? "-"}</td>

            <!-- TRẠNG THÁI -->
            <td class="px-4 py-2 text-center">
                ${statusHtml}
            </td>

            <!-- THAO TÁC -->
<td class="px-4 py-2 text-center
           sticky right-0 z-30
           bg-white border-l">
  ${r.status === "borrowing"
        ? `
        <button
          class="px-2 py-1 text-sm
                 text-blue-600 hover:underline
                 font-medium"
          onclick="returnItem(${r.id})">
          Trả
        </button>
      `
        : `<span class="text-gray-400">—</span>`
      }
</td>

        </tr>
        `;
  });
  renderPagination(data.total, historyPage, loadHistory, "pagination-history", "history-page");
}

function openDeleteInventory(id) {
  const it = INVENTORY_CACHE.find(x => String(x.id) === String(id));
  if (!it) {
    toast("Không tìm thấy thiết bị", "error");
    return;
  }

  const box = document.createElement("div");
  box.className = "space-y-4";

  box.innerHTML = `
    <p class="text-gray-700">
      Bạn có chắc chắn muốn xóa thiết bị
      <strong class="text-red-600">${it.name}</strong>?
    </p>

    <p class="text-sm text-gray-500">
      Hành động này <strong>không thể hoàn tác</strong>.
    </p>

    <div class="flex justify-end gap-2 pt-3">
      <button type="button"
        class="px-3 py-1 border rounded"
        onclick="closeModal()">Hủy</button>

      <button type="button"
        data-primary
        class="px-4 py-1 bg-red-600 text-white rounded"
        id="btnConfirmDelete">
        Xóa
      </button>
    </div>
  `;

  modal(box, "Xác nhận xóa thiết bị", "small");

  box.querySelector("#btnConfirmDelete").onclick = async () => {
    const fd = new FormData();
    fd.append("action", "delete");
    fd.append("id", id);

    const res = await api(INVENTORY_API, {
      method: "POST",
      body: fd
    });
    const data = await res.json();

    if (data.ok) {
      toast("Đã xóa thiết bị", "success");
      closeModal();

      // cập nhật UI
      INVENTORY_CACHE = INVENTORY_CACHE.filter(x => String(x.id) !== String(id));
      loadInventory();
      loadStats();
    } else {
      toast(data.error || "Không thể xóa", "error");
    }
  };
}

/* =========================
   BORROW / RETURN
========================= */
function openBorrowModal(id) {
  const it = INVENTORY_CACHE.find(x => String(x.id) === String(id));
  if (!it) {
    toast("Không tìm thấy thiết bị", "error");
    return;
  }

  const available = it.total_quantity - it.borrowed_quantity;

  const form = document.createElement("form");
  form.className = "space-y-4";

  form.innerHTML = `
<div class="space-y-3">

  <!-- HEADER INFO -->
  <div class="text-sm text-gray-600">
    <div><strong>Mã:</strong> ${it.code}</div>
    <div><strong>Tên:</strong> ${it.name}</div>
  </div>

  <input type="hidden" name="inventory_id" value="${it.id}">

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    <!-- NGƯỜI MƯỢN -->
<!-- NGƯỜI MƯỢN -->
<div class="relative">
  <label class="block text-sm mb-1">
    Người mượn (MSSV) <span class="text-red-500">*</span>
  </label>

  <input
    name="borrower_name"
    id="borrower-input"
    placeholder="Nhập MSSV hoặc tên"
    class="w-full border px-3 py-2 rounded"
    autocomplete="off"
    required
  >

  <!-- SUGGEST BOX -->
  <div
    id="borrower-suggest"
    class="absolute z-50 mt-1 w-full bg-white border rounded shadow hidden max-h-60 overflow-y-auto">
  </div>
</div>

<!-- LỚP -->
<div>
  <label class="block text-sm mb-1">Lớp</label>
  <input
    name="borrower_unit"
    id="borrower-class"
    class="w-full border px-3 py-2 rounded bg-gray-100"
    readonly
  >
</div>


    <!-- SỐ LƯỢNG MƯỢN -->
    <div>
      <label class="block text-sm mb-1">Số lượng mượn <span class="text-red-500">*</span></label>
      <input name="quantity"
        type="number"
        min="1"
        max="${available}"
        value="1"
        class="w-full border px-3 py-2 rounded"
        required>
    </div>

    <!-- SỐ LƯỢNG CÒN -->
    <div>
      <label class="block text-sm mb-1">Số lượng có sẵn</label>
      <input type="number"
        value="${available}"
        class="w-full border px-3 py-2 rounded bg-gray-100"
        readonly>
    </div>

    <!-- NGÀY MƯỢN -->
    <div>
      <label class="block text-sm mb-1">Ngày mượn <span class="text-red-500">*</span></label>
      <input name="borrow_date"
        type="date"
        value="${new Date().toISOString().slice(0, 10)}"
        class="w-full border px-3 py-2 rounded"
        required>
    </div>

    <!-- HẠN TRẢ -->
    <div>
      <label class="block text-sm mb-1">Hạn trả <span class="text-red-500">*</span></label>
      <input name="return_deadline"
        type="date"
        class="w-full border px-3 py-2 rounded"
        required>
    </div>

  </div>

  <!-- MỤC ĐÍCH -->
  <div>
    <label class="block text-sm mb-1">Mục đích sử dụng</label>
    <input name="purpose"
      placeholder="VD: Tổ chức hoạt động văn nghệ"
      class="w-full border px-3 py-2 rounded">
  </div>

  <!-- GHI CHÚ -->
  <div>
    <label class="block text-sm mb-1">Ghi chú</label>
    <textarea name="note"
      rows="2"
      placeholder="Ghi chú thêm (nếu có)"
      class="w-full border px-3 py-2 rounded"></textarea>
  </div>

  <!-- ACTION -->
  <div class="flex justify-end gap-2 pt-3">
    <button type="button"
      class="px-4 py-1 border rounded"
      onclick="closeModal()">Hủy</button>

    <button type="submit"
      data-primary
      class="px-5 py-1 bg-blue-600 text-white rounded">
      Xác nhận mượn
    </button>
  </div>

</div>
`;

  form.onsubmit = async (e) => {
    e.preventDefault();
    if (!form.reportValidity()) return;

    const fd = new FormData(form);
    fd.append("action", "borrow");

    const res = await api(INVENTORY_API, {
      method: "POST",
      body: fd
    });

    const data = await res.json();

    if (data.ok) {
      toast("Mượn thiết bị thành công", "success");
      closeModal();
      loadInventory();
      loadStats();
    } else {
      toast(data.error || "Không thể mượn", "error");
    }
  };

  modal(form, "Mượn thiết bị / đồ dùng", "large");
  setTimeout(bindBorrowerAutocomplete, 0);

}
function bindBorrowerAutocomplete() {
  const input = document.getElementById("borrower-input");
  const suggest = document.getElementById("borrower-suggest");
  const classInput = document.getElementById("borrower-class");

  if (!input || !suggest) return;

  let timer = null;

  input.addEventListener("input", () => {
    const q = input.value.trim();
    classInput.value = "";
    suggest.innerHTML = "";
    suggest.classList.add("hidden");

    if (q.length < 2) return;

    clearTimeout(timer);
    timer = setTimeout(async () => {
      const res = await api(
        `${INVENTORY_API}?action=member_search&q=${encodeURIComponent(q)}`
      );
      const j = await res.json();
      if (!j.ok || j.data.length === 0) return;

      suggest.innerHTML = j.data.map(m => `
        <div
          class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm"
          data-mssv="${m.mssv}"
          data-name="${m.fullname}"
          data-class="${m.class_name ?? ''}">
          <strong>${m.mssv}</strong> – ${m.fullname}
          <div class="text-xs text-gray-500">${m.class_name ?? ''}</div>
        </div>
      `).join("");

      suggest.classList.remove("hidden");
    }, 300);
  });

  suggest.addEventListener("click", e => {
    const item = e.target.closest("[data-mssv]");
    if (!item) return;

    input.value = item.dataset.mssv + " – " + item.dataset.name;
    classInput.value = item.dataset.class;
    suggest.classList.add("hidden");
  });

  document.addEventListener("click", e => {
    if (!suggest.contains(e.target) && e.target !== input) {
      suggest.classList.add("hidden");
    }
  });
}


async function loadCategories() {
  const res = await api(`${INVENTORY_API}?action=categories`);
  const j = await res.json();
  if (!j.ok) return;

  CATEGORY_CACHE = j.rows; // ✅ cache lại

  const tbody = document.getElementById("category-tbody");
  tbody.innerHTML = "";

  j.rows.forEach(c => {
    tbody.innerHTML += `
        <tr class="border-t">
            <td class="py-2">${c.name}</td>
            <td class="text-right">
                <button class="text-gray-600 text-sm"
                    onclick="openEditCategory(${c.id}, '${c.name}')">
                    Sửa
                </button>
                <button class="text-red-600 text-sm ml-2"
                    onclick="deleteCategory(${c.id})">
                    Xóa
                </button>
            </td>
        </tr>`;
  });
}

function openAddCategory() {
  const form = document.createElement("form");
  form.innerHTML = `
    <label class="block text-sm mb-1">Tên danh mục</label>
    <input name="name" class="w-full border px-3 py-2 rounded" required>

    <div class="flex justify-end gap-2 mt-4">
      <button type="button" onclick="closeModal()" class="border px-3 py-1 rounded">Hủy</button>
      <button class="bg-blue-600 text-white px-4 py-1 rounded">Lưu</button>
    </div>
  `;

  form.onsubmit = async e => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append("action", "category_create");

    const res = await api(INVENTORY_API, { method: "POST", body: fd });
    const j = await res.json();

    if (j.ok) {
      toast("Đã thêm danh mục");
      closeModal();
      loadCategories();
      loadFilters(); // refresh select
    } else toast(j.error);
  };

  modal(form, "Thêm danh mục", "small");
}


async function returnItem(id) {
  if (!confirm("Xác nhận trả thiết bị?")) return;

  const fd = new FormData();
  fd.append("action", "return");
  fd.append("borrow_id", id);

  const res = await api(INVENTORY_API, { method: "POST", body: fd });
  const data = await res.json();
  if (data.ok) {
    toast("Đã trả");
    loadHistory();
    loadInventory();
    loadStats();
  }
}

/* =========================
   UTILS
========================= */
function renderPagination(total, page, cb, elementId = "pagination", prefix = "page") {
  const wrap = document.getElementById(elementId);
  if (!wrap) return;
  wrap.innerHTML = "";

  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
  page = Math.min(Math.max(1, page), totalPages);

  // === INFO TEXT ===
  const from = total === 0 ? 0 : (page - 1) * PER_PAGE + 1;
  const to = Math.min(page * PER_PAGE, total);

  setText(`${prefix}-from`, from);
  setText(`${prefix}-to`, to);
  setText(`${prefix}-total`, total);

  if (totalPages <= 1) return;

  // === BUTTON HELPER ===
  const btn = (label, disabled, targetPage, active = false) => {
    if (label === "...") {
      return `<span class="px-2 py-1 text-sm text-gray-400 cursor-default select-none">...</span>`;
    }
    const activeClass = active
      ? "bg-blue-600 text-white border-blue-600 font-medium"
      : "text-gray-700 hover:bg-gray-100 border-gray-300";
    const disabledClass = disabled
      ? "text-gray-300 cursor-not-allowed border-gray-200"
      : "";

    return `
      <button
        ${disabled ? "disabled" : ""}
        class="px-3 py-1 border rounded text-sm transition-colors ${activeClass} ${disabledClass}"
        data-page="${targetPage}">
        ${label}
      </button>
    `;
  };

  const pages = [];
  const range = 2; // Số trang xung quanh trang hiện tại

  // Luôn hiển thị trang đầu
  pages.push(1);

  if (page - range > 2) {
    pages.push("...");
  }

  // Các trang ở giữa
  const start = Math.max(2, page - range);
  const end = Math.min(totalPages - 1, page + range);
  for (let i = start; i <= end; i++) {
    pages.push(i);
  }

  if (page + range < totalPages - 1) {
    pages.push("...");
  }

  // Luôn hiển thị trang cuối
  if (totalPages > 1) {
    pages.push(totalPages);
  }

  let html = "";
  // Nút về đầu «
  html += btn("«", page === 1, 1);
  // Nút lùi ‹
  html += btn("‹", page === 1, page - 1);

  // Nút số trang
  pages.forEach(p => {
    if (p === "...") {
      html += btn("...", false, 0);
    } else {
      html += btn(p, false, p, p === page);
    }
  });

  // Nút tiến ›
  html += btn("›", page === totalPages, page + 1);
  // Nút về cuối »
  html += btn("»", page === totalPages, totalPages);

  wrap.innerHTML = html;

  // Đăng ký sự kiện click cho các nút
  wrap.querySelectorAll("button[data-page]").forEach(b => {
    b.addEventListener("click", () => {
      const targetPage = parseInt(b.getAttribute("data-page"), 10);
      if (targetPage && targetPage !== page) {
        cb(targetPage);
      }
    });
  });
}


function debounce(fn, t) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), t);
  };
}
function openAddInventory() {
  const wrap = document.createElement("form");
  wrap.className = "space-y-4";

  wrap.innerHTML = `
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

  <!-- MÃ -->
  <div class="md:col-span-2">
    <label class="block text-sm mb-1">Mã thiết bị</label>
    <div class="px-3 py-2 rounded bg-gray-100 text-gray-500 text-sm">
      Hệ thống sẽ tự động tạo mã
    </div>
  </div>

  <!-- TÊN -->
  <div class="md:col-span-2">
    <label class="block text-sm mb-1">Tên thiết bị / Đồ dùng *</label>
    <input name="name"
      class="w-full border px-3 py-2 rounded"
      required>
  </div>

  <!-- LOẠI -->
  <div>
    <label class="block text-sm mb-1">Loại *</label>
    <select name="type"
      class="w-full border px-3 py-2 rounded"
      required>
      <option value="">-- Chọn --</option>
      <option value="equipment">Thiết bị</option>
      <option value="item">Đồ dùng</option>
    </select>
  </div>

  <!-- DANH MỤC -->
  <div>
    <label class="block text-sm mb-1">Danh mục *</label>
    <select name="category_id"
      class="w-full border px-3 py-2 rounded"
      required>
      <option value="">-- Chọn danh mục --</option>
      ${CATEGORY_CACHE.map(c =>
    `<option value="${c.id}">${c.name}</option>`
  ).join("")}
    </select>
  </div>


  <!-- TỔNG SỐ LƯỢNG -->
  <div>
    <label class="block text-sm mb-1">Tổng số lượng *</label>
    <input name="total_quantity"
      type="number"
      min="1"
      class="w-full border px-3 py-2 rounded"
      required>
  </div>

  <!-- TRẠNG THÁI -->
  <div>
    <label class="block text-sm mb-1">Trạng thái</label>
    <select name="status"
      class="w-full border px-3 py-2 rounded">
      <option value="available">Sẵn sàng</option>
      <option value="broken">Hỏng / Bảo trì</option>
    </select>
  </div>

  <!-- GHI CHÚ -->
  <div class="md:col-span-2">
    <label class="block text-sm mb-1">Ghi chú</label>
    <textarea name="note"
      rows="3"
      class="w-full border px-3 py-2 rounded"></textarea>
  </div>

  <!-- ACTION -->
  <div class="md:col-span-2 flex justify-end gap-2 pt-2">
    <button type="button"
      class="px-3 py-1 border rounded"
      onclick="closeModal()">Hủy</button>

    <button type="submit"
      data-primary
      class="px-4 py-1 bg-blue-600 text-white rounded">
      Lưu
    </button>
  </div>

</div>
`;


  wrap.onsubmit = async (e) => {
    e.preventDefault();
    if (!wrap.reportValidity()) return;

    const fd = new FormData(wrap);
    fd.append("action", "create");

    // ❗ KHÔNG GỬI code
    const res = await api(INVENTORY_API, {
      method: "POST",
      body: fd
    });
    const data = await res.json();

    if (data.ok) {
      toast("Đã thêm thiết bị", "success");
      closeModal();
      loadInventory();
      loadStats();
    } else {
      toast(data.error || "Lỗi tạo thiết bị", "error");
    }
  };

  modal(wrap, "Thêm thiết bị / đồ dùng", "large");
}




function openEditInventory(id) {
  const it = INVENTORY_CACHE.find(x => String(x.id) === String(id));
  if (!it) {
    toast("Không tìm thấy thiết bị", "error");
    return;
  }

  const wrap = document.createElement("form");
  wrap.className = "space-y-4";

  wrap.innerHTML = `
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

  <!-- MÃ -->
  <div class="md:col-span-2">
    <label class="block text-sm mb-1">Mã thiết bị</label>
    <div class="px-3 py-2 rounded bg-gray-100 text-gray-700 text-sm font-mono">
      ${it.code}
    </div>
  </div>

  <!-- TÊN -->
  <div class="md:col-span-2">
    <label class="block text-sm mb-1">Tên thiết bị / Đồ dùng *</label>
    <input name="name"
      class="w-full border px-3 py-2 rounded"
      value="${it.name}"
      required>
  </div>

  <!-- LOẠI -->
  <div>
    <label class="block text-sm mb-1">Loại *</label>
    <select name="type"
      class="w-full border px-3 py-2 rounded"
      required>
      <option value="">-- Chọn --</option>
      <option value="equipment" ${it.type === "equipment" ? "selected" : ""}>
        Thiết bị
      </option>
      <option value="item" ${it.type === "item" ? "selected" : ""}>
        Đồ dùng
      </option>
    </select>
  </div>

  <!-- DANH MỤC -->
  <div>
    <label class="block text-sm mb-1">Danh mục *</label>
    <select name="category_id"
      class="w-full border px-3 py-2 rounded"
      required>
      <option value="">-- Chọn danh mục --</option>
      ${CATEGORY_CACHE.map(c =>
    `<option value="${c.id}" ${String(c.id) === String(it.category_id) ? "selected" : ""}>
          ${c.name}
        </option>`
  ).join("")}
    </select>
  </div>

  <!-- TỔNG SỐ LƯỢNG -->
  <div>
    <label class="block text-sm mb-1">Tổng số lượng *</label>
    <input name="total_quantity"
      type="number"
      min="1"
      class="w-full border px-3 py-2 rounded"
      value="${it.total_quantity}"
      required>
  </div>

  <!-- TRẠNG THÁI -->
  <div>
    <label class="block text-sm mb-1">Trạng thái</label>
    <select name="status"
      class="w-full border px-3 py-2 rounded">
      <option value="available" ${it.status === "available" ? "selected" : ""}>
        Sẵn sàng
      </option>
      <option value="broken" ${it.status === "broken" ? "selected" : ""}>
        Hỏng / Bảo trì
      </option>
    </select>
  </div>

  <!-- GHI CHÚ -->
  <div class="md:col-span-2">
    <label class="block text-sm mb-1">Ghi chú</label>
    <textarea name="note"
      rows="3"
      class="w-full border px-3 py-2 rounded">${it.note ?? ""}</textarea>
  </div>

  <input type="hidden" name="id" value="${it.id}">

  <!-- ACTION -->
  <div class="md:col-span-2 flex justify-end gap-2 pt-2">
    <button type="button"
      class="px-3 py-1 border rounded"
      onclick="closeModal()">Hủy</button>

    <button type="submit"
      data-primary
      class="px-4 py-1 bg-blue-600 text-white rounded">
      Cập nhật
    </button>
  </div>

</div>
`;

  wrap.onsubmit = async (e) => {
    e.preventDefault();
    if (!wrap.reportValidity()) return;

    const fd = new FormData(wrap);
    fd.append("action", "update");

    const res = await api(INVENTORY_API, { method: "POST", body: fd });
    const data = await res.json();

    if (data.ok) {
      toast("Đã cập nhật thiết bị", "success");
      closeModal();
      loadInventory();
      loadStats();
    } else {
      toast(data.error || "Lỗi cập nhật", "error");
    }
  };

  modal(wrap, "Cập nhật thiết bị / đồ dùng", "large");
}

function renderBorrowStatusBadge(r) {
  const overdue = r.status === "borrowing" && isOverdue(r.return_deadline);

  if (r.status === "returned") {
    return `
          <span class="inline-flex items-center px-2 py-0.5
                       rounded-full text-xs font-semibold
                       bg-green-100 text-green-700">
            Đã trả
          </span>`;
  }

  if (overdue) {
    return `
          <span class="inline-flex items-center px-2 py-0.5
                       rounded-full text-xs font-semibold
                       bg-red-100 text-red-700">
            Quá hạn
          </span>`;
  }

  return `
      <span class="inline-flex items-center px-2 py-0.5
                   rounded-full text-xs font-semibold
                   bg-yellow-100 text-yellow-700">
        Chưa trả
      </span>`;
}
function deleteCategory(id) {
  const box = document.createElement("div");
  box.className = "space-y-4";

  box.innerHTML = `
        <p class="text-gray-700">
            Bạn có chắc chắn muốn <strong class="text-red-600">xóa danh mục</strong> này?
        </p>

        <p class="text-sm text-gray-500">
            Danh mục đang được sử dụng sẽ <strong>không thể xóa</strong>.
        </p>

        <div class="flex justify-end gap-2 pt-3">
            <button
                type="button"
                class="px-3 py-1 border rounded"
                onclick="closeModal()">
                Hủy
            </button>

            <button
                type="button"
                data-primary
                class="px-4 py-1 bg-red-600 text-white rounded"
                id="btnConfirmDeleteCategory">
                Xóa
            </button>
        </div>
    `;

  modal(box, "Xác nhận xóa danh mục", "small");

  box.querySelector("#btnConfirmDeleteCategory").onclick = async () => {
    const fd = new FormData();
    fd.append("action", "category_delete");
    fd.append("id", id);

    const res = await api(INVENTORY_API, {
      method: "POST",
      body: fd
    });
    const j = await res.json();

    if (j.ok) {
      toast("Đã xóa danh mục", "success");
      closeModal();
      loadCategories();
      loadFilters();
    } else {
      toast(j.error || "Không thể xóa", "error");
    }
  };
}

function openEditCategory(id, name) {
  const form = document.createElement("form");
  form.className = "space-y-3";

  form.innerHTML = `
    <label class="block text-sm mb-1">Tên danh mục</label>
    <input
      name="name"
      class="w-full border px-3 py-2 rounded"
      value="${name}"
      required
    >

    <div class="flex justify-end gap-2 pt-3">
      <button
        type="button"
        class="border px-3 py-1 rounded"
        onclick="closeModal()">
        Hủy
      </button>

      <button
        type="submit"
        data-primary
        class="bg-blue-600 text-white px-4 py-1 rounded">
        Lưu
      </button>
    </div>
  `;

  form.onsubmit = async (e) => {
    e.preventDefault();

    if (!form.reportValidity()) return;

    const fd = new FormData(form);
    fd.append("action", "category_update");
    fd.append("id", id);

    const res = await api(INVENTORY_API, {
      method: "POST",
      body: fd
    });

    const j = await res.json();

    if (j.ok) {
      toast("Đã cập nhật danh mục", "success");
      closeModal();
      loadCategories();
      loadFilters();
    } else {
      toast(j.error || "Lỗi cập nhật", "error");
    }
  };

  modal(form, "Sửa danh mục", "small");
}


