function toast(m) { notify(m); }

/* =====================================
   ADD / EDIT ROLE
===================================== */
document.getElementById("btnAddRole")?.addEventListener("click", () => {
  openRoleForm();
});

document.querySelectorAll(".js-edit").forEach(btn => {
  btn.addEventListener("click", () => {
    openRoleForm({
      id: btn.dataset.id,
      code: btn.dataset.code,
      name: btn.dataset.name,
      description: btn.dataset.desc,
      is_active: btn.dataset.active === "1"
    });
  });
});

function openRoleForm(role = {}) {

  const form = document.createElement("form");
  form.className = "space-y-6";

  form.innerHTML = `
    <input type="hidden" name="id" value="${role.id || ''}">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">


      <div class="md:col-span-2">
        <label class="text-sm" >Tên role</label>
        <input name="name" value="${role.name || ''}"
          class="w-full border rounded-lg px-3 py-2" required>
      </div>

      <div class="md:col-span-2">
        <label class="text-sm">Mô tả</label>
        <input name="description" value="${role.description || ''}"
          class="w-full border rounded-lg px-3 py-2">
      </div>

      <div class="md:col-span-2 flex items-center gap-2">
        <input type="checkbox" name="is_active" ${role.is_active ? 'checked' : ''}>
        <span class="text-sm">Kích hoạt</span>
      </div>
    </div>

    <div class="flex justify-end gap-2">
      <button type="button" onclick="closeModal()"
        class="px-6 py-2 border rounded-lg">Hủy</button>
      <button class="px-6 py-2 bg-blue-600 text-white rounded-lg">
        Lưu
      </button>
    </div>
  `;

  modal(form, role.id ? "✏ Cập nhật Role" : "➕ Thêm Role");

  form.onsubmit = async e => {
    e.preventDefault();
    const f = new FormData(form);

    const action = role.id ? "update" : "create";
    const res = await api(`controllers/roles.php?action=${action}`, {
      method: "POST",
      body: f
    });

    const j = await res.json();
    j.ok ? notifyReload("Đã lưu role") : toast(j.error);
  };
}

/* =====================================
   DELETE ROLE
===================================== */
document.querySelectorAll(".js-del").forEach(btn => {
  btn.addEventListener("click", () => {

    const roleId = btn.dataset.id;
    const roleName =
      btn.dataset.name ||
      btn.closest("tr")?.querySelector("td")?.textContent ||
      "";

    const box = document.createElement("div");
    box.className = "space-y-4";

    box.innerHTML = `
      <p class="text-gray-700">
        Bạn có chắc chắn muốn xóa role
        <strong class="text-red-600">${roleName}</strong>?
      </p>

      <p class="text-sm text-gray-500">
        ⚠ Role bị xóa sẽ <strong>không thể sử dụng</strong> cho tài khoản mới.
      </p>

      <div class="flex justify-end gap-2 pt-4">
        <button type="button"
          class="px-4 py-2 border rounded-lg"
          onclick="closeModal()">
          Hủy
        </button>

        <button id="btnConfirmDeleteRole"
          class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700" data-primary>
          Xóa role
        </button>
      </div>
    `;

    modal(box, "⚠ Xác nhận xóa Role");

    box.querySelector("#btnConfirmDeleteRole").onclick = async () => {
      const fd = new FormData();
      fd.append("id", roleId);

      const res = await api("controllers/roles.php?action=delete", {
        method: "POST",
        body: fd
      });

      const j = await res.json();

      if (j.ok) {
        closeModal();
        notifyReload("Đã xóa role", `Role ${roleName} đã bị xóa.`);
      } else {
        toast(j.error || "Không thể xóa role");
      }
    };
  });
});


/* =====================================
   ROLE PERMISSIONS
===================================== */
document.querySelectorAll(".js-perm").forEach(btn => {
  btn.addEventListener("click", () => {
    openRolePermissions(btn.dataset.id, btn.dataset.name);
  });
});

async function openRolePermissions(roleId, roleName) {

  const res = await api(`controllers/roles.php?action=permissions&role_id=${roleId}`);
  const j = await res.json();
  if (!j.ok) return toast("Không tải được phân quyền");

  let html = "";
  j.rows.forEach(r => {
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

  const form = document.createElement("form");
  form.innerHTML = `
    <input type="hidden" name="role_id" value="${roleId}">

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


        <tbody>${html}</tbody>
      </table>
    </div>

    <div class="flex justify-end gap-2 mt-4">
      <button type="button" onclick="closeModal()"
        class="px-6 py-2 border rounded-lg">Hủy</button>
      <button class="px-6 py-2 bg-secondary text-white rounded-lg">
        Lưu quyền
      </button>
    </div>
  `;

  modal(form, `🔐 Phân quyền mặc định – ${roleName}`, "large");

  form.onsubmit = async e => {
    e.preventDefault();
    const f = new FormData(form);

    const res = await api("controllers/roles.php?action=save_permissions", {
      method: "POST",
      body: f
    });

    const j = await res.json();
    j.ok ? notify("Đã lưu phân quyền") : toast(j.error);
  };
  // ✅ Sync trạng thái header checkbox khi mở modal
  ['view', 'create', 'update', 'delete', 'review', 'print'].forEach(col => {
    const boxes = form.querySelectorAll(
      `input[name^="perms"][name$="[${col}]"]`
    );
    const header = form.querySelector(`.perm-col[data-col="${col}"]`);

    if (!header || boxes.length === 0) return;
    header.checked = [...boxes].every(cb => cb.checked);
  });

}

/* =====================================
   UTIL
===================================== */
function permCheckbox(pid, act, checked) {
  return `
    <td class="text-center">
      <input type="hidden" name="perms[${pid}][${act}]" value="0">
      <input type="checkbox"
        name="perms[${pid}][${act}]"
        value="1"
        ${checked ? "checked" : ""}>
    </td>`;
}


// ===============================================
// COLUMN CHECKBOX SYNC (HEADER <-> BODY) – MODAL SAFE
// ===============================================
document.addEventListener("change", e => {

  // 🔹 HEADER checkbox
  if (e.target.classList.contains("perm-col")) {
    const col = e.target.dataset.col;
    const checked = e.target.checked;

    const modal = e.target.closest(".modal, form");
    if (!modal) return;

    modal.querySelectorAll(
      `input[name^="perms"][name$="[${col}]"]`
    ).forEach(cb => cb.checked = checked);

    return;
  }

  // 🔹 BODY checkbox
  if (!e.target.name?.includes("perms")) return;

  const match = e.target.name.match(/\[(view|create|update|delete|review|print)\]$/);
  if (!match) return;

  const col = match[1];
  const modal = e.target.closest(".modal, form");
  if (!modal) return;

  const boxes = modal.querySelectorAll(
    `input[name^="perms"][name$="[${col}]"]`
  );

  const header = modal.querySelector(`.perm-col[data-col="${col}"]`);
  if (!header) return;

  header.checked = [...boxes].every(cb => cb.checked);
});
