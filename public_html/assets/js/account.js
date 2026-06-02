
function toDateInput(val) {
  if (!val) return '';
  if (val.includes('-')) return val; // đã đúng YYYY-MM-DD

  const [d, m, y] = val.split('/');
  if (!y) return '';
  return `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
}
function fillSelect(select, list, selectedId, placeholder) {
  select.innerHTML = `<option value="">${placeholder}</option>`;
  list.forEach(it => {
    const opt = document.createElement('option');
    opt.value = it.id;
    opt.textContent = it.name;
    if (String(it.id) === String(selectedId)) {
      opt.selected = true;
    }
    select.appendChild(opt);
  });
}
function fillDepartmentByGroup(select, list, selectedId, groupId) {
  select.innerHTML = `<option value="">-- Chọn khoa --</option>`;

  // ==========================
  // CHI ĐOÀN LỚP → CHỈ KHOA
  // ==========================
  if (String(groupId) === '1') {
    const khoa = list.filter(d => d.type === 'khoa');

    khoa.forEach(it => {
      const opt = document.createElement('option');
      opt.value = it.id;
      opt.textContent = it.name;
      if (String(it.id) === String(selectedId)) {
        opt.selected = true;
      }
      select.appendChild(opt);
    });

    return;
  }

  // ==========================
  // CHI ĐOÀN GIÁO VIÊN
  // → KHOA + PHÒNG (CÓ LABEL)
  // ==========================
  if (String(groupId) === '2') {
    const khoa = list.filter(d => d.type === 'khoa');
    const phong = list.filter(d => d.type === 'phong');

    if (khoa.length) {
      const ogKhoa = document.createElement('optgroup');
      ogKhoa.label = 'Khoa';

      khoa.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.textContent = it.name;
        if (String(it.id) === String(selectedId)) {
          opt.selected = true;
        }
        ogKhoa.appendChild(opt);
      });

      select.appendChild(ogKhoa);
    }

    if (phong.length) {
      const ogPhong = document.createElement('optgroup');
      ogPhong.label = 'Phòng';

      phong.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.textContent = it.name;
        if (String(it.id) === String(selectedId)) {
          opt.selected = true;
        }
        ogPhong.appendChild(opt);
      });

      select.appendChild(ogPhong);
    }
  }
}


document.getElementById("btnEditProfile")?.addEventListener("click", async () => {

  // Lấy thông tin từ backend
  const res = await api("controllers/account.php?action=get");
  const text = await res.text();
  console.log("Response:", text);

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    toast("❌ Backend trả dữ liệu không hợp lệ: " + text);
    return;
  }

  if (!data.ok) {
    toast("Lỗi: " + (data.error || "Không thể tải thông tin"));
    return;
  }

  const user = data.user || {};
  const m = data.member || {};
  // 🔒 CHẶN NGAY TỪ LÚC BẤM NÚT
  const lockedProfile = Number(m?.is_locked || 0) === 1;
  if (lockedProfile) {
    toast("Hồ sơ đang bị khóa, không thể chỉnh sửa thông tin.", "error");
    return;
  }
  const memberType = (m.type || '')
    .normalize('NFC')
    .replace(/\s+/g, ' ')
    .trim();

  // FORM EDIT PROFILE
  const wrap = document.createElement("div");
  wrap.innerHTML = `
  <form id="formEditProfile" class="grid grid-cols-1 sm:grid-cols-2 gap-4 w-full">
  ${lockedProfile ? `
  <div class="sm:col-span-2 p-3 rounded-lg bg-yellow-50 border border-yellow-200 text-sm text-yellow-900">
    Hồ sơ đang bị khóa nên không thể chỉnh sửa thông tin.
  </div>
` : ``}
      <div class="sm:col-span-2 flex items-center gap-4">
      <img id="avatarPreview"
          src="${user.avatar_url || 'https://via.placeholder.com/100'}"
          class="w-24 h-24 rounded-full border object-cover">

      <div class="flex-1">
        <label class="block text-sm font-medium mb-1">Ảnh đại diện (URL)</label>
        <input type="url"
              name="avatar_url"
              value="${user.avatar_url || ''}"
              placeholder="Dán link ảnh (Google Drive, Imgur...)"
              class="w-full px-3 py-2 border rounded-lg text-sm"
              oninput="document.getElementById('avatarPreview').src = this.value || 'https://via.placeholder.com/100'">
      </div>
    </div>


    <div>
      <label class="block text-sm font-medium mb-1">MSSV</label>
      <input type="text" name="mssv" value="${m.mssv || ''}"
        class="w-full px-3 py-2 border rounded-lg bg-gray-100 cursor-not-allowed text-sm" readonly>
    </div>

    <div>
      <label class="block text-sm font-medium mb-1">Họ và tên</label>
      <input type="text" name="fullname" value="${m.fullname || ''}"
        class="w-full px-3 py-2 border rounded-lg text-sm" required>
    </div>

    <div>
      <label class="block text-sm font-medium mb-1">Điện thoại</label>
      <input type="text" name="phone" value="${m.phone || ''}"
        class="w-full px-3 py-2 border rounded-lg text-sm">
    </div>

    <div>
      <label class="block text-sm font-medium mb-1">Email</label>
      <input type="email" name="email" value="${m.email || ''}"
        class="w-full px-3 py-2 border rounded-lg text-sm">
    </div>

    <div>
      <label class="block text-sm font-medium mb-1">Ngày sinh</label>
      <input type="date" name="birth" value="${m.birth || ''}"
        class="w-full px-3 py-2 border rounded-lg text-sm">
    </div>

    <div>
      <label class="block text-sm font-medium mb-1">Nguyên quán (mô hình chính quyền 2 cấp)</label>
      <input type="text"
        name="native_place"
        value="${m.native_place || ''}"
        placeholder="Xã/Phường, Tỉnh/Thành Phố"
        class="w-full px-3 py-2 border rounded-lg text-sm">
    </div>

    <div class="sm:col-span-2">
      <label class="block text-sm font-medium mb-1">Nơi ở hiện tại (mô hình chính quyền 2 cấp)</label>
      <textarea
        name="current_address"
        rows="2"
        placeholder="Số nhà, tên đường, Thôn/ Ấp/ Khu phố, Xã/Phường, Tỉnh/Thành Phố"
        class="w-full px-3 py-2 border rounded-lg text-sm">${m.current_address || ''}</textarea>
    </div>

<div>
  <label class="block text-sm font-medium mb-1">Đối tượng</label>
  <select name="type" id="memberType"
    class="w-full px-3 py-2 border rounded-lg text-sm" required>
    <option value="">-- Chọn --</option>
    <option value="member">Đoàn viên</option>
    <option value="youth">Thanh niên</option>
  </select>
</div>

<div id="wrapJoinDate">
  <label class="block text-sm font-medium mb-1">Ngày vào Đoàn</label>
  <input type="date"
  name="join_date"
  value="${toDateInput(m.join_date)}"
  class="w-full px-3 py-2 border rounded-lg text-sm">
</div>

<div>
  <label class="block text-sm font-medium mb-1">Nhóm chi đoàn</label>
  <select name="chidoan_group_id" id="chidoanGroupSelect"
    class="w-full px-3 py-2 border rounded-lg text-sm">
    <option value="">-- Chọn nhóm chi đoàn --</option>
  </select>
</div>

<div>
  <label class="block text-sm font-medium mb-1">Khoa/Phòng</label>
  <select name="department_id" id="deptSelect"
    class="w-full px-3 py-2 border rounded-lg text-sm">
    <option value="">-- Chọn khoa --</option>
  </select>
</div>

<div>
  <label class="block text-sm font-medium mb-1">Khóa</label>
  <select name="course_id" id="courseSelect"
    class="w-full px-3 py-2 border rounded-lg text-sm">
    <option value="">-- Chọn khóa --</option>
  </select>
</div>

<div>
  <label class="block text-sm font-medium mb-1">Lớp</label>
  <select name="class_id" id="classSelect"
    class="w-full px-3 py-2 border rounded-lg text-sm">
    <option value="">-- Chọn lớp --</option>
  </select>
</div>



    <div>
  <label class="block text-sm font-medium mb-1">Tôn giáo</label>
  <input type="text"
    name="religion"
    value="${m.religion || ''}"
    placeholder="VD: Không, Phật giáo"
    class="w-full px-3 py-2 border rounded-lg text-sm">
</div>

<div>
  <label class="block text-sm font-medium mb-1">Dân tộc</label>
  <input type="text"
    name="ethnicity"
    value="${m.ethnicity || ''}"
    placeholder="VD: Kinh"
    class="w-full px-3 py-2 border rounded-lg text-sm">
</div>

<!-- ĐẢNG VIÊN -->
<div class="sm:col-span-2">
  <label class="inline-flex items-center gap-2 cursor-pointer">
    <input type="checkbox"
           id="chkParty"
           class="w-4 h-4"
    >
    <span class="text-sm font-medium">Là Đảng viên</span>
  </label>
</div>

<div id="wrapPartyDates" class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4 hidden">
  <div>
    <label class="block text-sm font-medium mb-1">Ngày dự bị</label>
    <input type="date"
           name="party_probation_date"
           class="w-full px-3 py-2 border rounded-lg text-sm">
  </div>

  <div>
    <label class="block text-sm font-medium mb-1">Ngày chính thức</label>
    <input type="date"
           name="party_official_date"
           class="w-full px-3 py-2 border rounded-lg text-sm">
  </div>
</div>


    <div class="sm:col-span-2 flex justify-end gap-3 mt-4">
      <button type="button" onclick="closeModal()" 
        class="px-4 py-2 border rounded-lg text-sm">Hủy</button>

<button type="submit" id="btnSaveProfile"
  class="px-4 py-2 bg-primary text-white rounded-lg text-sm"
  ${lockedProfile ? "disabled" : ""}>
  ${lockedProfile ? "Đã khóa" : "Lưu"}
</button>

    </div>

  </form>
`;

  // MỞ MODAL
  modal(wrap, "Chỉnh sửa thông tin cá nhân", "large");
  if (lockedProfile) {
    const form = wrap.querySelector("#formEditProfile");

    // khóa toàn bộ input/select/textarea (trừ readonly MSSV thì vẫn ok)
    form.querySelectorAll("input, select, textarea").forEach(el => {
      // cho phép mssv readonly vẫn giữ nguyên (đang readonly rồi)
      if (el.name === "mssv") return;

      el.disabled = true;
      el.classList.add("opacity-60", "cursor-not-allowed");
    });

    // nút lưu
    const btnSave = wrap.querySelector("#btnSaveProfile");
    if (btnSave) {
      btnSave.disabled = true;
      btnSave.classList.add("opacity-60", "cursor-not-allowed");
    }
  }

  // ===== SELECTORS =====
  const deptSelect = wrap.querySelector('#deptSelect');
  const courseSelect = wrap.querySelector('#courseSelect');
  const classSelect = wrap.querySelector('#classSelect');

  // ===== DATA TỪ CONTROLLER =====
  const departments = data.departments || [];
  const courses = data.courses || [];
  const classes = data.classes || [];

  const chidoanGroups = data.chidoan_groups || [];

  const chidoanSelect = wrap.querySelector('#chidoanGroupSelect');
  const deptWrap = deptSelect.closest('div');
  const courseWrap = courseSelect.closest('div');
  const classWrap = classSelect.closest('div');

  const chkParty = wrap.querySelector('#chkParty');
  const wrapPartyDates = wrap.querySelector('#wrapPartyDates');
  const probationInput = wrap.querySelector('input[name="party_probation_date"]');
  const officialInput = wrap.querySelector('input[name="party_official_date"]');

  // set dữ liệu ban đầu từ member
  probationInput.value = toDateInput(m.party_probation_date);
  officialInput.value = toDateInput(m.party_official_date);

  // nếu đã có ngày → auto check
  if (m.party_probation_date || m.party_official_date) {
    chkParty.checked = true;
    wrapPartyDates.classList.remove('hidden');
  }
  chkParty.addEventListener('change', () => {
    if (chkParty.checked) {
      wrapPartyDates.classList.remove('hidden');
    } else {
      wrapPartyDates.classList.add('hidden');

      // clear ngày nếu bỏ check
      probationInput.value = '';
      officialInput.value = '';
    }
  });


  chidoanSelect.innerHTML =
    `<option value="">-- Chọn nhóm chi đoàn --</option>` +
    chidoanGroups
      .map(g => `<option value="${g.id}">${g.name}</option>`)
      .join('');

  const currentGroupId = m.chidoan_group_id
    ? String(m.chidoan_group_id)
    : '';


  // set chi đoàn
  chidoanSelect.value = currentGroupId;

  // apply layout nhưng KHÔNG reset
  applyChidoanGroup(chidoanSelect.value, true);

  // đổ khoa
  fillDepartmentByGroup(
    deptSelect,
    departments,
    m.department_id,
    chidoanSelect.value
  );

  // đổ khóa
  fillSelect(courseSelect, courses, m.course_id, '-- Chọn khóa --');

  // đổ lớp (PHẢI SAU KHI KHOA + KHÓA CÓ GIÁ TRỊ)
  renderClasses(true);

  // ===== ĐỔI LỚP → AUTO SET KHOA + KHÓA =====
  deptSelect.addEventListener('change', () => {
    courseSelect.value = '';
    renderClasses(false);
  });

  courseSelect.addEventListener('change', () => {
    renderClasses(false);
  });
  function renderClasses(isInit = false) {
    // ❌ chỉ áp dụng cho CHI ĐOÀN LỚP
    if (chidoanSelect.value !== '1') {
      classWrap.style.display = 'none';
      classSelect.innerHTML = '<option value="">-- Chọn lớp --</option>';
      return;
    }

    const deptId = deptSelect.value;
    const courseId = courseSelect.value;



    // ✅ đủ điều kiện → render lớp
    const list = classes.filter(
      c =>
        String(c.department_id) === String(deptId) &&
        String(c.course_id) === String(courseId)
    );

    classWrap.style.display = '';

    classSelect.innerHTML =
      '<option value="">-- Chọn lớp --</option>' +
      list
        .map(
          c =>
            `<option value="${c.id}" ${isInit && String(c.id) === String(m.class_id) ? 'selected' : ''
            }>${c.name}</option>`
        )
        .join('');
  }



  const typeSelect = wrap.querySelector('#memberType');
  const joinWrap = wrap.querySelector('#wrapJoinDate');
  const joinInput = wrap.querySelector('input[name="join_date"]');

  // SET GIÁ TRỊ BAN ĐẦU
  if (m.type === 'member' || m.type === 'youth') {
    typeSelect.value = m.type;
  } else {
    typeSelect.value = '';
  }

  function applyChidoanGroup(gid, isInit = false) {

    // ❌ CHỈ RESET KHI USER ĐỔI
    if (!isInit) {
      deptSelect.value = '';
      courseSelect.value = '';
      classSelect.value = '';
    }

    // ẩn hết trước
    deptWrap.style.display = 'none';
    courseWrap.style.display = 'none';
    classWrap.style.display = 'none';

    if (!gid) return;

    // ======================
    // CHI ĐOÀN LỚP
    // 👉 KHOA + KHÓA + LỚP
    // ======================
    if (String(gid) === '1') {
      deptWrap.style.display = '';
      courseWrap.style.display = '';
      classWrap.style.display = '';
      return;
    }

    // ======================
    // CHI ĐOÀN GIÁO VIÊN
    // 👉 CHỈ KHOA
    // ======================
    if (String(gid) === '2') {
      deptWrap.style.display = '';
      // khóa + lớp giữ nguyên hidden
      return;
    }
  }


  chidoanSelect.addEventListener('change', () => {
    const gid = chidoanSelect.value;

    applyChidoanGroup(gid);

    fillDepartmentByGroup(
      deptSelect,
      departments,
      null,
      gid
    );
  });

  // TOGGLE NGÀY VÀO ĐOÀN
  function toggleJoinDate(isInit = false) {
    if (typeSelect.value === 'member') {
      joinWrap.classList.remove('hidden');
      joinInput.disabled = false;
    } else {
      joinWrap.classList.add('hidden');
      joinInput.disabled = true;
      if (!isInit) joinInput.value = '';
    }
  }

  // CHẠY LẦN ĐẦU
  toggleJoinDate(true);

  // KHI ĐỔI OPTION
  typeSelect.addEventListener('change', () => toggleJoinDate(false));



  // ===============================
  // CHẶN ẢNH BASE64 (data:image)
  // ===============================
  const avatarInput = wrap.querySelector('input[name="avatar_url"]');
  const avatarPreview = wrap.querySelector('#avatarPreview');

  if (avatarInput) {
    avatarInput.addEventListener("input", () => {
      const value = avatarInput.value.trim();

      if (value.startsWith("data:image")) {
        toast("❌ Không hỗ trợ ảnh dạng base64. Vui lòng dùng link khác.");
        avatarInput.value = "";
        avatarPreview.src = "https://via.placeholder.com/100";
      }
    });
  }

  // XỬ LÝ LƯU FORM
  const form = wrap.querySelector("#formEditProfile");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (lockedProfile) {
      toast("Hồ sơ đã khóa, không thể chỉnh sửa.", "error");
      return;
    }

    const fd = new FormData(form);
    fd.append("action", "update_profile");

    const r = await api("controllers/account.php", {
      method: "POST",
      body: fd
    });

    // 🔒 backend trả 423
    if (r.status === 423) {
      toast("Hồ sơ đã khóa, không thể chỉnh sửa.", "error");
      return;
    }

    const text = await r.text();
    console.log("Update response:", text);

    try {
      const j = JSON.parse(text);

      if (j.ok) {
        toast("Cập nhật thành công!");
        closeModal();
        setTimeout(() => location.reload(), 300);
      } else {
        toast("Lỗi: " + (j.error || text));
      }

    } catch {
      toast("Phản hồi máy chủ không hợp lệ");
    }
  });


});




document.getElementById("btnChangePassword")?.addEventListener("click", () => {

  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="formChangePassword" class="space-y-4">

      <div>
        <label class="block text-sm font-medium mb-1">Mật khẩu hiện tại</label>
        <input type="password" name="current_password"
          class="w-full px-3 py-2 border rounded-lg text-sm" required>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Mật khẩu mới</label>
        <input type="password" name="new_password"
          class="w-full px-3 py-2 border rounded-lg text-sm" required minlength="6">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Nhập lại mật khẩu mới</label>
        <input type="password" name="confirm_password"
          class="w-full px-3 py-2 border rounded-lg text-sm" required minlength="6">
      </div>

      <div class="flex justify-end gap-3 pt-4">
        <button type="button" onclick="closeModal()"
          class="px-4 py-2 border rounded-lg text-sm">
          Hủy
        </button>

        <button
          class="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm">
          Đổi mật khẩu
        </button>
      </div>

    </form>
  `;

  modal(wrap, "Đổi mật khẩu", "small");

  const form = wrap.querySelector("#formChangePassword");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const fd = new FormData(form);
    fd.append("action", "change_password");

    // check client-side
    if (fd.get("new_password") !== fd.get("confirm_password")) {
      toast("❌ Mật khẩu mới không khớp");
      return;
    }

    const r = await api("controllers/account.php", {
      method: "POST",
      body: fd
    });

    const text = await r.text();
    console.log("Change password response:", text);

    try {
      const j = JSON.parse(text);

      if (j.ok) {
        toast("✅ Đổi mật khẩu thành công!");
        closeModal();
      } else {
        toast("❌ " + (j.error || "Không thể đổi mật khẩu"));
      }

    } catch {
      toast("❌ Phản hồi máy chủ không hợp lệ");
    }
  });

});
