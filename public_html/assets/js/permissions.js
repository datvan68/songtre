function toast(m) { notify(m); }
let CURRENT_EDIT_USER_ID = null;
let FORCE_ROLE_MODE = false;
const selectedClasses = new Set();
let CURRENT_EDIT_ROLE_ID = null;
const BITHU_ROLE_ID = "3";
const GVCN_ROLE_ID = "6";
function normalizeVN(str = "") {
  return String(str)
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")   // bỏ dấu
    .replace(/[đ]/g, "d")             // đ -> d
    .replace(/[^a-z0-9]+/g, " ")      // ký tự lạ -> space (để search ổn định)
    .replace(/\s+/g, " ")             // gom nhiều khoảng trắng
    .trim();
}

let TOUCH_PERMISSION = false;
document.addEventListener("change", e => {
  if (e.target.name?.startsWith("perms[")) {
    TOUCH_PERMISSION = true;
  }
});
function reloadKeepPage() {
  const params = new URLSearchParams(window.location.search);
  const page = params.get("page") || "1";

  params.set("page", page);
  location.href = location.pathname + "?" + params.toString();
}
function showEl(el) {
  if (!el) return;
  el.classList.remove("hidden");
}

function hideEl(el) {
  if (!el) return;
  el.classList.add("hidden");
}

function applyChidoanGroupUI(groupId, els) {
  const { fDept, fCourse, fClass } = els;

  const deptWrap = fDept?.closest?.("div") || null;
  const courseWrap = fCourse?.closest?.("div") || null;
  const classWrap = fClass?.closest?.("div") || null;

  // ===== Chi đoàn giáo viên =====
  if (groupId === "2") {
    if (deptWrap) hideEl(deptWrap);
    if (courseWrap) hideEl(courseWrap);
    if (classWrap) hideEl(classWrap);
    return;
  }

  // ===== Chi đoàn lớp =====
  if (groupId === "1") {
    if (deptWrap) showEl(deptWrap);
    if (courseWrap) showEl(courseWrap);
    if (classWrap) showEl(classWrap);
  }
}


/* =====================================================
   PAGINATION – USERS TABLE (WITH URL STATE)
   ===================================================== */

document.addEventListener("DOMContentLoaded", () => {
  const PAGE_SIZE = 10;

  const tbody = document.getElementById("tbodyUsers");
  if (!tbody) return;

  const rows = Array.from(tbody.querySelectorAll("tr"));
  const pager = document.getElementById("paginationUsers");
  const pageInput = document.getElementById("pageInputUsers");
  const pageTotal = document.getElementById("pageTotalUsers");

  const params = new URLSearchParams(window.location.search);

  let state = {
    page: Math.max(1, parseInt(params.get("page") || "1", 10)),
    total: 1
  };

  // mặc định: tất cả match
  rows.forEach(tr => tr.dataset.match = "1");

  function getMatchedRows() {
    return rows.filter(tr => tr.dataset.match !== "0");
  }

  function updateURL() {
    const p = new URLSearchParams(window.location.search);
    p.set("page", state.page);
    history.replaceState(null, "", "?" + p.toString());
  }

  function render() {
    const matched = getMatchedRows();

    state.total = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
    if (state.page > state.total) state.page = state.total;

    rows.forEach(tr => tr.style.display = "none");

    const start = (state.page - 1) * PAGE_SIZE;
    const end = start + PAGE_SIZE;

    matched.slice(start, end).forEach(tr => tr.style.display = "");

    pageInput.value = state.page;
    pageTotal.textContent = `/ ${state.total}`;

    updateURL();
    // 👇 SHOW TABLE SAU KHI ĐÃ PHÂN TRANG
    tbody.classList.remove("hidden");
    tbody.style.visibility = "visible";
  }

  function goto(page) {
    if (page < 1) page = 1;
    if (page > state.total) page = state.total;
    state.page = page;
    render();
  }

  pager?.addEventListener("click", e => {
    const act = e.target.dataset.act;
    if (!act) return;

    if (act === "first") goto(1);
    if (act === "prev") goto(state.page - 1);
    if (act === "next") goto(state.page + 1);
    if (act === "last") goto(state.total);
  });

  pageInput?.addEventListener("change", () => {
    goto(parseInt(pageInput.value || 1, 10));
  });

  window.addEventListener("popstate", () => {
    const p = new URLSearchParams(window.location.search);
    goto(parseInt(p.get("page") || "1", 10));
  });

  // EXPORT 1 LẦN DUY NHẤT
  window.__usersPager = {
    setMatch(predicate) {
      rows.forEach(tr => tr.dataset.match = predicate(tr) ? "1" : "0");
      state.page = 1;
      render();
    },
    goto,
    refresh() {
      rows.length = 0;
      rows.push(...tbody.querySelectorAll("tr"));
    }
    ,
    getPage() {
      return state.page;
    }
  };


  // INIT DUY NHẤT
  render();
});



// Search scripts
document.addEventListener("DOMContentLoaded", () => {
  const input = document.getElementById("searchUser");
  const filterRole = document.getElementById("filterRole");

  function filterTable() {
    const raw = input.value || "";
    const q = normalizeVN(raw); // đã gom whitespace trong normalizeVN
    const roleFilterVal = String(filterRole.value || "");

    if (!window.__usersPager) return;

    window.__usersPager.setMatch(row => {
      const usernameEl = row.querySelector(".username");
      const fullnameEl = row.querySelector(".fullname");
      const roleCell = row.querySelector(".role");
      if (!usernameEl || !fullnameEl || !roleCell) return false;

      const username = normalizeVN(usernameEl.textContent || "");
      const fullname = normalizeVN(fullnameEl.textContent || "");
      const roleName = normalizeVN(roleCell.textContent || "");
      const roleId = String(roleCell.dataset.role || "");

      let matchKeyword = true;

      if (q) {
        if (q.includes(" ")) {
          // ✅ NHIỀU TỪ: PHẢI CÓ ĐÚNG CỤM LIÊN TIẾP TRONG FULLNAME
          matchKeyword = fullname.includes(q);
        } else {
          // ✅ 1 TỪ: tìm rộng (fullname/username/role)
          matchKeyword =
            fullname.includes(q) ||
            username.includes(q) ||
            roleName.includes(q);
        }
      }

      const matchRole = !roleFilterVal || roleId === roleFilterVal;
      return matchKeyword && matchRole;
    });
  }



  input.addEventListener("input", filterTable);
  filterRole.addEventListener("change", filterTable);
});


document.addEventListener('change', e => {
  if (!e.target.matches('.perm-parent')) return;

  const pid = e.target.dataset.id;
  document.querySelectorAll(`.perm-child-${pid}`)
    .forEach(cb => cb.checked = e.target.checked);
});
document.addEventListener('change', e => {
  if (!e.target.name.includes('[view]')) return;

  if (!e.target.checked) {
    const row = e.target.closest('tr');
    row.querySelectorAll('input[type=checkbox]')
      .forEach(cb => cb.checked = false);
  }
});

// ===============================================
// SỬA NGƯỜI DÙNG (CHUYỂN SANG modal() MỚI)
// ===============================================
document.querySelectorAll('.js-edit').forEach(btn =>
  btn.addEventListener('click', async () => {

    const id = btn.dataset.id;
    CURRENT_EDIT_USER_ID = id;
    FORCE_ROLE_MODE = false;
    const memberGroupId = btn.dataset.chidoanGroupId || null;

    const username = btn.dataset.username;
    const fullname = btn.dataset.fullname;
    const hasMember = btn.dataset.hasMember === "1";
    const roleId = btn.dataset.roleId; // 👈 role_id chuẩn
    CURRENT_EDIT_ROLE_ID = String(roleId); // 👈 DÒNG SỐNG CÒN

    if (!window.__ROLES__) {
      toast("Thiếu danh sách role");
      return;
    }


    const form = document.createElement("form");
    form.className = "space-y-6";

    form.innerHTML = `
      <input type="hidden" name="id" value="${id}">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
<div>
  <label class="text-sm">Tài khoản</label>

  <input name="username"
    value="${username}"
    class="w-full border rounded-lg px-3 py-2 ${hasMember ? 'bg-gray-100 text-gray-500' : ''}"
    ${hasMember ? 'readonly' : 'required'}>

  ${hasMember ? `
    <p class="mt-1 text-xs text-gray-500 italic">
      Tài khoản đoàn viên được tạo theo MSSV và không thể chỉnh sửa
    </p>
  ` : ''}
</div>

      <div>
        <label class="text-sm">Họ và tên</label>

        <input name="fullname"
          value="${fullname || ''}"
          class="w-full border rounded-lg px-3 py-2 ${hasMember ? 'bg-gray-100 text-gray-500' : ''}"
          ${hasMember ? 'readonly' : ''}>

        ${hasMember ? `
          <p class="mt-1 text-xs text-gray-500 italic">
            Họ và tên user chỉ được sửa trong thông tin đoàn viên
          </p>
        ` : ''}
      </div>

        <div>
          <label class="text-sm">Mật khẩu mới</label>
          <input name="password" class="w-full border rounded-lg px-3 py-2">
        </div>

        <div>
          <label class="text-sm">Vai trò</label>
          <select name="role_id" class="w-full border rounded-lg px-3 py-2">
            ${window.__ROLES__.map(r =>
      `<option value="${r.id}" ${String(r.id) === String(roleId) ? 'selected' : ''}>
                ${r.name}
              </option>`
    ).join("")}
          </select>
        </div>


      </div>
      <div id="bithu-extra"
        class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden">


  <div>
    <label class="text-sm">Nhóm chi đoàn</label>
    <select id="fChidoanGroup" name="chidoan_group_id"
      class="w-full border rounded-lg px-3 py-2">
      <option value="">-- Chọn nhóm --</option>
      <option value="1">Chi đoàn lớp</option>
      <option value="2">Chi đoàn giáo viên</option>
    </select>
  </div>

  <div>
    <label class="text-sm">Khoa / Phòng</label>
    <select id="fDepartment" name="department_id"
      class="w-full border rounded-lg px-3 py-2">
      <option value="">-- Chọn khoa / phòng --</option>
    </select>
  </div>

  <div>
    <label class="text-sm">Khóa học</label>
    <select id="fCourse" name="course_id"
      class="w-full border rounded-lg px-3 py-2">
      <option value="">-- Chọn khóa học --</option>
    </select>
  </div>

  <div>
    <label class="text-sm">Lớp</label>
    <select id="fClass" name="class_id"
      class="w-full border rounded-lg px-3 py-2">
      <option value="">-- Chọn lớp --</option>
    </select>
  </div>

  <!-- GVCN: NHIỀU LỚP -->
<div id="gvcn-multiclass" class="hidden col-span-2 space-y-2">

  <label class="text-sm font-medium">
    Lớp phụ trách
  </label>

  <div class="flex gap-2">
    <select id="gvcnClassSelect"
      class="flex-1 border rounded-lg px-3 py-2">
      <option value="">-- Chọn lớp --</option>
    </select>

    <button type="button"
      id="btnAddClass"
      class="px-3 py-2 bg-blue-600 text-white rounded-lg">
      +
    </button>
  </div>

  <div id="gvcnClassList" class="flex flex-wrap gap-2"></div>
</div>
</div>



  <button type="button"
    id="btnResetRole"
    class="px-4 py-2 border rounded-lg text-sm whitespace-nowrap">
    Reset về quyền Role
  </button>
  <div id="permWarning"
    class="hidden inline-flex items-center px-3 py-1.5 rounded-md text-sm
          bg-yellow-50 text-yellow-800 border border-yellow-200 whitespace-nowrap">
  </div>




<div class="overflow-auto border rounded-lg">
  <table class="w-full text-sm">
    <thead class="bg-gray-50">
      <tr>
        <th class="text-left px-2 py-1">Chức năng</th>

        <th class="text-center">
          <div class="flex flex-col items-center gap-1">
            <span class="text-xs">Xem</span>
            <input type="checkbox" class="perm-col" data-col="view">
          </div>
        </th>

        <th class="text-center">
          <div class="flex flex-col items-center gap-1">
            <span class="text-xs">Thêm</span>
            <input type="checkbox" class="perm-col" data-col="create">
          </div>
        </th>

        <th class="text-center">
          <div class="flex flex-col items-center gap-1">
            <span class="text-xs">Sửa</span>
            <input type="checkbox" class="perm-col" data-col="update">
          </div>
        </th>

        <th class="text-center">
          <div class="flex flex-col items-center gap-1">
            <span class="text-xs">Xóa</span>
            <input type="checkbox" class="perm-col" data-col="delete">
          </div>
        </th>

    <th class="text-center">
  <div class="flex flex-col items-center gap-1">
    <span class="text-xs">Duyệt</span>
    <input type="checkbox" class="perm-col" data-col="review">
  </div>
</th>

        <th class="text-center">
          <div class="flex flex-col items-center gap-1">
            <span class="text-xs">In</span>
            <input type="checkbox" class="perm-col" data-col="print">
          </div>
        </th>
      </tr>
    </thead>

<tbody id="permBody"></tbody>
  </table>
</div>


      <div class="flex justify-end gap-2">
        <button type="button" onclick="closeModal()" class="px-6 py-2 border rounded-lg">Hủy</button>
        <button class="px-6 py-2 bg-secondary text-white rounded-lg">Lưu</button>
      </div>
      
    `;

    modal(form, "Cập nhật tài khoản & phân quyền", "large");




    syncPermissionHeaders();
    // ======================
    // GVCN MULTI CLASS LOGIC
    // ======================
    const btnAddClass = form.querySelector("#btnAddClass");
    const classSelect = form.querySelector("#gvcnClassSelect");
    const classList = form.querySelector("#gvcnClassList");

    // reset khi mở modal
    selectedClasses.clear();
    classList.innerHTML = "";

    btnAddClass.addEventListener("click", () => {
      const id = classSelect.value;
      const text = classSelect.options[classSelect.selectedIndex]?.text;

      if (!id) return toast("Chọn lớp trước");

      if (selectedClasses.has(id)) {
        return toast("Lớp này đã được thêm");
      }

      selectedClasses.add(id);

      const chip = document.createElement("div");
      chip.className =
        "flex items-center gap-1 px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm";
      chip.innerHTML = `
    ${text}
    <button type="button" class="ml-1 text-red-600 font-bold">×</button>
  `;

      chip.querySelector("button").onclick = () => {
        selectedClasses.delete(id);
        chip.remove();
      };

      classList.appendChild(chip);
    });



    // 1️⃣ Load quyền user ban đầu
    await loadPermissionsByUser(id);


    const btnReset = form.querySelector("#btnResetRole");
    const roleSelect = form.querySelector('select[name="role_id"]');

    const fGroup = form.querySelector("#fChidoanGroup");
    const fDept = form.querySelector("#fDepartment");
    const fCourse = form.querySelector("#fCourse");
    const fClass = form.querySelector("#fClass");

    const bithuEls = { fGroup, fDept, fCourse, fClass };
    // 🔥 SET GROUP TỪ MEMBERS NGAY KHI MỞ FORM
    if (memberGroupId) {
      fGroup.value = String(memberGroupId);
      applyChidoanGroupUI(String(memberGroupId), bithuEls);
    }
    // load khóa học trước (1 lần)
    await loadOptions(
      fCourse,
      "controllers/permissions.php?action=courses",
      "-- Chọn khóa học --"
    );

    // ==========================
    // LOAD BÍ THƯ / GVCN SCOPE (NẾU CÓ)
    // ==========================
    if (roleSelect.value === BITHU_ROLE_ID) {
      await loadBithuScopeForUser(CURRENT_EDIT_USER_ID, bithuEls);
    }

    if (roleSelect.value === GVCN_ROLE_ID) {
      toggleBithuExtra(roleSelect, bithuEls);
      await loadGvcnScopeForUser(CURRENT_EDIT_USER_ID, bithuEls);
    }

    // ======================
    // CHỌN NHÓM CHI ĐOÀN
    // ======================
    fGroup.addEventListener("change", async () => {
      if (CURRENT_EDIT_ROLE_ID !== BITHU_ROLE_ID) return;

      const gid = fGroup.value;

      applyChidoanGroupUI(gid, bithuEls);

      fDept.innerHTML = `<option value="">-- Chọn khoa / phòng --</option>`;
      fClass.innerHTML = `<option value="">-- Chọn lớp --</option>`;

      if (gid === "1") {
        // Chi đoàn lớp → khoa
        await loadOptions(
          fDept,
          "controllers/permissions.php?action=departments&type=khoa",
          "-- Chọn khoa --"
        );
      }

    });



    // ======================
    // CHỌN KHOA / PHÒNG
    // ======================
    fDept.addEventListener("change", async () => {
      fClass.innerHTML = `<option value="">-- Chọn lớp --</option>`;
      await loadGvcnClassOptions(fDept, fCourse);
    });


    // ======================
    // CHỌN KHÓA
    // ======================
    fCourse.addEventListener("change", async () => {
      if (!fDept.value || !fCourse.value) return;

      // ❌ GVCN: không load lớp đơn
      if (CURRENT_EDIT_ROLE_ID !== GVCN_ROLE_ID) {
        await loadOptions(
          fClass,
          `controllers/permissions.php?action=classes&department_id=${fDept.value}&course_id=${fCourse.value}`,
          "-- Chọn lớp --"
        );
      }

      // ✅ luôn load lớp phụ trách cho GVCN
      await loadGvcnClassOptions(fDept, fCourse);
    });




    // init lần đầu
    toggleBithuExtra(roleSelect, bithuEls);

    // khi đổi role
    roleSelect.addEventListener("change", () => {
      toggleBithuExtra(roleSelect, bithuEls);
    });

    if (btnReset) {
      btnReset.onclick = async () => {
        if (!confirm("Reset toàn bộ quyền về role?")) return;

        FORCE_ROLE_MODE = true;

        await loadPermissionsByRole(roleSelect.value);

        document
          .querySelectorAll('input[name^="perms["]')
          .forEach(cb => cb.disabled = true);

        toast("Đã chuyển về quyền Role, bấm Lưu để áp dụng");
      };

    }


    roleSelect.addEventListener("change", async () => {
      // 🔥 CẬP NHẬT ROLE HIỆN TẠI
      CURRENT_EDIT_ROLE_ID = String(roleSelect.value);

      toggleBithuExtra(roleSelect, bithuEls);

      await loadPermissionsByRole(roleSelect.value);
      document
        .querySelectorAll('input[name^="perms["]')
        .forEach(cb => cb.disabled = false);

      FORCE_ROLE_MODE = false;

      if (CURRENT_EDIT_ROLE_ID === BITHU_ROLE_ID) {
        await loadBithuScopeForUser(CURRENT_EDIT_USER_ID, bithuEls);
      }

      if (CURRENT_EDIT_ROLE_ID === GVCN_ROLE_ID) {
        // ❌ KHÔNG GÁN GROUP
        hideEl(bithuEls.fGroup.closest("div"));

        // ✅ LOAD SCOPE GVCN
        await loadGvcnScopeForUser(CURRENT_EDIT_USER_ID, bithuEls);
      }




    });




    form.onsubmit = async e => {
      e.preventDefault();

      const f = new FormData(form);

      if (hasMember) f.delete("fullname");

      // ============================
      // QUYẾT ĐỊNH API GỌI
      // ============================

      let action = "update"; // mặc định: chỉ update info
      if (roleSelect.value === GVCN_ROLE_ID) {
        f.delete("class_id"); // ❌ cấm lớp đơn

        selectedClasses.forEach(cid => {
          f.append("class_ids[]", cid);
        });
      }

      if (FORCE_ROLE_MODE || TOUCH_PERMISSION) {
        action = "update_full";

        if (FORCE_ROLE_MODE) {
          // xóa toàn bộ perms để backend reset về role
          for (const key of [...f.keys()]) {
            if (key.startsWith("perms[")) {
              f.delete(key);
            }
          }
        }
      } else {
        // ❌ KHÔNG ĐỤNG QUYỀN → XÓA PERMS KHỎI FORM
        for (const key of [...f.keys()]) {
          if (key.startsWith("perms[")) {
            f.delete(key);
          }
        }
      }

      const res = await api(
        `controllers/permissions.php?action=${action}`,
        {
          method: "POST",
          body: f
        }
      );

      const j = await res.json();

      if (j.ok) {
        toast(
          action === "update"
            ? "Đã cập nhật thông tin tài khoản"
            : "Đã cập nhật tài khoản & phân quyền"
        );

        // ✅ UPDATE DOM NGAY TẠI TRANG
        const row = document.querySelector(
          `.js-edit[data-id="${CURRENT_EDIT_USER_ID}"]`
        )?.closest("tr");

        if (row) {
          const newUsername = f.get("username");
          const newFullname = f.get("fullname");
          const newRoleId = f.get("role_id");

          if (newUsername) {
            const u = row.querySelector(".username");
            if (u) u.textContent = newUsername;
          }

          if (newFullname) {
            const fn = row.querySelector(".fullname");
            if (fn) fn.textContent = newFullname;
          }

          if (newRoleId && window.__ROLES__) {
            const roleName =
              window.__ROLES__.find(r => String(r.id) === String(newRoleId))?.name || "";

            const roleCell = row.querySelector(".role");
            if (roleCell) {
              roleCell.textContent = roleName;
              roleCell.dataset.role = newRoleId;
            }
          }
        }

        closeModal();
      }
      else {
        toast(j.error || "Lỗi khi lưu");
      }

    };

  })
);




function renderPermissionTable(rows) {
  let html = "";

  rows.forEach(r => {
    if (r.parent_id === null) {
      html += `
        <tr class="bg-gray-100 font-semibold">
          <td colspan="7">${r.name}</td>
        </tr>`;
      return;
    }

    html += `
      <tr>
        <td class="pl-6">${r.name}</td>
        ${permCheckbox(r.id, 'view', r.can_view)}
        ${permCheckbox(r.id, 'create', r.can_create)}
        ${permCheckbox(r.id, 'update', r.can_update)}
        ${permCheckbox(r.id, 'delete', r.can_delete)}
        ${permCheckbox(r.id, 'review', r.can_review)}
        ${permCheckbox(r.id, 'print', r.can_print)}
      </tr>`;
  });

  document.getElementById("permBody").innerHTML = html;
  syncPermissionHeaders();
}

async function loadPermissionsByUser(userId) {
  const res = await api(
    `controllers/permissions_matrix.php?action=list&user_id=${userId}`
  );
  const json = await res.json();
  if (!json.ok) return toast("Không tải được quyền user");
  window.CURRENT_PERMISSION_MODE = json.mode;
  renderPermissionTable(json.rows);
  // 🔥 LƯU SNAPSHOT BAN ĐẦU
  ORIGINAL_PERMS_JSON = JSON.stringify(
    json.rows.map(r => ({
      id: r.id,
      view: r.can_view,
      create: r.can_create,
      update: r.can_update,
      delete: r.can_delete,
      review: r.can_review,
      print: r.can_print
    }))
  );
  if (window.CURRENT_PERMISSION_MODE === 'custom') {
    const w = document.getElementById("permWarning");
    if (w) {
      w.textContent = "⚠ Người dùng đang dùng quyền TÙY CHỈNH (custom)";
      w.classList.remove("hidden");
    }
  }


}

async function loadPermissionsByRole(roleId) {
  const res = await api(
    `controllers/permissions_matrix.php?action=list_by_role&role_id=${roleId}`
  );
  const json = await res.json();
  if (!json.ok) return toast("Không tải được quyền role");
  renderPermissionTable(json.rows);
}

// ===============================================
// COLUMN CHECKBOX SYNC (HEADER <-> BODY)
// ===============================================

// Header → body
document.addEventListener("change", e => {
  if (!e.target.classList.contains("perm-col")) return;

  const col = e.target.dataset.col;
  const checked = e.target.checked;

  document
    .querySelectorAll(`input[name^="perms"][name$="[${col}]"]`)
    .forEach(cb => cb.checked = checked);
});

// Body → header
document.addEventListener("change", e => {
  if (!e.target.name?.startsWith("perms[")) return;

  const match = e.target.name.match(/\[(view|create|update|delete|review|print)\]$/);
  if (!match) return;

  const col = match[1];

  const boxes = [...document.querySelectorAll(
    `input[name^="perms"][name$="[${col}]"]`
  )].filter(cb => !cb.disabled);

  const header = document.querySelector(
    `.perm-col[data-col="${col}"]`
  );
  if (!header) return;

  header.checked = boxes.length > 0 && boxes.every(cb => cb.checked);
});

function syncPermissionHeaders() {
  ['view', 'create', 'update', 'delete', 'review', 'print'].forEach(col => {
    const boxes = [...document.querySelectorAll(
      `input[name^="perms"][name$="[${col}]"]`
    )].filter(cb => !cb.disabled);

    const header = document.querySelector(`.perm-col[data-col="${col}"]`);
    if (!header) return;

    header.checked = boxes.length > 0 && boxes.every(cb => cb.checked);
  });
}


function permCheckbox(pid, action, checked) {
  return `
    <td class="text-center">
      <input type="checkbox"
        name="perms[${pid}][${action}]"
        ${checked ? 'checked' : ''}>
    </td>`;
}

async function saveUserAndPermissions(e) {
  e.preventDefault();

  const f = new FormData(e.target);

  const res = await api('controllers/permissions.php?action=update_full', {
    method: 'POST',
    body: f
  });

  const j = await res.json();

  if (j.ok) {
    toast(
      action === "update"
        ? "Đã cập nhật thông tin tài khoản"
        : "Đã cập nhật tài khoản & phân quyền"
    );

    setTimeout(() => {
      reloadKeepPage();
    }, 500);
  } else {
    toast(j.error || "Lỗi khi lưu");
  }
}

// ===============================================
// NÚT XÓA
// ===============================================
document.querySelectorAll(".js-del").forEach(btn => {
  btn.addEventListener("click", () => {
    const id = btn.dataset.id;
    const username =
      btn.dataset.username ||
      btn.closest("tr")?.querySelector(".username")?.textContent ||
      "";

    const form = document.createElement("div");
    form.className = "space-y-4";

    form.innerHTML = `
      <p class="text-gray-700">
        Bạn có chắc chắn muốn xóa tài khoản
        <strong class="text-red-600">${username}</strong>?
      </p>

      <p class="text-sm text-gray-500">
        Hành động này <strong>không thể hoàn tác</strong>.
      </p>

      <div class="flex justify-end gap-2 pt-4">
        <button type="button"
          class="px-4 py-2 border rounded-lg"
          onclick="closeModal()">
          Hủy
        </button>

        <button id="btnConfirmDelete"
          class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700" data-primary>
          Xóa
        </button>
      </div>
    `;

    modal(form, "⚠ Xác nhận xóa tài khoản");

    form.querySelector("#btnConfirmDelete").onclick = async () => {
      const fd = new FormData();
      fd.append("action", "delete");
      fd.append("id", id);

      const res = await api("controllers/permissions.php?action=delete", {
        method: "POST",
        body: fd
      });

      const json = await res.json();

      if (json.ok) {
        closeModal();
        toast(`Đã xóa tài khoản ${username}`);

        const row = btn.closest("tr");
        if (row) row.remove();

        if (window.__usersPager) {
          const currentPage = window.__usersPager.getPage();
          window.__usersPager.refresh();
          window.__usersPager.goto(currentPage);
        }

      }

    }
  });
});

// ===============================================
// ➕ THÊM TÀI KHOẢN
// ===============================================
const btnAdd = document.getElementById("btnAddUser");

if (btnAdd) {
  btnAdd.addEventListener("click", () => {

    const form = document.createElement("form");
    form.className = "space-y-6";
    form.id = "addUserForm";

    form.innerHTML = `
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <div>
          <label class="text-sm">Tài khoản (username)</label>
          <input name="username"
            class="w-full border rounded-lg px-3 py-2"
            required>
        </div>

        <div>
          <label class="text-sm">Họ và tên</label>
          <input name="fullname"
            class="w-full border rounded-lg px-3 py-2"
            required>
        </div>

        <div>
          <label class="text-sm">Mật khẩu</label>
          <input type="password" name="password"
            class="w-full border rounded-lg px-3 py-2"
            required>
        </div>

<div>
  <label class="text-sm">Vai trò</label>
  <select name="role_id" class="w-full border rounded-lg px-3 py-2">
    ${window.__ROLES__.map(r => `
      <option value="${r.id}">
        ${r.name}
      </option>
    `).join("")}
  </select>
</div>


<div class="md:col-span-2 flex justify-end gap-3 mt-4">
  <button
    type="button"
    onclick="closeModal()"
    class="px-6 py-2 border rounded-lg hover:bg-gray-50"
  >
    Hủy
  </button>

  <button
    type="submit"
    class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
  >
    Tạo tài khoản
  </button>
</div>
    `;

    modal(form, "➕ Thêm tài khoản mới");

    form.onsubmit = async (e) => {
      e.preventDefault();

      const f = new FormData(form);

      const res = await api("controllers/permissions.php?action=create", {
        method: "POST",
        body: f
      });

      const j = await res.json();

      if (j.ok) {
        notifyReload("Đã tạo tài khoản mới");
      } else {
        toast(j.error || "Không thể tạo tài khoản");
      }
    };
  });
}


function toggleBithuExtra(roleSelect, els = {}) {
  const wrap = document.getElementById("bithu-extra");
  if (!wrap) return;

  // ===== GVCN =====
  if (roleSelect.value === GVCN_ROLE_ID) {
    wrap.classList.remove("hidden");

    // ❌ KHÔNG CÓ NHÓM CHI ĐOÀN
    hideEl(els.fGroup.closest("div"));

    // ✅ CÓ KHOA + KHÓA
    showEl(els.fDept.closest("div"));
    showEl(els.fCourse.closest("div"));

    // ❌ KHÔNG CÓ LỚP ĐƠN
    removeClassSingleUI(els);

    // ✅ CHỈ DÙNG LỚP PHỤ TRÁCH (multi)
    showMultiClassUI();
    return;
  }




  // ===== BÍ THƯ =====
  if (roleSelect.value === BITHU_ROLE_ID) {
    wrap.classList.remove("hidden");

    showEl(els.fGroup.closest("div"));

    restoreClassSingleUI(els); // 👈 RẤT QUAN TRỌNG

    if (els.fGroup.value === "1") {
      showEl(els.fDept.closest("div"));
      showEl(els.fCourse.closest("div"));
      showEl(els.fClass.closest("div"));
    } else {
      hideEl(els.fDept.closest("div"));
      hideEl(els.fCourse.closest("div"));
      hideEl(els.fClass.closest("div"));
    }

    hideMultiClassUI();
    return;
  }



  // ===== ROLE KHÁC =====
  wrap.classList.add("hidden");
  hideMultiClassUI();
}





async function loadOptions(select, url, placeholder) {
  select.innerHTML = `<option value="">${placeholder}</option>`;

  const res = await api(url);
  const j = await res.json();
  if (!j.ok) return;

  j.rows.forEach(r => {
    const opt = document.createElement("option");
    opt.value = r.id;
    opt.textContent = r.name;
    select.appendChild(opt);
  });
}

async function loadGvcnClassOptions(fDept, fCourse) {
  const select = document.getElementById("gvcnClassSelect");
  if (!select) return;

  // reset
  select.innerHTML = `<option value="">-- Chọn lớp --</option>`;

  if (!fDept.value || !fCourse.value) return;

  await loadOptions(
    select,
    `controllers/permissions.php?action=classes&department_id=${fDept.value}&course_id=${fCourse.value}`,
    "-- Chọn lớp --"
  );
}

async function loadBithuScopeForUser(userId, els) {
  const { fGroup, fDept, fCourse, fClass } = els;

  const res = await api(
    `controllers/permissions.php?action=get_bithu_scope&user_id=${userId}`
  );
  const j = await res.json();
  if (!j.ok || !j.data) return;

  const s = j.data;

  // ===== CHUNG: load group + dept + course =====
  fGroup.value = String(s.chidoan_group_id);

  if (s.department_id) {
    await loadOptions(
      fDept,
      `controllers/permissions.php?action=departments&type=khoa`,
      "-- Chọn khoa / phòng --"
    );
    fDept.value = String(s.department_id);
  }

  if (s.course_id) {
    await loadOptions(
      fCourse,
      "controllers/permissions.php?action=courses",
      "-- Chọn khóa học --"
    );
    fCourse.value = String(s.course_id);
  }

  // ===== CHỈ BÍ THƯ MỚI ĐƯỢC LOAD LỚP ĐƠN =====
  if (
    CURRENT_EDIT_ROLE_ID === BITHU_ROLE_ID &&
    s.chidoan_group_id === 1 &&
    s.class_id
  ) {
    await loadOptions(
      fClass,
      `controllers/permissions.php?action=classes&department_id=${s.department_id}&course_id=${s.course_id}`,
      "-- Chọn lớp --"
    );
    fClass.value = String(s.class_id);
  }
  // 🔥 BẮT BUỘC BẬT UI
  applyChidoanGroupUI(String(s.chidoan_group_id), els);
}



function showMultiClassUI() {
  document.getElementById("gvcn-multiclass")?.classList.remove("hidden");
}

function hideMultiClassUI() {
  document.getElementById("gvcn-multiclass")?.classList.add("hidden");
  document.getElementById("gvcnClassList").innerHTML = "";

  if (typeof selectedClasses !== "undefined") {
    selectedClasses.clear();
  }

  document
    .querySelectorAll('input[name="class_ids[]"]')
    .forEach(e => e.remove());
}
async function loadGvcnScopeForUser(userId, els) {
  const { fGroup, fDept, fCourse } = els;

  const res = await api(
    `controllers/permissions.php?action=get_gvcn_classes&user_id=${userId}`
  );
  const j = await res.json();
  if (!j.ok) return;

  // 🔥 LẤY NHÓM CHI ĐOÀN TỪ MEMBERS
  const groupId = String(j.data.chidoan_group_id || "1");
  fGroup.value = groupId;

  applyChidoanGroupUI(groupId, els);

  // GVCN LUÔN LOAD KHOA + KHÓA
  await loadOptions(
    fDept,
    "controllers/permissions.php?action=departments&type=khoa",
    "-- Chọn khoa / phòng --"
  );

  await loadOptions(
    fCourse,
    "controllers/permissions.php?action=courses",
    "-- Chọn khóa học --"
  );

  const classes = j.data.classes || [];
  if (classes.length === 0) return;

  // SET KHOA + KHÓA THEO LỚP ĐẦU
  fDept.value = String(classes[0].department_id);
  fCourse.value = String(classes[0].course_id);

  // RENDER CHIP
  const list = document.getElementById("gvcnClassList");
  if (!list) return;

  list.innerHTML = "";
  selectedClasses.clear();

  classes.forEach(c => {
    const cid = String(c.id);
    selectedClasses.add(cid);

    const chip = document.createElement("div");
    chip.className =
      "flex items-center gap-1 px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm";

    chip.innerHTML = `
      ${c.name}
      <button type="button" class="ml-1 text-red-600 font-bold">×</button>
    `;

    chip.querySelector("button").onclick = () => {
      selectedClasses.delete(cid);
      chip.remove();
    };

    list.appendChild(chip);
  });

  await loadGvcnClassOptions(fDept, fCourse);
}



function removeClassSingleUI(els) {
  if (!els?.fClass) return;

  const wrap = els.fClass.closest("div");
  if (wrap) wrap.remove();

  els.fClass = null;
}
function restoreClassSingleUI(els) {
  if (els.fClass) return; // đã tồn tại thì thôi

  const courseWrap = els.fCourse.closest(".grid");
  if (!courseWrap) return;

  const div = document.createElement("div");
  div.innerHTML = `
    <label class="text-sm">Lớp</label>
    <select id="fClass" name="class_id"
      class="w-full border rounded-lg px-3 py-2">
      <option value="">-- Chọn lớp --</option>
    </select>
  `;

  courseWrap.appendChild(div);
  els.fClass = div.querySelector("select");
}
