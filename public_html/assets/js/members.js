const MEMBER_API = "controllers/members.php";

let currentPage = 1;

let MEMBER_PERM = {
  create: false,
  update: false,
  delete: false,
  print: false,
  view: false
};
const filterDept = document.getElementById("filterDept");
const filterCourse = document.getElementById("filterCourse");
const filterClass = document.getElementById("filterClass");
const hideStoppedCheckbox = document.getElementById("hideStopped");

function escapeHtml(str = "") {
  return str
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
function normalizeYear(x) {
  if (typeof x === "string") {
    return {
      school_year_id: null,
      year_label: String(x).trim(),
      is_active: 1,
      is_open: 1,     // legacy fallback (nếu backend trả string)
      opened_at: null,
      closed_at: null,
    };
  }
  return {
    school_year_id: x.school_year_id ?? x.id ?? null,
    year_label: String(x.year_label || "").trim(),
    is_active: Number(x.is_active ?? 1),
    is_open: Number(x.is_open ?? x.review_open ?? 0),
    opened_at: x.opened_at ?? null,
    closed_at: x.closed_at ?? null,
  };
}

function badgeHtmlFromState(y, reviewLocked) {
  if (Number(y.is_active) !== 1) {
    return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-slate-200 text-slate-700">Ngừng</span>`;
  }

  // window chưa mở / đã đóng
  if (Number(y.is_open) !== 1) {
    if (y.closed_at) {
      return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-900">Đã đóng đánh giá</span>`;
    }
    return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700">Chưa mở đánh giá</span>`;
  }

  // window đang mở
  if (reviewLocked) {
    return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-amber-200 text-amber-900">Đã khóa</span>`;
  }

  return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-800">Đang mở</span>`;
}

function isLockedMember(m) {
  return Number(m?.is_locked || 0) === 1;
}

function canAdminLock() {
  // controller yêu cầu role admin + can('members','update')
  return window.MEMBER_SCOPE?.role === "admin" && !!MEMBER_PERM.update;
}

async function setMemberLock(id, lock) {
  const fd = new FormData();
  fd.append("action", "set_lock");
  fd.append("id", id);
  fd.append("is_locked", lock ? 1 : 0);

  const res = await api(MEMBER_API, { method: "POST", body: fd });
  const json = await safeJson(res);
  if (!json.ok) throw new Error(json.error || "Update failed");
  return true;
}
const selectedMemberIds = new Set();


async function lockAllFiltered() {
  const q = document.getElementById("memberSearch")?.value.trim() || "";
  const filter = document.getElementById("memberFilter")?.value || "";
  const deptId = filterDept?.value || "";
  const courseId = filterCourse?.value || "";
  const classId = filterClass?.value || "";
  const hideStopped = hideStoppedCheckbox?.checked ? 1 : 0;

  const fd = new FormData();
  fd.append("action", "lock_all");
  fd.append("is_locked", "1");
  fd.append("q", q);
  fd.append("filter", filter);
  fd.append("department_id", deptId);
  fd.append("course_id", courseId);
  fd.append("class_id", classId);
  fd.append("hide_stopped", String(hideStopped));

  const res = await api(MEMBER_API, { method: "POST", body: fd });
  const json = await safeJson(res);
  if (!json.ok) throw new Error(json.error || "Lock all failed");
  return json;
}

function debounce(fn, wait = 300) {
  let t = null;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), wait);
  };
}

function getHeaderFilters() {
  const out = {};
  document.querySelectorAll(".js-th-filter").forEach(el => {
    const key = el.dataset.key;
    if (!key) return;
    const val = (el.value ?? "").toString().trim();
    if (val !== "") out[key] = val;
  });
  return out;
}

function applyHeaderFiltersToInputs(hf = {}) {
  document.querySelectorAll(".js-th-filter").forEach(el => {
    const key = el.dataset.key;
    if (!key) return;
    el.value = hf[key] ?? "";
  });
}

function wireHeaderFilters() {
  const run = debounce(() => loadMembers(1), 250);
  document.querySelectorAll(".js-th-filter").forEach(el => {
    const evt = (el.tagName === "SELECT") ? "change" : "input";
    el.addEventListener(evt, run);
  });
}



function openLockAllModal() {
  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <div class="space-y-4">
      <div class="text-sm text-gray-800">
        Bạn chắc chắn muốn <b>khóa tất cả</b> đoàn viên theo <b>bộ lọc hiện tại</b>?
        <div class="mt-2 text-xs text-gray-500">
          Lưu ý: thao tác này áp dụng cho toàn bộ kết quả lọc (không chỉ trang đang xem).
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button class="px-4 py-2 border rounded-lg" type="button" onclick="closeModal()">
          Hủy
        </button>
        <button class="px-4 py-2 bg-gray-900 text-white rounded-lg" type="button" id="btnYesLockAll">
          Khóa tất cả
        </button>
      </div>
    </div>
  `;

  modal(wrap, "Khóa tất cả", "small");

  const btnYes = wrap.querySelector("#btnYesLockAll");
  btnYes.onclick = async () => {
    btnYes.disabled = true;
    btnYes.textContent = "Đang xử lý...";

    try {
      const r = await lockAllFiltered();
      toast(`Đã khóa ${Number(r.updated || 0)} hồ sơ`, "success");
      closeModal();

      selectedMemberIds.clear();
      const chkAll = document.getElementById("chkSelectAll");
      if (chkAll) { chkAll.checked = false; chkAll.indeterminate = false; }

      loadMembers(currentPage, false);
    } catch (e) {
      toast(e.message || "Không xử lý được", "error");
      btnYes.disabled = false;
      btnYes.textContent = "Khóa tất cả";
    }
  };
}
async function setLockAllFiltered(lock) {
  const q = document.getElementById("memberSearch")?.value.trim() || "";
  const filter = document.getElementById("memberFilter")?.value || "";
  const deptId = filterDept?.value || "";
  const courseId = filterCourse?.value || "";
  const classId = filterClass?.value || "";
  const hideStopped = hideStoppedCheckbox?.checked ? 1 : 0;

  const fd = new FormData();
  fd.append("action", "lock_all");
  fd.append("is_locked", lock ? 1 : 0);

  // giữ đúng logic filter đang có
  fd.append("q", q);
  fd.append("filter", filter);
  fd.append("department_id", deptId);
  fd.append("course_id", courseId);
  fd.append("class_id", classId);
  fd.append("hide_stopped", String(hideStopped));
  Object.entries(hf).forEach(([k, v]) => fd.append(k, v)); // ✅ thêm

  const res = await api(MEMBER_API, { method: "POST", body: fd });
  const json = await safeJson(res);
  if (!json.ok) throw new Error(json.error || "Bulk lock/unlock failed");
  return json; // {ok:1, updated:...}
}

function openLockAllModal(lock) {
  const wrap = document.createElement("div");

  const title = lock ? "Khóa tất cả" : "Mở khóa tất cả";
  const actionText = lock ? "Khóa tất cả" : "Mở khóa tất cả";
  const btnClass = lock ? "bg-gray-900" : "bg-emerald-600";

  wrap.innerHTML = `
    <div class="space-y-4">
      <div class="text-sm text-gray-800">
        Bạn chắc chắn muốn <b>${actionText.toLowerCase()}</b> đoàn viên theo <b>bộ lọc hiện tại</b>?
        <div class="mt-2 text-xs text-gray-500">
          Lưu ý: áp dụng cho toàn bộ kết quả lọc (không chỉ trang đang xem).
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button class="px-4 py-2 border rounded-lg" type="button" onclick="closeModal()">Hủy</button>
        <button class="px-4 py-2 ${btnClass} text-white rounded-lg" type="button" id="btnYesLockAll">
          ${actionText}
        </button>
      </div>
    </div>
  `;

  modal(wrap, title, "small");

  const btnYes = wrap.querySelector("#btnYesLockAll");
  btnYes.onclick = async () => {
    btnYes.disabled = true;
    btnYes.textContent = "Đang xử lý...";

    try {
      const r = await setLockAllFiltered(lock);
      toast(`${actionText} thành công (${Number(r.updated || 0)} hồ sơ)`, "success");
      closeModal();

      // clear selection UI nếu đang chọn nhiều
      selectedMemberIds.clear();
      const chkAll = document.getElementById("chkSelectAll");
      if (chkAll) { chkAll.checked = false; chkAll.indeterminate = false; }

      loadMembers(currentPage, false);
    } catch (e) {
      toast(e.message || "Không xử lý được", "error");
      btnYes.disabled = false;
      btnYes.textContent = actionText;
    }
  };
}

async function bulkSetMemberLock(ids, lock) {
  const fd = new FormData();
  fd.append("action", "bulk_set_lock");
  fd.append("is_locked", lock ? 1 : 0);

  // gửi ids[] để PHP nhận dạng array
  ids.forEach(id => fd.append("ids[]", String(id)));

  const res = await api(MEMBER_API, { method: "POST", body: fd });
  const json = await safeJson(res);
  if (!json.ok) throw new Error(json.error || "Bulk update failed");
  return json;
}
function renderBulkLockBar() {
  const bar = document.getElementById("bulkLockBar");
  const txt = document.getElementById("bulkLockText");
  const btnLock = document.getElementById("btnBulkLock");
  const btnUnlock = document.getElementById("btnBulkUnlock");
  const btnClear = document.getElementById("btnBulkClear");

  if (!bar) return;

  const n = selectedMemberIds.size;
  const can = canAdminLock();

  if (!can || n === 0) {
    bar.classList.add("hidden");
    return;
  }

  bar.classList.remove("hidden");
  if (txt) txt.textContent = `Đã chọn ${n} đoàn viên`;

  const run = async (lock) => {
    const ids = Array.from(selectedMemberIds);
    try {
      if (btnLock) btnLock.disabled = true;
      if (btnUnlock) btnUnlock.disabled = true;

      await bulkSetMemberLock(ids, lock);
      toast(lock ? "Đã khóa các hồ sơ đã chọn" : "Đã mở khóa các hồ sơ đã chọn", "success");

      selectedMemberIds.clear();
      loadMembers(currentPage, false);
      bar.classList.add("hidden");
    } catch (e) {
      toast(e.message || "Không xử lý được", "error");
    } finally {
      if (btnLock) btnLock.disabled = false;
      if (btnUnlock) btnUnlock.disabled = false;
    }
  };

  if (btnLock && !btnLock.__wired) {
    btnLock.__wired = true;
    btnLock.addEventListener("click", () => run(true));
  }
  if (btnUnlock && !btnUnlock.__wired) {
    btnUnlock.__wired = true;
    btnUnlock.addEventListener("click", () => run(false));
  }
  if (btnClear && !btnClear.__wired) {
    btnClear.__wired = true;
    btnClear.addEventListener("click", () => {
      selectedMemberIds.clear();
      renderBulkLockBar();
      const chkAll = document.getElementById("chkSelectAll");
      if (chkAll) { chkAll.checked = false; chkAll.indeterminate = false; }
      document.querySelectorAll(".js-select-member").forEach(chk => (chk.checked = false));
    });
  }
}

function openLockModal({ id, fullname, nextLock }) {
  const wrap = document.createElement("div");
  const title = nextLock ? "Khóa đoàn viên" : "Mở khóa đoàn viên";
  const msg = nextLock
    ? `Bạn chắc chắn muốn khóa hồ sơ của <b>${escapeHtml(fullname || "")}</b>?`
    : `Bạn chắc chắn muốn mở khóa hồ sơ của <b>${escapeHtml(fullname || "")}</b>?`;

  wrap.innerHTML = `
    <div class="space-y-4">
      <div class="text-sm text-gray-800">${msg}</div>

      <div class="flex justify-end gap-2 pt-2">
        <button class="px-4 py-2 border rounded-lg" type="button" onclick="closeModal()">
          Hủy
        </button>
        <button class="px-4 py-2 ${nextLock ? "bg-gray-900" : "bg-green-600"} text-white rounded-lg" type="button" id="btnYesLock">
          ${nextLock ? "Khóa" : "Mở khóa"}
        </button>
      </div>
    </div>
  `;

  modal(wrap, title, "small");

  const btnYes = wrap.querySelector("#btnYesLock");
  btnYes.onclick = async () => {
    btnYes.disabled = true;
    btnYes.textContent = "Đang xử lý...";

    try {
      await setMemberLock(id, nextLock);
      toast(nextLock ? "Đã khóa hồ sơ" : "Đã mở khóa hồ sơ", "success");
      closeModal();
      loadMembers(currentPage, false);
    } catch (err) {
      toast(err.message || "Không xử lý được", "error");
      btnYes.disabled = false;
      btnYes.textContent = nextLock ? "Khóa" : "Mở khóa";
    }
  };
}


function loadLucideIcons() {
  if (window.lucide) {
    lucide.createIcons();
  }
}

function renderUnit(m) {
  // Ưu tiên lớp nếu có
  if (m.class_name || m.class_name2) return m.class_name || m.class_name2;

  // Hỗ trợ nhiều tên field (dept_name hoặc dept)
  const deptName = m.dept_name ?? m.dept ?? m.department_name ?? "";
  const deptType = m.dept_type ?? m.department_type ?? m.dept_type_name ?? "";

  if (deptName) {
    if (deptType === "phong") return "Phòng " + deptName;
    if (deptType === "khoa") return "Khoa " + deptName;
    return deptName;
  }

  return "-";
}

async function safeJson(res) {
  // 403 → báo quyền
  if (res.status === 403) {
    notify("Không có quyền", "Bạn không được phép thao tác chức năng này.", "error");
    throw new Error("Forbidden");
  }

  // 423 → member locked
  if (res.status === 423) {
    notify("Đã khóa", "Hồ sơ đoàn viên đang bị khóa. Không thể chỉnh sửa.", "error");
    throw new Error("Locked");
  }

  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    notify("Lỗi backend", text.substring(0, 300), "error");
    throw new Error("Bad JSON");
  }
}



async function loadMembers(page = 1, push = true) {
  currentPage = page;

  const hf = getHeaderFilters();
  const q = document.getElementById("memberSearch")?.value.trim() || "";
  const filter = document.getElementById("memberFilter")?.value || "";
  const deptId = filterDept?.value || "";
  const courseId = filterCourse?.value || "";
  const classId = filterClass?.value || "";
  const hideStopped = hideStoppedCheckbox?.checked ? 1 : 0;

  if (push) {
    const params = new URLSearchParams();
    params.set("p", "members");
    params.set("page", String(page));
    if (q) params.set("q", q);
    if (filter) params.set("filter", filter);
    if (hideStopped) params.set("hide_stopped", "1");
    if (deptId) params.set("department_id", deptId);
    if (courseId) params.set("course_id", courseId);
    if (classId) params.set("class_id", classId);
    Object.entries(hf).forEach(([k, v]) => params.set(k, v));

    history.pushState({ page, q, filter, deptId, courseId, classId, hide_stopped: hideStopped, hf }, "", "?" + params.toString());
  }

  const paramsApi = new URLSearchParams();
  paramsApi.set("action", "search");
  paramsApi.set("q", q);
  paramsApi.set("filter", filter);
  paramsApi.set("department_id", deptId);
  paramsApi.set("course_id", courseId);
  paramsApi.set("class_id", classId);
  paramsApi.set("hide_stopped", String(hideStopped));
  paramsApi.set("page", String(page));
  Object.entries(hf).forEach(([k, v]) => paramsApi.set(k, v));

  const res = await api(`${MEMBER_API}?${paramsApi.toString()}`);
  const json = await safeJson(res);
  if (json.stats) {
    updateStats(json.stats);
  }
  // ✅ lưu quyền (nếu backend có trả)
  if (json.perm) MEMBER_PERM = json.perm;

  // ✅ Ẩn/hiện nút Khóa tất cả
  const btnLockAll = document.getElementById("btnLockAll");
  if (btnLockAll) btnLockAll.style.display = canAdminLock() ? "" : "none";

  const btnUnlockAll = document.getElementById("btnUnlockAll");
  if (btnUnlockAll) btnUnlockAll.style.display = canAdminLock() ? "" : "none";

  renderTable(json.rows || json);
  renderPagination(page, json.totalPages || 1);

  // ✅ Ẩn/hiện nút theo quyền
  const btnAdd = document.getElementById("btnAddMember");
  if (btnAdd) btnAdd.style.display = MEMBER_PERM.create ? "" : "none";

  const btnImport = document.getElementById("btnImport");
  if (btnImport) btnImport.style.display = MEMBER_PERM.print ? "" : "none";

  const btnExport = document.getElementById("btnExport");
  if (btnExport) btnExport.style.display = MEMBER_PERM.print ? "" : "none";

  // ✅ SYNC INPUT
  const pageInput = document.getElementById("pageInput");
  if (pageInput) pageInput.value = String(page);
}

function updateStats(stats) {
  const elMember = document.querySelector("[data-stat='member']");
  const elYouth = document.querySelector("[data-stat='youth']");

  if (elMember) elMember.textContent = stats.member ?? 0;
  if (elYouth) elYouth.textContent = stats.youth ?? 0;
}


function renderPagination(page, total) {
  const wrap = document.getElementById("pagination");
  if (!wrap || total <= 1) {
    if (wrap) wrap.innerHTML = "";
    return;
  }

  wrap.innerHTML = `
    <!-- Về trang đầu -->
    <button class="page-btn px-3 py-1 border rounded-lg"
      data-page="1"
      ${page === 1 ? "disabled" : ""}>
      «
    </button>

    <!-- Lùi 1 -->
    <button class="page-btn px-3 py-1 border rounded-lg"
      data-page="${page - 1}"
      ${page === 1 ? "disabled" : ""}>
      ‹
    </button>

    <!-- Ô nhập trang -->
    <input id="pageInput"
      type="number"
      min="1"
      max="${total}"
      value="${page}"
      class="w-12 text-center border rounded-lg px-2 py-1">

    <span class="text-sm text-gray-500">/ ${total}</span>

    <!-- Tiến 1 -->
    <button class="page-btn px-3 py-1 border rounded-lg"
      data-page="${page + 1}"
      ${page === total ? "disabled" : ""}>
      ›
    </button>

    <!-- Về trang cuối -->
    <button class="page-btn px-3 py-1 border rounded-lg"
      data-page="${total}"
      ${page === total ? "disabled" : ""}>
      »
    </button>
  `;
  loadLucideIcons(); // 👈 THÊM DÒNG NÀY

}

function injectPageInputStyle() {
  if (document.getElementById("page-input-style")) return;

  const style = document.createElement("style");
  style.id = "page-input-style";
  style.textContent = `
    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type="number"] {
      -moz-appearance: textfield;
    }
  `;
  document.head.appendChild(style);
}
document.addEventListener("DOMContentLoaded", injectPageInputStyle);


document.addEventListener("click", e => {
  const btn = e.target.closest(".page-btn");
  if (!btn || btn.disabled) return;

  const page = parseInt(btn.dataset.page, 10);
  if (Number.isNaN(page)) return;

  loadMembers(page);
});


document.addEventListener("keydown", e => {
  if (e.target.id !== "pageInput") return;

  if (e.key === "Enter") {
    const page = parseInt(e.target.value, 10);
    const max = parseInt(e.target.max, 10);

    if (!page || page < 1 || page > max) {
      toast(`Trang phải từ 1 đến ${max}`);
      e.target.value = currentPage;
      return;
    }

    loadMembers(page);
  }
});

window.addEventListener("popstate", e => {
  const state = e.state || {};
  const page = state.page || 1;

  currentPage = page;
  const q = state.q || "";
  const filter = state.filter || "";
  const hideStopped = state.hide_stopped || 0;
  if (hideStoppedCheckbox) {
    hideStoppedCheckbox.checked = hideStopped == 1;
  }

  if (state.deptId) filterDept.value = state.deptId;
  if (state.courseId) filterCourse.value = state.courseId;
  if (searchInput) searchInput.value = q;
  if (filterSelect) filterSelect.value = filter;

  renderClassFilter();

  if (state.classId) filterClass.value = state.classId;

  applyHeaderFiltersToInputs(state.hf || {}); // ✅ trước
  loadMembers(page, false);                   // ✅ sau

});



function renderTable(data) {
  const tbody = document.getElementById("tbodyMembers");
  tbody.innerHTML = "";

  const showLockBtn = canAdminLock();
  const showLockCol = showLockBtn;
  const showActionCol = (!!MEMBER_PERM.update) || (!!MEMBER_PERM.delete) || showLockBtn;
  const colCount = (showLockCol ? 1 : 0) + 19 + (showActionCol ? 1 : 0);

  if (!data || !data.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="${colCount}" class="text-center py-4 text-gray-500">
          Không tìm thấy kết quả
        </td>
      </tr>`;
    return;
  }

  for (const m of data) {
    const locked = isLockedMember(m);

    const row = document.createElement("tr");
    row.className =
      "border-t hover:bg-gray-50 " +
      (Number(m.stop_follow) === 1 ? "opacity-60 bg-gray-100 " : "") +
      (locked ? " bg-yellow-50 " : "");

    const canEditPerm = !!MEMBER_PERM.update;
    const canDelPerm = !!MEMBER_PERM.delete;

    // locked => không cho sửa/xóa/stop_follow/note
    const canEdit = canEditPerm && !locked;
    const canDel = canDelPerm && !locked;

    const lockBadge = locked
      ? `<span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-yellow-200 text-yellow-900">
           <i data-lucide="lock" class="w-3 h-3"></i>
           <span>Đã khóa</span>
         </span>`
      : "";

    const editBtn = canEditPerm
      ? `<button
        class="js-edit icon-btn ${locked ? "opacity-50 cursor-not-allowed" : ""}"
        data-id="${m.id}"
        ${locked ? 'disabled title="Hồ sơ đã khóa"' : 'title="Sửa"'}
     >
        <i data-lucide="pencil" class="w-4 h-4"></i>
     </button>`
      : "";


    const delBtn = canDelPerm
      ? `<button
        class="js-del icon-btn danger ${locked ? "opacity-50 cursor-not-allowed" : ""}"
        data-id="${m.id}"
        ${locked ? 'disabled title="Hồ sơ đã khóa"' : 'title="Xóa"'}
     >
        <i data-lucide="trash-2" class="w-4 h-4"></i>
     </button>`
      : "";


    const lockBtn = showLockBtn
      ? `<button
        class="js-lock icon-btn ${locked ? "success" : "dark"}"
        data-id="${m.id}"
        data-fullname="${escapeHtml(m.fullname || "")}"
        data-next="${locked ? 0 : 1}"
        title="${locked ? "Mở khóa" : "Khóa"}"
     >
        <i data-lucide="${locked ? "unlock" : "lock"}" class="w-4 h-4"></i>
     </button>`
      : "";


    const rowId = Number(m.id);
    const checked = selectedMemberIds.has(rowId) ? "checked" : "";

    const selTd = showLockCol
      ? `<td class="px-3 py-2 text-center">
       <input type="checkbox" class="js-select-member" data-id="${rowId}" ${checked}>
     </td>`
      : "";

    const actionTd = showActionCol
      ? `<td class="px-3 py-2 text-center sticky-col-right">
      <div class="flex items-center justify-center gap-2">
        ${editBtn}
        ${delBtn}
        ${lockBtn}
        ${(!editBtn && !delBtn && !lockBtn) ? `<span class="text-gray-400 text-xs">—</span>` : ""}
      </div>
    </td>`
      : "";

    row.innerHTML = `
  ${selTd}
  <td class="px-3 py-2">${m.mssv}</td>
  <td class="px-3 py-2 font-medium">
    ${escapeHtml(m.fullname || "")}
    ${lockBadge}
  </td>
  <td class="px-3 py-2">${escapeHtml(renderUnit(m))}</td>
  <td class="px-3 py-2">${m.type === "member" ? "Đoàn viên" : "Thanh niên"}</td>
  <td class="px-3 py-2">${formatDate(m.birth)}</td>
  <td class="px-3 py-2">${formatDate(m.join_date)}</td>

  <td class="px-3 py-2 text-center">
    ${m.age_life !== null && m.age_life !== undefined ? m.age_life : "-"}
  </td>

  <td class="px-3 py-2 text-center">
    ${m.age_youth !== null && m.age_youth !== undefined ? m.age_youth : "-"}
  </td>

  <td class="px-3 py-2 text-center font-semibold text-blue-700">
    ${Number(m.total_score || 0)}
  </td>

  <td class="px-3 py-2">${escapeHtml(m.native_place || "-")}</td>
  <td class="px-3 py-2 whitespace-normal break-words">${escapeHtml(m.current_address || "-")}</td>
  <td class="px-3 py-2">${escapeHtml(m.ethnicity || "-")}</td>
  <td class="px-3 py-2">${escapeHtml(m.religion || "-")}</td>
  <td class="px-3 py-2 whitespace-normal break-words">${escapeHtml(m.phone || "-")}</td>
  <td class="px-3 py-2">${escapeHtml(m.email || "-")}</td>

  <td class="px-3 py-2 border-l">
    ${m.party_probation_date ? formatDate(m.party_probation_date) : "-"}
  </td>
  <td class="px-3 py-2">
    ${m.party_official_date ? formatDate(m.party_official_date) : "-"}
  </td>

  <td class="px-3 py-2 text-center">
    <input
      type="checkbox"
      class="js-stop-follow scale-110"
      data-id="${m.id}"
      ${Number(m.stop_follow) === 1 ? "checked" : ""}
      ${(!canEdit || locked) ? "disabled" : ""}
      ${locked ? 'title="Hồ sơ đã khóa"' : ""}
    >
  </td>

  <td class="px-3 py-2">
    <input
      type="text"
      class="js-note w-full border rounded px-2 py-1 text-sm"
      data-id="${m.id}"
      value="${m.note ? escapeHtml(m.note) : ""}"
      placeholder="Ghi chú..."
      ${(!canEdit || locked) ? "disabled" : ""}
      ${locked ? 'title="Hồ sơ đã khóa"' : ""}
    >
  </td>

  ${actionTd}
`;


    tbody.appendChild(row);
  }

  // ===============================
  // NGỪNG THEO DÕI
  // ===============================
  tbody.querySelectorAll(".js-stop-follow").forEach(chk => {
    chk.addEventListener("change", async () => {
      const id = chk.dataset.id;
      const stop = chk.checked ? 1 : 0;

      const fd = new FormData();
      fd.append("action", "update_stop_follow");
      fd.append("id", id);
      fd.append("stop_follow", stop);

      try {
        const res = await api(MEMBER_API, { method: "POST", body: fd });
        const json = await safeJson(res);
        if (!json.ok) throw new Error(json.error || "Update failed");

        chk.closest("tr")?.classList.toggle("opacity-60", stop === 1);
        chk.closest("tr")?.classList.toggle("bg-gray-100", stop === 1);
        loadMembers(currentPage, false);
      } catch (err) {
        toast(err.message || "Không cập nhật được", "error");
        chk.checked = !chk.checked;
      }
    });
  });

  // ===============================
  // GHI CHÚ
  // ===============================
  tbody.querySelectorAll(".js-note").forEach(input => {
    let oldValue = input.value;

    input.addEventListener("focus", () => {
      oldValue = input.value;
    });

    input.addEventListener("blur", async () => {
      const id = input.dataset.id;
      const note = input.value.trim();

      if (note === oldValue) return;

      const fd = new FormData();
      fd.append("action", "update_note");
      fd.append("id", id);
      fd.append("note", note);

      try {
        const res = await api(MEMBER_API, { method: "POST", body: fd });
        const json = await safeJson(res);
        if (!json.ok) throw new Error(json.error || "Update failed");
      } catch (err) {
        toast(err.message || "Không lưu được ghi chú", "error");
        input.value = oldValue;
      }
    });
  });

  // ===============================
  // SỬA / XÓA
  // ===============================
  tbody.querySelectorAll(".js-edit").forEach(b => {
    b.onclick = () => openMemberModal(b.dataset.id);
  });
  tbody.querySelectorAll(".js-del").forEach(b => {
    b.onclick = () => delMember(b.dataset.id);
  });

  // ===============================
  // KHÓA / MỞ KHÓA (ADMIN)
  // ===============================
  tbody.querySelectorAll(".js-lock").forEach(btn => {
    btn.onclick = () => {
      const id = btn.dataset.id;
      const fullname = btn.dataset.fullname || "";
      const nextLock = Number(btn.dataset.next || 0); // 1=khóa, 0=mở khóa

      openLockModal({ id, fullname, nextLock });
    };
  });
  // ===============================
  // CHỌN NHIỀU (ADMIN LOCK)
  // ===============================
  if (canAdminLock()) {
    const chkAll = document.getElementById("chkSelectAll");

    // Tick từng dòng
    tbody.querySelectorAll(".js-select-member").forEach(chk => {
      chk.addEventListener("change", () => {
        const id = Number(chk.dataset.id);
        if (chk.checked) selectedMemberIds.add(id);
        else selectedMemberIds.delete(id);

        syncSelectAllState();
        renderBulkLockBar();
      });
    });

    function syncSelectAllState() {
      if (!chkAll) return;

      const idsOnPage = [...tbody.querySelectorAll(".js-select-member")]
        .map(x => Number(x.dataset.id));

      const checkedCount = idsOnPage.filter(id => selectedMemberIds.has(id)).length;

      chkAll.indeterminate = checkedCount > 0 && checkedCount < idsOnPage.length;
      chkAll.checked = idsOnPage.length > 0 && checkedCount === idsOnPage.length;
    }

    if (chkAll && !chkAll.__wired) {
      chkAll.__wired = true;

      chkAll.addEventListener("change", () => {
        const idsOnPage = [...tbody.querySelectorAll(".js-select-member")]
          .map(x => Number(x.dataset.id));

        if (chkAll.checked) idsOnPage.forEach(id => selectedMemberIds.add(id));
        else idsOnPage.forEach(id => selectedMemberIds.delete(id));

        // phản ánh ra UI
        tbody.querySelectorAll(".js-select-member").forEach(chk => {
          const id = Number(chk.dataset.id);
          chk.checked = selectedMemberIds.has(id);
        });

        chkAll.indeterminate = false;
        renderBulkLockBar();
      });
    }

    // sync trạng thái ban đầu
    // (ví dụ bạn chọn trước đó rồi đổi trang)
    setTimeout(() => {
      const event = new Event("change");
      // chỉ để gọi syncSelectAllState logic (không bắt buộc)
      // syncSelectAllState();
    }, 0);
  }
  renderBulkLockBar();

  loadLucideIcons();
}


document.addEventListener("DOMContentLoaded", () => {
  loadLucideIcons();
  initFilterOptions();
  wireHeaderFilters();

  document.getElementById("btnAddMember")?.addEventListener("click", () => openMemberModal());
  document.querySelectorAll(".js-edit").forEach(btn =>
    btn.addEventListener("click", () => openMemberModal(btn.dataset.id))
  );
  document.querySelectorAll(".js-del").forEach(btn =>
    btn.addEventListener("click", () => delMember(btn.dataset.id))
  );
  document.querySelectorAll(".js-showQR").forEach(btn =>
    btn.addEventListener("click", () => showQR(btn.dataset.id))
  );

  const url = new URL(window.location.href);
  const HF_KEYS = [
    "mssv", "fullname", "class_name", "type", "birth", "join_date",
    "age_life_min", "age_youth_min", "score_min",
    "native_place", "current_address", "ethnicity", "religion",
    "phone", "email", "party", "party_probation_date", "party_official_date",
    "stop_follow", "note"
  ];

  const hfFromUrl = {};
  HF_KEYS.forEach(k => {
    const v = url.searchParams.get(k);
    if (v !== null) hfFromUrl[k] = v;
  });
  applyHeaderFiltersToInputs(hfFromUrl);

  const page = parseInt(url.searchParams.get("page")) || 1;
  const q = url.searchParams.get("q") || "";
  const filter = url.searchParams.get("filter") || "";
  const hideStopped = url.searchParams.get("hide_stopped");

  if (hideStopped === "1" && hideStoppedCheckbox) {
    hideStoppedCheckbox.checked = true;
  }


  if (searchInput) searchInput.value = q;
  if (filterSelect) filterSelect.value = filter;

  loadMembers(page, false);



  // ==============================
  // 📥 IMPORT XLSX
  // ==============================
  document.getElementById("btnImport")?.addEventListener("click", () => {
    document.getElementById("xlsxInput").click();
  });

  document.getElementById("xlsxInput")?.addEventListener("change", async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append("action", "import_xlsx");
    fd.append("file", file);

    const btnImport = document.getElementById("btnImport");
    const overlay = document.getElementById("importOverlay");

    try {
      // 🔒 khóa UI + hiện overlay
      if (btnImport) btnImport.disabled = true;
      overlay?.classList.remove("hidden");

      const res = await api("controllers/members.php", {
        method: "POST",
        body: fd
      });

      const text = await res.text();
      console.log("Import XLSX Response:", text);

      let json;
      try {
        json = JSON.parse(text);
      } catch {
        notify("Lỗi backend", text, "error");
        return;
      }

      if (json.ok) {
        notifyReload("Thành công", json.msg || "Đã nhập dữ liệu");
      } else {
        notify("Lỗi", json.error || "Import thất bại", "error");
      }

    } catch (err) {
      notify("Lỗi JS", err.message, "error");
    } finally {
      // 🔓 mở UI + tắt overlay (CHỈ KHI XONG)
      overlay?.classList.add("hidden");
      if (btnImport) btnImport.disabled = false;
      e.target.value = "";
    }
  });



  // === Xuất XLSX (có lọc) ===
  document.getElementById("btnExport")?.addEventListener("click", () => {
    const hf = getHeaderFilters();
    const params = new URLSearchParams();
    // LẤY GIÁ TRỊ FILTER ĐANG CHỌN (ĐÚNG ID: memberFilter)
    const filter = document.getElementById("memberFilter")?.value || "";

    // Link export
    const deptId = filterDept?.value || "";
    const courseId = filterCourse?.value || "";
    const classId = filterClass?.value || "";
    const hideStopped = hideStoppedCheckbox?.checked ? 1 : 0;

    const urlExport =
      MEMBER_API
      + "?action=export_xlsx"
      + "&filter=" + encodeURIComponent(filter)
      + "&department_id=" + deptId
      + "&course_id=" + courseId
      + "&class_id=" + classId
      + "&hide_stopped=" + hideStopped;
      
    params.set("action", "export_xlsx");
    params.set("filter", document.getElementById("memberFilter")?.value || "");
    params.set("department_id", filterDept?.value || "");
    params.set("course_id", filterCourse?.value || "");
    params.set("class_id", filterClass?.value || "");
    params.set("hide_stopped", hideStoppedCheckbox?.checked ? "1" : "0");

    Object.entries(hf).forEach(([k, v]) => params.set(k, v)); // ✅ thêm

    window.location.href = `${MEMBER_API}?${params.toString()}`;

    // MỞ FILE TRỰC TIẾP (tránh lỗi file bị corrupt khi dùng api)
    window.location.href = urlExport;
  });
  const btnLockAll = document.getElementById("btnLockAll");
  if (btnLockAll && !btnLockAll.__wired) {
    btnLockAll.__wired = true;
    btnLockAll.addEventListener("click", () => {
      if (!canAdminLock()) return toast("Không có quyền", "error");
      openLockAllModal(true);
    });
  }
  const btnUnlockAll = document.getElementById("btnUnlockAll");
  if (btnUnlockAll && !btnUnlockAll.__wired) {
    btnUnlockAll.__wired = true;
    btnUnlockAll.addEventListener("click", () => {
      if (!canAdminLock()) return toast("Không có quyền", "error");
      openLockAllModal(false);
    });
  }
});

function initFilterOptions() {
  // guard: nếu view nào đó không có filter thì khỏi crash
  if (!filterDept || !filterCourse || !filterClass) return;

  const role = window.MEMBER_SCOPE?.role || "";
  const s = window.MEMBER_SCOPE || {};
  const opts = window.memberOptions || { departments: [], courses: [], classes: [] };

  // reset
  filterDept.innerHTML = '<option value="">-- Khoa / Phòng --</option>';
  filterCourse.innerHTML = '<option value="">-- Khóa --</option>';
  filterClass.innerHTML = '<option value="">-- Lớp --</option>';

  filterDept.disabled = false;
  filterCourse.disabled = false;
  filterClass.disabled = false;

  // helpers
  const deptLabel = (d) => {
    if (!d) return "";
    if (d.type === "phong") return "Phòng " + d.name;
    if (d.type === "khoa") return "Khoa " + d.name;
    return d.name;
  };

  const renderDeptKhoaPhong = () => {
    const khoa = (opts.departments || []).filter(d => d.type === "khoa");
    const phong = (opts.departments || []).filter(d => d.type === "phong");

    let html = '<option value="">-- Khoa / Phòng --</option>';

    if (khoa.length) {
      html += `<optgroup label="Khoa">
        ${khoa.map(d => `<option value="${d.id}">${deptLabel(d)}</option>`).join("")}
      </optgroup>`;
    }
    if (phong.length) {
      html += `<optgroup label="Phòng">
        ${phong.map(d => `<option value="${d.id}">${deptLabel(d)}</option>`).join("")}
      </optgroup>`;
    }

    // fallback: nếu data type không có
    if (!khoa.length && !phong.length) {
      html =
        '<option value="">-- Khoa / Phòng --</option>' +
        (opts.departments || []).map(d => `<option value="${d.id}">${deptLabel(d)}</option>`).join("");
    }

    filterDept.innerHTML = html;
  };

  /* =====================
     BÍ THƯ
  ===================== */
  if (role === "bithu") {
    const gid = Number(s.chidoan_group_id || 1);

    // ===== Bí thư chi đoàn lớp (gid=1) =====
    if (gid === 1) {
      const bithuScope = getBithuScopeData();

      // nếu thiếu dept/course/class => fallback kiểu admin để không crash
      if (!bithuScope?.department || !bithuScope?.course || !bithuScope?.class) {
        // fallback
        filterDept.innerHTML =
          '<option value="">-- Khoa --</option>' +
          (opts.departments || [])
            .filter(d => d.type === "khoa")
            .map(d => `<option value="${d.id}">${deptLabel(d)}</option>`)
            .join("");

        filterCourse.innerHTML =
          '<option value="">-- Khóa --</option>' +
          (opts.courses || []).map(c => `<option value="${c.id}">${c.name}</option>`).join("");

        filterClass.innerHTML = '<option value="">-- Lớp --</option>';
        return;
      }

      // lock đúng scope
      filterDept.innerHTML = `<option value="${bithuScope.department.id}">${deptLabel(bithuScope.department)}</option>`;
      filterDept.value = bithuScope.department.id;
      filterDept.disabled = true;

      filterCourse.innerHTML = `<option value="${bithuScope.course.id}">${bithuScope.course.name}</option>`;
      filterCourse.value = bithuScope.course.id;
      filterCourse.disabled = true;

      filterClass.innerHTML = `<option value="${bithuScope.class.id}">${bithuScope.class.name}</option>`;
      filterClass.value = bithuScope.class.id;
      filterClass.disabled = true;

      return;
    }

    // ===== Bí thư chi đoàn GV (gid=2) =====
    if (gid === 2) {
      // Chỉ filter theo Khoa/Phòng. Khóa/Lớp disable.
      const dept = (opts.departments || []).find(d => Number(d.id) === Number(s.department_id));

      if (dept) {
        filterDept.innerHTML = `<option value="${dept.id}">${deptLabel(dept)}</option>`;
        filterDept.value = dept.id;
        filterDept.disabled = true; // vì backend của bạn đang cấm đổi dept với group 2
      } else {
        // nếu scope thiếu department_id hoặc không match data => vẫn cho chọn để khỏi toang
        renderDeptKhoaPhong();
      }

      filterCourse.innerHTML = '<option value="">-- Khóa --</option>';
      filterClass.innerHTML = '<option value="">-- Lớp --</option>';
      filterCourse.disabled = true;
      filterClass.disabled = true;

      return;
    }
  }

  /* =====================
     GVCN
  ===================== */
  if (role === "gvcn") {
    const gvcnScope = getGvcnScopeData();
    if (!gvcnScope) {
      // fallback như admin nếu scope thiếu
    } else {
      filterDept.innerHTML =
        '<option value="">-- Khoa --</option>' +
        gvcnScope.departments.map(d => `<option value="${d.id}">${deptLabel(d)}</option>`).join("");

      filterCourse.innerHTML =
        '<option value="">-- Khóa --</option>' +
        gvcnScope.courses.map(c => `<option value="${c.id}">${c.name}</option>`).join("");

      filterClass.innerHTML = '<option value="">-- Lớp --</option>';
      return;
    }
  }

  /* =====================
     ADMIN / DEFAULT
  ===================== */
  renderDeptKhoaPhong(); // ✅ hiển thị cả Khoa + Phòng (optgroup)

  filterCourse.innerHTML =
    '<option value="">-- Khóa --</option>' +
    (opts.courses || []).map(c => `<option value="${c.id}">${c.name}</option>`).join("");

  filterClass.innerHTML = '<option value="">-- Lớp --</option>';

}




function formatDate(d) {
  if (!d) return "-";
  const [y, m, day] = d.split("-");
  return `${day}/${m}/${y}`;
}
function renderClassFilter() {
  const role = window.MEMBER_SCOPE?.role;
  const gvcnScope = getGvcnScopeData();

  const deptId = filterDept.value;
  const courseId = filterCourse.value;

  // reset
  filterClass.innerHTML = '<option value="">-- Lớp --</option>';

  // ✅ nếu chọn "Phòng" => khóa Khóa/Lớp (vì không áp dụng)
  const deptObj = (window.memberOptions?.departments || [])
    .find(d => String(d.id) === String(deptId));

  const isPhong = deptObj?.type === "phong";

  if (isPhong) {
    // clear + disable
    filterCourse.value = "";
    filterClass.value = "";
    filterCourse.disabled = true;
    filterClass.disabled = true;

    filterCourse.innerHTML = '<option value="">-- Khóa --</option>';
    filterClass.innerHTML = '<option value="">-- Lớp --</option>';
    return; // không render lớp nữa
  } else {
    // bật lại khi chọn khoa / bỏ chọn
    filterCourse.disabled = false;
    filterClass.disabled = false;
  }
  /* =====================
     GVCN
  ===================== */
  if (role === "gvcn" && gvcnScope) {

    // ❌ CHƯA CHỌN ĐỦ → KHÔNG HIỆN LỚP
    if (!deptId || !courseId) return;

    const list = gvcnScope.classes.filter(
      c =>
        c.department_id == deptId &&
        c.course_id == courseId
    );

    filterClass.innerHTML +=
      list.map(c => `<option value="${c.id}">${c.name}</option>`).join("");

    return;
  }

  /* =====================
     ADMIN / BÍ THƯ
  ===================== */
  let list = window.memberOptions.classes;

  if (deptId) {
    list = list.filter(c => c.department_id == deptId);
    if (courseId) {
      list = list.filter(c => c.course_id == courseId);
    }
  }

  filterClass.innerHTML +=
    list.map(c => `<option value="${c.id}">${c.name}</option>`).join("");
}



filterDept.addEventListener("change", () => {
  renderClassFilter();
  loadMembers(1);
});

filterCourse.addEventListener("change", () => {
  renderClassFilter();
  loadMembers(1);
});

filterClass.addEventListener("change", () => {
  loadMembers(1);
});



// === TÌM KIẾM TRỰC TIẾP ===
const searchInput = document.getElementById("memberSearch");
const filterSelect = document.getElementById("memberFilter");

let searchTimeout;

if (searchInput) {
  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(doSearch, 250);
  });
}
if (filterSelect) {
  filterSelect.addEventListener("change", doSearch);
}
// ✅ Ẩn đoàn viên ngừng theo dõi
if (hideStoppedCheckbox) {
  hideStoppedCheckbox.addEventListener("change", () => {
    loadMembers(1);
  });
}



async function doSearch() {
  loadMembers(1);
}

function getBithuScopeData() {
  const s = window.MEMBER_SCOPE;
  if (!s || s.role !== "bithu") return null;

  const dept = window.memberOptions?.departments
    ?.find(d => d.id == s.department_id) || null;

  const course = s.course_id
    ? window.memberOptions?.courses
      ?.find(c => c.id == s.course_id) || null
    : null;

  const cls = s.class_id
    ? window.memberOptions?.classes
      ?.find(c => c.id == s.class_id) || null
    : null;

  return {
    chidoan_group_id: Number(s.chidoan_group_id),
    department: dept,
    course: course,
    class: cls
  };
}

function getGvcnScopeData() {
  const s = window.MEMBER_SCOPE;
  if (!s || s.role !== "gvcn" || !Array.isArray(s.class_ids)) return null;

  const classes = window.memberOptions.classes.filter(c =>
    s.class_ids.includes(Number(c.id))
  );

  if (!classes.length) return null;

  // ✅ TẬP HỢP KHOA (unique)
  const departmentIds = [...new Set(classes.map(c => c.department_id))];
  const departments = window.memberOptions.departments
    .filter(d => departmentIds.includes(Number(d.id)));

  // ✅ TẬP HỢP KHÓA (unique)
  const courseIds = [...new Set(classes.map(c => c.course_id))];
  const courses = window.memberOptions.courses
    .filter(c => courseIds.includes(Number(c.id)));

  return {
    class_ids: s.class_ids,
    classes,
    departments,
    courses
  };
}




async function openMemberModal(id) {
  const isEdit = !!id;

  let data = {};
  let lockedEdit = false; // ✅ HOIST RA NGOÀI

  if (id) {
    const res = await api(`${MEMBER_API}?action=get&id=${id}`);
    data = await safeJson(res);
    lockedEdit = Number(data?.is_locked || 0) === 1; // ✅ LẤY Ở ĐÂY

  }

  const makeOptions = (list, selected) =>
    list
      .map(
        o =>
          `<option value="${o.id}" ${selected == o.id ? "selected" : ""
          }>${o.name}</option>`
      )
      .join("");


  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="memberForm" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <input type="hidden" name="action" value="${id ? "update" : "create"
    }">
      ${id ? `<input type="hidden" name="id" value="${id}">` : ""}

      <div>
        <label class="block text-sm">MSSV</label>
        <input name="mssv" value="${data.mssv ?? ""}" required
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Họ tên</label>
        <input name="fullname" value="${data.fullname ?? ""}" required
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Nhóm chi đoàn</label>
        <select name="chidoan_group_id" id="selectChidoanGroup"
          class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Chọn nhóm chi đoàn --</option>
        </select>
      </div>

      <div>
        <label class="block text-sm">Khoa/Phòng</label>
        <select name="department_id" id="selectDept"
          class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Chọn khoa --</option>

        </select>
      </div>


      <div>
        <label class="block text-sm">Khóa học</label>
        <select name="course_id" id="selectCourse"
          class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Chọn khóa học --</option>

        </select>
      </div>

      <div>
        <label class="block text-sm">Lớp</label>
        <select name="class_id" id="selectClass"
          class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Chọn lớp --</option>
        </select>
      </div>

      <div>
        <label class="block text-sm">Đối tượng</label>
        <select name="type" class="w-full px-3 py-2 border rounded-lg">
          <option value="member" ${data.type === "member" ? "selected" : ""
    }>Đoàn viên</option>
          <option value="youth" ${data.type === "youth" ? "selected" : ""
    }>Thanh niên</option>
        </select>
      </div>


<div id="joinDateWrap">
  <label class="block text-sm">Ngày vào Đoàn</label>
  <input type="date" name="join_date" id="joinDate"
    value="${data.join_date ?? ""}"
    class="w-full px-3 py-2 border rounded-lg">
</div>

      <div>
        <label class="block text-sm">Ngày sinh</label>
        <input type="date" name="birth" value="${data.birth ?? ""}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>
      <div>
        <label class="block text-sm">Dân tộc</label>
        <input name="ethnicity" value="${data.ethnicity ?? ""}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Tôn giáo</label>
        <input name="religion" value="${data.religion ?? ""}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">SĐT</label>
        <input name="phone" value="${data.phone ?? ""}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Email</label>
        <input type="email" name="email" value="${data.email ?? ""}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>
      <div>
        <label class="block text-sm">Nguyên quán (mô hình chính quyền 2 cấp)</label>
        <input name="native_place" value="${data.native_place ?? ""}"
        placeholder="Xã/Phường, Tỉnh/Thành Phố"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm">Nơi ở hiện tại (mô hình chính quyền 2 cấp)</label>
        <textarea name="current_address" rows="2"
        placeholder ="Số nhà, tên đường, Thôn/ Ấp/ Khu phố, Xã/Phường, Tỉnh/Thành Phố"
          class="w-full px-3 py-2 border rounded-lg resize-none"
        >${data.current_address ?? ""}</textarea>
      </div>


<!-- =======================
     ĐẢNG VIÊN
======================= -->
<div class="md:col-span-2">
  <label class="inline-flex items-center gap-2 cursor-pointer">
    <input type="checkbox" id="isPartyMember"
      ${data.party_probation_date || data.party_official_date ? "checked" : ""}
      class="w-4 h-4">
    <span class="text-sm font-medium">Là Đảng viên</span>
  </label>
</div>

<div id="partyDatesWrap" class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
  <div>
    <label class="block text-sm">Ngày dự bị</label>
    <input type="date" name="party_probation_date"
      value="${data.party_probation_date ?? ""}"
      class="w-full px-3 py-2 border rounded-lg">
  </div>

  <div>
    <label class="block text-sm">Ngày chính thức</label>
    <input type="date" name="party_official_date"
      value="${data.party_official_date ?? ""}"
      class="w-full px-3 py-2 border rounded-lg">
  </div>
</div>
      <div class="md:col-span-2 flex justify-end gap-2 mt-3">
        <button type="button" class="px-6 py-2 border rounded-lg"
          onclick="closeModal()">Hủy</button>
      <button class="px-6 py-2 bg-secondary text-white rounded-lg" ${lockedEdit ? "disabled" : ""}>
        ${lockedEdit ? "Đã khóa" : "Lưu"}
      </button>
      </div>
    </form>
  `;

  modal(wrap, id ? "Sửa đoàn viên" : "Thêm đoàn viên", "large");
  loadLucideIcons(); // 👈 BẮT BUỘC

  /* =========================
     LOGIC LỌC LỚP
  ========================= */
  const selectGroup = wrap.querySelector("#selectChidoanGroup");
  const selectDept = wrap.querySelector("#selectDept");
  const selectCourse = wrap.querySelector("#selectCourse");
  const selectClass = wrap.querySelector("#selectClass");
  const bithuScope = getBithuScopeData();
  const gvcnScope = getGvcnScopeData();


  const deptWrap = selectDept.closest("div");
  const courseWrap = selectCourse.closest("div");
  const classWrap = selectClass.closest("div");

  const typeSelect = wrap.querySelector('select[name="type"]');
  const joinWrap = wrap.querySelector("#joinDateWrap");
  const joinInput = wrap.querySelector("#joinDate");

  const chkParty = wrap.querySelector("#isPartyMember");
  const partyWrap = wrap.querySelector("#partyDatesWrap");
  const probationInput = wrap.querySelector('input[name="party_probation_date"]');
  const officialInput = wrap.querySelector('input[name="party_official_date"]');

  function togglePartyDates() {
    if (chkParty.checked) {
      partyWrap.style.display = "";
    } else {
      partyWrap.style.display = "none";

      // ⛔ clear để backend nhận NULL
      probationInput.value = "";
      officialInput.value = "";
    }
  }

  // =========================
  // 🔒 ẨN HẾT KHI CHƯA CHỌN NHÓM
  // =========================
  deptWrap.style.display = "none";
  courseWrap.style.display = "none";
  classWrap.style.display = "none";

  /* =========================
   RENDER NHÓM CHI ĐOÀN
========================= */
  const groups = window.memberOptions.chidoan_groups;

  // 👉 BÍ THƯ: CHỈ THẤY CHI ĐOÀN LỚP
  const filteredGroups =
    window.MEMBER_SCOPE?.role === "bithu"
      ? groups.filter(g => Number(g.id) === Number(window.MEMBER_SCOPE.chidoan_group_id))
      : window.MEMBER_SCOPE?.role === "gvcn"
        ? groups.filter(g => Number(g.id) === 1) // GVCN chỉ chi đoàn lớp
        : groups;


  selectGroup.innerHTML += filteredGroups
    .map(g => `<option value="${g.id}">${g.name}</option>`)
    .join("");


  function detectGroupFromData(data) {
    // EDIT
    if (data && data.chidoan_group_id) {
      return Number(data.chidoan_group_id);
    }

    // ADD + BÍ THƯ → LẤY THEO SCOPE
    if (window.MEMBER_SCOPE?.role === "bithu") {
      return Number(window.MEMBER_SCOPE.chidoan_group_id);
    }

    // ADMIN ADD → fallback lớp
    return 1;
  }



  const detectedGroup = detectGroupFromData(data);

  selectGroup.value = detectedGroup;
  applyChidoanGroup(detectedGroup);
  // === 🔥 FIX LOAD LẠI KHOA / KHÓA / LỚP KHI EDIT ===
  if (isEdit) {
    if (data.department_id) {
      selectDept.value = String(data.department_id);
    }

    if (data.course_id) {
      selectCourse.value = String(data.course_id);
    }

    // ✅ PHÂN NHÁNH RÕ
    if (gvcnScope) {
      // GVCN → render theo scope
      renderGvcnClasses();
    } else {
      // ADMIN / BÍ THƯ
      renderClasses();
    }

    if (data.class_id) {
      selectClass.value = String(data.class_id);
    }
  }


  /* =========================
     ÁP LUẬT THEO NHÓM CHI ĐOÀN
  ========================= */
  selectGroup.addEventListener("change", () => {
    applyChidoanGroup(Number(selectGroup.value));
  });

  function toggleJoinDate() {
    if (typeSelect.value === "youth") {
      joinWrap.style.display = "none";
      joinInput.value = ""; // ⛔ clear để backend nhận NULL
    } else {
      joinWrap.style.display = "";
    }
  }

  function applyChidoanGroup(gid) {

    // reset
    deptWrap.style.display = "none";
    courseWrap.style.display = "none";
    classWrap.style.display = "none";

    selectDept.innerHTML = '<option value="">-- Chọn khoa --</option>';
    selectCourse.innerHTML = '<option value="">-- Chọn khóa học --</option>';
    selectClass.innerHTML = '<option value="">-- Chọn lớp --</option>';

    if (!gid) return;

    /* =========================
       CHI ĐOÀN LỚP
    ========================= */
    if (gid === 1) {
      deptWrap.style.display = "";
      courseWrap.style.display = "";
      classWrap.style.display = "";

      // 🔒 BÍ THƯ → ÉP CỨNG THEO bithu_scopes
      if (bithuScope?.department && bithuScope?.course && bithuScope?.class) {

        selectDept.innerHTML =
          `<option value="${bithuScope.department.id}">
      ${bithuScope.department.name}
    </option>`;
        selectDept.value = bithuScope.department.id;
        selectDept.disabled = true;      // 🔒 KHÓA

        selectCourse.innerHTML =
          `<option value="${bithuScope.course.id}">
      ${bithuScope.course.name}
    </option>`;
        selectCourse.value = bithuScope.course.id;
        selectCourse.disabled = true;    // 🔒 KHÓA

        selectClass.innerHTML =
          `<option value="${bithuScope.class.id}">
      ${bithuScope.class.name}
    </option>`;
        selectClass.value = bithuScope.class.id;
        selectClass.disabled = true;     // 🔒 KHÓA

        selectGroup.disabled = true;     // 🔒 KHÓA

        return;
      }


      // 🔒 GVCN – cho chọn trong phạm vi các khoa / khóa / lớp được phân công
      if (gvcnScope) {

        deptWrap.style.display = "";
        courseWrap.style.display = "";
        classWrap.style.display = "";

        // ===== KHOA =====
        selectDept.innerHTML =
          '<option value="">-- Chọn khoa --</option>' +
          gvcnScope.departments
            .map(d => `<option value="${d.id}">${d.name}</option>`)
            .join("");

        // ===== KHÓA =====
        selectCourse.innerHTML =
          '<option value="">-- Chọn khóa học --</option>' +
          gvcnScope.courses
            .map(c => `<option value="${c.id}">${c.name}</option>`)
            .join("");

        // ===== LỚP (render theo khoa + khóa) =====
        function renderGvcnClassesFull() {
          const deptId = selectDept.value;
          const courseId = selectCourse.value;

          // ❌ CHƯA CHỌN ĐỦ → KHÔNG HIỆN LỚP
          if (!deptId || !courseId) {
            selectClass.innerHTML = '<option value="">-- Chọn lớp --</option>';
            return;
          }
          let list = gvcnScope.classes;

          if (deptId) {
            list = list.filter(c => c.department_id == deptId);
          }
          if (courseId) {
            list = list.filter(c => c.course_id == courseId);
          }

          selectClass.innerHTML =
            '<option value="">-- Chọn lớp --</option>' +
            list.map(c => `<option value="${c.id}">${c.name}</option>`).join("");
        }

        selectDept.onchange = renderGvcnClassesFull;
        selectCourse.onchange = renderGvcnClassesFull;

        // 🔒 khóa nhóm
        selectGroup.disabled = true;
        return;
      }



      // ===== ADMIN =====
      selectDept.innerHTML += window.memberOptions.departments
        .filter(d => d.type === "khoa")
        .map(d => `<option value="${d.id}">${d.name}</option>`)
        .join("");

      selectCourse.innerHTML += window.memberOptions.courses
        .map(c => `<option value="${c.id}">${c.name}</option>`)
        .join("");

      if (!bithuScope) {
        renderClasses();
      } return;
    }

    /* =========================
       CHI ĐOÀN GIÁO VIÊN
    ========================= */
    if (gid === 2) {
      deptWrap.style.display = "";

      const khoa = window.memberOptions.departments.filter(d => d.type === "khoa");
      const phong = window.memberOptions.departments.filter(d => d.type === "phong");

      selectDept.innerHTML = '<option value="">-- Chọn khoa / phòng --</option>';

      if (khoa.length) {
        selectDept.innerHTML += `
    <optgroup label="Khoa">
      ${khoa.map(d => `<option value="${d.id}">${d.name}</option>`).join("")}
    </optgroup>`;
      }

      if (phong.length) {
        selectDept.innerHTML += `
    <optgroup label="Phòng">
      ${phong.map(d => `<option value="${d.id}">${d.name}</option>`).join("")}
    </optgroup>`;
      }

      if (isEdit && data.department_id) {
        selectDept.value = String(data.department_id);
      }


      return;
    }


  }
  // chạy ngay khi mở modal (edit / add đều đúng)
  toggleJoinDate();
  togglePartyDates();
  chkParty.addEventListener("change", togglePartyDates);
  // lắng nghe thay đổi
  typeSelect.addEventListener("change", toggleJoinDate);


  function renderClasses() {
    // bí thư → ép cứng, không render lại
    if (bithuScope) return;

    const deptId = selectDept.value;
    const courseId = selectCourse.value;

    // ❌ CHƯA CHỌN KHOA HOẶC KHÓA → KHÔNG HIỆN LỚP
    if (!deptId || !courseId) {
      selectClass.innerHTML = '<option value="">-- Chọn lớp --</option>';
      return;
    }

    // ✅ CÓ ĐỦ KHOA + KHÓA → MỚI LỌC LỚP
    const list = window.memberOptions.classes.filter(
      c =>
        c.department_id == deptId &&
        c.course_id == courseId
    );

    selectClass.innerHTML =
      '<option value="">-- Chọn lớp --</option>' +
      list.map(c => `<option value="${c.id}">${c.name}</option>`).join("");
  }
  // ✅ ADMIN: đổi khoa / khóa => reload lớp
  if (!bithuScope && !gvcnScope) {
    selectDept.addEventListener("change", renderClasses);
    selectCourse.addEventListener("change", renderClasses);
  }

  function renderGvcnClasses() {
    const courseId = selectCourse.value;

    if (!courseId) {
      selectClass.innerHTML = '<option value="">-- Chọn lớp --</option>';
      return;
    }

    const list = window.memberOptions.classes.filter(
      c =>
        gvcnScope.class_ids.includes(Number(c.id)) &&
        c.course_id == courseId
    );

    selectClass.innerHTML =
      '<option value="">-- Chọn lớp --</option>' +
      list.map(c => `<option value="${c.id}">${c.name}</option>`).join("");
  }

  // ✅ ADMIN: đổi khoa / khóa => reload lớp
  if (!bithuScope && !gvcnScope) {
    selectDept.addEventListener("change", renderClasses);
    selectCourse.addEventListener("change", renderClasses);
  }

  /* =========================
     SUBMIT FORM
  ========================= */
  const form = wrap.querySelector("#memberForm");

  if (lockedEdit) {
    // ✅ KHÓA TOÀN BỘ INPUT/SELECT/TEXTAREA (trừ nút Hủy)
    form.querySelectorAll("input, select, textarea").forEach(el => {
      // giữ hidden để submit handler khỏi lỗi FormData lặt vặt
      if (el.type === "hidden") return;
      el.disabled = true;
      el.classList.add("opacity-60", "cursor-not-allowed");
    });

    // ✅ KHÓA CHECKBOX ĐẢNG VIÊN (nếu có)
    const chkParty = form.querySelector("#isPartyMember");
    if (chkParty) chkParty.disabled = true;

    // ✅ KHÓA NÚT LƯU
    const btnSave = form.querySelector('button[type="submit"]');
    if (btnSave) {
      btnSave.disabled = true;
      btnSave.classList.add("opacity-60", "cursor-not-allowed");
    }
  }


  form.addEventListener("submit", async e => {
    e.preventDefault();
    if (window.MEMBER_SCOPE?.role === "gvcn") {
      selectGroup.disabled = false;
      selectDept.disabled = false;
      selectCourse.disabled = false;
    }

    // 🔥 FIX: đảm bảo department_id tồn tại với chi đoàn GV
    if (Number(selectGroup.value) === 2 && !selectDept.value && isEdit && data.department_id) {
      selectDept.value = String(data.department_id);
    }
    const fd = new FormData(form);
    const res = await api(MEMBER_API, { method: "POST", body: fd });
    const text = await res.text();

    let json;
    try {
      json = JSON.parse(text);
    } catch {
      toast("Lỗi backend: " + text.substring(0, 100));
      return;
    }

    if (json.ok) {
      toast(id ? "Đã cập nhật đoàn viên" : "Đã thêm đoàn viên");
      closeModal();
      loadMembers(currentPage, false);

    } else {
      toast(json.error || "Lỗi khi lưu đoàn viên", "error");
    }

  });

}










async function delMember(id) {
  // Modal confirm (đồng bộ UI)
  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <div class="text-center space-y-4">
      <div class="text-sm text-gray-800">
        Bạn chắc chắn muốn xóa đoàn viên này?
      </div>

      <div class="flex justify-center gap-2 pt-2">
        <button class="px-4 py-2 border rounded-lg"
          type="button"
          onclick="closeModal()">
          Hủy
        </button>

        <button class="px-4 py-2 bg-red-600 text-white rounded-lg"
          type="button"
          id="btnYesDel">
          Xóa
        </button>
      </div>
    </div>
  `;

  modal(wrap, "Xóa đoàn viên", "small");

  const btnYes = wrap.querySelector("#btnYesDel");
  btnYes.onclick = async () => {
    // chống double click
    btnYes.disabled = true;
    btnYes.textContent = "Đang xóa...";

    try {
      const fd = new FormData();
      fd.append("action", "delete");
      fd.append("id", id);

      const res = await api(MEMBER_API, { method: "POST", body: fd });
      const json = await safeJson(res);

      if (json.ok) {
        toast("Đã xóa đoàn viên", "success");
        closeModal();

        // Nếu bạn muốn reload nguyên trang:
        // location.reload();

        // Tốt hơn: reload lại đúng trang hiện tại cho mượt
        loadMembers(currentPage, false);
      } else {
        toast(json.error || "Lỗi khi xóa", "error");
        btnYes.disabled = false;
        btnYes.textContent = "Xóa";
      }
    } catch (err) {
      toast(err.message || "Lỗi khi xóa", "error");
      btnYes.disabled = false;
      btnYes.textContent = "Xóa";
    }
  };
}



async function showQR(id) {
  const res = await api(`${MEMBER_API}?action=get&id=${id}`);
  const member = await res.json();

  if (!member || !member.fullname) {
    toast("Không tìm thấy dữ liệu đoàn viên");
    return;
  }

  // Format ngày tháng
  const birth = member.birth
    ? new Date(member.birth).toLocaleDateString("vi-VN")
    : "-";

  const joinDate = member.join_date
    ? new Date(member.join_date).toLocaleDateString("vi-VN")
    : "-";

  // 🚀 Payload FULL tiếng Việt, sạch đẹp
  const payload =
    `Họ tên: ${member.fullname}\n` +
    `MSSV: ${member.mssv || member.id}\n` +
    `Khoa: ${member.dept || "-"}\n` +
    `Lớp: ${member.class_name || member.class_name2 || "-"}\n` +
    `Ngày sinh: ${birth}\n` +
    `Ngày vào Đoàn: ${joinDate}`;

  const html = `
    <div class="flex flex-col items-center justify-center text-center min-h-[300px]">
      <canvas id="aztecCanvas" class="mb-5"></canvas>
      <div class="font-heading text-lg font-semibold">${member.fullname}</div>
      <div class="text-sm text-subtext mb-4">${member.mssv}</div>
      <button onclick="closeModal()" class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-blue-800 transition">
        Đóng
      </button>
    </div>
  `;

  modal(html, "Mã QR đoàn viên");
  loadLucideIcons(); // 👈 THÊM

  setTimeout(() => {
    const canvas = document.getElementById("aztecCanvas");
    if (!canvas) return;

    bwipjs.toCanvas(canvas, {
      bcid: 'azteccode',
      text: payload,
      scale: 2,
      version: 12,
      format: 'full'
    });
  }, 80);
}

(function injectIconBtnStyle() {
  if (document.getElementById("member-iconbtn-style")) return;
  const style = document.createElement("style");
  style.id = "member-iconbtn-style";
  style.textContent = `
    .icon-btn{
      display:inline-flex;align-items:center;justify-content:center;
      width:34px;height:34px;border-radius:10px;
      border:1px solid rgba(0,0,0,.08);
      background:#fff;
    }
    .icon-btn:hover{background:rgba(0,0,0,.04)}
    .icon-btn.danger{background:rgba(220,38,38,.08);border-color:rgba(220,38,38,.15)}
    .icon-btn.danger:hover{background:rgba(220,38,38,.14)}
    .icon-btn.dark{background:rgba(17,24,39,.92);border-color:rgba(17,24,39,.92);color:#fff}
    .icon-btn.dark:hover{background:rgba(17,24,39,1)}
    .icon-btn.success{background:rgba(22,163,74,.9);border-color:rgba(22,163,74,.9);color:#fff}
    .icon-btn.success:hover{background:rgba(22,163,74,1)}
    .icon-btn[disabled]{opacity:.45;cursor:not-allowed}
  `;
  document.head.appendChild(style);
})();

/* =========================================================
   MEMBERS REVIEW MODAL (Pagination + Search + Years from DB)
   Requires globals: api(url, opts), modal(node, title, size), closeModal(), toast(msg[, type, ms])

   Backend expected:
     - GET  controllers/members.php?action=review_search&page=1&q=...
       => { ok:true, rows:[{id,mssv,fullname,is_locked,class_name2,has_review}], page,totalPages,totalRows }

     - GET  controllers/members.php?action=review_get&id=...
       => { ok:1, member:{id,is_locked}, reviews:[{school_year,rating,note,lock_applied,lock_applied_at,reviewed_at}] }

     - GET  controllers/members.php?action=review_years
       => { ok:1, years:[{year_label}] OR years:["2023-2024", ...] }

     - POST controllers/members.php?action=review_save
       body JSON: { member_id, school_years:[{year_label, rating, note}] }
       => { ok:1, saved, deleted }

     - (ADMIN) POST controllers/members.php?action=review_unlock_year   { member_id, school_year }
========================================================= */

(function () {
  const btn = document.getElementById("btnReviewMembers");
  if (!btn) return;

  const API_BASE = "controllers/members.php";

  const ALLOWED_RATINGS = new Set(["excellent", "good", "completed", "incomplete"]);
  const RATING_LABEL = {
    excellent: "Xuất sắc",
    good: "Tốt",
    completed: "Hoàn thành",
    incomplete: "Chưa hoàn thành",
  };

  const isAdmin = () => String(window.MEMBER_SCOPE?.role || "") === "admin";

  btn.addEventListener("click", () => {
    openReviewMembersModal();
  });

  function escapeHtml(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function debounce(fn, wait = 300) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  }

  function getYearRowByLabel(yearsWrap, label) {
    const inputs = yearsWrap.querySelectorAll("[data-year-label]");
    for (const inp of inputs) {
      if (String(inp.value) === String(label)) {
        return inp.closest("[data-year-row]");
      }
    }
    return null;
  }

  function updateRowState(rowEl) {
    const reviewLocked = rowEl.dataset.reviewLocked === "1";
    const windowOpen = rowEl.dataset.windowOpen === "1";
    const windowActive = rowEl.dataset.windowActive !== "0";
    const windowClosed = rowEl.dataset.windowClosed === "1";

    // readonly nếu: năm không active OR window chưa mở OR review đã khóa
    const readonly = !windowActive || !windowOpen || reviewLocked;

    const sel = rowEl.querySelector("[data-year-rating]");
    const note = rowEl.querySelector("[data-year-note]");
    if (sel) sel.disabled = readonly;
    if (note) note.disabled = readonly;

    // class nền theo trạng thái
    rowEl.classList.toggle("opacity-70", readonly);
    rowEl.classList.toggle("bg-amber-50", reviewLocked);
    rowEl.classList.toggle("bg-slate-50", !reviewLocked && readonly);
    rowEl.classList.toggle("border-amber-200", reviewLocked);

    // badge
    const y = {
      is_active: Number(rowEl.dataset.windowActive || 1),
      is_open: Number(rowEl.dataset.windowOpen || 0),
      opened_at: rowEl.dataset.windowOpenedAt || null,
      closed_at: windowClosed ? (rowEl.dataset.windowClosedAt || "1") : null,
    };

    const badge = rowEl.querySelector("[data-year-badge]");
    if (badge) badge.innerHTML = badgeHtmlFromState(y, reviewLocked);

    const btnUnlock = rowEl.querySelector('[data-action="unlock-year"]');
    if (btnUnlock) btnUnlock.style.display = (isAdmin() && reviewLocked) ? "" : "none";
  }


  function applyPresetToRow(rowEl, preset) {
    const rating = String(preset?.rating || "");
    const note = String(preset?.note || "");
    const reviewLocked = Number(preset?.lock_applied || 0) === 1;

    const sel = rowEl.querySelector("[data-year-rating]");
    const inp = rowEl.querySelector("[data-year-note]");

    if (sel) sel.value = rating || "";
    if (inp) inp.value = note || "";

    rowEl.dataset.exists = preset ? "1" : "0";
    rowEl.dataset.reviewLocked = reviewLocked ? "1" : "0";

    updateRowState(rowEl);
  }


  function renderYearRowHtml(yearObj, preset) {
    const y = normalizeYear(yearObj);
    const label = y.year_label;

    const preRating = String(preset?.rating || "");
    const preNote = String(preset?.note || "");
    const reviewLocked = Number(preset?.lock_applied || 0) === 1;

    const windowActive = Number(y.is_active) === 1;
    const windowOpen = Number(y.is_open) === 1;

    const readonly = !windowActive || !windowOpen || reviewLocked;
    const badgeHtml = badgeHtmlFromState(y, reviewLocked);

    return `
    <div data-year-row
      data-exists="${preset ? "1" : "0"}"
      data-review-locked="${reviewLocked ? "1" : "0"}"
      data-window-active="${windowActive ? "1" : "0"}"
      data-window-open="${windowOpen ? "1" : "0"}"
      data-window-opened-at="${escapeHtml(String(y.opened_at || ""))}"
      data-window-closed="${y.closed_at ? "1" : "0"}"
      data-window-closed-at="${escapeHtml(String(y.closed_at || ""))}"
      class="border rounded-xl p-3 flex flex-col gap-3
        ${reviewLocked ? "bg-amber-50" : (readonly ? "bg-slate-50" : "")}
        ${readonly ? "opacity-70" : ""}">

      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-sm font-semibold text-slate-800">${escapeHtml(label)}</div>
          <div class="text-xs text-slate-500">Năm học</div>
        </div>

        <div class="flex items-center gap-2">
          <div data-year-badge>${badgeHtml}</div>

          ${isAdmin()
        ? `
              <button type="button"
                data-action="unlock-year"
                data-year="${escapeHtml(label)}"
                class="px-3 py-1.5 rounded-lg text-xs border bg-white hover:bg-slate-50"
                style="display:${reviewLocked ? "" : "none"}">
                Mở khóa
              </button>
            `
        : ``
      }
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium mb-1">Mức đánh giá</label>
          <select data-year-rating class="w-full px-3 py-2 border rounded-lg text-sm" ${readonly ? "disabled" : ""}>
            <option value="" ${preRating === "" ? "selected" : ""}>-- Chọn --</option>
            <option value="excellent" ${preRating === "excellent" ? "selected" : ""}>${RATING_LABEL.excellent}</option>
            <option value="good" ${preRating === "good" ? "selected" : ""}>${RATING_LABEL.good}</option>
            <option value="completed" ${preRating === "completed" ? "selected" : ""}>${RATING_LABEL.completed}</option>
            <option value="incomplete" ${preRating === "incomplete" ? "selected" : ""}>${RATING_LABEL.incomplete}</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium mb-1">Ghi chú (tuỳ chọn)</label>
          <input data-year-note
            class="w-full px-3 py-2 border rounded-lg text-sm"
            value="${escapeHtml(preNote)}"
            placeholder="Nhập ghi chú ngắn..."
            ${readonly ? "disabled" : ""} />
        </div>
      </div>

      <input type="hidden" data-year-label value="${escapeHtml(label)}" />
    </div>
  `;
  }


  function collectSavablePayload(yearsWrap) {
    const items = [];
    const rows = yearsWrap.querySelectorAll("[data-year-row]");

    rows.forEach((row) => {
      const windowActive = row.dataset.windowActive !== "0";
      const windowOpen = row.dataset.windowOpen === "1";
      const reviewLocked = row.dataset.reviewLocked === "1";

      // chỉ thao tác khi: active + window mở + chưa bị khóa review
      if (!windowActive || !windowOpen || reviewLocked) return;

      const label = row.querySelector("[data-year-label]")?.value || "";
      if (!label) return;

      const rating = row.querySelector("[data-year-rating]")?.value || "";
      const note = row.querySelector("[data-year-note]")?.value?.trim() || "";

      const existed = row.dataset.exists === "1"; // đã có record member_reviews trước đó?

      // ✅ rating rỗng => chỉ gửi để XÓA nếu trước đó đã có đánh giá
      if (!rating) {
        if (existed) {
          items.push({ year_label: label, rating: "", note: "" }); // note không quan trọng khi xóa
        }
        return;
      }

      // ✅ rating có giá trị => gửi để lưu/update
      items.push({ year_label: label, rating, note });
    });

    return items;
  }



  function openReviewMembersModal() {
    const wrap = document.createElement("div");
    wrap.innerHTML = `
  <div class="w-full">
    <div class="flex items-center justify-between gap-3 mb-3">
      <div class="text-sm text-slate-600">
        Chọn đoàn viên để đánh giá
      </div>

      <div class="flex items-center gap-2 w-[520px] max-w-full justify-end">
        <input id="rvSearch"
          class="flex-1 min-w-[220px] px-3 py-2 border rounded-lg text-sm"
          placeholder="Tìm theo MSSV / Họ tên / Lớp..." />

        ${isAdmin() ? `
          <button type="button" id="rvBtnOpenReview"
            class="px-3 py-2 rounded-lg text-sm border bg-white hover:bg-slate-50">
            Mở đánh giá
          </button>
        ` : ``}
      </div>
    </div>

    <div id="rvList" class="border rounded-xl overflow-hidden">
      <div class="p-4 text-sm text-slate-500">Đang tải...</div>
    </div>

    <div class="mt-3 flex items-center justify-between gap-3">
      <div id="rvInfo" class="text-sm text-slate-600">-</div>
      <div id="rvPager" class="flex items-center gap-1"></div>
    </div>
  </div>
`;


    modal(wrap, "Đánh giá đoàn viên", "large");

    const els = {
      q: wrap.querySelector("#rvSearch"),
      btnOpenReview: wrap.querySelector("#rvBtnOpenReview"),
      list: wrap.querySelector("#rvList"),
      info: wrap.querySelector("#rvInfo"),
      pager: wrap.querySelector("#rvPager"),
    };

    if (els.btnOpenReview) {
      els.btnOpenReview.addEventListener("click", () => openReviewWindowModal());
    }


    let STATE = { page: 1, totalPages: 1, totalRows: 0, q: "" };
    let pending = null;
    let expandedMemberId = null;

    const REVIEW_CACHE = new Map(); // memberId -> { __loaded, map, has }
    let ACTIVE_YEARS = null;
    let ACTIVE_YEARS_PROMISE = null;

    function invalidateActiveYearsCache() {
      ACTIVE_YEARS = null;
      ACTIVE_YEARS_PROMISE = null;
    }

    // GET list năm từ school_years + trạng thái window (member_review_windows)
    async function fetchReviewWindowYears() {
      const r = await api(`${API_BASE}?action=review_window_years`);
      const text = await r.text();
      let j = null;
      try { j = JSON.parse(text); } catch { j = null; }
      if (!j?.ok) throw new Error(j?.error || "Không tải được danh sách năm học");

      const years = Array.isArray(j.years) ? j.years : [];
      return years.map((x) => {
        if (typeof x === "string") {
          return { school_year_id: null, year_label: String(x), is_active: 1, is_open: 0, opened_at: null, closed_at: null };
        }
        return {
          school_year_id: x.school_year_id ?? x.id ?? null,
          year_label: String(x.year_label || "").trim(),
          is_active: Number(x.is_active ?? 1),
          is_open: Number(x.is_open ?? x.review_open ?? 0),
          opened_at: x.opened_at ?? null,
          closed_at: x.closed_at ?? null,
        };
      }).filter((y) => y.year_label);
    }

    async function postOpenClose(action, payload) {
      const r = await api(`${API_BASE}?action=${action}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await r.text();
      let j = null;
      try { j = JSON.parse(text); } catch { j = null; }

      if (r.status === 403) throw new Error("Không có quyền (admin).");
      if (!j?.ok) throw new Error(j?.error || "Thao tác thất bại");
      return j;
    }

    async function openReviewWindowModal() {
      if (!isAdmin()) return;

      const box = document.createElement("div");
      box.innerHTML = `
    <div class="w-full">
      <div class="mb-2 text-sm text-slate-600">
        Chọn năm học để <b>Mở</b> hoặc <b>Đóng</b> đánh giá.
        <div class="text-xs text-slate-500 mt-1">
          Gợi ý: mỗi năm chỉ nên mở 1 lần. Đóng rồi thì không nên mở lại.
        </div>
      </div>

      <div class="flex items-center justify-between gap-2 mb-3">
        <div id="rwHint" class="text-xs text-slate-500">-</div>
        <button type="button" id="rwRefresh"
          class="px-3 py-1.5 rounded-lg text-sm border bg-white hover:bg-slate-50">
          Tải lại
        </button>
      </div>

      <div id="rwList" class="border rounded-xl overflow-hidden">
        <div class="p-4 text-sm text-slate-500">Đang tải...</div>
      </div>
    </div>
  `;

      modal(box, "Mở đánh giá", "medium");

      const $list = box.querySelector("#rwList");
      const $hint = box.querySelector("#rwHint");
      const $refresh = box.querySelector("#rwRefresh");

      let CURRENT = [];

      function badgeHtml(y) {
        if (y.is_active !== 1) {
          return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-slate-200 text-slate-700">Ngừng</span>`;
        }
        if (y.is_open === 1) {
          return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-800">Đang mở</span>`;
        }
        if (y.closed_at) {
          return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-900">Đã đóng</span>`;
        }
        if (y.opened_at) {
          return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-sky-100 text-sky-900">Đã mở</span>`;
        }
        return `<span class="inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700">Chưa mở</span>`;
      }

      function render() {
        const openYear = CURRENT.find((y) => y.is_open === 1);
        $hint.textContent = openYear
          ? `Năm đang mở: ${openYear.year_label}`
          : `Hiện chưa mở năm nào để đánh giá`;

        if (!CURRENT.length) {
          $list.innerHTML = `<div class="p-4 text-sm text-slate-500">Không có dữ liệu năm học</div>`;
          return;
        }

        $list.innerHTML = CURRENT.map((y) => {
          const canOpen = (y.is_active === 1) && (y.is_open !== 1); // cho mở lại sau khi đóng
          const canClose = (y.is_open === 1);

          const disabledOpen = canOpen ? "" : "disabled";
          const disabledClose = canClose ? "" : "disabled";

          return `
        <div class="border-b last:border-b-0 p-3 flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-slate-800">${escapeHtml(y.year_label)}</div>
            <div class="text-xs text-slate-500 mt-1">${badgeHtml(y)}</div>
          </div>

          <div class="flex items-center gap-2">
            <button type="button"
              data-act="open"
              data-id="${escapeHtml(String(y.school_year_id ?? ""))}"
              data-year="${escapeHtml(y.year_label)}"
              class="px-3 py-1.5 rounded-lg text-sm border bg-white hover:bg-slate-50 ${disabledOpen ? "opacity-50 cursor-not-allowed" : ""}"
              ${disabledOpen}>
              Mở
            </button>

            <button type="button"
              data-act="close"
              data-id="${escapeHtml(String(y.school_year_id ?? ""))}"
              data-year="${escapeHtml(y.year_label)}"
              class="px-3 py-1.5 rounded-lg text-sm bg-slate-900 text-white hover:bg-slate-800 ${disabledClose ? "opacity-50 cursor-not-allowed" : ""}"
              ${disabledClose}>
              Đóng
            </button>
          </div>
        </div>
      `;
        }).join("");
      }

      async function reload() {
        $list.innerHTML = `<div class="p-4 text-sm text-slate-500">Đang tải...</div>`;
        try {
          CURRENT = await fetchReviewWindowYears();
          render();
        } catch (e) {
          $list.innerHTML = `<div class="p-4 text-sm text-red-600">${escapeHtml(e?.message || "Lỗi tải")}</div>`;
        }
      }

      $refresh.addEventListener("click", reload);

      $list.addEventListener("click", async (e) => {
        const b = e.target.closest("button[data-act]");
        if (!b) return;

        const act = b.getAttribute("data-act");
        const idRaw = b.getAttribute("data-id");
        const year = b.getAttribute("data-year") || "";

        b.disabled = true;

        try {
          // ưu tiên school_year_id, fallback year_label
          const payload = (idRaw && String(idRaw).trim() !== "")
            ? { school_year_id: Number(idRaw) }
            : { year_label: String(year) };

          if (act === "open") {
            await postOpenClose("review_window_open", payload);
            toast(`Đã mở đánh giá: ${year}`, "success");
          } else if (act === "close") {
            await postOpenClose("review_window_close", payload);
            toast(`Đã đóng đánh giá: ${year}`, "success");
          }

          // cập nhật cache năm đang mở để modal đánh giá đoàn viên render đúng
          invalidateActiveYearsCache();
          await reload();

          // nếu đang expand 1 member thì refresh lại block years luôn
          if (expandedMemberId) {
            const exp = els.list.querySelector(`#rvExpand_${expandedMemberId}`);
            if (exp) exp.dataset.prefilled = "0";
            REVIEW_CACHE.delete(String(expandedMemberId));
            await prefillExpandFromExisting(expandedMemberId);
          }

        } catch (err) {
          toast(err?.message || "Thao tác thất bại", "error");
        } finally {
          b.disabled = false;
        }
      });

      reload();
    }

    function reviewsToMap(reviews) {
      const map = {};
      (reviews || []).forEach((r) => {
        const sy = String(r.school_year || "").trim();
        if (!sy) return;
        map[sy] = {
          rating: String(r.rating || ""),
          note: String(r.note || ""),
          lock_applied: Number(r.lock_applied || 0),
          lock_applied_at: r.lock_applied_at || null,
          reviewed_at: r.reviewed_at || null,
        };
      });
      return map;
    }

    async function fetchYearsOnce() {
      if (ACTIVE_YEARS) return ACTIVE_YEARS;
      if (ACTIVE_YEARS_PROMISE) return ACTIVE_YEARS_PROMISE;

      ACTIVE_YEARS_PROMISE = (async () => {
        const r = await api(`${API_BASE}?action=review_years`);
        const text = await r.text();
        let j = null;
        try { j = JSON.parse(text); } catch { j = null; }

        if (!j?.ok) return [];

        const years = Array.isArray(j.years) ? j.years : [];
        const normalized = years.map(normalizeYear).filter((y) => y.year_label);

        ACTIVE_YEARS = normalized;
        return ACTIVE_YEARS;
      })();

      return ACTIVE_YEARS_PROMISE;
    }


    async function fetchReviewGet(memberId) {
      const cached = REVIEW_CACHE.get(String(memberId));
      if (cached && cached.__loaded) return cached;

      const url = `${API_BASE}?action=review_get&id=${encodeURIComponent(memberId)}`;
      try {
        const r = await api(url);
        const text = await r.text();
        const j = JSON.parse(text);

        if (!j || !j.ok) {
          REVIEW_CACHE.set(String(memberId), { __loaded: true, map: {}, has: false });
          return REVIEW_CACHE.get(String(memberId));
        }

        const map = reviewsToMap(j.reviews || []);
        const has = Object.keys(map).length > 0;
        const obj = { __loaded: true, map, has, raw: j };
        REVIEW_CACHE.set(String(memberId), obj);
        return obj;
      } catch {
        REVIEW_CACHE.set(String(memberId), { __loaded: true, map: {}, has: false });
        return REVIEW_CACHE.get(String(memberId));
      }
    }

    async function prefillExpandFromExisting(memberId) {
      const box = els.list.querySelector(`#rvExpand_${memberId}`);
      if (!box) return;
      if (box.dataset.prefilled === "1") return;
      box.dataset.prefilled = "1";

      const yearsWrap = els.list.querySelector(`#rvYears_${memberId}`);
      if (!yearsWrap) return;

      yearsWrap.innerHTML = `<div data-placeholder class="text-sm text-slate-500">Đang tải năm học...</div>`;

      const [years, data] = await Promise.all([
        fetchYearsOnce(),
        fetchReviewGet(memberId),
      ]);

      if (!years.length) {
        yearsWrap.innerHTML = `<div data-placeholder class="text-sm text-slate-500">
  Admin chưa mở năm để đánh giá. (Vào nút “Mở đánh giá” để bật)
</div>`;
        return;
      }

      yearsWrap.innerHTML = years
        .map((y) => renderYearRowHtml(y, data.map[y.year_label] || null))
        .join("");

      // apply preset + state
      years.forEach((y) => {
        const label = y.year_label;
        const row = getYearRowByLabel(yearsWrap, label);
        if (!row) return;

        // set window meta lại cho chắc (phòng DOM bị thiếu)
        row.dataset.windowActive = Number(y.is_active) === 1 ? "1" : "0";
        row.dataset.windowOpen = Number(y.is_open) === 1 ? "1" : "0";
        row.dataset.windowOpenedAt = y.opened_at ? String(y.opened_at) : "";
        row.dataset.windowClosed = y.closed_at ? "1" : "0";
        row.dataset.windowClosedAt = y.closed_at ? String(y.closed_at) : "";

        const preset = data.map[label] || null;
        if (preset) applyPresetToRow(row, preset);
        else {
          row.dataset.exists = "0";
          row.dataset.reviewLocked = "0";
          updateRowState(row);
        }
      });


      const rowBtn = els.list.querySelector(`button[data-id="${memberId}"]`);
      if (rowBtn) {
        if (data.has) rowBtn.classList.add("bg-amber-50", "hover:bg-amber-100");
        else {
          rowBtn.classList.remove("bg-amber-50", "hover:bg-amber-100");
          rowBtn.classList.add("hover:bg-slate-50");
        }
      }
    }

    async function load(page, q) {
      if (pending) pending.abort();
      pending = new AbortController();

      els.list.innerHTML = `<div class="p-4 text-sm text-slate-500">Đang tải...</div>`;

      const url =
        `${API_BASE}?action=review_search&page=${encodeURIComponent(page)}` +
        `&q=${encodeURIComponent(q || "")}`;

      let r, text, j;
      try {
        r = await api(url, { signal: pending.signal });
        text = await r.text();
        j = JSON.parse(text);
      } catch (e) {
        if (e?.name === "AbortError") return;
        els.list.innerHTML = `<div class="p-4 text-sm text-red-600">Lỗi tải dữ liệu</div>`;
        return;
      }

      if (!j?.ok) {
        els.list.innerHTML = `<div class="p-4 text-sm text-red-600">${escapeHtml(j?.error || "Không thể tải")}</div>`;
        return;
      }

      STATE.page = Number(j.page || 1);
      STATE.totalPages = Number(j.totalPages || 1);
      STATE.totalRows = Number(j.totalRows || 0);
      STATE.q = q || "";

      expandedMemberId = null;
      renderList(j.rows || []);
      renderPager();
    }

    function renderList(rows) {
      if (!rows.length) {
        els.list.innerHTML = `<div class="p-4 text-sm text-slate-500">Không có kết quả</div>`;
        els.info.textContent = `0 kết quả`;
        return;
      }

      els.info.textContent = `Trang ${STATE.page}/${STATE.totalPages} • Tổng ${STATE.totalRows} đoàn viên`;

      let lastClass = null;
      const parts = [];

      const classLabel = (x) => {
        const s = String(x || "").trim();
        return s ? s : "Chưa có lớp";
      };

      rows.forEach((m) => {
        const locked = Number(m.is_locked || 0) === 1;
        const reviewed = Number(m.has_review || 0) === 1;

        const cls = classLabel(m.class_name2);
        if (cls !== lastClass) {
          lastClass = cls;
          parts.push(`
        <div class="px-4 py-2 text-xs font-semibold bg-slate-50 text-slate-700 border-b">
          ${escapeHtml(cls)}
        </div>
      `);
        }

        const badge = locked
          ? `<span class="text-xs font-semibold text-red-600">Đang khóa hồ sơ</span>`
          : `<span class="text-xs font-semibold text-emerald-600">Đang mở hồ sơ</span>`;

        const sub = `MSSV: ${escapeHtml(m.mssv)} • ${escapeHtml(cls)}`;
        const rowBgClass = reviewed ? "bg-amber-50 hover:bg-amber-100" : "hover:bg-slate-50";

        parts.push(`
      <div class="border-b last:border-b-0">
        <button type="button"
          data-id="${m.id}"
          data-name="${escapeHtml(m.fullname)}"
          class="relative w-full text-left px-4 py-3 pr-28 ${rowBgClass}">
          
          <div class="font-semibold text-slate-800">${escapeHtml(m.fullname)}</div>
          <div class="text-sm text-slate-500">${sub}</div>

          <div class="absolute top-2 right-3">
            ${badge}
          </div>
        </button>

        <div id="rvExpand_${m.id}" class="hidden px-4 pb-4">
          ${renderExpandFormHtml(m.id)}
        </div>
      </div>
    `);
      });

      els.list.innerHTML = parts.join("");

      els.list.querySelectorAll("button[data-id]").forEach((btnRow) => {
        btnRow.addEventListener("click", () => {
          const id = String(btnRow.getAttribute("data-id") || "");
          toggleExpand(id);
        });
      });
    }


    function renderExpandFormHtml(memberId) {
      return `
        <div class="mt-3 rounded-xl border bg-white p-4">
          <div class="flex items-center justify-end">
            <button type="button"
              id="rvSave_${memberId}"
              class="px-4 py-2 rounded-lg text-sm bg-indigo-600 text-white hover:bg-indigo-700">
              Lưu đánh giá
            </button>
          </div>

          <div id="rvYears_${memberId}" class="mt-4 space-y-2">
            <div data-placeholder class="text-sm text-slate-500">Đang tải năm học...</div>
          </div>
        </div>
      `;
    }

    function toggleExpand(memberId) {
      if (expandedMemberId && expandedMemberId !== memberId) {
        const prev = els.list.querySelector(`#rvExpand_${expandedMemberId}`);
        if (prev) prev.classList.add("hidden");
      }

      const box = els.list.querySelector(`#rvExpand_${memberId}`);
      if (!box) return;

      const isOpen = !box.classList.contains("hidden");
      if (isOpen) {
        box.classList.add("hidden");
        expandedMemberId = null;
        return;
      }

      box.classList.remove("hidden");
      expandedMemberId = memberId;

      wireExpandEvents(memberId);
      prefillExpandFromExisting(memberId);
    }

    async function adminUnlockYear(memberId, yearLabel) {
      const r = await api(`${API_BASE}?action=review_unlock_year`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ member_id: Number(memberId), school_year: String(yearLabel) }),
      });
      const text = await r.text();
      let j = null;
      try { j = JSON.parse(text); } catch { j = null; }

      if (r.status === 403) throw new Error("Không có quyền (admin).");
      if (!j?.ok) throw new Error(j?.error || "Mở khóa thất bại");
      return j;
    }

    function wireYearActionsOnce(memberId, yearsWrap) {
      if (yearsWrap.__wiredActions) return;
      yearsWrap.__wiredActions = true;

      yearsWrap.addEventListener("click", async (e) => {
        const btnUnlock = e.target.closest('[data-action="unlock-year"]');
        if (!btnUnlock) return;

        if (!isAdmin()) return;
        const year = btnUnlock.getAttribute("data-year") || "";
        if (!year) return;

        btnUnlock.disabled = true;
        try {
          await adminUnlockYear(memberId, year);

          // refresh cache + update row
          REVIEW_CACHE.delete(String(memberId));
          const data = await fetchReviewGet(memberId);

          const row = getYearRowByLabel(yearsWrap, year);
          if (row) {
            const preset = data.map[year] || null;
            if (preset) applyPresetToRow(row, preset);
            else {
              row.querySelector("[data-year-rating]").value = "";
              row.querySelector("[data-year-note]").value = "";
              row.dataset.exists = "0";
              row.dataset.reviewLocked = "0";
              updateRowState(row);
            }

          }

          toast(`Đã mở khóa ${year}`, "success");
        } catch (err) {
          toast(err?.message || "Không mở khóa được", "error");
        } finally {
          btnUnlock.disabled = false;
        }
      });
    }

    function wireExpandEvents(memberId) {
      const btnSave = els.list.querySelector(`#rvSave_${memberId}`);
      const yearsWrap = els.list.querySelector(`#rvYears_${memberId}`);

      if (!btnSave || !yearsWrap) return;
      if (btnSave.__wired) return;

      btnSave.__wired = true;
      wireYearActionsOnce(memberId, yearsWrap);

      btnSave.addEventListener("click", async () => {
        const payload = collectSavablePayload(yearsWrap);

        if (!payload.length) {
          toast("Không có thay đổi để lưu.", "error");
          return;
        }


        const bad = payload.find((x) => x.rating && !ALLOWED_RATINGS.has(x.rating));
        if (bad) {
          toast("Mức đánh giá không hợp lệ.", "error");
          return;
        }

        const btnText = btnSave.textContent;
        btnSave.disabled = true;
        btnSave.classList.add("opacity-60", "cursor-not-allowed");
        btnSave.textContent = "Đang lưu...";

        try {
          const r = await api(`${API_BASE}?action=review_save`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              member_id: Number(memberId),
              school_years: payload,
            }),
          });

          const text = await r.text();
          let j = null;
          try { j = JSON.parse(text); } catch { j = null; }

          if (r.status === 403) {
            toast("Bạn không có quyền đánh giá (members.review).", "error");
            return;
          }

          if (!j?.ok) {
            toast(j?.error || "Lưu đánh giá thất bại", "error");
            return;
          }

          // refresh cache + apply lock state theo DB
          REVIEW_CACHE.delete(String(memberId));
          const after = await fetchReviewGet(memberId);

          // update rows theo preset sau lưu
          const rows = yearsWrap.querySelectorAll("[data-year-row]");
          rows.forEach((row) => {
            const label = row.querySelector("[data-year-label]")?.value || "";
            if (!label) return;

            const preset = after.map[label];
            if (preset) {
              applyPresetToRow(row, preset); // sẽ lock ngay vì lock_applied=1
            } else {
              row.dataset.exists = "0";
              row.dataset.reviewLocked = "0";
              row.querySelector("[data-year-rating]").value = "";
              row.querySelector("[data-year-note]").value = "";
              updateRowState(row);
            }

          });

          // highlight list row
          const rowBtn = els.list.querySelector(`button[data-id="${memberId}"]`);
          if (rowBtn) {
            if (after.has) rowBtn.classList.add("bg-amber-50", "hover:bg-amber-100");
            else {
              rowBtn.classList.remove("bg-amber-50", "hover:bg-amber-100");
              rowBtn.classList.add("hover:bg-slate-50");
            }
          }

          const saved = Number(j.saved || 0);
          const deleted = Number(j.deleted || 0);

          const parts = [];
          if (saved) parts.push(`lưu ${saved} năm`);
          if (deleted) parts.push(`xóa ${deleted} năm`);
          const detail = parts.length ? ` (${parts.join(", ")})` : "";

          toast(`Đã cập nhật đánh giá${detail}. Các năm đã lưu được khóa ngay.`, "success", 3500);
        } catch (e) {
          toast("Lỗi kết nối khi lưu đánh giá", "error");
        } finally {
          btnSave.disabled = false;
          btnSave.classList.remove("opacity-60", "cursor-not-allowed");
          btnSave.textContent = btnText;
        }
      });
    }

    function renderPager() {
      const { page, totalPages } = STATE;

      const windowSize = 5;
      let start = Math.max(1, page - Math.floor(windowSize / 2));
      let end = Math.min(totalPages, start + windowSize - 1);
      start = Math.max(1, end - windowSize + 1);

      const parts = [];

      const btnHtml = (label, p, disabled = false, active = false) => `
        <button type="button"
          ${disabled ? "disabled" : ""}
          data-page="${p}"
          class="px-3 py-1.5 rounded-lg text-sm border
            ${active ? "bg-slate-900 text-white border-slate-900" : "bg-white hover:bg-slate-50"}
            ${disabled ? "opacity-50 cursor-not-allowed" : ""}">
          ${label}
        </button>
      `;

      parts.push(btnHtml("Trước", page - 1, page <= 1));

      if (start > 1) {
        parts.push(btnHtml("1", 1, false, page === 1));
        if (start > 2) parts.push(`<span class="px-1 text-slate-400">…</span>`);
      }

      for (let p = start; p <= end; p++) {
        parts.push(btnHtml(String(p), p, false, p === page));
      }

      if (end < totalPages) {
        if (end < totalPages - 1) parts.push(`<span class="px-1 text-slate-400">…</span>`);
        parts.push(btnHtml(String(totalPages), totalPages, false, page === totalPages));
      }

      parts.push(btnHtml("Sau", page + 1, page >= totalPages));

      els.pager.innerHTML = parts.join("");

      els.pager.querySelectorAll("button[data-page]").forEach((b) => {
        b.addEventListener("click", () => {
          const p = Number(b.getAttribute("data-page"));
          if (!p || p < 1 || p > STATE.totalPages || p === STATE.page) return;
          load(p, STATE.q);
        });
      });
    }

    els.q.addEventListener(
      "input",
      debounce(() => {
        const q = els.q.value.trim();
        load(1, q);
      }, 300)
    );

    // preload years sẵn (không bắt buộc)
    fetchYearsOnce();

    load(1, "");
  }
})();
