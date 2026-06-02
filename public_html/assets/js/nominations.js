/* =====================================================
   NOMINATIONS.JS (FULL - FIXED)
   - FIX: Form đăng ký (nmForm) hiển thị Khóa/Lớp theo Đơn vị
   - FIX: Load courses/classes cho form đăng ký
   - KEEP: Modal bổ sung hồ sơ đã có logic
   - NOTE: View đang dùng option data-group="..." + data-dept-id="..."
           => JS đọc dataset.group (fallback dataset.groupId)
===================================================== */

const CAN = window.NOMINATIONS_CAN || {
  view: false,
  create: false,
  update: false,
  review: false,
  delete: false,
  print: false
};

const PAGE_SIZE = 5;

let rawData = [];
let currentPage = 1;
let keyword = "";
let statusFilter = "";

let boxToolbar = null;
let boxCards = null;
let boxPager = null;

let userData = [];
let userPage = 1;

/* =========================
   HELPERS
========================= */
function s(v) {
  return (v ?? "").toString().toLowerCase();
}

function formatDate(dateStr) {
  if (!dateStr) return "---";
  const d = new Date(dateStr);
  return d.toLocaleDateString("vi-VN");
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (m) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  })[m]);
}

/**
 * ✅ ONLY use controller links (no Drive fallback)
 */
function getAttachmentLink(file, kind /* 'view'|'download' */) {
  if (!file) return "";
  if (kind === "view") return (file.view_url || "").trim();
  return (file.download_url || "").trim();
}

function renderAttachmentActions(file) {
  const viewUrl = getAttachmentLink(file, "view");
  const dlUrl = getAttachmentLink(file, "download");

  const viewBtn = viewUrl
    ? `<a href="${viewUrl}" target="_blank" class="text-blue-600 hover:underline">Xem</a>`
    : `<span class="text-gray-400 text-xs">Không có link xem</span>`;

  const dlBtn = dlUrl
    ? `<a href="${dlUrl}" class="px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Tải về</a>`
    : `<button type="button" class="px-3 py-1.5 text-xs bg-gray-200 text-gray-500 rounded-lg cursor-not-allowed" disabled>Tải về</button>`;

  return `<div class="flex items-center gap-3 shrink-0">${viewBtn}${dlBtn}</div>`;
}

/* =========================
   LOAD SCHOOL YEARS (GLOBAL)
========================= */
async function loadSchoolYearsToSelect(selectEl, selectedValue = "") {
  if (!selectEl) return;

  try {
    const res = await api("controllers/nominations.php?action=get_school_years", {
      credentials: "include",
    });
    const j = await res.json();

    const years = j.data || [];
    selectEl.innerHTML =
      `<option value="">-- Chọn năm học --</option>` +
      years.map((y) => `
        <option value="${escapeHtml(y)}" ${String(y) === String(selectedValue) ? "selected" : ""}>
          ${escapeHtml(y)}
        </option>
      `).join("");

  } catch (e) {
    console.error("Load school years error:", e);
  }
}

/* =====================================================
   ✅ FIX: CREATE FORM COURSE/CLASS TOGGLE + LOAD
   - Dành cho form đăng ký (view)
   - View option đang dùng:
       data-group="1" (chi đoàn lớp)
       data-dept-id="..."
===================================================== */
async function initCreateFormCourseClass() {
  const form = document.getElementById("nominationForm");
  if (!form) return;

  const deptSelect = form.querySelector('select[name="dept"]');
  const wrapCourse = document.getElementById("wrapCourse");
  const wrapClass = document.getElementById("wrapClass");
  const courseSelect = document.getElementById("courseSelect");
  const classSelect = document.getElementById("classSelect");

  if (!deptSelect || !wrapCourse || !wrapClass || !courseSelect || !classSelect) return;

  const CLASS_GROUP_ID = 1;

  async function loadCourses(selectedCourseId = "") {
    const res = await api("controllers/nominations.php?action=get_courses", { credentials: "include" });
    const j = await res.json();
    const data = j.data || [];

    courseSelect.innerHTML =
      `<option value="">-- Chọn khóa --</option>` +
      data.map((c) => `
        <option value="${c.id}" ${String(c.id) === String(selectedCourseId) ? "selected" : ""}>
          ${escapeHtml(c.name)}
        </option>
      `).join("");
  }

  async function loadClasses(deptId, courseId, selectedClassId = "") {
    if (!deptId || !courseId) {
      classSelect.innerHTML = `<option value="">-- Chọn lớp --</option>`;
      return;
    }

    const res = await api(
      `controllers/nominations.php?action=get_classes&dept_id=${encodeURIComponent(deptId)}&course_id=${encodeURIComponent(courseId)}`,
      { credentials: "include" }
    );
    const j = await res.json();
    const data = j.data || [];

    classSelect.innerHTML =
      `<option value="">-- Chọn lớp --</option>` +
      data.map((c) => `
        <option value="${c.id}" ${String(c.id) === String(selectedClassId) ? "selected" : ""}>
          ${escapeHtml(c.name)}
        </option>
      `).join("");
  }

  async function handleDeptChange({ reset = true } = {}) {
    const opt = deptSelect.selectedOptions?.[0];
    if (!opt) return;

    // view đang dùng data-group, modal dùng data-group-id
    const gid = parseInt(opt.dataset.groupId || opt.dataset.group || "0", 10);
    const deptId = opt.dataset.deptId || "";

    if (gid === CLASS_GROUP_ID) {
      wrapCourse.classList.remove("hidden");
      wrapClass.classList.remove("hidden");

      if (reset) {
        await loadCourses("");
        await loadClasses(deptId, "", "");
      } else {
        // giữ giá trị hiện tại nếu có
        await loadCourses(courseSelect.value || "");
        await loadClasses(deptId, courseSelect.value || "", classSelect.value || "");
      }
    } else {
      wrapCourse.classList.add("hidden");
      wrapClass.classList.add("hidden");
      courseSelect.innerHTML = `<option value="">-- Chọn khóa --</option>`;
      classSelect.innerHTML = `<option value="">-- Chọn lớp --</option>`;
      courseSelect.value = "";
      classSelect.value = "";
    }
  }

  deptSelect.addEventListener("change", async () => {
    await handleDeptChange({ reset: true });
  });

  courseSelect.addEventListener("change", async () => {
    const opt = deptSelect.selectedOptions?.[0];
    if (!opt) return;
    const deptId = opt.dataset.deptId || "";
    await loadClasses(deptId, courseSelect.value || "", "");
  });

  // init theo option hiện tại (nếu user refresh khi đã chọn)
  await handleDeptChange({ reset: false });
}

/* =========================
   ADMIN LIST
========================= */
async function initAdminList() {
  boxToolbar = document.getElementById("adminToolbar");
  boxCards = document.getElementById("adminCards");
  boxPager = document.getElementById("adminPagination");

  if (!boxToolbar || !boxCards || !boxPager) return;

  if (!CAN.view) {
    boxCards.innerHTML =
      `<p class="text-center text-red-500 py-6">Bạn không có quyền xem danh sách.</p>`;
    return;
  }

  if (!rawData.length) {
    await loadData();
  }

  renderToolbar();
  await loadFilters();   // ← THÊM DÒNG NÀY
  render();
}

async function loadUserData() {
  if (!CAN.view && !CAN.create) {
    userData = [];
    return [];
  }

  const res = await api("controllers/nominations.php?action=list_user", {
    credentials: "include"
  });
  const j = await res.json();
  userData = j.data || [];
  return userData;
}

async function loadData() {
  const year = document.getElementById("filterYear")?.value || "";
  const semester = document.getElementById("filterSemester")?.value || "";
  const titleId = document.getElementById("filterTitle")?.value || "";

  let url = "controllers/nominations.php?action=list";

  if (year) url += `&year=${encodeURIComponent(year)}`;
  if (semester) url += `&semester=${encodeURIComponent(semester)}`;
  if (titleId) url += `&title_id=${encodeURIComponent(titleId)}`;

  const res = await api(url, { credentials: "include" });
  const j = await res.json();
  rawData = j.data || [];
}

function getFiltered() {
  return rawData.filter((r) => {
    const okName = !keyword || s(r.fullname).includes(keyword);
    const okStatus = !statusFilter || r.status === statusFilter;
    return okName && okStatus;
  });
}

function render() {
  if (!boxCards || !boxPager) return;

  const list = getFiltered();
  const totalPage = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
  if (currentPage > totalPage) currentPage = totalPage;

  renderCards(list);
  renderPager(totalPage);
}

function renderToolbar() {
  boxToolbar.innerHTML = `
    <!-- Tìm theo tên -->
    <div class="flex-1 min-w-[220px]">
      <label class="block text-xs font-medium text-gray-500 mb-1">Tìm theo tên người nộp</label>
      <input id="searchName" placeholder="🔍 Nhập tên..." class="w-full border px-3 py-2.5 rounded-lg text-sm">
    </div>

    <!-- Trạng thái (lọc client-side) -->
    <div class="min-w-[140px]">
      <label class="block text-xs font-medium text-gray-500 mb-1">Trạng thái</label>
      <select id="filterStatus" class="w-full border px-3 py-2.5 rounded-lg text-sm">
        <option value="">Tất cả</option>
        <option value="approved">Đã duyệt</option>
        <option value="pending">Chờ duyệt</option>
        <option value="rejected">Từ chối</option>
      </select>
    </div>

    <!-- Năm học -->
    <div class="min-w-[140px]">
      <label class="block text-xs font-medium text-gray-500 mb-1">Năm học</label>
      <select id="filterYear" class="w-full border px-3 py-2.5 rounded-lg text-sm"></select>
    </div>

    <!-- Học kỳ -->
    <div class="min-w-[110px]">
      <label class="block text-xs font-medium text-gray-500 mb-1">Học kỳ</label>
      <select id="filterSemester" class="w-full border px-3 py-2.5 rounded-lg text-sm">
        <option value="">Tất cả</option>
        <option value="HK1">HK1</option>
        <option value="HK2">HK2</option>
      </select>
    </div>

    <!-- Danh hiệu -->
    <div class="min-w-[180px]">
      <label class="block text-xs font-medium text-gray-500 mb-1">Danh hiệu</label>
      <select id="filterTitle" class="w-full border px-3 py-2.5 rounded-lg text-sm"></select>
    </div>
  `;

  // ================== SỰ KIỆN ==================

  // Tìm tên + Trạng thái → lọc client-side (nhanh, không reload)
  document.getElementById("searchName").oninput = (e) => {
    keyword = e.target.value.toLowerCase();
    render();
  };

  document.getElementById("filterStatus").onchange = (e) => {
    statusFilter = e.target.value;   // ← Quan trọng: gán giá trị
    render();
  };

  // Các filter server-side → reload dữ liệu từ controller
  ["filterYear", "filterSemester", "filterTitle"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.onchange = async () => {
      currentPage = 1;
      await loadData();
      render();
    };
  });

  loadFilters();
}

async function loadFilters() {
  // Năm học
  const yearSelect = document.getElementById("filterYear");
  if (yearSelect) await loadSchoolYearsToSelect(yearSelect);

  // Danh hiệu
  const titleSelect = document.getElementById("filterTitle");
  if (titleSelect) {
    const res = await api("controllers/nominations.php?action=form_meta");
    const j = await res.json();
    titleSelect.innerHTML = `<option value="">Tất cả danh hiệu</option>` +
      (j.titles || []).map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join("");
  }
}

function renderCards(list) {
  boxCards.innerHTML = "";

  const start = (currentPage - 1) * PAGE_SIZE;
  const pageItems = list.slice(start, start + PAGE_SIZE);

  if (!pageItems.length) {
    boxCards.innerHTML = `
      <p class="text-center text-gray-500 py-6">
        Không có dữ liệu
      </p>`;
    return;
  }

  pageItems.forEach((r) => {
    const statusMap = {
      approved: { text: "Đã duyệt", cls: "bg-green-100 text-green-700", icon: "✅" },
      rejected: { text: "Từ chối", cls: "bg-red-100 text-red-700", icon: "❌" },
      pending: { text: "Chờ duyệt", cls: "bg-yellow-100 text-yellow-700", icon: "⏳" }
    };

    const st = statusMap[r.status] || statusMap.pending;

    boxCards.insertAdjacentHTML("beforeend", `
      <div class="relative border rounded-xl p-4 bg-white hover:shadow-md transition">

        <div class="flex justify-between items-start mb-2">
          <h3 class="text-lg font-bold text-gray-800">
            ${r.title_name || "—"}
          </h3>

          ${CAN.review ? `
            <a href="javascript:void(0)"
              onclick="openReviewModal(${r.id})"
              class="text-blue-600 text-sm hover:underline flex items-center gap-1">
              👁 <span>Xem &amp; Duyệt</span>
            </a>
          ` : `
            <span class="text-gray-400 text-sm italic">
              Không có quyền duyệt
            </span>
          `}
        </div>

        <div class="text-sm text-gray-700 space-y-1">
          <p>Người nộp: <b>${escapeHtml(r.fullname || "")}</b></p>
          <p>Đơn vị: ${escapeHtml(r.dept || "")}</p>
          <p>Ngày nộp: ${formatDate(r.created_at)}</p>
        </div>

        <div class="absolute bottom-3 right-4">
          <span class="px-3 py-1 rounded-full text-sm ${st.cls}">
            ${st.icon} ${st.text}
          </span>
        </div>
      </div>
    `);
  });
}

function renderUserCards() {
  const box = document.getElementById("userCards");
  const pager = document.getElementById("userPagination");
  if (!box || !pager) return;

  const PAGE_SIZE_LOCAL = 5;
  const totalPage = Math.max(1, Math.ceil(userData.length / PAGE_SIZE_LOCAL));
  userPage = Math.min(userPage, totalPage);

  box.innerHTML = "";

  const start = (userPage - 1) * PAGE_SIZE_LOCAL;
  const items = userData.slice(start, start + PAGE_SIZE_LOCAL);

  if (!items.length) {
    box.innerHTML = `<p class="text-center text-gray-500 py-6">Chưa có hồ sơ nào.</p>`;
    pager.innerHTML = "";
    return;
  }

  items.forEach((r) => {
    box.insertAdjacentHTML("beforeend", `
      <div class="border rounded-xl p-4 bg-white relative hover:shadow-md transition">

        <span class="absolute top-3 right-4 px-3 py-1 text-sm rounded-full
          ${r.status === "approved"
        ? "bg-green-100 text-green-700"
        : r.status === "rejected"
          ? "bg-red-100 text-red-700"
          : "bg-yellow-100 text-yellow-700"}">
          ${r.status === "approved"
        ? "Đã duyệt"
        : r.status === "rejected"
          ? "Bị từ chối"
          : "Chờ duyệt"}
        </span>

        <h3 class="text-lg font-bold mb-2">
          ${escapeHtml(r.title_name || "—")}
        </h3>

        <div class="text-sm text-gray-700 space-y-1">
          <p><b>Năm học:</b> ${escapeHtml(r.school_year || "---")}</p>
          <p><b>Đơn vị:</b> ${escapeHtml(r.dept || "---")}</p>
          <p><b>Chức vụ:</b> ${escapeHtml(r.proposer_pos || "---")}</p>
          <p><b>Ngày nộp:</b> ${formatDate(r.created_at)}</p>
        </div>

        <div class="mt-3 text-sm text-gray-600">
          ${r.files_count > 0
        ? `<span>📎 ${r.files_count} file đính kèm</span>`
        : `<span class="text-gray-400">Không có file đính kèm</span>`
      }
        </div>

        ${r.status === "rejected"
        ? `
            <p class="mt-2 text-red-600 text-sm">
              <b>Lý do từ chối:</b> ${escapeHtml(r.reviewer_note || "")}
            </p>
            <button
              onclick="openSupplementForm(${r.id})"
              class="absolute bottom-4 right-4 px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
              📝 Bổ sung hồ sơ
            </button>
          `
        : ""
      }
      </div>
    `);
  });

  pager.innerHTML = `
    <div class="flex gap-2 text-sm">
      <button onclick="userPage=1;renderUserCards()">«</button>
      <button onclick="userPage--;renderUserCards()">‹</button>
      <span>${userPage} / ${totalPage}</span>
      <button onclick="userPage++;renderUserCards()">›</button>
      <button onclick="userPage=${totalPage};renderUserCards()">»</button>
    </div>
  `;
}

function renderPager(totalPage) {
  boxPager.innerHTML = `
    <div class="flex items-center gap-2 text-sm">
      <button onclick="goPage(1)">«</button>
      <button onclick="goPage(currentPage-1)">‹</button>

      <input type="number" value="${currentPage}" min="1" max="${totalPage}"
        onchange="jumpPage(this.value)"
        class="w-12 text-center border rounded">

      <span>/ ${totalPage}</span>

      <button onclick="goPage(currentPage+1)">›</button>
      <button onclick="goPage(${totalPage})">»</button>
    </div>
  `;
}

function goPage(p) {
  currentPage = Math.max(1, p);
  render();
}

function jumpPage(p) {
  currentPage = Math.max(1, parseInt(p || 1));
  render();
}

/* =========================
   REVIEW MODAL
========================= */
window.openReviewModal = async function (id) {
  if (!CAN.review) {
    notify("Bạn không có quyền duyệt hồ sơ", "error");
    return;
  }

  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <div id="reviewContent" class="text-gray-700">
      <div class="text-center text-gray-400 py-6">Đang tải dữ liệu...</div>
    </div>
  `;

  modal(wrap, "Chi tiết hồ sơ", "large");

  const content = wrap.querySelector("#reviewContent");

  try {
    const res = await api(`controllers/nominations.php?action=get_detail&id=${id}`, {
      credentials: "include",
    });
    const j = await res.json();

    if (!j.ok) {
      content.innerHTML = `<p class="text-red-600 text-center py-6">${escapeHtml(j.error || "Lỗi tải dữ liệu")}</p>`;
      return;
    }

    const d = j.data || {};

    content.innerHTML = `
      <div class="space-y-6">

        <section class="bg-gray-50 rounded-xl p-4">
          <h3 class="font-semibold mb-3 text-lg">Thông tin cơ bản</h3>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <p><b>Họ tên:</b> ${escapeHtml(d.fullname || "")}</p>
            <p><b>Năm học:</b> ${escapeHtml(d.school_year || "---")}</p>
            <p><b>Học kỳ:</b> ${escapeHtml(d.semester || "---")}</p>
            
            <p><b>Chức vụ:</b> ${escapeHtml(d.proposer_pos || "---")}</p>
            <p><b>Đơn vị:</b> ${escapeHtml(d.dept || "---")}</p>

            <p><b>Khóa:</b> ${escapeHtml(d.course_name || d.course || "---")}</p>
            <p><b>Lớp:</b> ${escapeHtml(d.class_name || d.class || "---")}</p>
          </div>
          <div class="mt-3">
            <label class="block text-sm font-medium mb-1">Danh hiệu đề nghị:</label>
            <input readonly class="w-full border rounded-lg px-3 py-2 bg-gray-100" value="${escapeHtml(d.title_name || "")}">
          </div>
        </section>

        <section class="bg-yellow-50 rounded-xl p-4">
          <h3 class="font-semibold mb-3 text-lg">Hồ sơ đính kèm</h3>

          ${Array.isArray(d.attachments) && d.attachments.length
        ? `
              <div class="space-y-2 text-sm">
                ${d.attachments.map((f) => `
                  <div class="flex items-center justify-between gap-4">
                    <span class="truncate">${escapeHtml(f.label || "")}</span>
                    ${renderAttachmentActions(f)}
                  </div>
                `).join("")}
              </div>
            `
        : `<p class="text-sm text-gray-500">Không có hồ sơ đính kèm</p>`
      }
        </section>

        <section>
          <h3 class="font-semibold mb-3 text-lg">Duyệt hồ sơ</h3>
          <form id="reviewForm" class="space-y-3">
            <input type="hidden" name="id" value="${escapeHtml(d.id)}">

            <div>
              <label class="block text-sm font-medium mb-1">Trạng thái</label>
              <select name="status" class="w-full border rounded-lg px-3 py-2">
                <option value="approved" ${d.status === "approved" ? "selected" : ""}>Duyệt</option>
                <option value="pending" ${d.status === "pending" ? "selected" : ""}>Chờ duyệt</option>
                <option value="rejected" ${d.status === "rejected" ? "selected" : ""}>Từ chối</option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium mb-1">Phản hồi</label>
              <textarea name="note" rows="3" class="w-full border rounded-lg px-3 py-2">${escapeHtml(d.reviewer_note || "")}</textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
              <button type="button" class="px-5 py-2 border rounded-lg hover:bg-gray-100" onclick="closeModal()">Hủy</button>
              <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">✔ Cập nhật trạng thái</button>
            </div>
          </form>
        </section>

      </div>
    `;

    const reviewForm = content.querySelector("#reviewForm");
    reviewForm.addEventListener("submit", async (e) => {
      e.preventDefault();

      const fd = new FormData(reviewForm);
      fd.append("action", "review");

      const res = await api(`controllers/nominations.php`, {
        method: "POST",
        body: fd,
        credentials: "include",
      });
      const json = await res.json();

      if (json.ok) {
        notifyReload("Cập nhật thành công!");
        closeModal();
      } else {
        notify(json.error || "Lỗi cập nhật!", "error");
      }
    });

  } catch (err) {
    content.innerHTML = `<p class="text-red-600 text-center py-6">Không thể tải dữ liệu.</p>`;
  }
};

/* =========================
   SUPPLEMENT FORM (MODAL)
========================= */
window.openSupplementForm = async function (id) {
  if (!CAN.view) {
    notify("Bạn không có quyền bổ sung hồ sơ", "error");
    return;
  }

  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <div id="supplementContent" class="text-gray-700">
      <div class="text-center text-gray-400 py-6">Đang tải dữ liệu...</div>
    </div>
  `;

  modal(wrap, "📝 Bổ sung hồ sơ", "large");

  const content = wrap.querySelector("#supplementContent");

  try {
    const res = await api(`controllers/nominations.php?action=get_detail&id=${id}`, {
      credentials: "include"
    });

    const j = await res.json();
    if (!j.ok) {
      content.innerHTML = `<p class="text-red-600 text-center py-6">${escapeHtml(j.error || "")}</p>`;
      return;
    }

    const d = j.data || {};

    const metaRes = await api("controllers/nominations.php?action=form_meta");
    const meta = await metaRes.json();

    if (!meta.ok) {
      content.innerHTML = "<p class='text-red-600'>Không tải được dữ liệu form</p>";
      return;
    }

    const positions = meta.positions || [];
    const groups = meta.groups || [];

    // helper: find old attachment by code
    function findOldByCode(code) {
      if (!Array.isArray(d.attachments)) return null;
      return d.attachments.find((x) => String(x.code) === String(code)) || null;
    }

    function renderOldFileLine(old) {
      if (!old) return `<p class="text-xs text-gray-400 mt-1">Chưa có file</p>`;

      const viewUrl = getAttachmentLink(old, "view");
      const dlUrl = getAttachmentLink(old, "download");

      // ✅ không fallback url
      const viewPart = viewUrl
        ? `<a href="${viewUrl}" target="_blank" class="text-blue-600 underline">xem</a>`
        : `<span class="text-gray-400">không có link xem</span>`;

      const dlPart = dlUrl
        ? `<a href="${dlUrl}" class="text-blue-600 underline">tải về</a>`
        : `<span class="text-gray-400">không có link tải</span>`;

      return `
        <p class="text-xs text-gray-500 mt-1">
          Đã có file cũ — ${viewPart}
          <span class="mx-1">|</span>
          ${dlPart}
        </p>
      `;
    }

    content.innerHTML = `
      <form id="formSupplement" enctype="multipart/form-data" class="space-y-5">
        <input type="hidden" name="id" value="${escapeHtml(d.id)}">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

          <div>
            <label class="block text-sm font-medium mb-1">Họ và tên</label>
            <input name="fullname" value="${escapeHtml(d.fullname || "")}" class="w-full border rounded-lg px-3 py-2">
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Năm học</label>
            <input name="school_year" value="${escapeHtml(d.school_year || "")}" class="w-full border rounded-lg px-3 py-2">
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Chức vụ</label>
            <select name="proposer_pos" class="w-full border rounded-lg px-3 py-2" required>
              <option value="">-- Chọn chức vụ --</option>
              ${positions.map((p) => `
                <option value="${escapeHtml(p.name)}" ${d.proposer_pos === p.name ? "selected" : ""}>
                  ${escapeHtml(p.name)}
                </option>
              `).join("")}
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Đơn vị</label>
            <select name="dept" class="w-full border rounded-lg px-3 py-2" required>
              <option value="">-- Chọn đơn vị --</option>

              ${groups.map((g) => `
                <optgroup label="${escapeHtml(g.name)}">
                  ${(g.items || []).map((it) => `
                    <option
                      value="${escapeHtml(it.name)}"
                      data-group-id="${escapeHtml(g.group_id)}"
                      data-dept-id="${escapeHtml(it.dept_id)}"
                      data-chidoan-id="${escapeHtml(it.chidoan_id)}"
                      ${d.dept === it.name ? "selected" : ""}>
                      ${escapeHtml(it.name)}
                    </option>
                  `).join("")}
                </optgroup>
              `).join("")}
            </select>
          </div>

        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

          <div id="wrapCourse" class="hidden">
            <label class="block text-sm font-medium mb-1">Khóa *</label>
            <select name="course" id="courseSelect"
              class="w-full px-3 py-2 border rounded-lg">
              <option value="">-- Chọn khóa --</option>
            </select>
          </div>

          <div id="wrapClass" class="hidden">
            <label class="block text-sm font-medium mb-1">Lớp *</label>
            <select name="class" id="classSelect"
              class="w-full px-3 py-2 border rounded-lg">
              <option value="">-- Chọn lớp --</option>
            </select>
          </div>

        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Danh hiệu / hình thức đề nghị</label>
          <select name="title_id" required class="w-full border rounded-lg px-3 py-2">
            <option value="">-- Chọn danh hiệu --</option>
            ${(meta.titles || []).map((t) => `
              <option value="${t.id}" ${String(d.title_id) === String(t.id) ? "selected" : ""}>
                ${escapeHtml(t.name)}
              </option>
            `).join("")}
          </select>
        </div>

        <div class="bg-gray-50 p-4 rounded-xl space-y-4">
          <h3 class="font-semibold mb-2">
            Hồ sơ đính kèm (chỉ upload file cần bổ sung)
          </h3>
          <div id="supp-att-list" class="space-y-4"></div>
        </div>

        <div class="flex justify-end gap-3 pt-3">
          <button type="button" class="px-5 py-2 border rounded-lg hover:bg-gray-100" onclick="closeModal()">
            Hủy
          </button>

          <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold">
            💾 Cập nhật hồ sơ
          </button>
        </div>

      </form>
    `;

    const form = content.querySelector("#formSupplement");
    const suppTitleSelect = form.querySelector('select[name="title_id"]');
    if (suppTitleSelect) {
      suppTitleSelect.addEventListener("change", async () => {
        const titleId = suppTitleSelect.value;
        if (!titleId) return;
        const res = await api(`controllers/nominations.php?action=attachments_by_title&title_id=${titleId}`);
        const j = await res.json();
        if (j.ok) {
          const attWrap = form.querySelector(".bg-gray-50.p-4.rounded-xl.space-y-4 > div.space-y-4");  // Adjust selector
          // Wait, in supplement, attachments are rendered with meta.attachments, which is all.

          // To fix, perhaps re-render the attachments section.

          // For simplicity, since meta.attachments is all, but attachments_by_title is per title, need to change to use attachments_by_title.

          // In openSupplementForm, instead of using meta.attachments, when title selected, load and render.

          // But initial d.title_id is set, so load initially.

          // Add after content.innerHTML

          const attSection = content.querySelector('.bg-gray-50.p-4.rounded-xl.space-y-4');
          if (attSection) {
            const attList = attSection.querySelector('div.space-y-4');
            if (!attList) {
              // Create
              attSection.insertAdjacentHTML('beforeend', '<div id="supp-att-list" class="space-y-4"></div>');
            } else {
              attList.id = "supp-att-list";
            }
          }

          async function loadSuppAttachments(titleId) {
            if (!titleId) return;
            const res = await api(`controllers/nominations.php?action=attachments_by_title&title_id=${titleId}`);
            const j = await res.json();
            if (!j.ok) return;

            const attList = content.querySelector("#supp-att-list");
            if (!attList) return;

            attList.innerHTML = j.data.map(a => {
              const old = findOldByCode(a.code);
              return `
            <div>
              <label class="block text-sm font-medium mb-1">${escapeHtml(a.label)} ${a.is_required ? '*' : ''}</label>
              <input type="file" name="attachments[${a.id}]" class="w-full border rounded-lg px-3 py-2" ${a.is_required ? 'required' : ''} />
              ${renderOldFileLine(old)}
            </div>
          `;
            }).join("");
          }

          suppTitleSelect.addEventListener("change", () => loadSuppAttachments(suppTitleSelect.value));

          // Initial load
          loadSuppAttachments(d.title_id);
        }
      });
    }

    const deptSelect = form.querySelector('select[name="dept"]');
    const wrapCourse = form.querySelector("#wrapCourse");
    const wrapClass = form.querySelector("#wrapClass");
    const courseSelect = form.querySelector("#courseSelect");
    const classSelect = form.querySelector("#classSelect");

    const CLASS_GROUP_ID = 1;
    async function loadCourses(selectedCourseId = null) {
      const res = await api("controllers/nominations.php?action=get_courses", { credentials: "include" });
      const j = await res.json();
      const data = j.data || [];

      courseSelect.innerHTML =
        `<option value="">-- Chọn khóa --</option>` +
        data.map((c) => `
          <option value="${c.id}" ${String(c.id) === String(selectedCourseId) ? "selected" : ""}>
            ${escapeHtml(c.name)}
          </option>
        `).join("");
    }

    async function loadClasses(deptId, courseId, selectedClassId = null) {
      if (!deptId || !courseId) {
        classSelect.innerHTML = `<option value="">-- Chọn lớp --</option>`;
        return;
      }

      const res = await api(
        `controllers/nominations.php?action=get_classes&dept_id=${encodeURIComponent(deptId)}&course_id=${encodeURIComponent(courseId)}`,
        { credentials: "include" }
      );
      const j = await res.json();
      const data = j.data || [];

      classSelect.innerHTML =
        `<option value="">-- Chọn lớp --</option>` +
        data.map((c) => `
          <option value="${c.id}" ${String(c.id) === String(selectedClassId) ? "selected" : ""}>
            ${escapeHtml(c.name)}
          </option>
        `).join("");
    }

    async function handleDeptChange({ keepOld = true } = {}) {
      const opt = deptSelect?.selectedOptions?.[0];
      if (!opt) return;

      const gid = parseInt(opt.dataset.groupId || opt.dataset.group || "0", 10);

      if (gid === CLASS_GROUP_ID) {
        wrapCourse.classList.remove("hidden");
        wrapClass.classList.remove("hidden");

        const deptId = opt.dataset.deptId || "";

        const oldCourse = keepOld ? (d.course_id || "") : "";
        const oldClass = keepOld ? (d.class_id || "") : "";

        await loadCourses(oldCourse);

        const courseId = courseSelect.value || oldCourse || "";
        await loadClasses(deptId, courseId, oldClass);
      } else {
        wrapCourse.classList.add("hidden");
        wrapClass.classList.add("hidden");
        courseSelect.innerHTML = `<option value="">-- Chọn khóa --</option>`;
        classSelect.innerHTML = `<option value="">-- Chọn lớp --</option>`;
      }
    }

    courseSelect.addEventListener("change", async () => {
      const opt = deptSelect.selectedOptions[0];
      if (!opt) return;

      const deptId = opt.dataset.deptId || "";
      const courseId = courseSelect.value || "";
      await loadClasses(deptId, courseId, null);
    });

    deptSelect.addEventListener("change", async () => {
      await handleDeptChange({ keepOld: false });
    });

    await handleDeptChange({ keepOld: true });

    form.addEventListener("submit", async (e) => {
      if (!CAN.update && !CAN.create) {
        notify("Bạn không có quyền cập nhật hồ sơ", "error");
        return;
      }
      e.preventDefault();

      const fd = new FormData(form);
      fd.set("course", courseSelect.value || "");
      fd.set("class", classSelect.value || "");

      // ───── THÊM 2 DÒNG NÀY ─────
      const semesterSelect = form.querySelector('select[name="semester"]');
      if (semesterSelect) fd.set("semester", semesterSelect.value || "");

      fd.append("action", "update");

      try {
        const res = await api("controllers/nominations.php", {
          method: "POST",
          body: fd,
          credentials: "include"
        });

        const r = await res.json();
        if (r.ok) {
          notifyReload(r.msg || "Đã cập nhật hồ sơ!");
          closeModal();
        } else {
          notify(r.error || "Có lỗi xảy ra!", "error");
        }

      } catch (err) {
        notify("Lỗi kết nối máy chủ!", "error");
        console.error(err);
      }
    });

  } catch (err) {
    content.innerHTML = `<p class="text-red-600 text-center py-6">Không thể tải dữ liệu.</p>`;
    console.error(err);
  }
};


async function openAttachmentManager() {
  if (!CAN.update) {
    toast("Bạn không có quyền quản lý loại hồ sơ", "error");
    return;
  }

  // Load meta to get titles
  const metaRes = await api("controllers/nominations.php?action=form_meta");
  const meta = await metaRes.json();
  if (!meta.ok) {
    toast("Không tải được danh sách danh hiệu", "error");
    return;
  }

  const titles = meta.titles || [];

  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <div id="attachment-modal-body" class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Chọn danh hiệu</label>
        <select id="managerTitleSelect" class="w-full border rounded-lg px-3 py-2">
          <option value="">-- Chọn danh hiệu --</option>
          ${titles.map(t => `
            <option value="${t.id}">${escapeHtml(t.name)}</option>
          `).join('')}
        </select>
      </div>
      <div id="attachment-manager-list" class="space-y-3"></div>
      <div id="add-attachment-section" class="hidden">
        <button type="button" id="addAttachmentBtn" class="px-4 py-2 bg-green-600 text-white rounded">
          + Thêm loại hồ sơ
        </button>
      </div>
    </div>
  `;

  modal(wrap, "Quản lý loại hồ sơ", "medium");

  const titleSelect = wrap.querySelector("#managerTitleSelect");
  const listWrap = wrap.querySelector("#attachment-manager-list");
  const addSection = wrap.querySelector("#add-attachment-section");
  const addBtn = wrap.querySelector("#addAttachmentBtn");

  titleSelect.addEventListener("change", async () => {
    const titleId = titleSelect.value;
    if (!titleId) {
      listWrap.innerHTML = "";
      addSection.classList.add("hidden");
      return;
    }

    addSection.classList.remove("hidden");
    await loadAttachmentsForTitle(titleId, listWrap);
  });

  addBtn.addEventListener("click", () => renderAddAttachmentView(titleSelect.value));
}

async function loadAttachmentsForTitle(titleId, listWrap) {
  const res = await api(`controllers/nominations.php?action=attachments_by_title&title_id=${titleId}`);
  const j = await res.json();
  if (!j.ok) {
    listWrap.innerHTML = `<p class="text-red-600">Lỗi tải dữ liệu</p>`;
    return;
  }

  const items = j.data || [];
  listWrap.innerHTML = "";

  items.forEach((i) => {
    const row = document.createElement("div");
    row.className = "flex items-center gap-2";

    row.innerHTML = `
      <input
        class="flex-1 border rounded px-2 py-1 text-sm"
        value="${escapeHtml(i.label)}"
        data-id="${i.id}"
        data-title-id="${titleId}"
        onchange="updateAttachmentType(this)"
      >
      <button
        class="px-3 py-1 bg-red-600 text-white rounded text-sm"
        onclick="confirmUnlinkAttachment(${titleId}, ${i.id}, '${escapeHtml(i.label)}')">
        Xóa
      </button>
    `;

    listWrap.appendChild(row);
  });
}

function renderAddAttachmentView(titleId) {
  if (!titleId) {
    toast("Vui lòng chọn danh hiệu trước", "error");
    return;
  }

  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="addAttachmentForm" class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Tên loại hồ sơ</label>
        <input name="label" required class="w-full border rounded-lg px-3 py-2" placeholder="Ví dụ: Biên bản kiểm điểm">
        <input type="hidden" name="title_id" value="${titleId}">
      </div>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded">Hủy</button>
        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded">Thêm</button>
      </div>
    </form>
  `;

  modal(wrap, "Thêm loại hồ sơ cho danh hiệu", "small");

  const form = wrap.querySelector("#addAttachmentForm");
  form.onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append("action", "attachment_types_add");

    const res = await api("controllers/nominations.php", {
      method: "POST",
      body: fd
    });
    const j = await res.json();
    if (j.ok) {
      toast("Đã thêm loại hồ sơ", "success");
      closeModal();
      // Reload manager list
      const titleSelect = document.querySelector("#managerTitleSelect");
      if (titleSelect) {
        const listWrap = document.querySelector("#attachment-manager-list");
        await loadAttachmentsForTitle(titleSelect.value, listWrap);
      }
    } else {
      toast(j.error || "Lỗi thêm", "error");
    }
  };
}

async function confirmUnlinkAttachment(titleId, attId, label) {
  if (!confirm(`Xóa "${label}" khỏi danh hiệu này?`)) return;

  const fd = new FormData();
  fd.append("action", "rtat_unlink");
  fd.append("title_id", titleId);
  fd.append("attachment_type_id", attId);

  const res = await api("controllers/nominations.php", {
    method: "POST",
    body: fd
  });
  const j = await res.json();
  if (j.ok) {
    toast("Đã xóa liên kết", "success");
    // Reload list
    const listWrap = document.querySelector("#attachment-manager-list");
    await loadAttachmentsForTitle(titleId, listWrap);
  } else {
    toast(j.error || "Lỗi xóa", "error");
  }
}

async function updateAttachmentType(input) {
  const id = input.dataset.id;
  const label = input.value.trim();
  if (!id || !label) return;

  const fd = new FormData();
  fd.append("action", "attachment_types_update");
  fd.append("id", id);
  fd.append("label", label);

  await api("controllers/nominations.php", {
    method: "POST",
    body: fd
  });

  // Optional: toast success
  toast("Đã cập nhật tên", "success");
}

function renderAttachmentUploadList(items) {
  const wrap = document.getElementById("attachment-upload-list");
  if (!wrap) return;

  wrap.innerHTML = "";

  if (!items.length) {
    wrap.innerHTML = `<p class="text-sm text-gray-500">Chưa có loại hồ sơ cho danh hiệu này.</p>`;
    return;
  }

  items.forEach((i) => {
    const div = document.createElement("div");
    div.innerHTML = `
      <label class="block text-sm font-medium mb-1">
        ${escapeHtml(i.label)} ${i.is_required ? '*' : ''}
      </label>
      <input
        type="file"
        name="attachments[${i.id}]"
        class="w-full border rounded-lg px-3 py-2"
      >
    `;
    wrap.appendChild(div);
  });
}

/* =========================
   TAB SWITCHING
========================= */
let __ADMIN_INITED = false;
let __USER_INITED = false;

function setTabActive(btn, active) {
  if (!btn) return;

  btn.classList.toggle("text-blue-600", active);
  btn.classList.toggle("bg-blue-50", active);
  btn.classList.toggle("border-blue-600", active);

  btn.classList.toggle("text-gray-500", !active);
  btn.classList.toggle("border-transparent", !active);
}

function showPanel(panelEl, show) {
  if (!panelEl) return;
  panelEl.classList.toggle("hidden", !show);
}

async function switchTab(tabKey, { pushUrl = true } = {}) {
  const tabFormBtn = document.getElementById("tabForm");
  const tabListBtn = document.getElementById("tabList");
  const tabUserListBtn = document.getElementById("tabUserList");

  const nmForm = document.getElementById("nmForm");
  const nmList = document.getElementById("nmList");
  const nmUserList = document.getElementById("nmUserList");

  showPanel(nmForm, false);
  showPanel(nmList, false);
  showPanel(nmUserList, false);

  setTabActive(tabFormBtn, false);
  setTabActive(tabListBtn, false);
  setTabActive(tabUserListBtn, false);

  if (tabKey === "list" && nmList && tabListBtn) {
    showPanel(nmList, true);
    setTabActive(tabListBtn, true);

    if (!__ADMIN_INITED) {
      __ADMIN_INITED = true;
      await initAdminList();
    }

  } else if (tabKey === "userlist" && nmUserList && tabUserListBtn) {
    showPanel(nmUserList, true);
    setTabActive(tabUserListBtn, true);

    if (!__USER_INITED) {
      __USER_INITED = true;
      await loadUserData();
      renderUserCards();
    }

  } else {
    if (nmForm && tabFormBtn) {
      showPanel(nmForm, true);
      setTabActive(tabFormBtn, true);
    }
    tabKey = "form";
  }

  if (pushUrl) {
    const url = new URL(window.location.href);
    url.searchParams.set("tab", tabKey);
    history.replaceState({}, "", url.toString());
  }
}

function initTabs() {
  const tabFormBtn = document.getElementById("tabForm");
  const tabListBtn = document.getElementById("tabList");
  const tabUserListBtn = document.getElementById("tabUserList");

  if (tabFormBtn) {
    tabFormBtn.addEventListener("click", (e) => {
      e.preventDefault();
      switchTab("form");
    });
  }

  if (tabListBtn) {
    tabListBtn.addEventListener("click", (e) => {
      e.preventDefault();
      switchTab("list");
    });
  }

  if (tabUserListBtn) {
    tabUserListBtn.addEventListener("click", (e) => {
      e.preventDefault();
      switchTab("userlist");
    });
  }

  const params = new URLSearchParams(window.location.search);
  const tab = params.get("tab") || "form";
  switchTab(tab, { pushUrl: false });
}

/* =====================================================
   MAIN DOMContentLoaded (ONLY ONE)
===================================================== */
document.addEventListener("DOMContentLoaded", () => {
  setTimeout(async () => {
    try {
      initTabs();

      // ✅ FIX: init logic Khóa/Lớp cho form đăng ký
      await initCreateFormCourseClass();

      initNominationCreateForm();

      // luôn load list năm học cho form user
      const regForm = document.getElementById("nominationForm");
      if (regForm) {
        const yearSelectInit = regForm.querySelector('select[name="school_year"]');
        if (yearSelectInit) {
          await loadSchoolYearsToSelect(yearSelectInit);
        }
      }

      const params = new URLSearchParams(window.location.search);

      const currentTab = (params.get("tab") || "").toLowerCase();
      if (currentTab && currentTab !== "form") return;

      const preTitleId = params.get("prefill_title_id") || "";
      const preSchoolYear = params.get("prefill_school_year") || "";
      if (!preTitleId && !preSchoolYear) return;

      await switchTab("form");

      const yearSelect = document.querySelector('select[name="school_year"]');
      if (yearSelect && preSchoolYear) {
        await loadSchoolYearsToSelect(yearSelect, preSchoolYear);
      }

      if (preTitleId) {
        const titleSelect = document.querySelector('select[name="title_id"]');
        if (titleSelect) {
          titleSelect.value = preTitleId;
          titleSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }

    } catch (e) {
      console.error("Prefill nominations error:", e);
    }
  }, 80);

  async function initNominationCreateForm() {

    const titleSelect = document.querySelector('select[name="title_id"]');
    if (titleSelect) {
      titleSelect.addEventListener("change", async () => {
        const titleId = titleSelect.value;
        if (!titleId) {
          renderAttachmentUploadList([]);
          return;
        }
        const res = await api(`controllers/nominations.php?action=attachments_by_title&title_id=${titleId}`);
        const j = await res.json();
        if (j.ok) {
          renderAttachmentUploadList(j.data);
        }
      });
    }

    const form = document.getElementById("nominationForm");
    if (!form) return;

    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const btnSubmit = form.querySelector('button[type="submit"]');
      if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = `
          <span class="inline-flex items-center gap-2">
            <span class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
            Đang gửi, vui lòng không thoát trang...
          </span>`;
      }

      try {
        const courseSelect = document.getElementById("courseSelect");
        const classSelect = document.getElementById("classSelect");
        const semesterSelect = form.querySelector('select[name="semester"]');

        const fd = new FormData(form);
        if (courseSelect) fd.set("course", courseSelect.value || "");
        if (classSelect) fd.set("class", classSelect.value || "");
        if (semesterSelect) fd.set("semester", semesterSelect.value || "");

        fd.append("action", "create");

        const res = await api("controllers/nominations.php", {
          method: "POST",
          body: fd,
          credentials: "include",
        });

        const j = await res.json();

        if (!j.ok) {
          notify(j.error || "Nộp hồ sơ thất bại!", "error");
          return;
        }

        notify(j.msg || "Nộp hồ sơ thành công!", "success");

        // Chuyển sang tab danh sách đã gửi
        const url = new URL(window.location.href);
        url.searchParams.set("tab", "userlist");
        history.replaceState({}, "", url.toString());

        await switchTab("userlist", { pushUrl: false });
        await loadUserData();
        renderUserCards();

        form.reset();
        await initCreateFormCourseClass();

      } catch (err) {
        console.error(err);
        notify("Lỗi kết nối máy chủ!", "error");
      } finally {
        // Khôi phục nút
        if (btnSubmit) {
          btnSubmit.disabled = false;
          btnSubmit.innerHTML = "Nộp hồ sơ";
        }
      }
    });
  }
});