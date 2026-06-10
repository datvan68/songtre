// assets/js/scoring.js
const BASE_API = "controllers/scoring.php";
const TOTAL_POINTS = 10;

let selectedKeys = new Set();   // lưu những cái user đã tick
let items = [];                 // ←←← THÊM DÒNG NÀY
let includeFees = false;
let allItems = [];              // ← THÊM DÒNG NÀY
let currentViewMode = "summary"; // Mặc định là chế độ Tổng quan

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

// ---- SAFE JSON (giống members.js)
async function safeJson(res) {
  // 403 → báo quyền
  if (res.status === 403) {
    if (typeof notify === "function") {
      notify("Không có quyền", "Bạn không được phép thao tác chức năng này.", "error");
    } else if (typeof toast === "function") {
      toast("Không có quyền (403)", "error");
    }
    throw new Error("Forbidden");
  }

  const text = await res.text();

  // Server error hay trả HTML
  if (!text || !text.trim()) {
    if (typeof notify === "function") {
      notify("Lỗi backend", "Response rỗng", "error");
    }
    throw new Error("Empty response");
  }

  try {
    return JSON.parse(text);
  } catch (e) {
    // show 300 ký tự đầu để debug
    const preview = text.substring(0, 300);
    if (typeof notify === "function") {
      notify("Lỗi backend", preview, "error");
    } else if (typeof toast === "function") {
      toast("Backend trả dữ liệu không hợp lệ", "error");
      console.error("Bad JSON preview:", preview);
    } else {
      console.error("Bad JSON preview:", preview);
    }
    throw new Error("Bad JSON");
  }
}

// ---- fetch wrapper: ưu tiên dùng api() nếu hệ thống bạn có
async function request(url, opts) {
  if (typeof api === "function") return api(url, opts);
  return fetch(url, opts);
}

function updateSummary() {
  // Luôn tính tất cả items (kể cả khoản thu)
  const sum = round2(items.reduce((s, it) => s + (it.point || 0), 0));
  const remain = round2(TOTAL_POINTS - sum);

  const elSum = $("sumPoint");
  const elRemain = $("remainPoint");
  if (elSum) elSum.textContent = sum.toFixed(2);
  if (elRemain) elRemain.textContent = remain.toFixed(2);
}

function renderTable() {
  const body = $("scoringBody");
  const wrap = $("scoringTableWrap");
  const status = $("scoringStatus");

  if (!wrap || !status || !body) return;

  if (!items.length) {
    wrap.classList.add("hidden");
    status.classList.remove("hidden");
    status.textContent = "Không có phong trào / khoản thu có dữ liệu theo bộ lọc.";
    return;
  }

  wrap.classList.remove("hidden");
  status.classList.add("hidden");

  body.innerHTML = items.map(it => `
    <tr class="border-t">
      <td class="px-4 py-2">
        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold
          ${it.type === "campaign" ? "bg-indigo-50 text-indigo-700" : "bg-amber-50 text-amber-700"}">
          ${it.type === "campaign" ? "Phong trào" : "Khoản thu"}
        </span>
      </td>
      <td class="px-4 py-2 font-medium text-gray-800">${escapeHtml(it.title)}</td>
      <td class="px-4 py-2 text-center">
        <input
          data-key="${it.key}"
          class="w-28 text-center border rounded-lg px-2 py-1.5 text-sm"
          type="number" step="0.01" min="0" max="10"
          value="${(it.point ?? 0).toFixed(2)}"
        />
      </td>
      <td class="px-4 py-2 text-center">
        <input
          data-lock="${it.key}"
          type="checkbox"
          ${it.locked ? "checked" : ""}
        />
      </td>
    </tr>
  `).join("");

  // bind input
  body.querySelectorAll('input[data-key]').forEach(inp => {
    inp.oninput = () => {
      const key = inp.dataset.key;
      const it = items.find(x => x.key === key);
      if (!it) return;

      it.point = round2(toNum(inp.value));
      it.locked = true; // nhập tay => khóa

      const lock = body.querySelector(`input[data-lock="${CSS.escape(key)}"]`);
      if (lock) lock.checked = true;

      computeAndApplyDistribution();
    };
  });

  // bind lock
  body.querySelectorAll('input[data-lock]').forEach(chk => {
    chk.onchange = () => {
      const key = chk.dataset.lock;
      const it = items.find(x => x.key === key);
      if (!it) return;
      it.locked = chk.checked;
      computeAndApplyDistribution();
    };
  });
}

function computeAndApplyDistribution() {
  setError("");

  // Luôn tính tất cả items (khoản thu luôn được cộng)
  const lockedItems = items.filter(it => it.locked);
  const unlockedItems = items.filter(it => !it.locked);

  const lockedSum = round2(lockedItems.reduce((s, it) => s + (it.point || 0), 0));
  const remain = round2(TOTAL_POINTS - lockedSum);

  if (remain < -0.0001) {
    setError("Tổng điểm các mục đã khóa đang vượt quá 10. Vui lòng giảm điểm.");
    return;
  }

  const each = unlockedItems.length > 0 ? round2(remain / unlockedItems.length) : 0;
  unlockedItems.forEach(it => { it.point = each; });

  renderTable();
  updateSummary();
}

function renderSelectionList() {
  const wrap = $("scoringTableWrap");
  if (!wrap) return;

  let html = `<div class="mb-6">`;
  html += `<h3 class="font-semibold mb-4 text-lg">✅ Chọn phong trào / khoản thu để tính điểm</h3>`;

  const camps = items.filter(i => i.type === "campaign");
  const fees = items.filter(i => i.type === "fee");

  // === PHONG TRÀO ===
  if (camps.length) {
    html += `
      <div class="mb-6">
        <div class="flex items-center justify-between mb-3">
          <div class="text-indigo-700 font-medium">Phong trào (${camps.length})</div>
          <div class="flex gap-2">
            <button type="button" onclick="selectAllByType('campaign')" 
              class="text-xs px-3 py-1 bg-indigo-100 text-indigo-700 rounded hover:bg-indigo-200">Chọn tất cả</button>
            <button type="button" onclick="selectAllByType('campaign', false)" 
              class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">Bỏ chọn</button>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3 max-h-80 overflow-auto pr-2">
    `;
    html += camps.map(it => `
      <label class="flex items-start gap-2 p-2 hover:bg-gray-50 rounded-lg cursor-pointer border border-transparent hover:border-gray-200">
        <input type="checkbox" data-key="${it.key}" ${selectedKeys.has(it.key) ? "checked" : ""} class="mt-0.5 w-4 h-4 accent-indigo-600">
<span class="text-sm text-gray-800 leading-tight line-clamp-2">${escapeHtml(it.title || 'Khoản thu không tên')}</span>      </label>
    `).join("");
    html += `</div></div>`;
  }

  // === KHOẢN THU ===
  if (fees.length) {
    html += `
      <div>
        <div class="flex items-center justify-between mb-3">
          <div class="text-amber-700 font-medium">Khoản thu (${fees.length})</div>
          <div class="flex gap-2">
            <button type="button" onclick="selectAllByType('fee')" 
              class="text-xs px-3 py-1 bg-amber-100 text-amber-700 rounded hover:bg-amber-200">Chọn tất cả</button>
            <button type="button" onclick="selectAllByType('fee', false)" 
              class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">Bỏ chọn</button>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3 max-h-80 overflow-auto pr-2">
    `;
    html += fees.map(it => `
      <label class="flex items-start gap-2 p-2 hover:bg-gray-50 rounded-lg cursor-pointer border border-transparent hover:border-gray-200">
        <input type="checkbox" data-key="${it.key}" ${selectedKeys.has(it.key) ? "checked" : ""} class="mt-0.5 w-4 h-4 accent-amber-600">
        <span class="text-sm text-gray-800 leading-tight line-clamp-2">${escapeHtml(it.title || 'Khoản thu không tên')}</span>      </label>
    `).join("");
    html += `</div></div>`;
  }

  html += `
    <div class="mt-8">
      <button id="btnApplySelection" 
        class="w-full px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-xl text-base">
        Áp dụng & Tính điểm (${selectedKeys.size} mục)
      </button>
    </div>
  `;

  wrap.innerHTML = html;
  wrap.classList.remove("hidden");

  // bind checkbox
  wrap.querySelectorAll('input[type="checkbox"]').forEach(chk => {
    chk.onchange = () => {
      const key = chk.dataset.key;
      if (chk.checked) selectedKeys.add(key);
      else selectedKeys.delete(key);
      updateApplyButtonText();
    };
  });

  document.getElementById("btnApplySelection").onclick = () => {
    // lưu full list trước khi filter
    if (allItems.length === 0) {
      allItems = JSON.parse(JSON.stringify(items));
    }

    items = items.filter(it => selectedKeys.has(it.key));

    if (items.length === 0) {
      alert("Bạn chưa chọn phong trào hoặc khoản thu nào!");
      return;
    }

    // === CHIA ĐIỂM MỚI: Khoản thu mặc định 2.00, phong trào chia đều phần còn lại ===
    const feeItems = items.filter(it => it.type === "fee");
    const campItems = items.filter(it => it.type === "campaign");

    // 1. Khoản thu = 2.00 (có thể chỉnh tay sau)
    feeItems.forEach(it => {
      it.point = 2.00;
      it.locked = false;
    });

    // 2. Phần còn lại chia đều cho phong trào
    const totalFeePoint = feeItems.reduce((sum, it) => sum + it.point, 0);
    const remain = TOTAL_POINTS - totalFeePoint;

    const campCount = campItems.length;
    const eachCamp = campCount > 0 ? round2(remain / campCount) : 0;

    campItems.forEach(it => {
      it.point = eachCamp;
      it.locked = false;
    });

    renderScoringTable();
    switchTab("overview");
  };
}
// === HÀM HIỂN THỊ BẢNG TÍNH ĐIỂM (mới) ===
function renderScoringTable() {
  const wrap = $("scoringTableWrap");
  if (!wrap) return;

  wrap.innerHTML = `
    <div class="mb-4">
      <h3 class="font-semibold text-lg mb-3 flex items-center gap-2">
        📊 Bảng tính điểm
      </h3>
      <table class="min-w-full bg-white border border-gray-200 rounded-lg overflow-hidden">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Loại</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Tên</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 w-28">Điểm tối đa</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 w-20">Khóa</th>
          </tr>
        </thead>
        <tbody id="scoringBody" class="divide-y"></tbody>
      </table>
    </div>
    <div class="flex gap-2">
      <button onclick="backToSelection()" 
        class="mt-4 px-5 py-2 text-sm text-indigo-600 hover:text-indigo-800 flex items-center gap-1">
        ← Quay lại chọn mục khác
      </button>
    </div>
  `;

  renderTable();
  updateSummary();

  // Tự động tải xem trước điểm các lớp
  loadPreviewScoring();
}

window.backToSelection = function () {
  if (allItems.length > 0) {
    items = JSON.parse(JSON.stringify(allItems));   // khôi phục full list
  }
  selectedKeys.clear();                             // reset tick
  renderSelectionList();

  const previewWrap = $("scoringPreviewWrap");
  if (previewWrap) previewWrap.classList.add("hidden");
};

// === 2 HÀM HỖ TRỢ (thêm ngay dưới hàm renderSelectionList() trên) ===
window.selectAllByType = function (type, checked = true) {
  const wrap = $("scoringTableWrap");
  if (!wrap) return;
  wrap.querySelectorAll('input[type="checkbox"]').forEach(chk => {
    const key = chk.dataset.key;
    if (key.startsWith(type + ':')) {
      chk.checked = checked;
      if (checked) selectedKeys.add(key);
      else selectedKeys.delete(key);
    }
  });
  updateApplyButtonText();
};

function updateApplyButtonText() {
  const btn = document.getElementById("btnApplySelection");
  if (btn) btn.textContent = `Áp dụng & Tính điểm (${selectedKeys.size} mục)`;
}
async function loadSchoolYears() {
  try {
    const res = await request(`${BASE_API}?action=school_year_options`);
    const json = await safeJson(res);

    if (!json.ok) return;

    const select = $("filterYear");
    if (!select) return;

    select.innerHTML =
      `<option value="">-- Chọn năm học --</option>` +
      (json.data || []).map(y => `<option value="${y.id}">${escapeHtml(y.year_label)}</option>`).join("");
  } catch (e) {
    // lỗi đã được safeJson notify rồi
  }
}

async function loadSemesters() {
  try {
    const res = await request(`${BASE_API}?action=semester_options`);
    const json = await safeJson(res);

    if (!json.ok) return;

    const select = $("filterSemester");
    if (!select) return;

    select.innerHTML =
      `<option value="">-- Chọn học kỳ --</option>` +
      (json.data || []).map(s => `<option value="${escapeHtml(s.code)}">${escapeHtml(s.label)}</option>`).join("");
  } catch (e) { }
}

async function loadScoringItems() {
  setError("");
  isDeptFilterInitialized = false;
  previewCurrentPage = 1;

  const previewWrap = $("scoringPreviewWrap");
  if (previewWrap) previewWrap.classList.add("hidden");

  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";

  const wrap = $("scoringTableWrap");
  const status = $("scoringStatus");

  if (!year || !sem) {
    items = [];
    wrap?.classList.add("hidden");
    if (status) {
      status.classList.remove("hidden");
      status.textContent = "Hãy chọn Năm học và Học kỳ.";
    }
    updateSummary();
    return;
  }

  if (status) {
    status.classList.remove("hidden");
    status.textContent = "Đang tải danh sách tất cả...";
  }

  try {
    const res = await request(
      `${BASE_API}?action=scoring_items&school_year=${encodeURIComponent(year)}&semester=${encodeURIComponent(sem)}`
    );
    const json = await safeJson(res);

    if (!json.ok) return;

    const campaigns = json.data?.campaigns || [];
    const fees = json.data?.fees || [];

    items = [
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

    // default chia đều cho phong trào
    const campCount = items.filter(it => it.type === "campaign").length;
    const each = campCount > 0 ? round2(TOTAL_POINTS / campCount) : 0;
    items.forEach(it => {
      if (it.type === "campaign") it.point = each;
    });

    renderSelectionList();
  } catch (e) {
    if (status) status.textContent = "Lỗi tải dữ liệu.";
  }
}

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
    meta: { include_fees: includeFees }
  };
}

function submitExport() {
  setError("");

  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  if (!year || !sem) {
    setError("Vui lòng chọn Năm học và Học kỳ trước khi xuất Excel.");
    return;
  }

  // === TÍNH TỔNG ĐIỂM ĐÚNG (luôn bao gồm khoản thu) ===
  const sum = round2(items.reduce((s, it) => s + (it.point || 0), 0));

  if (Math.abs(sum - TOTAL_POINTS) > 0.02) {
    setError(`Tổng điểm hiện tại = ${sum.toFixed(2)} (yêu cầu đúng 10.00).`);
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
}

let previewData = null;
let previewCurrentPage = 1;
const previewPageSize = 20;
let isDeptFilterInitialized = false;

// === TẢI DỮ LIỆU XEM TRƯỚC ĐIỂM CÁC LỚP ===
async function loadPreviewScoring() {
  const year = $("filterYear")?.value || "";
  const sem = $("filterSemester")?.value || "";
  const previewWrap = $("scoringPreviewWrap");
  
  if (!year || !sem || !previewWrap) return;
  
  previewWrap.classList.remove("hidden");
  const tbody = $("previewTableBody");
  const thead = $("previewTableHeader");
  
  if (thead && tbody) {
    if (!thead.innerHTML || thead.innerHTML.includes("Đang tính toán") || thead.innerHTML.includes("Lỗi")) {
      thead.innerHTML = `<th class="px-4 py-3 text-center" colspan="100">Đang tính toán và tải dữ liệu xem trước...</th>`;
      tbody.innerHTML = "";
    } else {
      tbody.innerHTML = `<tr><td class="px-4 py-8 text-center text-gray-500" colspan="100"><div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600 mb-2"></div><div>Đang tải dữ liệu...</div></td></tr>`;
    }
  }
  
  try {
    const payload = buildPointsPayload();
    const search = ($("previewSearchClass")?.value || "").trim();
    const dept = $("previewFilterDept")?.value || "";
    
    const res = await request(`${BASE_API}?action=preview_scoring_summary`, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded"
      },
      body: `school_year=${encodeURIComponent(year)}&semester=${encodeURIComponent(sem)}&points_json=${encodeURIComponent(JSON.stringify(payload))}&page=${previewCurrentPage}&limit=${previewPageSize}&search=${encodeURIComponent(search)}&dept_name=${encodeURIComponent(dept)}`
    });
    
    const json = await safeJson(res);
    if (!json.ok) {
      if (thead) thead.innerHTML = `<th class="px-4 py-3 text-center text-rose-500" colspan="100">Lỗi: ${escapeHtml(json.error || 'Không thể tải dữ liệu')}</th>`;
      return;
    }
    
    previewData = json.data || { campaigns: [], fees: [], classes_scores: [], total_count: 0, departments: [] };
    
    initDeptFilter();
    renderPreviewTable();
    
  } catch (e) {
    console.error(e);
    if (thead) thead.innerHTML = `<th class="px-4 py-3 text-center text-rose-500" colspan="100">Lỗi hệ thống khi tải dữ liệu</th>`;
  }
}

// Khởi tạo bộ lọc khoa từ danh sách của server
function initDeptFilter() {
  const select = $("previewFilterDept");
  if (!select || !previewData) return;
  
  if (isDeptFilterInitialized) return;
  
  const depts = previewData.departments || [];
  const currentVal = select.value;
  
  select.innerHTML = `<option value="">-- Tất cả Khoa --</option>` + 
    depts.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join("");
    
  if (currentVal && depts.includes(currentVal)) {
    select.value = currentVal;
  }
  
  isDeptFilterInitialized = true;
}

// Render bảng xem trước điểm lớp (Server-side Pagination & Multi-view)
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
    // Chế độ Chi tiết (Detail View) - Có sticky cột Lớp
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
    tbody.innerHTML = `<tr><td class="px-4 py-8 text-center text-gray-500" colspan="100">Không tìm thấy lớp học nào phù hợp.</td></tr>`;
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
      // Tính tổng điểm đạt được và điểm tối đa của từng phần (khoản thu, phong trào)
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
            <button type="button" class="text-indigo-600 hover:text-indigo-900 text-xs font-semibold px-2 py-1 rounded bg-indigo-50 hover:bg-indigo-100 transition" 
              onclick="openClassDetail(${cls.class_id}, '${escapeHtml(cls.class_name)}')">
              Chi tiết
            </button>
          </td>
        </tr>
      `;
    } else {
      // Chế độ Chi tiết (Detailed View) - Cố định cột Lớp bằng sticky left-0
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
        bodyHtml += `<td class="px-4 py-3 text-center font-medium text-amber-700 bg-amber-50/20" title="${scoreObj.paid}/${cls.class_size} thành viên đã đóng">${scoreObj.earned.toFixed(2)}</td>`;
      });
      
      camps.forEach(c => {
        const scoreObj = cls.campaign_scores[c.id] || { earned: 0, joined: 0 };
        bodyHtml += `<td class="px-4 py-3 text-center font-medium text-indigo-700 bg-indigo-50/20" title="${scoreObj.joined}/${cls.class_size} thành viên tham gia">${scoreObj.earned.toFixed(2)}</td>`;
      });
      
      bodyHtml += `
          <td class="px-4 py-3 text-center font-bold text-gray-900">${cls.total_score.toFixed(2)}</td>
          <td class="px-4 py-3 text-center font-medium">${progressBarHtml}</td>
          <td class="px-4 py-3 text-left text-xs text-gray-500 truncate max-w-[200px]" title="${escapeHtml(cls.note)}">${escapeHtml(cls.note)}</td>
          <td class="px-4 py-3 text-center">
            <button type="button" class="text-indigo-600 hover:text-indigo-900 text-xs font-semibold px-2 py-1 rounded bg-indigo-50 hover:bg-indigo-100 transition" 
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
      <div>
        Hiển thị <span class="font-semibold text-gray-700">${startIdx + 1} - ${endIdx}</span> trên tổng số <span class="font-semibold text-gray-700">${totalCount}</span> lớp
      </div>
      <div class="flex items-center gap-1">
    `;
    
    pagHtml += `
      <button onclick="changePreviewPage(${previewCurrentPage - 1})" ${previewCurrentPage === 1 ? 'disabled' : ''} 
        class="px-2.5 py-1.5 border rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed text-xs font-semibold text-gray-600 transition">
        Trước
      </button>
    `;
    
    for (let pNum = 1; pNum <= totalPages; pNum++) {
      if (pNum === 1 || pNum === totalPages || (pNum >= previewCurrentPage - 2 && pNum <= previewCurrentPage + 2)) {
        pagHtml += `
          <button onclick="changePreviewPage(${pNum})" 
            class="px-3 py-1.5 border rounded-lg text-xs font-semibold transition ${previewCurrentPage === pNum ? 'bg-indigo-600 border-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-50'}">
            ${pNum}
          </button>
        `;
      } else if (pNum === previewCurrentPage - 3 || pNum === previewCurrentPage + 3) {
        pagHtml += `<span class="px-1 text-gray-400">...</span>`;
      }
    }
    
    pagHtml += `
      <button onclick="changePreviewPage(${previewCurrentPage + 1})" ${previewCurrentPage === totalPages ? 'disabled' : ''} 
        class="px-2.5 py-1.5 border rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed text-xs font-semibold text-gray-600 transition">
        Sau
      </button>
      </div>
    `;
    
    pag.innerHTML = pagHtml;
  }
}

function changePreviewPage(page) {
  previewCurrentPage = page;
  loadPreviewScoring();
  
  const scrollTarget = $("scoringPreviewWrap");
  if (scrollTarget) {
    scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

window.changePreviewPage = changePreviewPage;

// === XEM CHI TIẾT ĐIỂM SỐ LỚP (MODAL) ===
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
      $("classDetailSubtitle").textContent = `Lỗi tải chi tiết: ${json.error || 'Lỗi không xác định'}`;
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
    $("classDetailSubtitle").textContent = "Lỗi hệ thống khi tải chi tiết lớp.";
  }
}

function closeClassDetail() {
  const modal = $("classDetailModal");
  if (modal) modal.classList.add("hidden");
}

function switchTab(tabId) {
  const configBtn = $("tabConfigBtn");
  const overviewBtn = $("tabOverviewBtn");
  const configContent = $("tabConfigContent");
  const overviewContent = $("tabOverviewContent");
  
  if (!configBtn || !overviewBtn || !configContent || !overviewContent) return;
  
  const activeClasses = ["border-indigo-600", "text-indigo-600"];
  const inactiveClasses = ["border-transparent", "text-gray-500", "hover:text-gray-700", "hover:border-gray-300"];
  
  if (tabId === "config") {
    configBtn.classList.remove(...inactiveClasses);
    configBtn.classList.add(...activeClasses);
    
    overviewBtn.classList.remove(...activeClasses);
    overviewBtn.classList.add(...inactiveClasses);
    
    configContent.classList.remove("hidden");
    overviewContent.classList.add("hidden");
  } else if (tabId === "overview") {
    overviewBtn.classList.remove(...inactiveClasses);
    overviewBtn.classList.add(...activeClasses);
    
    configBtn.classList.remove(...activeClasses);
    configBtn.classList.add(...inactiveClasses);
    
    overviewContent.classList.remove("hidden");
    configContent.classList.add("hidden");
    
    // Load preview data if year & semester selected and not yet loaded
    const year = $("filterYear")?.value || "";
    const sem = $("filterSemester")?.value || "";
    if (year && sem && (!previewData || previewData.classes_scores.length === 0)) {
      loadPreviewScoring();
    }
  }
}

window.switchTab = switchTab;
window.closeClassDetail = closeClassDetail;
window.openClassDetail = openClassDetail;
window.loadPreviewScoring = loadPreviewScoring;

document.addEventListener("DOMContentLoaded", async () => {
  // guard: nếu view chưa có element thì khỏi crash
  if (!$("filterYear") || !$("filterSemester")) return;

  await loadSchoolYears();
  await loadSemesters();

  $("filterYear").onchange = loadScoringItems;
  $("filterSemester").onchange = loadScoringItems;

  const chkFees = $("includeFees");
  if (chkFees) {
    chkFees.onchange = () => {
      includeFees = chkFees.checked;
      computeAndApplyDistribution();
    };
  }

  $("btnAuto")?.addEventListener("click", computeAndApplyDistribution);
  $("btnExport")?.addEventListener("click", submitExport);
  
  // Tab switch event listeners
  $("tabConfigBtn")?.addEventListener("click", () => switchTab("config"));
  $("tabOverviewBtn")?.addEventListener("click", () => switchTab("overview"));
  
  // Lọc & reload bảng xem trước với debounce cho tìm kiếm
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

  // View mode switcher events
  const btnSummary = $("btnViewModeSummary");
  const btnDetail = $("btnViewModeDetail");
  
  if (btnSummary && btnDetail) {
    const activeClasses = ["bg-white", "text-indigo-700", "shadow-sm"];
    const inactiveClasses = ["text-gray-600", "hover:text-gray-800"];
    
    btnSummary.addEventListener("click", () => {
      if (currentViewMode === "summary") return;
      currentViewMode = "summary";
      
      btnSummary.classList.remove(...inactiveClasses);
      btnSummary.classList.add(...activeClasses);
      
      btnDetail.classList.remove(...activeClasses);
      btnDetail.classList.add(...inactiveClasses);
      
      renderPreviewTable();
    });
    
    btnDetail.addEventListener("click", () => {
      if (currentViewMode === "detail") return;
      currentViewMode = "detail";
      
      btnDetail.classList.remove(...inactiveClasses);
      btnDetail.classList.add(...activeClasses);
      
      btnSummary.classList.remove(...activeClasses);
      btnSummary.classList.add(...inactiveClasses);
      
      renderPreviewTable();
    });
  }

  updateSummary();
});
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

  const esc = (s = "") =>
    String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  function open(dd) { dd.classList.remove("hidden"); }
  function close(dd) { dd.classList.add("hidden"); }

  function getItemsFromSelect(selectEl) {
    return [...selectEl.options]
      .map(o => ({ id: o.value ?? "", title: o.textContent ?? "" }))
      // bỏ option placeholder nếu muốn (value rỗng nhưng text placeholder)
      .filter(it => it.title && it.title.trim().length);
  }

  function setSelected(selectEl, value) {
    selectEl.value = String(value ?? "");
    // ✅ kích hoạt lại mọi listener đang nghe select change trong scoring.js
    selectEl.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function syncInputFromSelect(selectEl, inputEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const val = String(selectEl.value || "");
    // value rỗng => để trống input cho dễ gõ lại
    inputEl.value = val ? (opt?.textContent || "") : "";
  }

  function bindPicker({ input, select, dropdown, list }) {
    let lastRendered = [];

    function render(qText, forceFull = false) {
      const q = (qText || "").trim().toLowerCase();
      const items = getItemsFromSelect(select);

      const filtered = (forceFull || !q)
        ? items
        : items.filter(it => (it.title || "").toLowerCase().includes(q));

      lastRendered = filtered.slice(0, 60);

      list.innerHTML = lastRendered.map(it => `
        <button type="button"
          class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
          data-id="${esc(it.id)}"
          data-title="${esc(it.title)}">
          ${esc(it.title)}
        </button>
      `).join("") || `<div class="px-3 py-2 text-sm text-gray-500">Không tìm thấy</div>`;

      open(dropdown);
    }

    // ✅ focus luôn show FULL list (dù input đang có text)
    input.addEventListener("focus", () => render("", true));

    // gõ để lọc
    input.addEventListener("input", () => render(input.value, false));

    // click chọn
    list.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-id]");
      if (!btn) return;

      const id = btn.dataset.id ?? "";
      const title = btn.dataset.title ?? "";

      setSelected(select, id);
      input.value = String(id || "") ? title : "";
      close(dropdown);
    });

    // Enter chọn item đầu tiên
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

  // close all khi click ra ngoài
  document.addEventListener("click", (e) => {
    if (!yearDropdown.contains(e.target) && e.target !== yearInput) close(yearDropdown);
    if (!semDropdown.contains(e.target) && e.target !== semInput) close(semDropdown);
  });

  // bind 2 picker
  bindPicker({ input: yearInput, select: yearSelect, dropdown: yearDropdown, list: yearList });
  bindPicker({ input: semInput, select: semSelect, dropdown: semDropdown, list: semList });

  // init text input theo select hiện tại (sau khi bạn load options)
  syncInputFromSelect(yearSelect, yearInput);
  syncInputFromSelect(semSelect, semInput);

  // nếu scoring.js có code thay đổi select.value bằng JS => sync lại input
  yearSelect.addEventListener("change", () => syncInputFromSelect(yearSelect, yearInput));
  semSelect.addEventListener("change", () => syncInputFromSelect(semSelect, semInput));
})();
