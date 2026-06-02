document.getElementById("btnEditBCHProfile")?.addEventListener("click", async () => {
  const res = await api("controllers/bch_account.php?action=get");
  const text = await res.text();
  console.log("BCH get response:", text);

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    toast("❌ Backend trả dữ liệu không hợp lệ: " + text);
    return;
  }

  if (!data.ok) {
    toast("❌ " + (data.error || "Không thể tải thông tin"));
    return;
  }

  const user = data.user || {};
  const p = data.profile || {};

  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="formEditBCHProfile" class="grid grid-cols-1 sm:grid-cols-2 gap-4 w-full">

      <!-- AVATAR + URL -->
      <div class="sm:col-span-2 flex items-center gap-4">
        <img
          id="avatarPreview"
          src="${user.avatar_url || "https://via.placeholder.com/100"}"
          class="w-24 h-24 rounded-full border object-cover"
          alt="Avatar"
        >

        <div class="flex-1 min-w-0">
          <div class="text-sm text-gray-500">Tài khoản</div>
          <div class="text-base font-semibold text-gray-800 truncate">
            ${(user.fullname || user.username || "").toString()}
          </div>

          <div class="mt-3">
            <label class="block text-sm font-medium mb-1">Ảnh đại diện (URL)</label>
            <input
              type="url"
              name="avatar_url"
              value="${user.avatar_url || ""}"
              placeholder="Dán link ảnh (Google Drive, Imgur...)"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
          </div>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Điện thoại</label>
        <input type="text" name="phone" value="${p.phone || ""}"
          class="w-full px-3 py-2 border rounded-lg text-sm">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email" value="${p.email || ""}"
          class="w-full px-3 py-2 border rounded-lg text-sm">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Ngày sinh</label>
        <input type="date" name="birth" value="${p.birth || ""}"
          class="w-full px-3 py-2 border rounded-lg text-sm">
      </div>

      <div class="sm:col-span-2">
        <label class="block text-sm font-medium mb-1">Địa chỉ</label>
        <textarea name="address" rows="2"
          class="w-full px-3 py-2 border rounded-lg text-sm"
          placeholder="VD: 123 Nguyễn Văn A, P.5, Q.10, TP.HCM">${p.address || ""}</textarea>
      </div>

      <div class="sm:col-span-2 flex justify-end gap-3 mt-2">
        <button type="button" onclick="closeModal()"
          class="px-4 py-2 border rounded-lg text-sm">
          Hủy
        </button>

        <button class="px-4 py-2 bg-primary text-white rounded-lg text-sm">
          Lưu
        </button>
      </div>

    </form>
  `;

  modal(wrap, "Chỉnh sửa hồ sơ BCH", "large");

  // ✅ Preview avatar + chặn base64
  const avatarInput = wrap.querySelector('input[name="avatar_url"]');
  const avatarPreview = wrap.querySelector("#avatarPreview");

  avatarInput?.addEventListener("input", () => {
    const value = (avatarInput.value || "").trim();

    if (value.startsWith("data:image")) {
      toast("❌ Không hỗ trợ ảnh dạng base64. Dán link ảnh thật nha.");
      avatarInput.value = "";
      avatarPreview.src = "https://via.placeholder.com/100";
      return;
    }

    avatarPreview.src = value || "https://via.placeholder.com/100";
  });

  // ✅ Submit
  const form = wrap.querySelector("#formEditBCHProfile");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const fd = new FormData(form);
    fd.append("action", "update_profile");

    const r = await api("controllers/bch_account.php", {
      method: "POST",
      body: fd,
    });

    const t = await r.text();
    console.log("BCH update response:", t);

    try {
      const j = JSON.parse(t);
      if (j.ok) {
        toast("✅ Cập nhật BCH thành công!");
        closeModal();
        setTimeout(() => location.reload(), 250);
      } else {
        toast("❌ " + (j.error || "Không thể cập nhật"));
      }
    } catch {
      toast("❌ Phản hồi máy chủ không hợp lệ");
    }
  });
});
// ==================== ĐỔI MẬT KHẨU BCH ====================
document.getElementById("btnChangeBCHPassword")?.addEventListener("click", () => {
  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <form id="formChangeBCHPassword" class="space-y-4 w-full max-w-md">

      <div>
        <label class="block text-sm font-medium mb-1">Mật khẩu cũ</label>
        <input type="password" name="old_password" required autocomplete="current-password"
          class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Nhập mật khẩu hiện tại">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Mật khẩu mới</label>
        <input type="password" name="new_password" required minlength="6" autocomplete="new-password"
          class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Ít nhất 6 ký tự">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Nhập lại mật khẩu mới</label>
        <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password"
          class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Nhập lại mật khẩu mới">
      </div>

      <div class="flex justify-end gap-3 mt-6">
        <button type="button" onclick="closeModal()" 
          class="px-4 py-2 border rounded-lg text-sm">Hủy</button>
        <button type="submit" 
          class="px-6 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium">
          Xác nhận đổi mật khẩu
        </button>
      </div>

    </form>
  `;

  modal(wrap, "Đổi mật khẩu BCH", "medium");

  // Xử lý submit
  const form = wrap.querySelector("#formChangeBCHPassword");
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const fd = new FormData(form);
    fd.append("action", "change_password");

    // Kiểm tra khớp mật khẩu mới
    if (fd.get("new_password") !== fd.get("confirm_password")) {
      toast("❌ Mật khẩu mới không khớp nhau!");
      return;
    }

    const r = await api("controllers/bch_account.php", {
      method: "POST",
      body: fd,
    });

    const t = await r.text();
    console.log("Change password response:", t);

    try {
      const j = JSON.parse(t);
      if (j.ok) {
        toast("✅ Đổi mật khẩu thành công!");
        closeModal();
        // Tùy chọn: logout để đăng nhập lại (an toàn hơn)
        // setTimeout(() => location.href = "logout.php", 1200);
      } else {
        toast("❌ " + (j.error || "Không thể đổi mật khẩu"));
      }
    } catch {
      toast("❌ Phản hồi máy chủ không hợp lệ");
    }
  });
});