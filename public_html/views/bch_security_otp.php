<?php
auth_guard();

/**
 * Tuỳ hệ thống của bạn:
 * - Nếu bạn đã có hàm is_banchaphanh() trong auth.php thì dùng trực tiếp.
 * - Nếu chưa có, xem phần (2) để bổ sung helper.
 */
if (!function_exists('is_banchaphanh') || !is_banchaphanh()) {
  http_response_code(403);
  exit('Forbidden');
}
?>

<section class="p-6">
  <div class="w-full">

    <div class="bg-white border rounded-2xl shadow-card p-6 mb-4">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-primary flex items-center gap-2">
            <i data-lucide="shield-check"></i>
            Bảo mật đăng nhập (Gmail OTP)
          </h1>
          <p class="text-subtext mt-1">
            Thiết lập OTP cho tài khoản đang đăng nhập (role Ban Chấp Hành)
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <div class="px-3 py-2 rounded-xl bg-gray-50 border text-sm text-gray-700 flex items-center gap-2">
            <i data-lucide="users" class="w-4 h-4"></i>
            Ban chấp hành
          </div>
          <div class="px-3 py-2 rounded-xl bg-gray-50 border text-sm text-gray-700 flex items-center gap-2">
            <i data-lucide="clock" class="w-4 h-4"></i>
            Có ghi log thao tác
          </div>
        </div>
      </div>
    </div>

    <!-- OTP CARD ONLY -->
    <div class="bg-white border rounded-2xl shadow-card overflow-hidden">
      <div class="p-6 border-b bg-gradient-to-br from-amber-50 to-white">
        <div class="flex items-start gap-3">
          <div class="w-11 h-11 rounded-xl bg-amber-600 text-white flex items-center justify-center shrink-0">
            <i data-lucide="lock"></i>
          </div>
          <div class="min-w-0">
            <h2 class="font-semibold text-lg text-gray-900">Bảo mật đăng nhập (Gmail OTP)</h2>
            <p class="text-sm text-gray-600 mt-1">
              Khi bật, sau khi nhập mật khẩu sẽ phải nhập <b>mã OTP</b> gửi về Gmail để xác minh.
            </p>
          </div>
        </div>
      </div>

      <div class="p-6 space-y-4">
        <div class="rounded-xl border bg-gray-50 p-4 text-sm text-gray-700">
          <div class="flex items-start gap-2">
            <i data-lucide="info" class="w-4 h-4 mt-[2px]"></i>
            <div class="min-w-0">
              <div>
                Thiết lập áp dụng cho <b>tài khoản đang đăng nhập</b>.
              </div>
              <div class="text-xs text-gray-500 mt-1">
                Khuyến nghị: bấm <b>Xác minh</b> để chắc chắn email nhận OTP hoạt động trước khi bật bắt buộc.
              </div>
            </div>
          </div>
        </div>

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
          <label class="flex items-start gap-2 text-sm text-gray-700">
            <input id="adminOtpEnabled" type="checkbox" class="mt-1">
            <span>
              <b>Bật xác minh OTP qua Gmail</b>
              <div class="text-xs text-gray-500 mt-1">Nếu tắt, đăng nhập như bình thường.</div>
            </span>
          </label>

          <div class="flex items-center gap-2">
            <div class="text-sm text-gray-700">
              Trạng thái:
              <span id="adminOtpStatusBadge"
                class="ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-white text-gray-700">
                ...
              </span>
            </div>
            <div class="text-xs text-gray-500 hidden sm:block">
              Xác minh lần cuối: <span id="adminOtpVerifiedAt">...</span>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email nhận OTP</label>
            <input id="adminOtpEmail" type="email" class="w-full border rounded-xl p-2 bg-white"
              placeholder="yourname@example.com">
            <div class="mt-2 text-xs text-gray-500">
              Email này chỉ dùng để <b>nhận OTP</b>.
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Chế độ áp dụng</label>
            <select id="adminOtpMode" class="w-full border rounded-xl p-2 bg-white">
              <option value="login">Yêu cầu OTP mỗi lần đăng nhập</option>
              <option value="session">Chỉ yêu cầu 1 lần mỗi phiên</option>
              <option value="3d">Chỉ yêu cầu 1 lần mỗi 3 ngày</option>
              <option value="7d">Chỉ yêu cầu 1 lần mỗi 7 ngày</option>
            </select>
            <div class="mt-2 text-xs text-gray-500">
              3 ngày/7 ngày: yêu cầu OTP lại khi quá hạn kể từ lần xác minh gần nhất.
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
          <button id="btnSaveAdminOtp"
            class="px-4 py-3 bg-amber-600 text-white font-semibold rounded-xl hover:bg-amber-700 transition inline-flex justify-center items-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i>
            Lưu cấu hình
          </button>

          <button id="btnSendTestOtp" type="button"
            class="px-4 py-3 border rounded-xl hover:bg-gray-50 transition inline-flex justify-center items-center gap-2">
            <i data-lucide="send" class="w-4 h-4"></i>
            Xác minh
          </button>

          <button id="btnDisableOtp" type="button"
            class="px-4 py-3 border border-red-200 text-red-700 rounded-xl hover:bg-red-50 transition inline-flex justify-center items-center gap-2">
            <i data-lucide="shield-off" class="w-4 h-4"></i>
            Tắt OTP
          </button>
        </div>

        <div class="rounded-xl border bg-amber-50 p-4 text-sm text-amber-900">
          <div class="flex items-start gap-2">
            <i data-lucide="triangle-alert" class="w-4 h-4 mt-[2px]"></i>
            <div>
              <div class="font-semibold">Lưu ý</div>
              <ul class="list-disc ml-5 mt-1 space-y-1 text-sm">
                <li>OTP nên có hạn dùng ngắn (ví dụ 5 phút) và giới hạn số lần thử.</li>
                <li>Nên có chống spam “gửi lại OTP” (cooldown).</li>
                <li>Ghi log: bật/tắt, gửi OTP, verify thành công/thất bại.</li>
              </ul>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- TOAST dùng lại -->
  <div id="backupToast"
    class="fixed bottom-6 right-6 z-[9999] hidden bg-gray-900 text-white px-5 py-3 rounded-xl shadow-lg flex items-center gap-3">
    <i data-lucide="loader" class="w-5 h-5 animate-spin"></i>
    <span id="backupToastText">Đang xử lý, vui lòng chờ...</span>
  </div>
</section>

<script src="https://unpkg.com/lucide@latest"></script>

<script>
  lucide.createIcons();

  // ====== Helpers (tối thiểu) ======
  function escHtml(s) {
    return String(s ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function showToast(text) {
    const t = document.getElementById('backupToast');
    const tt = document.getElementById('backupToastText');
    if (tt) tt.textContent = text;
    t?.classList.remove('hidden');
  }
  function hideToast() {
    document.getElementById('backupToast')?.classList.add('hidden');
  }

  // Bạn đang dùng modal()/closeModal() sẵn trong layout => giữ nguyên
  function showOkModal(message) {
    const box = document.createElement('div');
    box.innerHTML = `
      <p class="text-sm text-gray-700">${message}</p>
      <div class="flex justify-end mt-4">
        <button class="px-4 py-2 bg-primary text-white rounded-lg" data-primary>Đóng</button>
      </div>
    `;
    modal(box, 'Thông báo', 'small');
    box.querySelector('[data-primary]').onclick = closeModal;
  }
  function showErrorModal(message) {
    const box = document.createElement('div');
    box.innerHTML = `
      <p class="text-sm text-red-600">❌ ${escHtml(message)}</p>
      <div class="flex justify-end mt-4">
        <button class="px-4 py-2 bg-gray-800 text-white rounded-lg" data-primary>Đóng</button>
      </div>
    `;
    modal(box, 'Lỗi', 'small');
    box.querySelector('[data-primary]').onclick = closeModal;
  }

  // ====== OTP Logic (copy từ trang settings, giữ API như cũ) ======
  const ADMIN_OTP_API = 'controllers/admin_otp_security.php';

  function isEmail(addr) {
    const s = String(addr || '').trim();
    return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s);
  }

  function setOtpBadge(enabled, verified) {
    const el = document.getElementById('adminOtpStatusBadge');
    if (!el) return;

    if (!enabled) {
      el.textContent = 'Đang tắt';
      el.className = 'ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-white text-gray-700';
      return;
    }
    if (enabled && !verified) {
      el.textContent = 'Bật (chưa xác minh)';
      el.className = 'ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-red-50 text-red-700 border-red-200';
      return;
    }
    el.textContent = 'Đang bật';
    el.className = 'ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-emerald-50 text-emerald-800 border-emerald-200';
  }

  async function loadAdminOtpStatus() {
    try {
      const res = await fetch(`${ADMIN_OTP_API}?action=status`);
      const j = await res.json();
      if (!j.ok) return;

      const enabled = Number(j.data.enabled || 0) === 1;
      const verified = Number(j.data.verified || 0) === 1;

      document.getElementById('adminOtpEnabled').checked = enabled;
      document.getElementById('adminOtpEmail').value = j.data.email || '';
      document.getElementById('adminOtpMode').value = String(j.data.mode || 'login');

      setOtpBadge(enabled, verified);
      document.getElementById('adminOtpVerifiedAt').textContent = j.data.verified_at || '—';
    } catch (e) {}
  }

  document.getElementById('btnSaveAdminOtp')?.addEventListener('click', async () => {
    const enabled = document.getElementById('adminOtpEnabled').checked ? 1 : 0;
    const email = document.getElementById('adminOtpEmail').value.trim();
    const mode = document.getElementById('adminOtpMode').value;

    if (enabled === 1 && !isEmail(email)) return showErrorModal('Vui lòng nhập email hợp lệ');

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('enabled', String(enabled));
    fd.append('email', email);
    fd.append('mode', mode);

    showToast('Đang lưu cấu hình Gmail OTP...');
    try {
      const res = await fetch(ADMIN_OTP_API, { method: 'POST', body: fd });
      const j = await res.json();
      hideToast();

      if (j.ok) {
        await loadAdminOtpStatus();
        showOkModal('✅ Đã lưu cấu hình Gmail OTP.');
      } else showErrorModal(j.error || 'Lưu thất bại');
    } catch (e) {
      hideToast();
      showErrorModal('Không thể kết nối máy chủ');
    }
  });

  document.getElementById('btnDisableOtp')?.addEventListener('click', () => {
    const box = document.createElement('div');
    box.innerHTML = `
      <div class="space-y-4">
        <p class="text-sm text-gray-700">
          Bạn có chắc muốn <b class="text-red-600">tắt OTP</b> cho tài khoản đang đăng nhập?
        </p>
        <div class="flex justify-end gap-3">
          <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
          <button class="px-4 py-2 bg-red-600 text-white rounded-lg" data-primary>Xác nhận tắt</button>
        </div>
      </div>
    `;
    modal(box, 'Xác nhận', 'small');

    box.querySelector('[data-primary]').onclick = async () => {
      closeModal();
      const fd = new FormData();
      fd.append('action', 'disable');

      showToast('Đang tắt OTP...');
      try {
        const res = await fetch(ADMIN_OTP_API, { method: 'POST', body: fd });
        const j = await res.json();
        hideToast();

        if (j.ok) {
          await loadAdminOtpStatus();
          showOkModal('✅ Đã tắt OTP.');
        } else showErrorModal(j.error || 'Thao tác thất bại');
      } catch (e) {
        hideToast();
        showErrorModal('Không thể kết nối máy chủ');
      }
    };
  });

  document.getElementById('btnSendTestOtp')?.addEventListener('click', async () => {
    const enabled = document.getElementById('adminOtpEnabled').checked ? 1 : 0;
    const email = document.getElementById('adminOtpEmail').value.trim();

    if (enabled !== 1) return showErrorModal('Bạn cần bật OTP trước khi gửi OTP thử.');
    if (!isEmail(email)) return showErrorModal('Vui lòng nhập email hợp lệ');

    showToast('Đang gửi OTP xác minh về Gmail...');
    try {
      const res = await fetch(`${ADMIN_OTP_API}?action=send_test`);
      const j = await res.json();
      hideToast();
      if (!j.ok) return showErrorModal(j.error || 'Gửi OTP thất bại');

      const box = document.createElement('div');
      box.innerHTML = `
        <div class="space-y-3">
          <p class="text-sm text-gray-700">
            Đã gửi OTP về <b>${escHtml(email)}</b>. Nhập mã để xác minh.
          </p>
          <input id="otpCodeInput" class="w-full border rounded-xl p-2 bg-white"
            placeholder="Nhập OTP (6 số)" inputmode="numeric" maxlength="6">
          <div class="flex justify-end gap-3 pt-2">
            <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
            <button class="px-4 py-2 bg-amber-600 text-white rounded-lg" data-primary>Xác minh</button>
          </div>
          <div class="text-xs text-gray-500">
            OTP có hạn dùng ngắn. Nếu chưa nhận được, hãy thử gửi lại sau.
          </div>
        </div>
      `;
      modal(box, 'Xác minh OTP', 'small');

      box.querySelector('[data-primary]').onclick = async () => {
        const code = (box.querySelector('#otpCodeInput')?.value || '').trim();
        if (!/^\d{6}$/.test(code)) return showErrorModal('OTP không hợp lệ (cần 6 chữ số)');

        closeModal();
        showToast('Đang xác minh OTP...');
        try {
          const fd = new FormData();
          fd.append('action', 'verify_test');
          fd.append('code', code);

          const res2 = await fetch(ADMIN_OTP_API, { method: 'POST', body: fd });
          const j2 = await res2.json();
          hideToast();

          if (j2.ok) {
            await loadAdminOtpStatus();
            showOkModal('✅ Xác minh thành công. OTP đã sẵn sàng áp dụng cho đăng nhập.');
          } else showErrorModal(j2.error || 'Xác minh thất bại');
        } catch (e) {
          hideToast();
          showErrorModal('Không thể kết nối máy chủ');
        }
      };
    } catch (e) {
      hideToast();
      showErrorModal('Không thể kết nối máy chủ');
    }
  });

  loadAdminOtpStatus();
</script>
