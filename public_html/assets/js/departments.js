const DEPT_API = "controllers/departments.php";

function renderPagerAjaxUI(type, page, totalPages) {
  const wrap = document.querySelector(`.pager-${type}`);
  if (!wrap) return;

  const prev = Math.max(1, page - 1);
  const next = Math.min(totalPages, page + 1);

  wrap.innerHTML = `
    <div class="flex items-center gap-2 justify-center select-none">

      <button
        class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
        onclick="jumpPageAjax('${type}', 1, ${totalPages})"
        title="Trang đầu">
        &laquo;
      </button>

      <button
        class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
        onclick="jumpPageAjax('${type}', ${prev}, ${totalPages})"
        title="Trang trước">
        &lsaquo;
      </button>

      <div class="flex items-center gap-1 text-sm">
        <input
          type="number"
          min="1"
          max="${totalPages}"
          value="${page}"
          class="w-12 px-2 py-1 border rounded-lg text-center"
          onchange="jumpPageAjax('${type}', this.value, ${totalPages})"
        />
        <span class="text-gray-500">/ ${totalPages}</span>
      </div>

      <button
        class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
        onclick="jumpPageAjax('${type}', ${next}, ${totalPages})"
        title="Trang sau">
        &rsaquo;
      </button>

      <button
        class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
        onclick="jumpPageAjax('${type}', ${totalPages}, ${totalPages})"
        title="Trang cuối">
        &raquo;
      </button>

    </div>
  `;
}

async function jumpPageAjax(type, page, total) {
  page = parseInt(page);
  if (page < 1) page = 1;
  if (page > total) page = total;

  let actionMap = {
    dept: "list_departments",
    course: "list_courses",
    class: "list_classes"
  };

  let tbodyMap = {
    dept: "tbodyDept",
    course: "tbodyCourse",
    class: "tbodyClass"
  };

  const res = await fetch(
    `${DEPT_API}?action=${actionMap[type]}&page=${page}`,
    { credentials: "include" }
  );

  const json = await res.json();
  if (!json.ok) return toast("Lỗi tải dữ liệu");

  const tbody = document.getElementById(tbodyMap[type]);
  tbody.innerHTML = "";

  json.data.forEach(row => {
    const tr = document.createElement("tr");
    tr.className = "border-t";

    if (type === "dept") {
      let actions = "";

      if (window.PERM_DEPT.update) {
        actions += `
    <button
      class="px-2 text-blue-600 hover:underline"
      onclick='openDeptModal(${row.id}, ${JSON.stringify(row.name)})'>
      Sửa
    </button>
  `;
      }

      if (window.PERM_DEPT.delete) {
        actions += `
<button
  class="px-2 text-red-600 hover:underline"
  onclick="delItem('dept', 'delete_department', ${row.id})">
  Xóa
</button>
  `;
      }

      if (!actions) {
        actions = `<span class="text-xs text-gray-400">Không có quyền</span>`;
      }

      tr.innerHTML = `
  <td class="py-1">${row.name}</td>
  <td class="text-right whitespace-nowrap py-1">
    ${actions}
  </td>
`;

    }


    if (type === "course") {
      let actions = "";

      if (window.PERM_DEPT.update) {
        actions += `
<button
  class="px-2 text-blue-600 hover:underline"
  onclick='openCourseModal(${row.id}, ${JSON.stringify(row.name)})'>
  Sửa
</button>
`;
      }

      if (window.PERM_DEPT.delete) {
        actions += `
<button
  class="px-2 text-red-600 hover:underline"
  onclick="delItem('course', 'delete_course', ${row.id})">
  Xóa
</button>
  `;
      }

      if (!actions) {
        actions = `<span class="text-xs text-gray-400">Không có quyền</span>`;
      }

      tr.innerHTML = `
  <td class="py-1">${row.name}</td>
  <td class="text-right whitespace-nowrap py-1">
    ${actions}
  </td>
`;

    }


    if (type === "class") {
      let actions = "";

      if (window.PERM_DEPT.update) {
        actions += `
  <button
    class="px-2 text-blue-600 hover:underline js-edit-class"
    data-id="${row.id}"
    data-name="${encodeURIComponent(row.name || "")}"
    data-dept="${row.department_id || 0}"
    data-course="${row.course_id || 0}">
    Sửa
  </button>
`;
      }

      if (window.PERM_DEPT.delete) {
        actions += `
<button
  class="px-2 text-red-600 hover:underline"
  onclick="delItem('class', 'delete_class', ${row.id})">
  Xóa
</button>
  `;
      }

      if (!actions) {
        actions = `<span class="text-xs text-gray-400">Không có quyền</span>`;
      }

      tr.innerHTML = `
  <td class="py-1">${row.name}</td>
  <td class="text-right whitespace-nowrap py-1">
    ${actions}
  </td>
`;

    }

    tbody.appendChild(tr);
    // Gắn event cho nút Sửa lớp (tránh inline onclick bị lỗi cú pháp)
    // sau khi render xong toàn bộ rows
    if (type === "class") {
      tbody.querySelectorAll(".js-edit-class").forEach(btn => {
        btn.onclick = () => {
          const id = parseInt(btn.dataset.id, 10) || 0;
          const name = decodeURIComponent(btn.dataset.name || "");
          const dept = parseInt(btn.dataset.dept, 10) || 0;
          const course = parseInt(btn.dataset.course, 10) || 0;

          openClassModal(id, name, dept, course);
        };
      });
    }

  });

  // ✅ CẬP NHẬT INPUT (CHỖ BẠN BỊ SAI TRƯỚC ĐÓ)
  // update input
  const input = document.querySelector(`input[data-type="${type}"]`);
  if (input) input.value = page;

  // ✅ FIX QUAN TRỌNG
  renderPagerAjaxUI(type, page, total);

}




function openDeptModal(id = 0, name = "") {
  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="deptForm" class="grid gap-3">
      <input type="hidden" name="action" value="${id ? "update_department" : "create_department"}">
      ${id ? `<input type="hidden" name="id" value="${id}">` : ""}
      <div>
        <label class="block text-sm mb-1">Tên Khoa</label>
        <input name="name" value="${name}" required class="w-full px-3 py-2 border rounded-lg">
      </div>
      <div class="flex justify-end gap-2 mt-3">
        <button type="button" class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
        <button class="px-4 py-2 bg-secondary text-white rounded-lg" data-primary>Lưu</button>
      </div>
    </form>
  `;

  modal(wrap, id ? "Sửa Khoa" : "Thêm Khoa", "small");

  const form = wrap.querySelector("#deptForm");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const fd = new FormData(form);

    const res = await fetch(DEPT_API, {
      method: "POST",
      body: fd,
      credentials: "include",
    });

    const json = await res.json();

    if (!json.ok) {
      toast(json.error || "Lỗi khi lưu Khoa", "error");
      return;
    }

    // ✅ THÊM TOAST THÀNH CÔNG
    toast(id ? "Đã cập nhật khoa" : "Đã thêm khoa", "success");

    closeModal();

    await refreshDepartments(); // 🔥 CẬP NHẬT NGAY
    // reload đúng bảng KHOA
    jumpPageAjax("dept", 1, window.TOTAL_PAGES_DEPT);
  });
}


function openCourseModal(id = 0, name = "") {
  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="courseForm" class="grid gap-3">
      <input type="hidden" name="action" value="${id ? "update_course" : "create_course"}">
      ${id ? `<input type="hidden" name="id" value="${id}">` : ""}
      <div>
        <label class="block text-sm mb-1">Tên Khóa học</label>
        <input name="name" value="${name}" required class="w-full px-3 py-2 border rounded-lg">
      </div>
      <div class="flex justify-end gap-2 mt-3">
        <button type="button" class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
        <button class="px-4 py-2 bg-secondary text-white rounded-lg" data-primary>Lưu</button>
      </div>
    </form>
  `;
  modal(wrap, id ? "Sửa Khóa học" : "Thêm Khóa học", "small");

  const form = wrap.querySelector("#courseForm");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const fd = new FormData(form);

    const res = await fetch(DEPT_API, {
      method: "POST",
      body: fd,
      credentials: "include"
    });

    const json = await res.json();

    if (!json.ok) {
      toast(json.error || "Lỗi khi lưu Khóa học");
      return;
    }

    toast(id ? "Đã cập nhật khóa học" : "Đã thêm khóa học", "success");
    closeModal();

    await refreshCourses(); // 🔥 CẬP NHẬT NGAY
    // reload lại đúng bảng KHÓA
    jumpPageAjax("course", 1, window.TOTAL_PAGES_COURSE);
  });

}

function openClassModal(id = 0, name = "", dept = 0, course = 0) {
  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="classForm" class="grid gap-3">
      <input type="hidden" name="action" value="${id ? "update_class" : "create_class"}">
      ${id ? `<input type="hidden" name="id" value="${id}">` : ""}

      <div>
        <label class="block text-sm mb-1">Tên Lớp</label>
        <input name="name" value="${name}" required
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm mb-1">Khoa</label>
        <select name="department_id" required
          class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Chọn Khoa --</option>
          ${(window.departments || [])
      .map(d =>
        `<option value="${d.id}" ${dept == d.id ? "selected" : ""}>${d.name}</option>`
      )
      .join("")}
        </select>
      </div>

      <div>
        <label class="block text-sm mb-1">Khóa học</label>
        <select name="course_id" required
          class="w-full px-3 py-2 border rounded-lg">
          <option value="">-- Chọn Khóa --</option>
          ${(window.courses || [])
      .map(c =>
        `<option value="${c.id}" ${course == c.id ? "selected" : ""}>${c.name}</option>`
      )
      .join("")}
        </select>
      </div>

      <div class="flex justify-end gap-2 mt-3">
        <button type="button" class="px-4 py-2 border rounded-lg"
          onclick="closeModal()">Hủy</button>
        <button class="px-4 py-2 bg-secondary text-white rounded-lg" data-primary>
          Lưu
        </button>
      </div>
    </form>
  `;

  modal(wrap, id ? "Sửa Lớp" : "Thêm Lớp", "small");

  const form = wrap.querySelector("#classForm");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const fd = new FormData(form);

    const res = await fetch(DEPT_API, {
      method: "POST",
      body: fd,
      credentials: "include",
    });

    const json = await res.json();

    if (!json.ok) {
      toast(json.error || "Lỗi khi lưu Lớp");
      return;
    }

    toast(id ? "Đã cập nhật lớp" : "Đã thêm lớp", "success");
    closeModal();

    // reload lại đúng bảng LỚP
    jumpPageAjax("class", 1, window.TOTAL_PAGES_CLASS);
  });
}


async function delItem(type, action, id) {
  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <div class="text-center space-y-4">
      <div class="text-base font-medium">Xác nhận xóa</div>
      <div class="text-sm text-gray-600">
        Bạn chắc chắn muốn xóa mục này?
      </div>

      <div class="flex justify-center gap-2 pt-2">
        <button
          type="button"
          class="px-4 py-2 border rounded-lg"
          onclick="closeModal()">
          Hủy
        </button>

        <button
          type="button"
          id="btnConfirmDelete"
          data-primary
          class="px-4 py-2 bg-red-600 text-white rounded-lg">
          Xóa
        </button>
      </div>
    </div>
  `;

  modal(wrap, "Xóa dữ liệu", "small");

  const btnYes = wrap.querySelector("#btnConfirmDelete");

  btnYes.onclick = async () => {
    btnYes.disabled = true;
    btnYes.textContent = "Đang xóa...";

    try {
      const fd = new FormData();
      fd.append("action", action);
      fd.append("id", id);

      const res = await fetch(DEPT_API, {
        method: "POST",
        body: fd,
        credentials: "include"
      });

      const json = await res.json();
      if (!json.ok) throw new Error(json.error || "Xóa thất bại");

      toast("Đã xóa", "success");
      closeModal();

      // 🔥 reload lại đúng bảng + đúng trang (AJAX)
      const totalPages = window[`TOTAL_PAGES_${type.toUpperCase()}`];
      const input = document.querySelector(`.pager-${type} input[type="number"]`);
      const currentPage = input ? parseInt(input.value) || 1 : 1;

      jumpPageAjax(type, currentPage, totalPages);

    } catch (err) {
      toast(err.message || "Lỗi khi xóa", "error");
      btnYes.disabled = false;
      btnYes.textContent = "Xóa";
    }
  };
}

async function refreshDepartments() {
  const res = await fetch(`${DEPT_API}?action=list_departments&page=1`, {
    credentials: "include"
  });
  const json = await res.json();
  if (json.ok) window.departments = json.data;
}

async function refreshCourses() {
  const res = await fetch(`${DEPT_API}?action=list_courses&page=1`, {
    credentials: "include"
  });
  const json = await res.json();
  if (json.ok) window.courses = json.data;
}


document.addEventListener("DOMContentLoaded", () => {
  renderPagerAjaxUI("dept", 1, window.TOTAL_PAGES_DEPT);
  renderPagerAjaxUI("course", 1, window.TOTAL_PAGES_COURSE);
  renderPagerAjaxUI("class", 1, window.TOTAL_PAGES_CLASS);

  // 🔥 LOAD DATA TRANG 1 NGAY LẬP TỨC
  jumpPageAjax("dept", 1, window.TOTAL_PAGES_DEPT);
  jumpPageAjax("course", 1, window.TOTAL_PAGES_COURSE);
  jumpPageAjax("class", 1, window.TOTAL_PAGES_CLASS);
});

async function openSchoolYearConfigModal() {
  const wrap = document.createElement("div");
  wrap.className = "space-y-4";
  wrap.innerHTML = `
    <!-- Form thêm năm học -->
    <form id="syAddForm" class="flex gap-2 items-end border-b pb-4">
      <div class="flex-1">
        <label class="block text-sm font-medium text-gray-700 mb-1">Thêm năm học mới</label>
        <input type="text" name="year_label" required placeholder="VD: 2025-2026" 
          class="w-full px-3 py-2 border rounded-lg text-sm"
          pattern="^\\d{4}-\\d{4}$" title="Định dạng bắt buộc YYYY-YYYY (VD: 2025-2026)">
      </div>
      <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-semibold hover:bg-blue-800 h-[38px] flex items-center justify-center">
        + Thêm
      </button>
    </form>

    <!-- Danh sách năm học -->
    <div>
      <h3 class="text-sm font-semibold text-gray-700 mb-2">Danh sách năm học</h3>
      <div class="max-h-60 overflow-y-auto border rounded-lg divide-y bg-gray-50" id="syListContainer">
        <div class="p-4 text-center text-gray-400 text-sm">Đang tải...</div>
      </div>
    </div>

    <!-- Nút đóng -->
    <div class="flex justify-end pt-2">
      <button type="button" class="px-4 py-2 border rounded-lg text-sm" onclick="closeModal()">Đóng</button>
    </div>
  `;

  modal(wrap, "Quản lý Năm học", "medium");

  const listContainer = wrap.querySelector("#syListContainer");
  const addForm = wrap.querySelector("#syAddForm");

  // Hàm tải danh sách năm học
  async function loadSchoolYears() {
    listContainer.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">Đang tải...</div>';
    try {
      const res = await fetch("controllers/school_years.php?action=list", { credentials: "include" });
      const json = await res.json();
      if (!json.ok) {
        listContainer.innerHTML = `<div class="p-4 text-center text-red-500 text-sm">${json.error || "Lỗi tải dữ liệu"}</div>`;
        return;
      }

      const items = json.data || [];
      if (items.length === 0) {
        listContainer.innerHTML = '<div class="p-4 text-center text-gray-500 text-sm">Chưa có năm học nào</div>';
        return;
      }

      listContainer.innerHTML = "";
      items.forEach(item => {
        const row = document.createElement("div");
        row.className = "flex items-center justify-between p-3 bg-white hover:bg-gray-50 transition-colors";
        row.innerHTML = `
          <div class="flex items-center gap-2">
            <span class="font-medium text-gray-800">${item.year_label}</span>
            ${item.is_active == 1 
              ? `<span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full font-semibold">Đang hoạt động</span>`
              : `<span class="px-2 py-0.5 bg-gray-100 text-gray-500 text-xs rounded-full font-semibold">Khóa</span>`
            }
          </div>
          <button type="button" class="px-2.5 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 rounded-lg transition-colors border border-red-200 hover:border-red-300 btn-delete-sy" data-id="${item.id}" data-label="${item.year_label}">
            Xóa
          </button>
        `;
        listContainer.appendChild(row);
      });

      // Bind sự kiện xóa
      rowDeleteHandler();

    } catch (e) {
      listContainer.innerHTML = '<div class="p-4 text-center text-red-500 text-sm">Lỗi kết nối máy chủ</div>';
    }
  }

  // Xử lý xóa năm học
  function rowDeleteHandler() {
    listContainer.querySelectorAll(".btn-delete-sy").forEach(btn => {
      btn.onclick = async () => {
        const id = btn.dataset.id;
        const label = btn.dataset.label;
        if (!confirm(`Bạn có chắc chắn muốn xóa năm học ${label}?`)) return;

        btn.disabled = true;
        btn.textContent = "Đang xóa...";

        try {
          const fd = new FormData();
          fd.append("action", "delete");
          fd.append("id", id);

          const res = await fetch("controllers/school_years.php", {
            method: "POST",
            body: fd,
            credentials: "include"
          });
          const json = await res.json();
          if (json.ok) {
            toast("Đã xóa năm học thành công", "success");
            loadSchoolYears();
          } else {
            toast(json.error || "Lỗi khi xóa", "error");
            btn.disabled = false;
            btn.textContent = "Xóa";
          }
        } catch (err) {
          toast("Lỗi kết nối máy chủ", "error");
          btn.disabled = false;
          btn.textContent = "Xóa";
        }
      };
    });
  }

  // Xử lý thêm năm học
  addForm.onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(addForm);
    fd.append("action", "create");

    const submitBtn = addForm.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = "Đang thêm...";

    try {
      const res = await fetch("controllers/school_years.php", {
        method: "POST",
        body: fd,
        credentials: "include"
      });
      const json = await res.json();
      if (json.ok) {
        toast("Đã thêm năm học thành công", "success");
        addForm.reset();
        loadSchoolYears();
      } else {
        toast(json.error || "Lỗi khi thêm năm học", "error");
      }
    } catch (err) {
      toast("Lỗi kết nối máy chủ", "error");
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = "+ Thêm";
    }
  };

  // Tải danh sách lúc khởi chạy modal
  loadSchoolYears();
}
