// assets/js/auth.js

const loginHtml = `
  <div class="bg-card rounded-2xl w-full max-w-md ring-1 ring-black/5 p-8 md:p-10">
    <div class="flex items-center justify-center gap-4 mb-6">
      <img src="assets/images/logo_truong.png" class="h-14">
      <img src="assets/images/logo_doan.png" class="h-14">
    </div>

    <p class="text-center text-subtext text-sm mt-1 mb-6">
      Cổng thông tin quản lý Đoàn Thanh Niên
    </p>

    <div id="loginError" class="hidden mb-4 text-danger text-sm text-center"></div>

    <form id="loginModalForm" class="space-y-5">
      <input type="hidden" name="csrf" id="loginCsrf">

      <div>
        <label class="block text-sm font-medium mb-1">Mã số sinh viên (MSSV)</label>
        <input name="username" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary focus:outline-none" required>
      </div>

      <div class="relative">
        <label class="block text-sm font-medium mb-1">Mật khẩu</label>
        <input type="password" name="password" id="modalPassword"
          class="w-full px-3 py-2 pr-10 border rounded-lg focus:ring-2 focus:ring-primary focus:outline-none" required>
        <button type="button" id="togglePwd"
          class="absolute right-3 top-[38px] text-gray-400 hover:text-gray-600">👁</button>
      </div>

      <button class="w-full bg-primary text-white py-2 rounded-lg font-semibold hover:bg-blue-800 transition">
        Đăng nhập
      </button>
    </form>

    <div class="text-center mt-6 text-xs text-subtext">
      © ${new Date().getFullYear()} Đoàn Thanh Niên – Cổng thông tin quản lý
    </div>
  </div>
`;

function openLoginModal() {
  modal(loginHtml, "", "medium", { noHeader: true });
  bindLoginModal();
}

function maskEmail(email = "") {
  const s = String(email || "");
  const at = s.indexOf("@");
  if (at <= 1) return s || "—";
  const name = s.slice(0, at);
  const domain = s.slice(at + 1);
  return `${name.slice(0, 2)}***@${domain}`;
}

function otpHtml({ emailMasked = "..." } = {}) {
  return `
    <div class="bg-card rounded-2xl w-full max-w-md ring-1 ring-black/5 p-8 md:p-10">
      <div class="flex items-center justify-center gap-3 mb-4">
        <div class="w-10 h-10 rounded-xl bg-amber-600 text-white flex items-center justify-center">🔒</div>
        <div class="text-left">
          <div class="text-lg font-semibold">Xác minh OTP Admin</div>
          <div class="text-xs text-subtext mt-1">OTP đã gửi về: <b id="otpEmailMasked">${emailMasked}</b></div>
        </div>
      </div>

      <div id="otpError" class="hidden mb-4 text-danger text-sm text-center"></div>
      <div id="otpOk" class="hidden mb-4 text-emerald-700 text-sm text-center"></div>

      <form id="otpModalForm" class="space-y-4">
        <input type="hidden" name="csrf" id="otpCsrf">

        <div>
          <label class="block text-sm font-medium mb-1">Mã OTP (6 số)</label>
          <input id="otpCode" name="code" inputmode="numeric" maxlength="6"
            class="w-full px-3 py-3 border rounded-lg tracking-widest text-center text-lg focus:ring-2 focus:ring-primary focus:outline-none"
            placeholder="••••••" required>
        </div>

        <button class="w-full bg-amber-600 text-white py-2 rounded-lg font-semibold hover:bg-amber-700 transition">
          Xác minh
        </button>
      </form>

      <div class="mt-4 grid grid-cols-2 gap-2">
        <button type="button" id="btnResendOtp"
          class="px-4 py-2 border rounded-lg hover:bg-gray-50 transition">
          Gửi lại OTP
        </button>

        <button type="button" id="btnBackToLogin"
          class="px-4 py-2 border rounded-lg hover:bg-gray-50 transition">
          Quay lại
        </button>
      </div>

      <div class="text-center mt-4 text-xs text-subtext">
        Nếu nhập sai quá nhiều lần, hệ thống sẽ tạm khoá 60s.
      </div>
    </div>
  `;
}

async function fetchJsonSafe(url, options) {
  const res = await fetch(url, options);
  const text = await res.text();
  try {
    return { ok: true, data: JSON.parse(text), status: res.status };
  } catch {
    return { ok: false, data: text, status: res.status };
  }
}

async function showOtpModal() {
  modal(otpHtml({ emailMasked: "..." }), "", "medium", { noHeader: true });
  bindOtpModal();

  // load status (optional)
  try { /* otp_status */ } catch { }

  // ✅ tự gửi OTP ngay để user khỏi bấm "Gửi lại"
  try {
    const fd = new FormData();
    fd.append("action", "otp_resend");
    fd.append("csrf", window.CSRF_TOKEN || "");

    await fetch("controllers/auth.php", {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd
    });
  } catch { }
}


function bindLoginModal() {
  const form = document.getElementById("loginModalForm");
  const errBox = document.getElementById("loginError");
  const pwd = document.getElementById("modalPassword");

  // CSRF
  const csrfInput = document.getElementById("loginCsrf");
  if (csrfInput && window.CSRF_TOKEN) csrfInput.value = window.CSRF_TOKEN;

  document.getElementById("togglePwd").onclick = () => {
    pwd.type = pwd.type === "password" ? "text" : "password";
  };

  form.onsubmit = async (e) => {
    e.preventDefault();
    errBox.classList.add("hidden");
    errBox.textContent = "";

    if (!window.CSRF_TOKEN) {
      errBox.textContent = "Lỗi bảo mật, vui lòng tải lại trang";
      errBox.classList.remove("hidden");
      return;
    }

    const fd = new FormData(form);
    fd.append("action", "login");

    const r = await fetchJsonSafe("controllers/auth.php", {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd
    });

    if (!r.ok) {
      console.error("AUTH NOT JSON:", r.data);
      errBox.textContent = "Lỗi máy chủ, vui lòng F5";
      errBox.classList.remove("hidden");
      return;
    }

    const j = r.data;

    if (!j.ok) {
      errBox.textContent = j.error || "Đăng nhập thất bại";
      errBox.classList.remove("hidden");
      return;
    }

    // ✅ OTP required -> chuyển modal OTP (không reload, không toast success)
    if (Number(j.otp_required || 0) === 1) {
      await showOtpModal();
      return;
    }

    // ✅ Login OK
    toast("Đăng nhập thành công", "success");
    closeModal();
    setTimeout(() => location.reload(), 300);
  };
}

function bindOtpModal() {
  const form = document.getElementById("otpModalForm");
  const errBox = document.getElementById("otpError");
  const okBox = document.getElementById("otpOk");
  const codeEl = document.getElementById("otpCode");

  // CSRF
  const csrfInput = document.getElementById("otpCsrf");
  if (csrfInput && window.CSRF_TOKEN) csrfInput.value = window.CSRF_TOKEN;

  function showErr(msg) {
    okBox.classList.add("hidden");
    okBox.textContent = "";
    errBox.textContent = msg || "Xác minh thất bại";
    errBox.classList.remove("hidden");
  }
  function showOk(msg) {
    errBox.classList.add("hidden");
    errBox.textContent = "";
    okBox.textContent = msg || "OK";
    okBox.classList.remove("hidden");
  }

  document.getElementById("btnBackToLogin")?.addEventListener("click", () => {
    modal(loginHtml, "", "medium", { noHeader: true });
    bindLoginModal();
  });

  document.getElementById("btnResendOtp")?.addEventListener("click", async () => {
    errBox.classList.add("hidden");
    okBox.classList.add("hidden");

    const fd = new FormData();
    fd.append("action", "otp_resend");
    fd.append("csrf", window.CSRF_TOKEN || "");

    const r = await fetchJsonSafe("controllers/auth.php", {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd
    });

    if (!r.ok) return showErr("Lỗi máy chủ (OTP resend).");

    const j = r.data;
    if (!j.ok) return showErr(j.error || "Gửi lại OTP thất bại");

    showOk("Đã gửi lại OTP. Vui lòng kiểm tra Gmail.");
    codeEl?.focus();
  });

  form.onsubmit = async (e) => {
    e.preventDefault();
    errBox.classList.add("hidden");
    okBox.classList.add("hidden");

    const code = String(codeEl?.value || "").trim();
    if (!/^\d{6}$/.test(code)) return showErr("OTP không hợp lệ (cần 6 số)");

    const fd = new FormData();
    fd.append("action", "otp_verify");
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("code", code);

    const r = await fetchJsonSafe("controllers/auth.php", {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd
    });

    if (!r.ok) return showErr("Lỗi máy chủ (OTP verify).");

    const j = r.data;
    if (!j.ok) return showErr(j.error || "OTP không đúng");

    showOk("Xác minh thành công. Đang đăng nhập...");
    closeModal();
    // sau verify backend đã set session user_id
    setTimeout(() => location.reload(), 200);
  };

  // auto focus
  setTimeout(() => codeEl?.focus(), 50);
  codeEl?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") form.requestSubmit();
  });
}
