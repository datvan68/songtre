const API = "controllers/reward_units.php";
const PAGE_SIZE = {
  position: 5,
  department: 5,
  title: 5,
  group: 5,
  chidoan: 10,
  school_year: 5
};
function getPageSize(type) {
  return PAGE_SIZE[type] || 10;
}

/* =====================
   STATE
===================== */
const pager = {
  position: { page: 1, total: 1, data: [] },
  department: { page: 1, total: 1, data: [] },
  title: { page: 1, total: 1, data: [] },
  group: { page: 1, total: 1, data: [] },
  chidoan: { page: 1, total: 1, data: [] },
  school_year: { page: 1, total: 1, data: [] }
};

/* =====================
   INIT
===================== */
document.addEventListener("DOMContentLoaded", () => {
  loadPositions();
  loadDepartments();
  loadGroups();
  loadTitles();
  loadChidoans();
  loadSchoolYears();
});

function reloadPager(type, data, keepPage = true) {
  const p = pager[type];

  p.data = data || [];
  const size = getPageSize(type);
  p.total = Math.max(1, Math.ceil(p.data.length / size));

  if (!keepPage) {
    p.page = 1;
  } else if (p.page > p.total) {
    p.page = p.total;
  }

  render(type);
}

/* =====================
   LOAD DATA (FETCH 1 LẦN)
===================== */
async function loadPositions(keepPage = true) {
  const data = await apiFetch(`${API}?action=list_positions`);
  reloadPager("position", data, keepPage);
}

async function loadGroups(keepPage = true) {
  const data = await apiFetch(`${API}?action=list_groups`);
  reloadPager("group", data, keepPage);
}

async function loadDepartments(keepPage = true) {
  const data = await apiFetch(`${API}?action=list_departments`);
  reloadPager("department", data, keepPage);
}

async function loadTitles(keepPage = true) {
  const data = await apiFetch(`${API}?action=list_titles`);
  reloadPager("title", data, keepPage);
}

async function loadChidoans(keepPage = true) {
  const data = await apiFetch(`${API}?action=list_chidoans`);
  reloadPager("chidoan", data, keepPage);
}

async function loadUnits() {
  return await apiFetch(`${API}?action=list_units`);
}

// ✅ NĂM HỌC
async function loadSchoolYears(keepPage = true) {
  const data = await apiFetch(`${API}?action=list_school_years`);
  reloadPager("school_year", data, keepPage);
}

/* =====================
   RENDER LIST
===================== */
function renderPosition() {
  const { page, data, total } = pager.position;

  const size = getPageSize("position");
  const start = (page - 1) * size;
  const items = data.slice(start, start + size);

  document.getElementById("positionList").innerHTML = items.map(p => `
    <li class="flex items-start justify-between gap-3 py-2">

      <div class="flex-1 break-words">
        <div class="font-medium text-sm leading-snug">
          ${escapeHtml(p.name)}
        </div>
      </div>

      <div class="flex shrink-0 gap-2 text-sm whitespace-nowrap">
        ${PERM.update ? `
          <button class="text-blue-600"
            onclick="openPositionForm(${p.id}, '${escapeHtml(p.name)}')">
            Sửa
          </button>
        ` : ``}

        ${PERM.delete ? `
          <button class="text-red-600"
            onclick="confirmDelete('position', ${p.id})">
            Xóa
          </button>
        ` : ``}
      </div>

    </li>
  `).join("");

  renderPagination("position", page, total);
}

async function toggleTitle(id) {
  try {
    await api(API, {
      method: "POST",
      body: new URLSearchParams({
        action: "toggle_title",
        id
      })
    });

    toast("Đã cập nhật trạng thái", "success");
    loadTitles(true);

  } catch (err) {
    toast("Không thể cập nhật", "error");
  }
}

function renderTitle() {
  const { page, data, total } = pager.title;

  const size = getPageSize("title");
  const start = (page - 1) * size;
  const items = data.slice(start, start + size);

  document.getElementById("titleList").innerHTML = items.map(t => `
    <li class="flex items-start justify-between gap-3 py-2">

      <!-- LEFT -->
      <div class="flex-1 break-words">
        <div class="font-medium text-sm leading-snug
          ${t.is_active == 0 ? 'line-through text-gray-400' : ''}">
          ${escapeHtml(t.name)}
        </div>
      </div>

      <!-- RIGHT -->
      <div class="flex shrink-0 gap-2 text-sm whitespace-nowrap">

        ${PERM.update ? `
          <button
            class="${t.is_active == 1 ? 'text-gray-500' : 'text-green-600'}"
            onclick="toggleTitle(${t.id})">
            ${t.is_active == 1 ? 'Ẩn' : 'Hiện'}
          </button>
        ` : ``}

        ${PERM.update ? `
          <button class="text-blue-600"
            onclick="openTitleForm(${t.id}, '${escapeHtml(t.name)}')">
            Sửa
          </button>
        ` : ``}

        ${PERM.delete ? `
          <button class="text-red-600"
            onclick="confirmDelete('title', ${t.id})">
            Xóa
          </button>
        ` : ``}

      </div>

    </li>
  `).join("");

  renderPagination("title", page, total);
}

function renderGroup() {
  const { page, data, total } = pager.group;

  const size = getPageSize("group");
  const start = (page - 1) * size;
  const items = data.slice(start, start + size);

  document.getElementById("groupList").innerHTML = items.map(g => `
    <li class="flex items-start justify-between gap-3 py-2">

      <!-- LEFT -->
      <div class="flex-1 break-words">
        <div class="font-medium text-sm leading-snug">
          ${escapeHtml(g.name)}
        </div>
      </div>

      <!-- RIGHT -->
      <div class="flex shrink-0 gap-2 text-sm whitespace-nowrap">
        ${PERM.update ? `
          <button class="text-blue-600"
            onclick="openGroupForm(${g.id}, '${escapeHtml(g.name)}')">
            Sửa
          </button>
        ` : ``}

        ${PERM.delete ? `
          <button class="text-red-600"
            onclick="confirmDelete('group', ${g.id})">
            Xóa
          </button>
        ` : ``}
      </div>

    </li>
  `).join("");

  renderPagination("group", page, total);
}

function renderDepartment() {
  const { page, data, total } = pager.department;

  const size = getPageSize("department");
  const start = (page - 1) * size;
  const items = data.slice(start, start + size);

  document.getElementById("departmentList").innerHTML = items.map(d => `
    <li class="flex items-start justify-between gap-3 py-2">

      <!-- LEFT -->
      <div class="flex-1 break-words">
        <div class="font-medium text-sm leading-snug">
          ${escapeHtml(d.name)}
        </div>
      </div>

      <!-- RIGHT -->
      <div class="flex shrink-0 gap-2 text-sm whitespace-nowrap">
        ${PERM.update ? `
          <button class="text-blue-600"
            onclick="openDepartmentForm(${d.id}, '${escapeHtml(d.name)}')">
            Sửa
          </button>
        ` : ``}

        ${PERM.delete ? `
          <button class="text-red-600"
            onclick="confirmDelete('department', ${d.id})">
            Xóa
          </button>
        ` : ``}
      </div>

    </li>
  `).join("");

  renderPagination("department", page, total);
}

function renderChidoan() {
  const { page, data, total } = pager.chidoan;

  const size = getPageSize("chidoan");
  const start = (page - 1) * size;
  const items = data.slice(start, start + size);

  document.getElementById("chidoanList").innerHTML = items.map(c => `
    <li class="flex items-start justify-between gap-3 py-2">
      
      <!-- LEFT -->
      <div class="flex-1 break-words">
        <div class="font-medium text-sm leading-snug">
          ${escapeHtml(c.display_name)}
        </div>
        <div class="text-xs text-gray-500">
          ${escapeHtml(c.group_name)}
        </div>
      </div>

      <!-- RIGHT -->
      <div class="flex shrink-0 gap-2 text-sm whitespace-nowrap">
        ${PERM.update ? `
          <button class="text-blue-600"
            onclick="openChidoanForm(${c.id}, ${c.unit_id}, '${c.unit_type}', ${c.group_id})">
            Sửa
          </button>
        ` : ``}

        ${PERM.delete ? `
          <button class="text-red-600"
            onclick="confirmDelete('chidoan', ${c.id})">
            Xóa
          </button>
        ` : ``}
      </div>

    </li>
  `).join("");

  renderPagination("chidoan", page, total);
}

/* =====================
   ✅ NĂM HỌC
===================== */
async function toggleSchoolYear(id) {
  try {
    await api(API, {
      method: "POST",
      body: new URLSearchParams({
        action: "toggle_school_year",
        id
      })
    });

    toast("Đã cập nhật trạng thái", "success");
    loadSchoolYears(true);

  } catch (err) {
    toast("Không thể cập nhật", "error");
  }
}

function renderSchoolYear() {
  const { page, data, total } = pager.school_year;

  const size = getPageSize("school_year");
  const start = (page - 1) * size;
  const items = data.slice(start, start + size);

  document.getElementById("schoolYearList").innerHTML = items.map(s => `
    <li class="flex items-start justify-between gap-3 py-2">

      <!-- LEFT -->
      <div class="flex-1 break-words">
        <div class="font-medium text-sm leading-snug
          ${s.is_active == 0 ? 'line-through text-gray-400' : ''}">
          ${escapeHtml(s.year_label)}
        </div>
      </div>

      <!-- RIGHT -->
      <div class="flex shrink-0 gap-2 text-sm whitespace-nowrap">

        ${PERM.update ? `
          <button
            class="${s.is_active == 1 ? 'text-gray-500' : 'text-green-600'}"
            onclick="toggleSchoolYear(${s.id})">
            ${s.is_active == 1 ? 'Ẩn' : 'Hiện'}
          </button>
        ` : ``}

        ${PERM.update ? `
          <button class="text-blue-600"
            onclick="openSchoolYearForm(${s.id}, '${escapeHtml(s.year_label)}')">
            Sửa
          </button>
        ` : ``}

        ${PERM.delete ? `
          <button class="text-red-600"
            onclick="confirmDelete('school_year', ${s.id})">
            Xóa
          </button>
        ` : ``}

      </div>

    </li>
  `).join("");

  renderPagination("school_year", page, total);
}

/* =====================
   PAGINATION (GIỐNG MEMBERS)
===================== */
function renderPagination(type, page, total) {
  const wrap = document.getElementById(`pagination-${type}`);
  if (!wrap || total < 1) {
    if (wrap) wrap.innerHTML = "";
    return;
  }

  wrap.innerHTML = `
    <button class="page-btn px-3 py-1 border rounded-lg"
      data-type="${type}" data-page="1"
      ${page === 1 ? "disabled" : ""}>«</button>

    <button class="page-btn px-3 py-1 border rounded-lg"
      data-type="${type}" data-page="${page - 1}"
      ${page === 1 ? "disabled" : ""}>‹</button>

    <input
      id="pageInput-${type}"
      type="number"
      min="1"
      max="${total}"
      value="${page}"
      class="w-10 h-8 px-2 border rounded-lg text-center text-sm leading-8"
    />

    <span class="text-sm leading-8 text-gray-500">/ ${total}</span>

    <button class="page-btn px-3 py-1 border rounded-lg"
      data-type="${type}" data-page="${page + 1}"
      ${page === total ? "disabled" : ""}>›</button>

    <button class="page-btn px-3 py-1 border rounded-lg"
      data-type="${type}" data-page="${total}"
      ${page === total ? "disabled" : ""}>»</button>
  `;
}

/* =====================
   PAGINATION EVENTS
===================== */
document.addEventListener("click", e => {
  const btn = e.target.closest(".page-btn");
  if (!btn || btn.disabled) return;

  const page = parseInt(btn.dataset.page, 10);
  const type = btn.dataset.type;
  if (!page) return;

  pager[type].page = page;
  render(type);
});

document.addEventListener("keydown", e => {
  if (!e.target.id?.startsWith("pageInput-")) return;
  if (e.key !== "Enter") return;

  const type = e.target.id.replace("pageInput-", "");
  const page = parseInt(e.target.value, 10);
  const max = pager[type].total;

  if (!page || page < 1 || page > max) {
    toast(`Trang phải từ 1 đến ${max}`);
    e.target.value = pager[type].page;
    return;
  }

  pager[type].page = page;
  render(type);
});

function render(type) {
  if (type === "position") renderPosition();
  if (type === "department") renderDepartment();
  if (type === "group") renderGroup();
  if (type === "title") renderTitle();
  if (type === "chidoan") renderChidoan();
  if (type === "school_year") renderSchoolYear();
}

/* =====================
   OPEN FORMS (ADD / EDIT)
===================== */
function openPositionForm(id = "", name = "") {
  const isEdit = !!id;

  openUnitForm({
    title: isEdit ? "Sửa chức vụ" : "Thêm chức vụ",
    name,
    label: "Tên",
    placeholder: "Nhập tên chức vụ...",
    onSubmit: async v => {
      await api(API, {
        method: "POST",
        body: new URLSearchParams({
          action: isEdit ? "update_position" : "add_position",
          id,
          name: v.name
        })
      });

      toast(
        isEdit ? "Cập nhật chức vụ thành công" : "Thêm chức vụ thành công",
        "success"
      );

      loadPositions(true);
    }
  });
}

function openGroupForm(id = "", name = "") {
  const isEdit = !!id;

  openUnitForm({
    title: isEdit ? "Sửa nhóm chi đoàn" : "Thêm nhóm chi đoàn",
    name,
    label: "Tên",
    placeholder: "Nhập tên nhóm chi đoàn...",
    onSubmit: async v => {
      await api(API, {
        method: "POST",
        body: new URLSearchParams({
          action: isEdit ? "update_group" : "add_group",
          id,
          name: v.name
        })
      });

      toast(
        isEdit
          ? "Cập nhật nhóm chi đoàn thành công"
          : "Thêm nhóm chi đoàn thành công",
        "success"
      );

      loadGroups(true);
    }
  });
}

function openDepartmentForm(id = "", name = "") {
  const isEdit = !!id;

  openUnitForm({
    title: isEdit ? "Sửa phòng ban" : "Thêm phòng ban",
    name,
    label: "Tên",
    placeholder: "Nhập tên phòng ban...",
    onSubmit: async v => {
      await api(API, {
        method: "POST",
        body: new URLSearchParams({
          action: isEdit ? "update_department" : "add_department",
          id,
          name: v.name
        })
      });

      toast(
        isEdit ? "Cập nhật phòng ban thành công" : "Thêm phòng ban thành công",
        "success"
      );

      loadDepartments(true);
      loadChidoans(true);
    }
  });
}

function openTitleForm(id = "", name = "") {
  const isEdit = !!id;

  openUnitForm({
    title: isEdit ? "Sửa danh hiệu đề nghị" : "Thêm danh hiệu đề nghị",
    name,
    label: "Tên",
    placeholder: "Nhập tên danh hiệu...",
    onSubmit: async v => {
      await api(API, {
        method: "POST",
        body: new URLSearchParams({
          action: isEdit ? "update_title" : "add_title",
          id,
          name: v.name
        })
      });

      toast(
        isEdit
          ? "Cập nhật danh hiệu thành công"
          : "Thêm danh hiệu thành công",
        "success"
      );

      loadTitles(true);
    }
  });
}

async function openChidoanForm(id = "", unitId = "", unitType = "", groupId = "") {
  const isEdit = !!id;
  const groups = await apiFetch(`${API}?action=list_groups`);
  const units = await loadUnits();

  openUnitForm({
    title: isEdit ? "Sửa chi đoàn" : "Thêm chi đoàn",
    groups,
    unitId,
    unitType,
    groupId,
    units,
    onSubmit: async v => {
      await api(API, {
        method: "POST",
        body: new URLSearchParams({
          action: isEdit ? "update_chidoan" : "add_chidoan",
          id,
          unit_id: v.unit_id,
          unit_type: v.unit_type,
          group_id: v.group_id
        })
      });

      toast(
        isEdit ? "Cập nhật chi đoàn thành công" : "Thêm chi đoàn thành công",
        "success"
      );

      loadChidoans(true);
    }
  });
}

/* =====================
   ✅ FORM NĂM HỌC
===================== */
function openSchoolYearForm(id = "", year_label = "") {
  const isEdit = !!id;

  openUnitForm({
    title: isEdit ? "Sửa năm học" : "Thêm năm học",
    name: year_label,
    label: "Năm học",
    placeholder: "VD: 2025-2026",
    onSubmit: async v => {
      await api(API, {
        method: "POST",
        body: new URLSearchParams({
          action: isEdit ? "update_school_year" : "add_school_year",
          id,
          year_label: v.name
        })
      });

      toast(
        isEdit ? "Cập nhật năm học thành công" : "Thêm năm học thành công",
        "success"
      );

      loadSchoolYears(true);
    }
  });
}

/* =====================
   MODAL FORM CHUNG
===================== */
function openUnitForm({
  title,
  name = "",
  label = "Tên",
  placeholder = "",
  groups = null,
  groupId = "",
  units = null,
  unitId = "",
  unitType = "",
  onSubmit
}) {
  const wrap = document.createElement("div");
  const isChidoan = Array.isArray(units);

  wrap.innerHTML = `
  <form class="space-y-4">

    ${!isChidoan ? `
      <div>
        <label class="block text-sm mb-1">${label}</label>
        <input name="name" required
          value="${name}"
          placeholder="${escapeHtml(placeholder)}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>
    ` : ""}

    ${isChidoan ? `
      <div>
        <label class="block text-sm mb-1">Đơn vị</label>
        <select name="unit" required
          class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Chọn đơn vị --</option>

          <optgroup label="Khoa">
            ${units.filter(u => u.type === 'khoa').map(u => `
              <option value="khoa|${u.id}"
                ${unitType === 'khoa' && unitId == u.id ? "selected" : ""}>
                ${u.name}
              </option>
            `).join("")}
          </optgroup>

          <optgroup label="Phòng ban">
            ${units.filter(u => u.type === 'phong').map(u => `
              <option value="phong|${u.id}"
                ${unitType === 'phong' && unitId == u.id ? "selected" : ""}>
                ${u.name}
              </option>
            `).join("")}
          </optgroup>
        </select>
      </div>
    ` : ""}

    ${groups ? `
      <div>
        <label class="block text-sm mb-1">Nhóm chi đoàn</label>
        <select name="group_id" required
          class="w-full px-3 py-2 border rounded-lg">
          ${groups.map(g => `
            <option value="${g.id}" ${g.id == groupId ? "selected" : ""}>
              ${g.name}
            </option>
          `).join("")}
        </select>
      </div>
    ` : ""}

    <div class="flex justify-end gap-2 pt-2">
      <button type="button"
        onclick="closeModal()"
        class="px-4 py-2 border rounded-lg">
        Hủy
      </button>
      <button class="px-4 py-2 bg-primary text-white rounded-lg">
        Lưu
      </button>
    </div>

  </form>
  `;

  modal(wrap, title, "small");

  wrap.querySelector("form").onsubmit = async e => {
    e.preventDefault();
    const fd = new FormData(e.target);

    if (isChidoan) {
      const unitVal = fd.get("unit");
      if (!unitVal) {
        toast("Vui lòng chọn đơn vị", "error");
        return;
      }

      const [unit_type, unit_id] = unitVal.split("|");

      await onSubmit({
        unit_type,
        unit_id,
        group_id: fd.get("group_id")
      });
    } else {
      await onSubmit({
        name: fd.get("name")
      });
    }

    closeModal();
  };
}

/* =====================
   DELETE CONFIRM
===================== */
function confirmDelete(type, id) {
  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <div class="text-center space-y-4">
      <div>Bạn chắc chắn muốn xoá?</div>
      <div class="flex justify-center gap-2">
        <button class="px-4 py-2 border rounded-lg"
          onclick="closeModal()">Hủy</button>
        <button class="px-4 py-2 bg-red-600 text-white rounded-lg"
          id="btnYes" data-primary>Xóa</button>
      </div>
    </div>
  `;

  modal(wrap, "Xác nhận", "small");

  wrap.querySelector("#btnYes").onclick = async () => {
    const btn = wrap.querySelector("#btnYes");
    if (btn.disabled) return;

    btn.disabled = true;
    btn.textContent = "Đang xoá...";

    try {
      const res = await api(API, {
        method: "POST",
        body: new URLSearchParams({
          action: `delete_${type}`,
          id
        })
      });

      if (!res.ok) throw new Error("Lỗi máy chủ");

      const json = await res.json();
      if (!json.ok) throw new Error(json.error || "Xoá thất bại");

      toast("Đã xoá", "success");
      closeModal();

      if (type === "position") loadPositions(true);
      if (type === "group") loadGroups(true);
      if (type === "department") {
        loadDepartments(true);
        loadChidoans(true);
      }
      if (type === "title") loadTitles(true);
      if (type === "chidoan") loadChidoans(true);
      if (type === "school_year") loadSchoolYears(true);

    } catch (err) {
      toast(err.message || "Không thể xoá", "error");
      btn.disabled = false;
      btn.textContent = "Xóa";
    }
  };
}

/* =====================
   HELPERS
===================== */
function escapeHtml(str) {
  return String(str)
    .replace(/'/g, "&#39;")
    .replace(/"/g, "&quot;");
}
