// assets/js/scoring.js
const BASE_API = "controllers/scoring.php";
const TOTAL_POINTS = 10.0;

let items = [];                 // Active config items { key, type, id, title, locked, point }
let allItems = [];              // All available items fetched from the config API
let selectedKeys = new Set();   // Set of keys active in current configuration
let currentViewMode = "summary";

let previewData = null;
let previewCurrentPage = 1;
const previewPageSize = 20;
let isDeptFilterInitialized = false;

let savedData = null;
let savedCurrentPage = 1;
const savedPageSize = 20;

function $(id) { return document.getElementById(id); }

function escapeHtml(str = "") {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function toNum(v) {
  const n = parseFloat(String(v).replace(",", "."));
  return Number.isFinite(n) ? n : 0;
}

function round2(n) {
  return Math.round(n * 100) / 100;
}

function setError(msg = "") {
  const el = $("scoringError");
  if (!el) return;
  if (!msg) {
    el.classList.add("hidden");
    el.textContent = "";
  } else {
    el.classList.remove("hidden");
    el.textContent = msg;
  }
}

// Lưu bản nháp cấu hình tính điểm vào localStorage
function saveDraftConfig() {
  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  if (!year || !sem || items.length === 0) return;
  
  const draft = items.map(it => ({
    key: it.key,
    point: it.point,
    locked: it.locked
  }));
  localStorage.setItem(`scoring_draft_${year}_${sem}`, JSON.stringify(draft));
}

// Tải bản nháp cấu hình từ localStorage
function loadDraftConfig() {
  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  if (!year || !sem) return null;
  
  try {
    const data = localStorage.getItem(`scoring_draft_${year}_${sem}`);
    return data ? JSON.parse(data) : null;
  } catch (e) {
    console.error("Failed to parse draft config", e);
    return null;
  }
}

// Tạo skeleton loader hàng loạt (animated rows) cho giao diện cao cấp
function renderSkeletonRows(colCount) {
  let rowHtml = "";
  for (let r = 0; r < 5; r++) {
    rowHtml += `<tr class="animate-pulse border-b bg-white">`;
    for (let c = 0; c < colCount; c++) {
      rowHtml += `
        <td class="px-4 py-4.5">
          <div class="h-4 bg-gray-200 rounded w-5/6 mx-auto ${c === 1 || c === 2 || c === 3 ? 'text-left w-3/4 mx-0' : ''}"></div>
        </td>
      `;
    }
    rowHtml += `</tr>`;
  }
  return rowHtml;
}

async function safeJson(res) {
  if (res.status === 403) {
    toast("Không có quyền thực hiện tác vụ này (403)", "error");
    throw new Error("Forbidden");
  }

  const text = await res.text();
  if (!text || !text.trim()) {
    toast("Lỗi hệ thống: Phản hồi rỗng", "error");
    throw new Error("Empty response");
  }

  try {
    return JSON.parse(text);
  } catch (e) {
    const preview = text.substring(0, 300);
    toast("Phản hồi hệ thống không hợp lệ", "error");
    console.error("Bad JSON preview:", preview);
    throw new Error("Bad JSON");
  }
}

async function request(url, opts) {
  if (typeof api === "function") return api(url, opts);
  return fetch(url, opts);
}

// ==========================================
// SECTION COLLAPSIBLE CARDS
// ==========================================
function toggleSection(sectionId, forceState = null) {
  const contentEl = $(sectionId);
  if (!contentEl) return;

  const headerBtn = document.querySelector(`[data-target="${sectionId}"]`);
  const indicator = headerBtn ? headerBtn.querySelector(".toggle-indicator") : null;

  let isHidden = contentEl.classList.contains("hidden");
  if (forceState !== null) {
    isHidden = !forceState;
  }

  if (isHidden) {
    contentEl.classList.remove("hidden");
    if (indicator) indicator.textContent = "▼ Thu gọn";
    localStorage.setItem(`section_collapse_${sectionId}`, "expanded");
  } else {
    contentEl.classList.add("hidden");
    if (indicator) indicator.textContent = "▲ Mở rộng";
    localStorage.setItem(`section_collapse_${sectionId}`, "collapsed");
  }
}

function initCollapsibleSections() {
  document.querySelectorAll(".section-toggle-btn").forEach(btn => {
    const target = btn.dataset.target;
    // Bind click event
    btn.onclick = (e) => {
      // Don't toggle if user clicked on button inside header
      if (e.target.closest("button")) return;
      toggleSection(target);
    };

    // Restore state from localStorage
    const savedState = localStorage.getItem(`section_collapse_${target}`);
    if (savedState === "collapsed") {
      toggleSection(target, false);
    } else {
      toggleSection(target, true);
    }
  });
}

// ==========================================
// STEP SIDEBAR NAVIGATION
// ==========================================
function initStepNavigation() {
  const links = document.querySelectorAll(".nav-step-link");
  links.forEach(link => {
    link.onclick = (e) => {
      e.preventDefault();
      const targetId = link.getAttribute("href").substring(1);
      const targetEl = $(targetId);
      if (targetEl) {
        targetEl.scrollIntoView({ behavior: "smooth", block: "start" });
        // Expand target section if collapsed
        const contentId = targetId.split("-")[1] + "-content";
        toggleSection(contentId, true);
        
        // Highlight active step
        links.forEach(l => {
          l.classList.remove("text-indigo-700", "bg-indigo-50", "border-indigo-600");
          l.classList.add("text-gray-600", "hover:text-gray-900", "hover:bg-gray-50", "border-transparent");
        });
        link.classList.remove("text-gray-600", "hover:text-gray-900", "hover:bg-gray-50", "border-transparent");
        link.classList.add("text-indigo-700", "bg-indigo-50", "border-indigo-600");
      }
    };
  });
}

// ==========================================
// OPTIONS LOADING
// ==========================================
async function loadSchoolYears() {
  try {
    const res = await request(`${BASE_API}?action=school_year_options`);
    const json = await safeJson(res);
    if (!json.ok) return;

    const select = $("filterYear");
    if (!select) return;

    select.innerHTML = `<option value="">-- Chọn năm học --</option>` +
      (json.data || []).map(y => `<option value="${y.id}">${escapeHtml(y.year_label)}</option>`).join("");
  } catch (e) {
    console.error(e);
  }
}

async function loadSemesters() {
  try {
    const res = await request(`${BASE_API}?action=semester_options`);
    const json = await safeJson(res);
    if (!json.ok) return;

    const select = $("filterSemester");
    if (!select) return;

    select.innerHTML = `<option value="">-- Chọn học kỳ --</option>` +
      (json.data || []).map(s => `<option value="${escapeHtml(s.code)}">${escapeHtml(s.label)}</option>`).join("");
  } catch (e) {
    console.error(e);
  }
}

// ==========================================
// CONFIG SECTION (STEP 2)
// ==========================================
async function loadScoringItems() {
  setError("");
  isDeptFilterInitialized = false;
  previewCurrentPage = 1;

  // Clear preview block
  const previewEmpty = $("previewEmptyMessage");
  if (previewEmpty) previewEmpty.classList.remove("hidden");
  $("previewTableWrap")?.classList.add("hidden");
  $("previewFiltersRow")?.classList.add("hidden");
  $("previewPagination")?.classList.add("hidden");

  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";

  const wrap = $("scoringTableWrap");
  const status = $("scoringStatus");

  if (!year || !sem) {
    items = [];
    allItems = [];
    selectedKeys.clear();
    wrap?.classList.add("hidden");
    if (status) {
      status.classList.remove("hidden");
      status.textContent = "Hãy chọn Năm học và Học kỳ.";
    }
    updateSummary();
    
    // Hide action buttons in Section 2
    $("configActionsRow")?.classList.add("hidden");
    $("configItemCountBadge")?.classList.add("hidden");
    return;
  }

  if (status) {
    status.classList.remove("hidden");
    status.textContent = "Đang tải danh sách phong trào & khoản thu...";
  }

  try {
    const res = await request(
      `${BASE_API}?action=scoring_items&school_year=${encodeURIComponent(year)}&semester=${encodeURIComponent(sem)}`
    );
    const json = await safeJson(res);
    if (!json.ok) return;

    const campaigns = json.data?.campaigns || [];
    const fees = json.data?.fees || [];

    allItems = [
      ...campaigns.map(c => ({
        key: `campaign:${c.id}`,
        type: "campaign",
        id: String(c.id),
        title: c.title,
        locked: false,
        point: 0
      })),
      ...fees.map(f => ({
        key: `fee:${f.id}`,
        type: "fee",
        id: String(f.id),
        title: f.title,
        locked: false,
        point: 0
      }))
    ];

    // Mặc định cấu hình bắt đầu trống, trừ khi khôi phục từ bản nháp
    const draft = loadDraftConfig();
    if (draft && Array.isArray(draft)) {
      items = allItems.filter(it => draft.some(x => x.key === it.key));
      items.forEach(it => {
        const saved = draft.find(x => x.key === it.key);
        if (saved) {
          it.point = saved.point;
          it.locked = saved.locked;
        }
      });
      selectedKeys = new Set(items.map(it => it.key));
    } else {
      items = [];
      selectedKeys = new Set();
    }

    // Show action bar
    $("configActionsRow")?.classList.remove("hidden");
    $("configItemCountBadge")?.classList.remove("hidden");
    
    renderConfigTable();
    loadPreviewScoring();
  } catch (e) {
    console.error(e);
    if (status) status.textContent = "Lỗi tải dữ liệu mục tính điểm.";
  }
}

function updateSummary() {
  const sum = round2(items.reduce((s, it) => s + (it.point || 0), 0));
  const remain = round2(TOTAL_POINTS - sum);

  const elSum = $("sumPoint");
  const elRemain = $("remainPoint");
  if (elSum) elSum.textContent = sum.toFixed(2);
  if (elRemain) elRemain.textContent = remain.toFixed(2);

  // Update item count badge
  const badge = $("configItemCountBadge");
  if (badge) {
    badge.textContent = `${items.length} mục`;
    badge.classList.remove("hidden");
  }

  // Warning styling if sum !== 10
  const isComplete = Math.abs(sum - TOTAL_POINTS) <= 0.02;
  if (!isComplete) {
    setError(`Tổng điểm các mục hiện tại là ${sum.toFixed(2)}đ (Yêu cầu phải đạt đúng 10.00đ).`);
    elSum?.classList.add("text-rose-600");
  } else {
    setError("");
    elSum?.classList.remove("text-rose-600");
  }

  // Enable/Disable Section 3 Actions
  const btnExport = $("btnExport");
  const btnSave = $("btnSaveSemester");
  if (btnExport) {
    if (isComplete) {
      btnExport.removeAttribute("disabled");
      btnExport.classList.remove("opacity-50", "cursor-not-allowed");
    } else {
      btnExport.setAttribute("disabled", "true");
      btnExport.classList.add("opacity-50", "cursor-not-allowed");
    }
  }
  if (btnSave) {
    if (isComplete) {
      btnSave.removeAttribute("disabled");
      btnSave.classList.remove("opacity-50", "cursor-not-allowed");
    } else {
      btnSave.setAttribute("disabled", "true");
      btnSave.classList.add("opacity-50", "cursor-not-allowed");
    }
  }

  // Enable/Disable btnAuto (Chia đều)
  const btnAuto = $("btnAuto");
  const hasUnlocked = items.some(it => !it.locked);
  if (btnAuto) {
    if (items.length > 0 && hasUnlocked && remain >= 0) {
      btnAuto.removeAttribute("disabled");
      btnAuto.classList.remove("opacity-50", "cursor-not-allowed");
    } else {
      btnAuto.setAttribute("disabled", "true");
      btnAuto.classList.add("opacity-50", "cursor-not-allowed");
    }
  }
}

function renderConfigTable() {
  const body = $("scoringBody");
  const wrap = $("scoringTableWrap");
  const status = $("scoringStatus");

  if (!wrap || !status || !body) return;

  if (!items.length) {
    wrap.classList.add("hidden");
    status.classList.remove("hidden");
    status.textContent = "Không có mục tính điểm nào trong cấu hình. Nhấp nút '+ Thêm mục' để chọn.";
    return;
  }

  wrap.classList.remove("hidden");
  status.classList.add("hidden");

  body.innerHTML = items.map(it => `
    <tr class="border-t hover:bg-gray-50 transition">
      <td class="px-4 py-3">
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
          ${it.type === "campaign" ? "bg-indigo-100 text-indigo-800" : "bg-amber-100 text-amber-800"}">
          ${it.type === "campaign" ? "Phong trào" : "Khoản thu"}
        </span>
      </td>
      <td class="px-4 py-3 font-medium text-gray-800">${escapeHtml(it.title)}</td>
      <td class="px-4 py-3 text-center">
        <input
          data-key="${it.key}"
          class="w-24 text-center border border-gray-300 rounded-lg px-2 py-1 text-sm focus:ring-1 focus:ring-indigo-500 focus:outline-none"
          type="number" step="0.01" min="0" max="10"
          value="${(it.point ?? 0).toFixed(2)}"
        />
      </td>
      <td class="px-4 py-3 text-center">
        <input
          data-lock="${it.key}"
          type="checkbox"
          class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
          ${it.locked ? "checked" : ""}
        />
      </td>
      <td class="px-4 py-3 text-center">
        <button type="button" class="text-rose-600 hover:text-rose-900 transition p-1" onclick="removeConfigItem('${it.key}')" title="Xóa mục này">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
        </button>
      </td>
    </tr>
  `).join("");

  // Bind input changes
  body.querySelectorAll('input[data-key]').forEach(inp => {
    inp.oninput = () => {
      const key = inp.dataset.key;
      const it = items.find(x => x.key === key);
      if (!it) return;

      it.point = round2(toNum(inp.value));
      it.locked = true; // Nhập tay => Tự động Khóa điểm mục này

      const lock = body.querySelector(`input[data-lock="${CSS.escape(key)}"]`);
      if (lock) lock.checked = true;

      computeAndApplyDistribution();
    };
  });

  // Bind lock checkboxes
  body.querySelectorAll('input[data-lock]').forEach(chk => {
    chk.onchange = () => {
      const key = chk.dataset.lock;
      const it = items.find(x => x.key === key);
      if (!it) return;
      it.locked = chk.checked;
      computeAndApplyDistribution();
    };
  });

  updateSummary();
}

function computeAndApplyDistribution() {
  const lockedItems = items.filter(it => it.locked);
  const unlockedItems = items.filter(it => !it.locked);

  const lockedSum = round2(lockedItems.reduce((s, it) => s + (it.point || 0), 0));
  const remain = round2(TOTAL_POINTS - lockedSum);

  if (remain < -0.0001) {
    setError(`Tổng điểm các mục đã khóa là ${lockedSum.toFixed(2)}đ (Đã vượt quá 10.00đ). Vui lòng mở khóa hoặc giảm điểm.`);
    updateSummary();
    return;
  }

  const each = unlockedItems.length > 0 ? round2(remain / unlockedItems.length) : 0;
  unlockedItems.forEach(it => { it.point = each; });

  renderConfigTable();
  saveDraftConfig();
  loadPreviewScoring();
}

window.removeConfigItem = async function(key) {
  const it = items.find(x => x.key === key);
  if (!it) return;

  const { confirmed } = await window.showConfirmModal({
    title: 'Xóa mục tính điểm',
    message: `Bạn có chắc chắn muốn bỏ mục "${it.title}" ra khỏi danh sách tính điểm thi đua học kỳ này?`,
    confirmText: 'Bỏ chọn',
    danger: true
  });

  if (confirmed) {
    items = items.filter(x => x.key !== key);
    selectedKeys.delete(key);
    toast("Đã bỏ mục khỏi cấu hình", "success");
    computeAndApplyDistribution();
  }
};

// ==========================================
// ADD CONFIG ITEM MODAL (➕ THÊM MỤC MỚI)
// ==========================================
let modalActiveTab = "campaign";

function initAddConfigItemModal() {
  const modalEl = $("addConfigItemModal");
  if (!modalEl) return;

  $("btnOpenAddConfigItemModal").onclick = () => {
    modalEl.classList.remove("hidden");
    modalActiveTab = "campaign";
    renderAddModalList();
  };

  $("btnCloseAddConfigItemModal").onclick = $("btnCancelAddConfigItem").onclick = () => {
    modalEl.classList.add("hidden");
  };

  // Tab switching inside modal
  const tabBtns = $("addConfigItemModalTabs").querySelectorAll("button");
  tabBtns.forEach(btn => {
    btn.onclick = () => {
      tabBtns.forEach(b => {
        b.classList.remove("border-indigo-600", "text-indigo-600");
        b.classList.add("border-transparent", "text-gray-500", "hover:text-gray-700");
      });
      btn.classList.remove("border-transparent", "text-gray-500");
      btn.classList.add("border-indigo-600", "text-indigo-600");
      modalActiveTab = btn.dataset.type;
      renderAddModalList();
    };
  });

  // Confirm button inside modal
  $("btnConfirmAddConfigItem").onclick = () => {
    const listEl = $("addConfigItemModalList");
    const checkedBoxes = listEl.querySelectorAll("input:checked");
    if (checkedBoxes.length === 0) {
      toast("Vui lòng chọn ít nhất một mục!", "warning");
      return;
    }

    checkedBoxes.forEach(cb => {
      const key = cb.dataset.key;
      const originalItem = allItems.find(x => x.key === key);
      if (originalItem && !items.some(x => x.key === key)) {
        const newItem = JSON.parse(JSON.stringify(originalItem));
        newItem.point = newItem.type === "fee" ? 2.00 : 0.00;
        newItem.locked = false;
        items.push(newItem);
        selectedKeys.add(key);
      }
    });

    modalEl.classList.add("hidden");
    toast(`Đã thêm thành công ${checkedBoxes.length} mục vào cấu hình`, "success");
    computeAndApplyDistribution();
  };
}

function renderAddModalList() {
  const listEl = $("addConfigItemModalList");
  if (!listEl) return;

  // Filter available items: in allItems, NOT in active items, and matching active tab
  const activeKeys = new Set(items.map(x => x.key));
  const availableItems = allItems.filter(x => !activeKeys.has(x.key) && x.type === modalActiveTab);

  if (availableItems.length === 0) {
    listEl.innerHTML = `<div class="text-center py-6 text-sm text-gray-500">Tất cả các mục đã có trong cấu hình hiện tại.</div>`;
    return;
  }

  listEl.innerHTML = `
    <div class="flex items-center justify-between mb-2">
      <span class="text-xs font-semibold text-gray-400">Danh sách các mục chưa chọn (${availableItems.length})</span>
      <div class="flex gap-2">
        <button type="button" onclick="selectAllModalCheckboxes(true)" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold">Chọn tất cả</button>
        <span class="text-gray-300">|</span>
        <button type="button" onclick="selectAllModalCheckboxes(false)" class="text-xs text-gray-500 hover:text-gray-700 font-semibold">Bỏ chọn</button>
      </div>
    </div>
    <div class="space-y-1.5 max-h-56 overflow-auto">
      ${availableItems.map(it => `
        <label class="flex items-start gap-2.5 p-2 bg-gray-50 hover:bg-gray-100 rounded-lg cursor-pointer transition border border-gray-200">
          <input type="checkbox" data-key="${it.key}" class="mt-0.5 w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" />
          <span class="text-sm text-gray-800 leading-snug">${escapeHtml(it.title)}</span>
        </label>
      `).join("")}
    </div>
  `;
}

window.selectAllModalCheckboxes = function(checked) {
  const listEl = $("addConfigItemModalList");
  if (!listEl) return;
  listEl.querySelectorAll('input[type="checkbox"]').forEach(chk => {
    chk.checked = checked;
  });
};

// ==========================================
// PREVIEW SECTION & EXCEL EXPORT (STEP 3)
// ==========================================
function buildPointsPayload() {
  const campaigns = {};
  const fees = {};

  items.forEach(it => {
    if (it.type === "campaign") campaigns[it.id] = round2(it.point || 0);
    if (it.type === "fee") fees[it.id] = round2(it.point || 0);
  });

  return {
    campaigns,
    fees,
    meta: { include_fees: true }
  };
}

async function loadPreviewScoring() {
  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  
  const emptyMsg = $("previewEmptyMessage");
  const tableWrap = $("previewTableWrap");
  const filtersRow = $("previewFiltersRow");
  
  if (!year || !sem) {
    emptyMsg?.classList.remove("hidden");
    tableWrap?.classList.add("hidden");
    filtersRow?.classList.add("hidden");
    return;
  }
  
  emptyMsg?.classList.add("hidden");
  filtersRow?.classList.remove("hidden");
  tableWrap?.classList.remove("hidden");
  
  const tbody = $("previewTableBody");
  const thead = $("previewTableHeader");
  
  if (tbody) {
    // Render 11 cột cho table preview ở chế độ summary, hoặc tính động nếu ở chế độ detail
    const colCount = currentViewMode === "summary" ? 11 : 5 + (previewData ? (previewData.fees.length + previewData.campaigns.length + 4) : 10);
    tbody.innerHTML = renderSkeletonRows(colCount);
  }
  
  try {
    const payload = buildPointsPayload();
    const search = ($("previewSearchClass")?.value || "").trim();
    const dept = $("previewFilterDept")?.value || "";
    
    const res = await request(`${BASE_API}?action=preview_scoring_summary`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `school_year=${encodeURIComponent(year)}&semester=${encodeURIComponent(sem)}&points_json=${encodeURIComponent(JSON.stringify(payload))}&page=${previewCurrentPage}&limit=${previewPageSize}&search=${encodeURIComponent(search)}&dept_name=${encodeURIComponent(dept)}`
    });
    
    const json = await safeJson(res);
    if (!json.ok) {
      if (thead) thead.innerHTML = `<th class="px-4 py-3 text-center text-rose-500" colspan="100">Lỗi: ${escapeHtml(json.error)}</th>`;
      tbody.innerHTML = "";
      return;
    }
    
    previewData = json.data || { campaigns: [], fees: [], classes_scores: [], total_count: 0, departments: [] };
    
    // Show buttons
    $("btnExport")?.classList.remove("hidden");
    $("btnSaveSemester")?.classList.remove("hidden");
    
    initDeptFilter();
    renderPreviewTable();
  } catch (e) {
    console.error(e);
    if (tbody) tbody.innerHTML = `<tr><td class="px-4 py-8 text-center text-rose-500" colspan="100">Lỗi hệ thống khi tải dữ liệu xem trước.</td></tr>`;
  }
}

function initDeptFilter() {
  const select = $("previewFilterDept");
  if (!select || !previewData || isDeptFilterInitialized) return;
  
  const depts = previewData.departments || [];
  const currentVal = select.value;
  
  select.innerHTML = `<option value="">-- Tất cả Khoa --</option>` + 
    depts.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join("");
    
  if (currentVal && depts.includes(currentVal)) {
    select.value = currentVal;
  }
  isDeptFilterInitialized = true;
}

function renderPreviewTable() {
  const thead = $("previewTableHeader");
  const tbody = $("previewTableBody");
  const pag = $("previewPagination");
  if (!thead || !tbody || !previewData) return;
  
  const camps = previewData.campaigns || [];
  const fees = previewData.fees || [];
  const classesScores = previewData.classes_scores || [];
  const totalCount = previewData.total_count || 0;
  
  let headerHtml = "";
  
  if (currentViewMode === "summary") {
    headerHtml = `
      <th class="px-4 py-3 text-center w-12 font-bold text-gray-700">STT</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 w-48">Khoa</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 w-44">Lớp</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 w-48">GVCN</th>
      <th class="px-4 py-3 text-center w-20 font-bold text-gray-700">Sĩ số</th>
      <th class="px-4 py-3 text-center font-bold text-amber-700 bg-amber-50/50 w-36">Điểm khoản thu</th>
      <th class="px-4 py-3 text-center font-bold text-indigo-700 bg-indigo-50/50 w-36">Điểm phong trào</th>
      <th class="px-4 py-3 text-center font-bold text-gray-800 w-24">Tổng điểm</th>
      <th class="px-4 py-3 text-center font-bold text-rose-600 w-40">Tỉ lệ đạt</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 max-w-[200px]">Ghi chú</th>
      <th class="px-4 py-3 text-center font-bold text-gray-700 w-24">Tác vụ</th>
    `;
  } else {
    headerHtml = `
      <th class="px-4 py-3 text-center w-12 font-bold text-gray-700">STT</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 w-48">Khoa</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 sticky left-0 bg-gray-50 z-20 shadow-[3px_0_6px_-3px_rgba(0,0,0,0.15)] border-r w-44">Lớp</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 w-48">GVCN</th>
      <th class="px-4 py-3 text-center w-20 font-bold text-gray-700">Sĩ số</th>
    `;
    
    fees.forEach(f => {
      headerHtml += `
        <th class="px-3 py-3 text-center font-bold text-amber-700 bg-amber-50/50 min-w-[110px] max-w-[140px] cursor-help" title="${escapeHtml(f.title)}">
          <div class="truncate w-full">${escapeHtml(f.title)}</div>
        </th>
      `;
    });
    
    camps.forEach(c => {
      headerHtml += `
        <th class="px-3 py-3 text-center font-bold text-indigo-700 bg-indigo-50/50 min-w-[110px] max-w-[140px] cursor-help" title="${escapeHtml(c.title)}">
          <div class="truncate w-full">${escapeHtml(c.title)}</div>
        </th>
      `;
    });
    
    headerHtml += `
      <th class="px-4 py-3 text-center font-bold text-gray-800 w-24">Tổng điểm</th>
      <th class="px-4 py-3 text-center font-bold text-rose-600 w-40">Tỉ lệ đạt</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 max-w-[200px]">Ghi chú</th>
      <th class="px-4 py-3 text-center font-bold text-gray-700 w-24">Tác vụ</th>
    `;
  }
  
  thead.innerHTML = headerHtml;
  
  if (classesScores.length === 0) {
    tbody.innerHTML = `<tr><td class="px-4 py-8 text-center text-gray-500" colspan="100">Không tìm thấy lớp nào phù hợp.</td></tr>`;
    if (pag) pag.innerHTML = "";
    return;
  }
  
  const totalPages = Math.ceil(totalCount / previewPageSize);
  if (previewCurrentPage < 1) previewCurrentPage = 1;
  if (previewCurrentPage > totalPages) previewCurrentPage = totalPages;
  
  const startIdx = (previewCurrentPage - 1) * previewPageSize;
  const endIdx = startIdx + classesScores.length;
  
  let bodyHtml = "";
  classesScores.forEach((cls, idx) => {
    const stt = startIdx + idx + 1;
    const pct = Math.round(cls.performance_rate * 100);
    const progressBarHtml = `
      <div class="flex items-center justify-center gap-1.5">
        <span class="font-bold text-rose-600 w-10 text-right">${pct}%</span>
        <div class="w-12 bg-gray-200 rounded-full h-1.5 hidden sm:block overflow-hidden">
          <div class="bg-rose-500 h-1.5 rounded-full" style="width: ${pct}%"></div>
        </div>
      </div>
    `;
    
    if (currentViewMode === "summary") {
      let feeEarned = 0, feeMax = 0;
      fees.forEach(f => {
        const scoreObj = cls.fee_scores[f.id] || { earned: 0, max_point: 0 };
        feeEarned += scoreObj.earned || 0;
        feeMax += scoreObj.max_point || 0;
      });
      
      let campEarned = 0, campMax = 0;
      camps.forEach(c => {
        const scoreObj = cls.campaign_scores[c.id] || { earned: 0, max_point: 0 };
        campEarned += scoreObj.earned || 0;
        campMax += scoreObj.max_point || 0;
      });
      
      bodyHtml += `
        <tr class="hover:bg-gray-50 transition border-b">
          <td class="px-4 py-3 text-center font-medium text-gray-500">${stt}</td>
          <td class="px-4 py-3 text-left font-medium text-gray-800">${escapeHtml(cls.dept_name)}</td>
          <td class="px-4 py-3 text-left font-semibold text-indigo-600">${escapeHtml(cls.class_name)}</td>
          <td class="px-4 py-3 text-left text-gray-600">${escapeHtml(cls.gvcn_name || 'Chưa phân công')}</td>
          <td class="px-4 py-3 text-center font-medium text-gray-800">${cls.class_size}</td>
          <td class="px-4 py-3 text-center font-medium text-amber-700 bg-amber-50/10">
            ${feeEarned.toFixed(2)} <span class="text-xs text-gray-400">/ ${feeMax.toFixed(2)}</span>
          </td>
          <td class="px-4 py-3 text-center font-medium text-indigo-700 bg-indigo-50/10">
            ${campEarned.toFixed(2)} <span class="text-xs text-gray-400">/ ${campMax.toFixed(2)}</span>
          </td>
          <td class="px-4 py-3 text-center font-bold text-gray-900">${cls.total_score.toFixed(2)}</td>
          <td class="px-4 py-3 text-center font-medium">${progressBarHtml}</td>
          <td class="px-4 py-3 text-left text-xs text-gray-500 truncate max-w-[200px]" title="${escapeHtml(cls.note)}">${escapeHtml(cls.note)}</td>
          <td class="px-4 py-3 text-center">
            <button type="button" class="text-indigo-600 hover:text-indigo-900 text-xs font-semibold px-2 py-1 rounded bg-indigo-50 hover:bg-indigo-100 transition shadow-sm" 
              onclick="openClassDetail(${cls.class_id}, '${escapeHtml(cls.class_name)}')">
              Chi tiết
            </button>
          </td>
        </tr>
      `;
    } else {
      bodyHtml += `
        <tr class="hover:bg-gray-50 transition border-b">
          <td class="px-4 py-3 text-center font-medium text-gray-500">${stt}</td>
          <td class="px-4 py-3 text-left font-medium text-gray-800">${escapeHtml(cls.dept_name)}</td>
          <td class="px-4 py-3 text-left font-semibold text-indigo-600 sticky left-0 bg-white z-10 shadow-[3px_0_6px_-3px_rgba(0,0,0,0.15)] border-r">${escapeHtml(cls.class_name)}</td>
          <td class="px-4 py-3 text-left text-gray-600">${escapeHtml(cls.gvcn_name || 'Chưa phân công')}</td>
          <td class="px-4 py-3 text-center font-medium text-gray-800">${cls.class_size}</td>
      `;
      
      fees.forEach(f => {
        const scoreObj = cls.fee_scores[f.id] || { earned: 0, paid: 0 };
        bodyHtml += `<td class="px-4 py-3 text-center font-medium text-amber-700 bg-amber-50/20" title="${scoreObj.paid}/${cls.class_size} đã đóng">${scoreObj.earned.toFixed(2)}</td>`;
      });
      
      camps.forEach(c => {
        const scoreObj = cls.campaign_scores[c.id] || { earned: 0, joined: 0 };
        bodyHtml += `<td class="px-4 py-3 text-center font-medium text-indigo-700 bg-indigo-50/20" title="${scoreObj.joined}/${cls.class_size} tham gia">${scoreObj.earned.toFixed(2)}</td>`;
      });
      
      bodyHtml += `
          <td class="px-4 py-3 text-center font-bold text-gray-900">${cls.total_score.toFixed(2)}</td>
          <td class="px-4 py-3 text-center font-medium">${progressBarHtml}</td>
          <td class="px-4 py-3 text-left text-xs text-gray-500 truncate max-w-[200px]" title="${escapeHtml(cls.note)}">${escapeHtml(cls.note)}</td>
          <td class="px-4 py-3 text-center">
            <button type="button" class="text-indigo-600 hover:text-indigo-900 text-xs font-semibold px-2 py-1 rounded bg-indigo-50 hover:bg-indigo-100 transition shadow-sm" 
              onclick="openClassDetail(${cls.class_id}, '${escapeHtml(cls.class_name)}')">
              Chi tiết
            </button>
          </td>
        </tr>
      `;
    }
  });
  tbody.innerHTML = bodyHtml;
  
  if (pag) {
    let pagHtml = `
      <div>Hiển thị từ <b>${startIdx + 1}</b> đến <b>${endIdx}</b> trên tổng số <b>${totalCount}</b> lớp</div>
      <div class="flex items-center gap-1">
    `;
    
    pagHtml += `
      <button onclick="changePreviewPage(${previewCurrentPage - 1})" ${previewCurrentPage === 1 ? 'disabled' : ''} 
        class="px-2.5 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed text-xs font-semibold text-gray-600 transition">
        Trước
      </button>
    `;
    
    for (let pNum = 1; pNum <= totalPages; pNum++) {
      if (pNum === 1 || pNum === totalPages || (pNum >= previewCurrentPage - 2 && pNum <= previewCurrentPage + 2)) {
        pagHtml += `
          <button onclick="changePreviewPage(${pNum})" 
            class="px-3 py-1.5 border rounded-lg text-xs font-semibold transition ${previewCurrentPage === pNum ? 'bg-indigo-600 border-indigo-600 text-white font-bold' : 'border-gray-300 text-gray-600 hover:bg-gray-50'}">
            ${pNum}
          </button>
        `;
      } else if (pNum === previewCurrentPage - 3 || pNum === previewCurrentPage + 3) {
        pagHtml += `<span class="px-1 text-gray-400">...</span>`;
      }
    }
    
    pagHtml += `
      <button onclick="changePreviewPage(${previewCurrentPage + 1})" ${previewCurrentPage === totalPages ? 'disabled' : ''} 
        class="px-2.5 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed text-xs font-semibold text-gray-600 transition">
        Sau
      </button>
      </div>
    `;
    pag.innerHTML = pagHtml;
    pag.classList.remove("hidden");
  }
}

window.changePreviewPage = function(page) {
  previewCurrentPage = page;
  loadPreviewScoring();
};

function submitExport() {
  setError("");
  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  if (!year || !sem) {
    toast("Vui lòng chọn Năm học và Học kỳ trước khi xuất Excel", "warning");
    return;
  }

  const sum = round2(items.reduce((s, it) => s + (it.point || 0), 0));
  if (Math.abs(sum - TOTAL_POINTS) > 0.02) {
    toast(`Tổng điểm phải đúng 10.00đ để xuất Excel (hiện tại: ${sum.toFixed(2)}đ)`, "error");
    return;
  }

  const payload = buildPointsPayload();

  const form = document.createElement("form");
  form.method = "POST";
  form.action = `${BASE_API}?action=export_scoring_summary`;
  form.style.display = "none";

  const add = (name, value) => {
    const inp = document.createElement("input");
    inp.type = "hidden";
    inp.name = name;
    inp.value = value;
    form.appendChild(inp);
  };

  add("school_year", year);
  add("semester", sem);
  add("points_json", JSON.stringify(payload));

  document.body.appendChild(form);
  form.submit();
  form.remove();
  toast("Đang tải xuống file Excel...", "success");
}

async function saveSemesterScores() {
  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  if (!year || !sem) {
    toast("Vui lòng chọn Năm học và Học kỳ trước khi lưu", "warning");
    return;
  }

  const sum = round2(items.reduce((s, it) => s + (it.point || 0), 0));
  if (Math.abs(sum - TOTAL_POINTS) > 0.02) {
    toast(`Tổng điểm phải đúng 10.00đ để lưu (hiện tại: ${sum.toFixed(2)}đ)`, "error");
    return;
  }

  const { confirmed } = await window.showConfirmModal({
    title: 'Lưu điểm học kỳ',
    message: 'Bạn có chắc chắn muốn lưu điểm học kỳ này? Mọi dữ liệu đã lưu trước đó của học kỳ này sẽ bị ghi đè.',
    confirmText: 'Lưu ngay'
  });

  if (!confirmed) return;

  const payload = buildPointsPayload();
  const btn = $("btnSaveSemester");
  if (btn) {
    btn.disabled = true;
    btn.textContent = "Đang lưu...";
  }

  try {
    const res = await request(`${BASE_API}?action=save_scoring_summary`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `school_year=${encodeURIComponent(year)}&semester=${encodeURIComponent(sem)}&points_json=${encodeURIComponent(JSON.stringify(payload))}`
    });

    const json = await safeJson(res);
    if (json.ok) {
      toast("Lưu điểm học kỳ thành công!", "success");
      loadSavedSemesters();
      
      // Auto toggle open Phase 4
      toggleSection("saved-content", true);
    } else {
      toast("Lỗi khi lưu điểm: " + json.error, "error");
    }
  } catch (e) {
    console.error(e);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = "Lưu điểm học kỳ";
    }
  }
}

// ==========================================
// CLASS DETAIL MODAL (MODAL 3)
// ==========================================
async function openClassDetail(classId, className) {
  const modal = $("classDetailModal");
  if (!modal) return;
  
  modal.classList.remove("hidden");
  $("modalLoading").classList.remove("hidden");
  $("modalEmpty").classList.add("hidden");
  $("modalContent").classList.add("hidden");
  
  $("classDetailTitle").textContent = `Chi tiết tham gia thi đua - Lớp ${className}`;
  $("classDetailSubtitle").textContent = "Đang tải dữ liệu...";
  
  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  
  try {
    const payload = buildPointsPayload();
    const res = await request(
      `${BASE_API}?action=class_scoring_detail&class_id=${classId}&school_year=${encodeURIComponent(year)}&semester=${encodeURIComponent(sem)}&points_json=${encodeURIComponent(JSON.stringify(payload))}`
    );
    
    const json = await safeJson(res);
    $("modalLoading").classList.add("hidden");
    
    if (!json.ok) {
      $("classDetailSubtitle").textContent = `Lỗi tải chi tiết: ${json.error}`;
      return;
    }
    
    const members = json.data?.members || [];
    if (members.length === 0) {
      $("modalEmpty").classList.remove("hidden");
      $("classDetailSubtitle").textContent = "Không có sinh viên nào trong lớp này.";
      return;
    }
    
    $("classDetailSubtitle").textContent = `Sĩ số: ${members.length} sinh viên`;
    $("modalContent").classList.remove("hidden");
    
    const camps = previewData?.campaigns || [];
    const fees = previewData?.fees || [];
    
    let headerHtml = `
      <th class="px-4 py-3 text-center w-12 font-bold text-gray-700">STT</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 w-32">MSSV</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700">Họ và tên</th>
    `;
    
    fees.forEach(f => {
      headerHtml += `<th class="px-4 py-3 text-center font-bold text-amber-700 bg-amber-50/50" title="${escapeHtml(f.title)}">${escapeHtml(f.title)}</th>`;
    });
    
    camps.forEach(c => {
      headerHtml += `<th class="px-4 py-3 text-center font-bold text-indigo-700 bg-indigo-50/50" title="${escapeHtml(c.title)}">${escapeHtml(c.title)}</th>`;
    });
    
    $("detailTableHeader").innerHTML = headerHtml;
    
    let bodyHtml = "";
    members.forEach((m, index) => {
      bodyHtml += `
        <tr class="hover:bg-gray-50 border-b">
          <td class="px-4 py-2.5 text-center text-gray-500">${index + 1}</td>
          <td class="px-4 py-2.5 text-left font-mono text-gray-600">${escapeHtml(m.username)}</td>
          <td class="px-4 py-2.5 text-left font-medium text-gray-800">${escapeHtml(m.fullname)}</td>
      `;
      
      fees.forEach(f => {
        const isPaid = m.fees[f.id] || false;
        bodyHtml += `
          <td class="px-4 py-2.5 text-center bg-amber-50/10">
            ${isPaid 
              ? `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 font-bold text-xs" title="Đã đóng">✓</span>` 
              : `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-rose-100 text-rose-800 font-bold text-xs" title="Chưa đóng">✗</span>`
            }
          </td>
        `;
      });
      
      camps.forEach(c => {
        const isJoined = m.campaigns[c.id] || false;
        bodyHtml += `
          <td class="px-4 py-2.5 text-center bg-indigo-50/10">
            ${isJoined 
              ? `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 font-bold text-xs" title="Đã tham gia">✓</span>` 
              : `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-rose-100 text-rose-800 font-bold text-xs" title="Chưa tham gia">✗</span>`
            }
          </td>
        `;
      });
      
      bodyHtml += `</tr>`;
    });
    
    $("detailTableBody").innerHTML = bodyHtml;
    
  } catch (e) {
    console.error(e);
  }
}

function closeClassDetail() {
  const modal = $("classDetailModal");
  if (modal) modal.classList.add("hidden");
}

async function openSavedClassDetail(id, className) {
  const modal = $("classDetailModal");
  if (!modal) return;
  
  modal.classList.remove("hidden");
  $("modalLoading").classList.remove("hidden");
  $("modalEmpty").classList.add("hidden");
  $("modalContent").classList.add("hidden");
  
  $("classDetailTitle").textContent = `Chi tiết điểm học kỳ đã lưu - Lớp ${className}`;
  $("classDetailSubtitle").textContent = "Đang tải dữ liệu...";
  
  try {
    const res = await request(`${BASE_API}?action=saved_class_detail&id=${id}`);
    const json = await safeJson(res);
    $("modalLoading").classList.add("hidden");
    
    if (!json.ok) {
      $("classDetailSubtitle").textContent = `Lỗi tải chi tiết: ${json.error}`;
      return;
    }
    
    const classScore = json.data?.class_score || {};
    const members = json.data?.members_scores || [];
    if (members.length === 0) {
      $("modalEmpty").classList.remove("hidden");
      $("classDetailSubtitle").textContent = "Không có sinh viên nào trong lớp này.";
      return;
    }
    
    $("classDetailSubtitle").textContent = `Sĩ số: ${members.length} sinh viên`;
    $("modalContent").classList.remove("hidden");
    
    const feeScores = classScore.fee_scores || {};
    const campScores = classScore.campaign_scores || {};
    
    const fees = Object.entries(feeScores).map(([fId, f]) => ({ id: fId, title: f.title }));
    const camps = Object.entries(campScores).map(([cId, c]) => ({ id: cId, title: c.title }));
    
    let headerHtml = `
      <th class="px-4 py-3 text-center w-12 font-bold text-gray-700">STT</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700 w-32">MSSV</th>
      <th class="px-4 py-3 text-left font-bold text-gray-700">Họ và tên</th>
    `;
    
    fees.forEach(f => {
      headerHtml += `<th class="px-4 py-3 text-center font-bold text-amber-700 bg-amber-50/50" title="${escapeHtml(f.title)}">${escapeHtml(f.title)}</th>`;
    });
    
    camps.forEach(c => {
      headerHtml += `<th class="px-4 py-3 text-center font-bold text-indigo-700 bg-indigo-50/50" title="${escapeHtml(c.title)}">${escapeHtml(c.title)}</th>`;
    });
    
    $("detailTableHeader").innerHTML = headerHtml;
    
    let bodyHtml = "";
    members.forEach((m, index) => {
      bodyHtml += `
        <tr class="hover:bg-gray-50 border-b">
          <td class="px-4 py-2.5 text-center text-gray-500">${index + 1}</td>
          <td class="px-4 py-2.5 text-left font-mono text-gray-600">${escapeHtml(m.username)}</td>
          <td class="px-4 py-2.5 text-left font-medium text-gray-800">${escapeHtml(m.fullname)}</td>
      `;
      
      fees.forEach(f => {
        const mFee = (m.fee_scores || {})[f.id] || {};
        const isPaid = mFee.paid || false;
        bodyHtml += `
          <td class="px-4 py-2.5 text-center bg-amber-50/10">
            ${isPaid 
              ? `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 font-bold text-xs" title="Đã đóng">✓</span>` 
              : `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-rose-100 text-rose-800 font-bold text-xs" title="Chưa đóng">✗</span>`
            }
          </td>
        `;
      });
      
      camps.forEach(c => {
        const mCamp = (m.campaign_scores || {})[c.id] || {};
        const isJoined = mCamp.joined || false;
        bodyHtml += `
          <td class="px-4 py-2.5 text-center bg-indigo-50/10">
            ${isJoined 
              ? `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 font-bold text-xs" title="Đã tham gia">✓</span>` 
              : `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-rose-100 text-rose-800 font-bold text-xs" title="Chưa tham gia">✗</span>`
            }
          </td>
        `;
      });
      
      bodyHtml += `</tr>`;
    });
    
    $("detailTableBody").innerHTML = bodyHtml;
    
  } catch (e) {
    console.error(e);
  }
}

// ==========================================
// SAVED SECTION & PAGINATION (STEP 4)
// ==========================================
async function loadSavedSemesters() {
  try {
    const res = await request(`${BASE_API}?action=list_saved_semesters`);
    const json = await safeJson(res);
    if (!json.ok) return;

    const select = $("savedSemesterSelect");
    if (!select) return;

    const currentVal = select.value;
    select.innerHTML = `<option value="">-- Chọn kỳ điểm --</option>` + 
      (json.data || []).map(r => `<option value="${r.school_year_id}:${r.semester_code}">${escapeHtml(r.year_label)} - ${escapeHtml(r.semester_label)}</option>`).join("");

    if (currentVal) {
      select.value = currentVal;
    }
  } catch (e) {
    console.error(e);
  }
}

async function loadSavedClasses() {
  const select = $("savedSemesterSelect");
  const emptyMsg = $("savedEmptyMessage");
  const tableWrap = $("savedTableWrap");
  const paginationEl = $("savedPagination");

  if (!select || !select.value) {
    emptyMsg?.classList.remove("hidden");
    tableWrap?.classList.add("hidden");
    paginationEl?.classList.add("hidden");
    return;
  }

  emptyMsg?.classList.add("hidden");
  tableWrap?.classList.remove("hidden");
  paginationEl?.classList.remove("hidden");

  const [schoolYearId, semesterCode] = select.value.split(":");
  const search = ($("savedSearchClass")?.value || "").trim();
  const dept = $("savedFilterDept")?.value || "";

  const tbody = $("savedTableBody");
  if (tbody) {
    tbody.innerHTML = renderSkeletonRows(9);
  }

  try {
    const res = await request(`${BASE_API}?action=list_saved_classes&school_year=${schoolYearId}&semester=${semesterCode}&search=${encodeURIComponent(search)}&dept_name=${encodeURIComponent(dept)}&page=${savedCurrentPage}&limit=${savedPageSize}`);
    const json = await safeJson(res);

    if (!json.ok) {
      tbody.innerHTML = `<tr><td class="px-4 py-8 text-center text-rose-500" colspan="100">Lỗi: ${escapeHtml(json.error)}</td></tr>`;
      return;
    }

    savedData = json.data || { classes_scores: [], total_count: 0, departments: [] };
    
    // Init saved dept filter
    const deptSelect = $("savedFilterDept");
    if (deptSelect && deptSelect.options.length <= 1) {
      const depts = savedData.departments || [];
      deptSelect.innerHTML = `<option value="">-- Tất cả Khoa --</option>` + 
        depts.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join("");
    }

    renderSavedTable();
  } catch (e) {
    console.error(e);
    if (tbody) tbody.innerHTML = `<tr><td class="px-4 py-8 text-center text-rose-500" colspan="100">Lỗi hệ thống khi tải điểm tích lũy.</td></tr>`;
  }
}

function renderSavedTable() {
  const tbody = $("savedTableBody");
  const pag = $("savedPagination");
  if (!tbody || !savedData) return;

  const classesScores = savedData.classes_scores || [];
  const totalCount = savedData.total_count || 0;

  if (classesScores.length === 0) {
    tbody.innerHTML = `<tr><td class="px-4 py-8 text-center text-gray-500" colspan="100">Không tìm thấy lớp học nào phù hợp.</td></tr>`;
    if (pag) pag.innerHTML = "";
    return;
  }

  const startIdx = (savedCurrentPage - 1) * savedPageSize;

  let bodyHtml = "";
  classesScores.forEach((cls, idx) => {
    const stt = startIdx + idx + 1;
    const pct = Math.round(cls.performance_rate * 100);
    
    // Color coded rows/badges based on performance rate
    let colorClass = "bg-rose-100 text-rose-800";
    if (pct >= 80) colorClass = "bg-green-100 text-green-800";
    else if (pct >= 50) colorClass = "bg-amber-100 text-amber-800";

    const progressBarHtml = `
      <div class="flex items-center justify-center gap-1.5">
        <span class="font-bold text-gray-700 w-10 text-right">${pct}%</span>
        <div class="w-12 bg-gray-200 rounded-full h-1.5 hidden sm:block overflow-hidden">
          <div class="h-1.5 rounded-full ${pct >= 80 ? 'bg-green-500' : pct >= 50 ? 'bg-amber-500' : 'bg-red-500'}" style="width: ${pct}%"></div>
        </div>
      </div>
    `;

    bodyHtml += `
      <tr class="hover:bg-gray-50 transition border-b">
        <td class="px-4 py-3 text-center font-medium text-gray-500">${stt}</td>
        <td class="px-4 py-3 text-left font-medium text-gray-800">${escapeHtml(cls.dept_name)}</td>
        <td class="px-4 py-3 text-left font-semibold text-indigo-600">${escapeHtml(cls.class_name)}</td>
        <td class="px-4 py-3 text-left text-gray-600">${escapeHtml(cls.gvcn_name || 'Chưa phân công')}</td>
        <td class="px-4 py-3 text-center font-medium text-gray-800">${cls.class_size}</td>
        <td class="px-4 py-3 text-center font-bold text-gray-900">${parseFloat(cls.total_score).toFixed(2)}</td>
        <td class="px-4 py-3 text-center font-medium">${progressBarHtml}</td>
        <td class="px-4 py-3 text-left text-xs text-gray-500 truncate max-w-[200px]" title="${escapeHtml(cls.note || '')}">${escapeHtml(cls.note || '')}</td>
        <td class="px-4 py-3 text-center">
          <div class="flex items-center justify-center gap-1.5">
            <button type="button" class="text-indigo-600 hover:text-indigo-900 text-xs font-semibold px-2 py-1 rounded bg-indigo-50 hover:bg-indigo-100 transition shadow-sm" 
              onclick="openSavedClassDetail(${cls.id}, '${escapeHtml(cls.class_name)}')">
              👁️ Chi tiết
            </button>
            <button type="button" class="text-amber-700 hover:text-amber-900 text-xs font-semibold px-2 py-1 rounded bg-amber-50 hover:bg-amber-100 transition shadow-sm" 
              onclick="openEditSavedClass(${cls.id}, '${escapeHtml(cls.class_name)}')">
              ✏️ Sửa
            </button>
            <button type="button" class="text-rose-600 hover:text-rose-900 text-xs font-semibold px-2 py-1 rounded bg-rose-50 hover:bg-rose-100 transition shadow-sm" 
              onclick="deleteSingleSavedClass(${cls.id}, '${escapeHtml(cls.class_name)}')">
              🗑️ Xóa
            </button>
          </div>
        </td>
      </tr>
    `;
  });
  tbody.innerHTML = bodyHtml;

  // Pagination
  const totalPages = Math.ceil(totalCount / savedPageSize);
  let pagHtml = `<div>Hiển thị từ <b>${startIdx + 1}</b> đến <b>${startIdx + classesScores.length}</b> trên tổng số <b>${totalCount}</b> lớp</div>`;
  if (totalPages > 1) {
    pagHtml += `<div class="flex gap-1">`;
    if (savedCurrentPage > 1) {
      pagHtml += `<button onclick="changeSavedPage(${savedCurrentPage - 1})" class="px-2.5 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 text-xs font-semibold text-gray-600">Trước</button>`;
    }
    for (let p = 1; p <= totalPages; p++) {
      pagHtml += `<button onclick="changeSavedPage(${p})" class="px-3 py-1.5 border rounded-lg text-xs font-semibold ${savedCurrentPage === p ? 'bg-indigo-600 border-indigo-600 text-white font-bold' : 'border-gray-300 hover:bg-gray-50 text-gray-600'}">${p}</button>`;
    }
    if (savedCurrentPage < totalPages) {
      pagHtml += `<button onclick="changeSavedPage(${savedCurrentPage + 1})" class="px-2.5 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 text-xs font-semibold text-gray-600">Sau</button>`;
    }
    pagHtml += `</div>`;
  }
  if (pag) pag.innerHTML = pagHtml;
}

window.changeSavedPage = function(page) {
  savedCurrentPage = page;
  loadSavedClasses();
};

async function deleteSavedSemester() {
  const select = $("savedSemesterSelect");
  if (!select || !select.value) {
    toast("Vui lòng chọn một kỳ điểm đã lưu để xóa", "warning");
    return;
  }

  const [schoolYearId, semesterCode] = select.value.split(":");
  const text = select.options[select.selectedIndex].textContent;

  const { confirmed } = await window.showConfirmModal({
    title: 'Xóa toàn bộ kỳ điểm',
    message: `CẢNH BÁO: Bạn có chắc chắn muốn xóa vĩnh viễn toàn bộ điểm tích lũy thi đua của kỳ "${text}"? Hành động này sẽ xóa điểm của tất cả các lớp & sinh viên thuộc kỳ này.`,
    confirmText: 'Xóa vĩnh viễn',
    danger: true
  });

  if (!confirmed) return;

  try {
    const res = await request(`${BASE_API}?action=delete_saved_semester`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `school_year=${schoolYearId}&semester=${semesterCode}`
    });

    const json = await safeJson(res);
    if (json.ok) {
      toast("Đã xóa toàn bộ kỳ điểm thành công!", "success");
      await loadSavedSemesters();
      loadSavedClasses();
    } else {
      toast("Lỗi khi xóa kỳ điểm: " + json.error, "error");
    }
  } catch (e) {
    console.error(e);
  }
}

// ==========================================
// NEW: EDIT SINGLE SAVED CLASS SCORE
// ==========================================
let activeEditingRecord = null; // Object { id, fee_scores, campaign_scores, note }

async function openEditSavedClass(id, className) {
  const modal = $("editSavedClassModal");
  if (!modal) return;

  modal.classList.remove("hidden");
  $("editSavedClassLoading").classList.remove("hidden");
  $("editSavedClassContent").classList.add("hidden");

  $("editSavedClassTitle").textContent = `✏️ Chỉnh sửa điểm lớp: ${className}`;
  $("editSavedClassSubtitle").textContent = "Đang tải dữ liệu...";

  try {
    const res = await request(`${BASE_API}?action=saved_class_detail&id=${id}`);
    const json = await safeJson(res);
    $("editSavedClassLoading").classList.add("hidden");

    if (!json.ok) {
      toast("Không thể tải chi tiết lớp học: " + json.error, "error");
      modal.classList.add("hidden");
      return;
    }

    const classScore = json.data?.class_score || {};
    activeEditingRecord = {
      id: classScore.id,
      fee_scores: classScore.fee_scores || {},
      campaign_scores: classScore.campaign_scores || {},
      note: classScore.note || ""
    };

    $("editSavedClassSubtitle").textContent = `Lớp ${className} | Sĩ số: ${classScore.class_size}`;
    $("editSavedClassNote").value = activeEditingRecord.note;
    $("editSavedClassContent").classList.remove("hidden");

    renderEditModalTable(classScore.class_size);
  } catch (e) {
    console.error(e);
    modal.classList.add("hidden");
  }
}

function renderEditModalTable(classSize) {
  const tbody = $("editSavedClassBody");
  if (!tbody || !activeEditingRecord) return;

  const fees = Object.values(activeEditingRecord.fee_scores);
  const camps = Object.values(activeEditingRecord.campaign_scores);

  let html = "";

  // Fees rows
  fees.forEach(f => {
    html += `
      <tr class="border-b">
        <td class="px-4 py-2 text-left">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Khoản thu</span>
        </td>
        <td class="px-4 py-2 font-medium text-gray-700">${escapeHtml(f.title)}</td>
        <td class="px-4 py-2 text-center">
          <input type="number" step="0.01" min="0" max="${f.max_point}" data-edit-type="fee" data-edit-id="${f.id}" 
            class="w-20 text-center border border-gray-300 rounded px-1.5 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500" 
            value="${parseFloat(f.earned).toFixed(2)}" />
        </td>
        <td class="px-4 py-2 text-center text-xs text-gray-500 font-medium">${f.paid}/${classSize} thành viên đóng</td>
      </tr>
    `;
  });

  // Campaigns rows
  camps.forEach(c => {
    html += `
      <tr class="border-b">
        <td class="px-4 py-2 text-left">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800">Phong trào</span>
        </td>
        <td class="px-4 py-2 font-medium text-gray-700">${escapeHtml(c.title)}</td>
        <td class="px-4 py-2 text-center">
          <input type="number" step="0.01" min="0" max="${c.max_point}" data-edit-type="campaign" data-edit-id="${c.id}" 
            class="w-20 text-center border border-gray-300 rounded px-1.5 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500" 
            value="${parseFloat(c.earned).toFixed(2)}" />
        </td>
        <td class="px-4 py-2 text-center text-xs text-gray-500 font-medium">${c.joined}/${classSize} tham gia</td>
      </tr>
    `;
  });

  tbody.innerHTML = html;

  // Bind keyup/change events to calculate total in real time
  tbody.querySelectorAll("input[data-edit-id]").forEach(input => {
    input.oninput = () => {
      const type = input.dataset.editType;
      const id = input.dataset.editId;
      const val = round2(toNum(input.value));
      const maxVal = parseFloat(input.max);

      // Validate bounds
      if (val > maxVal) {
        input.value = maxVal.toFixed(2);
      }

      if (type === "fee") {
        if (activeEditingRecord.fee_scores[id]) activeEditingRecord.fee_scores[id].earned = round2(toNum(input.value));
      } else {
        if (activeEditingRecord.campaign_scores[id]) activeEditingRecord.campaign_scores[id].earned = round2(toNum(input.value));
      }

      calculateEditModalTotal();
    };
  });

  calculateEditModalTotal();
}

function calculateEditModalTotal() {
  if (!activeEditingRecord) return;

  let total = 0.0;
  Object.values(activeEditingRecord.fee_scores).forEach(f => {
    total += parseFloat(f.earned || 0);
  });
  Object.values(activeEditingRecord.campaign_scores).forEach(c => {
    total += parseFloat(c.earned || 0);
  });

  total = round2(total);
  const totalEl = $("editSavedClassTotal");
  if (totalEl) {
    totalEl.textContent = total.toFixed(2);
    if (total > TOTAL_POINTS) {
      totalEl.className = "text-rose-600 font-bold";
    } else {
      totalEl.className = "text-indigo-700 font-bold";
    }
  }
}

async function submitEditSavedClass() {
  if (!activeEditingRecord) return;

  let total = 0.0;
  Object.values(activeEditingRecord.fee_scores).forEach(f => {
    total += parseFloat(f.earned || 0);
  });
  Object.values(activeEditingRecord.campaign_scores).forEach(c => {
    total += parseFloat(c.earned || 0);
  });
  
  total = round2(total);
  if (total > TOTAL_POINTS + 0.01) {
    toast(`Tổng điểm mới (${total.toFixed(2)}đ) vượt quá tối đa 10.00đ`, "error");
    return;
  }

  const note = $("editSavedClassNote").value.trim();
  const btn = $("btnSubmitEditSavedClass");
  if (btn) {
    btn.disabled = true;
    btn.textContent = "Đang lưu...";
  }

  try {
    const res = await request(`${BASE_API}?action=update_saved_class_score`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `id=${activeEditingRecord.id}&fee_scores=${encodeURIComponent(JSON.stringify(activeEditingRecord.fee_scores))}&campaign_scores=${encodeURIComponent(JSON.stringify(activeEditingRecord.campaign_scores))}&note=${encodeURIComponent(note)}`
    });

    const json = await safeJson(res);
    if (json.ok) {
      toast("Cập nhật điểm thi đua thành công!", "success");
      $("editSavedClassModal").classList.add("hidden");
      loadSavedClasses();
    } else {
      toast("Lỗi khi cập nhật điểm: " + json.error, "error");
    }
  } catch (e) {
    console.error(e);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = "Lưu thay đổi";
    }
  }
}

// ==========================================
// NEW: DELETE SINGLE SAVED CLASS SCORE
// ==========================================
async function deleteSingleSavedClass(id, className) {
  const { confirmed } = await window.showConfirmModal({
    title: 'Xóa điểm thi đua lớp',
    message: `Bạn có chắc chắn muốn xóa vĩnh viễn điểm thi đua đã lưu của lớp "<b>${className}</b>"? Dữ liệu điểm cá nhân tương ứng của các đoàn viên cũng sẽ bị xóa.`,
    confirmText: 'Xóa vĩnh viễn',
    danger: true
  });

  if (!confirmed) return;

  try {
    const res = await request(`${BASE_API}?action=delete_saved_class_score`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `id=${id}`
    });

    const json = await safeJson(res);
    if (json.ok) {
      toast(`Đã xóa điểm lớp ${className} thành công!`, "success");
      loadSavedClasses();
    } else {
      toast("Lỗi khi xóa điểm: " + json.error, "error");
    }
  } catch (e) {
    console.error(e);
  }
}

// ==========================================
// INIT APP
// ==========================================
document.addEventListener("DOMContentLoaded", async () => {
  if (!$("filterYear") || !$("filterSemester")) return;

  initCollapsibleSections();
  initStepNavigation();
  initAddConfigItemModal();
  
  // Edit saved class score modal events
  $("btnCloseEditSavedClassModal").onclick = $("btnCancelEditSavedClass").onclick = () => {
    $("editSavedClassModal").classList.add("hidden");
  };
  $("btnSubmitEditSavedClass").onclick = submitEditSavedClass;

  await loadSchoolYears();
  await loadSemesters();

  $("filterYear").onchange = loadScoringItems;
  $("filterSemester").onchange = loadScoringItems;

  $("btnAuto")?.addEventListener("click", computeAndApplyDistribution);
  $("btnExport")?.addEventListener("click", submitExport);
  $("btnSaveSemester")?.addEventListener("click", saveSemesterScores);

  // Preview live filters
  let searchTimeout = null;
  $("previewSearchClass")?.addEventListener("input", () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      previewCurrentPage = 1;
      loadPreviewScoring();
    }, 300);
  });
  $("previewFilterDept")?.addEventListener("change", () => {
    previewCurrentPage = 1;
    loadPreviewScoring();
  });
  $("btnReloadPreview")?.addEventListener("click", loadPreviewScoring);

  // Saved scores filter triggers
  let savedSearchTimeout = null;
  $("savedSearchClass")?.addEventListener("input", () => {
    clearTimeout(savedSearchTimeout);
    savedSearchTimeout = setTimeout(() => {
      savedCurrentPage = 1;
      loadSavedClasses();
    }, 300);
  });
  $("savedFilterDept")?.addEventListener("change", () => {
    savedCurrentPage = 1;
    loadSavedClasses();
  });
  $("savedSemesterSelect")?.addEventListener("change", () => {
    savedCurrentPage = 1;
    loadSavedClasses();
  });
  $("btnReloadSaved")?.addEventListener("click", loadSavedClasses);
  $("btnDeleteSavedSemester")?.addEventListener("click", deleteSavedSemester);

  // View mode switcher buttons
  const btnSummary = $("btnViewModeSummary");
  const btnDetail = $("btnViewModeDetail");
  
  if (btnSummary && btnDetail) {
    const activeClasses = ["bg-white", "text-indigo-700", "shadow-sm"];
    const inactiveClasses = ["text-gray-600", "hover:text-gray-800"];
    
    btnSummary.onclick = () => {
      if (currentViewMode === "summary") return;
      currentViewMode = "summary";
      btnSummary.classList.remove(...inactiveClasses);
      btnSummary.classList.add(...activeClasses);
      btnDetail.classList.remove(...activeClasses);
      btnDetail.classList.add(...inactiveClasses);
      renderPreviewTable();
    };
    
    btnDetail.onclick = () => {
      if (currentViewMode === "detail") return;
      currentViewMode = "detail";
      btnDetail.classList.remove(...inactiveClasses);
      btnDetail.classList.add(...activeClasses);
      btnSummary.classList.remove(...activeClasses);
      btnSummary.classList.add(...inactiveClasses);
      renderPreviewTable();
    };
  }

  // Sticky scroll micro-interaction
  const stickyNav = $("stickyNavHeader");
  if (stickyNav) {
    window.addEventListener("scroll", () => {
      if (window.scrollY > 20) {
        stickyNav.classList.add("bg-indigo-50/95", "border-indigo-300", "shadow-lg");
        stickyNav.classList.remove("bg-white/95", "border-gray-200", "shadow-md");
      } else {
        stickyNav.classList.add("bg-white/95", "border-gray-200", "shadow-md");
        stickyNav.classList.remove("bg-indigo-50/95", "border-indigo-300", "shadow-lg");
      }
    });
  }

  // Pre-load saved semesters
  loadSavedSemesters();
});

// Select search inputs binding (from base app select dropdown style)
(function initYearSemesterPickers() {
  const yearSelect = document.getElementById("filterYear");
  const yearInput = document.getElementById("filterYearSearch");
  const yearDropdown = document.getElementById("filterYearDropdown");
  const yearList = document.getElementById("filterYearList");

  const semSelect = document.getElementById("filterSemester");
  const semInput = document.getElementById("filterSemesterSearch");
  const semDropdown = document.getElementById("filterSemesterDropdown");
  const semList = document.getElementById("filterSemesterList");

  if (!yearSelect || !yearInput || !yearDropdown || !yearList) return;
  if (!semSelect || !semInput || !semDropdown || !semList) return;

  function open(dd) { dd.classList.remove("hidden"); }
  function close(dd) { dd.classList.add("hidden"); }

  function getItemsFromSelect(selectEl) {
    return [...selectEl.options]
      .map(o => ({ id: o.value ?? "", title: o.textContent ?? "" }))
      .filter(it => it.title && it.title.trim().length);
  }

  function setSelected(selectEl, value) {
    selectEl.value = String(value ?? "");
    selectEl.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function syncInputFromSelect(selectEl, inputEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const val = String(selectEl.value || "");
    inputEl.value = val ? (opt?.textContent || "") : "";
  }

  function bindPicker({ input, select, dropdown, list }) {
    let lastRendered = [];

    function render(qText, forceFull = false) {
      const q = (qText || "").trim().toLowerCase();
      const itemsList = getItemsFromSelect(select);

      const filtered = (forceFull || !q)
        ? itemsList
        : itemsList.filter(it => (it.title || "").toLowerCase().includes(q));

      lastRendered = filtered.slice(0, 60);

      list.innerHTML = lastRendered.map(it => `
        <button type="button"
          class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm focus:outline-none"
          data-id="${escapeHtml(it.id)}"
          data-title="${escapeHtml(it.title)}">
          ${escapeHtml(it.title)}
        </button>
      `).join("") || `<div class="px-3 py-2 text-sm text-gray-500">Không tìm thấy</div>`;

      open(dropdown);
    }

    input.addEventListener("focus", () => render("", true));
    input.addEventListener("input", () => render(input.value, false));

    list.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-id]");
      if (!btn) return;

      const id = btn.dataset.id ?? "";
      const title = btn.dataset.title ?? "";

      setSelected(select, id);
      input.value = String(id || "") ? title : "";
      close(dropdown);
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") return close(dropdown);

      if (e.key === "Enter" && !dropdown.classList.contains("hidden")) {
        e.preventDefault();
        const first = lastRendered?.[0];
        if (!first) return;

        setSelected(select, first.id);
        input.value = String(first.id || "") ? (first.title || "") : "";
        close(dropdown);
      }
    });
  }

  document.addEventListener("click", (e) => {
    if (!yearDropdown.contains(e.target) && e.target !== yearInput) close(yearDropdown);
    if (!semDropdown.contains(e.target) && e.target !== semInput) close(semDropdown);
  });

  bindPicker({ input: yearInput, select: yearSelect, dropdown: yearDropdown, list: yearList });
  bindPicker({ input: semInput, select: semSelect, dropdown: semDropdown, list: semList });

  syncInputFromSelect(yearSelect, yearInput);
  syncInputFromSelect(semSelect, semInput);

  yearSelect.addEventListener("change", () => syncInputFromSelect(yearSelect, yearInput));
  semSelect.addEventListener("change", () => syncInputFromSelect(semSelect, semInput));
})();

// Global assignments
window.openSavedClassDetail = openSavedClassDetail;
window.closeClassDetail = closeClassDetail;
window.openClassDetail = openClassDetail;
window.loadPreviewScoring = loadPreviewScoring;
window.openEditSavedClass = openEditSavedClass;
window.deleteSingleSavedClass = deleteSingleSavedClass;
