<?php
auth_guard();
if (!is_admin()) {
  http_response_code(403);
  exit('Forbidden');
}
?>

<section class="p-6">
  <div class="w-full">

    <!-- Header -->
    <div class="bg-white border rounded-2xl shadow-card p-6 mb-4">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-primary flex items-center gap-2">
            <i data-lucide="settings"></i>
            Cấu hình hệ thống
          </h1>
          <p class="text-subtext mt-1">
            Chỉ dành cho quản trị viên – thao tác cẩn thận
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <div class="px-3 py-2 rounded-xl bg-gray-50 border text-sm text-gray-700 flex items-center gap-2">
            <i data-lucide="shield-check" class="w-4 h-4"></i>
            Admin-only
          </div>
          <div class="px-3 py-2 rounded-xl bg-gray-50 border text-sm text-gray-700 flex items-center gap-2">
            <i data-lucide="clock" class="w-4 h-4"></i>
            Hệ thống sẽ ghi log thao tác
          </div>
        </div>
      </div>
    </div>

    <!-- 3 cards layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="bg-white border rounded-2xl shadow-card overflow-hidden">
        <div class="p-6 border-b bg-gradient-to-br from-amber-50 to-white">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-amber-600 text-white flex items-center justify-center shrink-0">
              <i data-lucide="lock"></i>
            </div>
            <div class="min-w-0">
              <h2 class="font-semibold text-lg text-gray-900">Bảo mật đăng nhập Admin (Gmail OTP)</h2>
              <p class="text-sm text-gray-600 mt-1">
                Khi bật, sau khi nhập mật khẩu Admin sẽ phải nhập <b>mã OTP</b> gửi về Gmail để xác minh.
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
                  Thiết lập này áp dụng cho <b>tài khoản admin đang đăng nhập</b>.
                </div>
                <div class="text-xs text-gray-500 mt-1">
                  Khuyến nghị: bật OTP và kiểm tra email nhận OTP hoạt động trước khi áp dụng bắt buộc khi đăng nhập.
                </div>
              </div>
            </div>
          </div>

          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <label class="flex items-start gap-2 text-sm text-gray-700">
              <input id="adminOtpEnabled" type="checkbox" class="mt-1">
              <span>
                <b>Bật xác minh OTP qua Gmail</b>
                <div class="text-xs text-gray-500 mt-1">Nếu tắt, admin đăng nhập như bình thường.</div>
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
                Chấp nhận mọi email hợp lệ. Email này chỉ dùng để <b>nhận OTP</b>.
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
                Với 3 ngày/7 ngày: hệ thống sẽ yêu cầu OTP lại khi quá hạn kể từ lần xác minh gần nhất.
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
                <div class="font-semibold">Lưu ý bảo mật</div>
                <ul class="list-disc ml-5 mt-1 space-y-1 text-sm">
                  <li>OTP nên có hạn dùng ngắn (ví dụ 5 phút) và giới hạn số lần thử.</li>
                  <li>Nên có cơ chế chống spam “gửi lại OTP” (cooldown).</li>
                  <li>Nên ghi log đầy đủ: bật/tắt OTP, gửi OTP, verify thành công/thất bại.</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- Card SMTP: Email hệ thống -->
      <div class="bg-white border rounded-2xl shadow-card overflow-hidden">
        <div class="p-6 border-b bg-gradient-to-br from-sky-50 to-white">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-sky-600 text-white flex items-center justify-center shrink-0">
              <i data-lucide="mail"></i>
            </div>
            <div class="min-w-0">
              <h2 class="font-semibold text-lg text-gray-900">Email hệ thống (SMTP)</h2>
              <p class="text-sm text-gray-600 mt-1">
                Tài khoản email dùng để <b>gửi OTP</b> và các thông báo hệ thống. Lưu trong DB và <b>mã hoá mật
                  khẩu</b>.
              </p>
            </div>
          </div>
        </div>

        <div class="p-6 space-y-4">


          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="text-sm text-gray-700">
              Trạng thái:
              <span id="smtpStatusBadge"
                class="ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-white text-gray-700">...</span>
            </div>
            <div class="text-xs text-gray-500 hidden sm:block">
              Cập nhật: <span id="smtpUpdatedAt">...</span>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host</label>
              <input id="smtpHost" class="w-full border rounded-xl p-2 bg-white" placeholder="smtp.gmail.com">
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
              <input id="smtpPort" type="number" class="w-full border rounded-xl p-2 bg-white" placeholder="587">
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
              <select id="smtpEnc" class="w-full border rounded-xl p-2 bg-white">
                <option value="tls">TLS</option>
                <option value="ssl">SSL</option>
                <option value="none">None</option>
              </select>
              <div class="mt-2 text-xs text-gray-500">
                Gmail: <code>587 + tls</code> hoặc <code>465 + ssl</code>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">From name</label>
              <input id="smtpFromName" class="w-full border rounded-xl p-2 bg-white" placeholder="DoanThanhNien System">
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Username</label>
              <input id="smtpUser" class="w-full border rounded-xl p-2 bg-white" placeholder="sender@gmail.com">
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">From email</label>
              <input id="smtpFromEmail" type="email" class="w-full border rounded-xl p-2 bg-white"
                placeholder="sender@gmail.com">
              <div class="mt-2 text-xs text-gray-500">Khuyến nghị: trùng Username (đặc biệt với Gmail).</div>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">App Password / SMTP Password</label>
            <input id="smtpPass" type="password" class="w-full border rounded-xl p-2 bg-white"
              placeholder="Nhập để thay đổi (để trống nếu giữ nguyên)">
            <div class="mt-2 text-xs text-gray-500">
              Mật khẩu sẽ được <b>mã hoá</b> khi lưu. Nếu để trống, hệ thống giữ mật khẩu hiện tại.
            </div>
            <div class="mt-2 text-xs" id="smtpHasPassHint"></div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            <button id="btnSaveSMTP"
              class="px-4 py-3 bg-sky-600 text-white font-semibold rounded-xl hover:bg-sky-700 transition inline-flex justify-center items-center gap-2">
              <i data-lucide="save" class="w-4 h-4"></i>
              Lưu SMTP
            </button>

            <button id="btnTestSMTP" type="button"
              class="px-4 py-3 border rounded-xl hover:bg-gray-50 transition inline-flex justify-center items-center gap-2">
              <i data-lucide="check-circle" class="w-4 h-4"></i>
              Test gửi email
            </button>

            <button id="btnResetSMTP" type="button"
              class="px-4 py-3 border border-red-200 text-red-700 rounded-xl hover:bg-red-50 transition inline-flex justify-center items-center gap-2">
              <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
              Khôi phục mặc định
            </button>
          </div>

          <div class="rounded-xl border bg-sky-50 p-4 text-sm text-sky-900">
            <div class="flex items-start gap-2">
              <i data-lucide="shield-check" class="w-4 h-4 mt-[2px]"></i>
              <div class="rounded-xl border bg-sky-50 p-4 text-sm text-sky-900">
                <div class="flex items-start gap-2">
                  <i data-lucide="book-open" class="w-4 h-4 mt-[2px]"></i>
                  <div class="min-w-0">
                    <div class="font-semibold">Hướng dẫn cấu hình SMTP</div>
                    <ul class="list-disc ml-5 mt-2 space-y-1 text-sm">
                      <li><b>SMTP Host</b>: địa chỉ máy chủ gửi mail (ví dụ Gmail: <code>smtp.gmail.com</code>).</li>
                      <li><b>Port</b>: cổng SMTP (Gmail: <code>587</code> cho TLS hoặc <code>465</code> cho SSL).</li>
                      <li><b>Encryption</b>: phương thức mã hoá (Gmail: <code>TLS</code> hoặc <code>SSL</code>).</li>
                      <li><b>Username/Password</b>: tài khoản đăng nhập SMTP (với Gmail: Password là <b>App
                          Password</b>).</li>
                      <li><b>From email</b>: email hiển thị người gửi (nên trùng Username với Gmail).</li>
                    </ul>

                    <div class="mt-3 flex flex-wrap gap-2">
                      <button id="btnHowToAppPassword" type="button"
                        class="px-3 py-2 border rounded-xl text-sm hover:bg-white transition inline-flex items-center gap-2">
                        <i data-lucide="key-round" class="w-4 h-4"></i>
                        App Password là gì? Lấy ở đâu?
                      </button>

                      <button id="btnSmtpPresets" type="button"
                        class="px-3 py-2 border rounded-xl text-sm hover:bg-white transition inline-flex items-center gap-2">
                        <i data-lucide="sliders-horizontal" class="w-4 h-4"></i>
                        Chọn cấu hình mẫu
                      </button>
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <!-- Card 1: Backup -->
      <div class="bg-white border rounded-2xl shadow-card overflow-hidden">
        <div class="p-6 border-b bg-gradient-to-br from-blue-50 to-white">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-blue-600 text-white flex items-center justify-center shrink-0">
              <i data-lucide="database-backup"></i>
            </div>
            <div>
              <h2 class="font-semibold text-lg text-gray-900">Sao lưu Database</h2>
              <p class="text-sm text-gray-600 mt-1">
                Tải toàn bộ dữ liệu hệ thống dưới dạng file <code>.sql</code>.
              </p>
            </div>
          </div>
        </div>

        <div class="p-6 space-y-4">
          <div class="rounded-xl border bg-blue-50 p-4 text-sm text-blue-900">
            <div class="flex items-start gap-2">
              <i data-lucide="cloud" class="w-4 h-4 mt-[2px]"></i>
              <div class="min-w-0">
                <div>
                  Khi bấm <b>Tải file backup</b>, hệ thống sẽ:
                  <ul class="list-disc ml-5 mt-1 space-y-1">
                    <li>Tải file <code>.sql</code> về máy của bạn</li>
                    <li><b>Đồng thời lưu 1 bản</b> vào Google Drive đã kết nối </li>
                  </ul>
                </div>
                <div class="text-xs text-blue-700/80 mt-2">
                  Lưu ý: Nếu Google Drive chưa kết nối hoặc lỗi quyền truy cập, file vẫn tải về máy bình thường.
                </div>
              </div>
            </div>
          </div>

          <button id="btnBackupDB"
            class="w-full inline-flex justify-center items-center gap-2 px-5 py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition">
            <i data-lucide="download"></i>
            Tải file backup
          </button>

          <div class="text-xs text-gray-500">
            File được tạo trực tiếp từ database hiện tại và sẽ được lưu kèm lên Google Drive (nếu đã kết nối).
          </div>
        </div>

      </div>

      <!-- Card 2: Restore -->
      <div class="bg-white border border-red-200 rounded-2xl shadow-card overflow-hidden">
        <div class="p-6 border-b border-red-200 bg-gradient-to-br from-red-50 to-white">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-red-600 text-white flex items-center justify-center shrink-0">
              <i data-lucide="alert-triangle"></i>
            </div>
            <div>
              <h2 class="font-semibold text-lg text-gray-900">Phục hồi Database</h2>
              <p class="text-sm text-red-700 mt-1">
                Ghi đè toàn bộ dữ liệu hiện tại. Chỉ dùng khi thật sự cần thiết.
              </p>
            </div>
          </div>
        </div>

        <div class="p-6 space-y-4">
          <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <div class="flex items-start gap-2">
              <i data-lucide="triangle-alert" class="w-4 h-4 mt-[2px]"></i>
              <div>
                <div class="font-semibold">Cảnh báo</div>
                <div class="text-red-700/90">Dữ liệu hiện tại sẽ mất sau khi phục hồi.</div>
              </div>
            </div>
          </div>

          <form id="formRestore" enctype="multipart/form-data" class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Chọn file SQL</label>
              <input type="file" name="sql_file" accept=".sql" required
                class="block w-full border rounded-xl p-2 bg-white">
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-700">
              <input type="checkbox" required class="mt-1">
              <span>Tôi hiểu rằng dữ liệu hiện tại sẽ bị mất và tôi chịu trách nhiệm khi thực hiện thao tác này.</span>
            </label>

            <button class="w-full px-5 py-3 bg-red-600 text-white font-semibold rounded-xl hover:bg-red-700 transition">
              Phục hồi từ file SQL
            </button>
          </form>

          <div class="text-xs text-gray-500">
            Nếu file lớn, thời gian phục hồi có thể kéo dài.
          </div>
        </div>
      </div>

      <!-- Card 3: Google Drive config -->
      <div class="bg-white border rounded-2xl shadow-card overflow-hidden">
        <div class="p-6 border-b bg-gradient-to-br from-emerald-50 to-white">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0">
              <i data-lucide="cloud"></i>
            </div>
            <div>
              <h2 class="font-semibold text-lg text-gray-900">Google Drive (Drive đích)</h2>
              <p class="text-sm text-gray-600 mt-1">
                Đổi Drive đích bằng <b>Folder ID</b> + share thư mục cho Service Account.
              </p>
            </div>
          </div>
        </div>

        <div class="p-6 space-y-4">
          <div class="rounded-xl border bg-gray-50 p-4 text-sm text-gray-700">
            <div class="flex items-start gap-2">
              <i data-lucide="key-round" class="w-4 h-4 mt-[2px]"></i>
              <div class="min-w-0">
                <div class="font-medium">Service Account Email</div>
                <div class="mt-1 font-mono text-xs break-all">
                  <span id="saEmail">id-hst-uploader@du-lieu-web-song.iam.gserviceaccount.com
                  </span>
                </div>
                <div class="mt-2 text-xs text-gray-500">
                  Share folder Drive đích cho email này (Editor).
                </div>
                <div class="mt-3 flex gap-2 flex-wrap">
                  <button id="btnCopySAEmail" type="button"
                    class="px-3 py-2 border rounded-xl text-sm hover:bg-white transition inline-flex items-center gap-2">
                    <i data-lucide="copy" class="w-4 h-4"></i>
                    Copy email
                  </button>
                  <button id="btnHowToFolderId" type="button"
                    class="px-3 py-2 border rounded-xl text-sm hover:bg-white transition inline-flex items-center gap-2">
                    <i data-lucide="help-circle" class="w-4 h-4"></i>
                    Cách lấy Folder ID
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Folder ID</label>
            <input id="gdriveFolderId" class="w-full border rounded-xl p-2 bg-white"
              placeholder="Ví dụ: 1AbC... (sau /folders/ trong URL)">
            <div class="mt-2 text-xs text-gray-500">
              Folder ID là phần sau <code>/folders/</code> trong URL thư mục Google Drive.
            </div>
            <div id="gdriveInfo" class="mt-2 text-xs text-gray-600 hidden"></div>
          </div>


          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button id="btnSaveGDrive"
              class="px-4 py-3 bg-emerald-600 text-white font-semibold rounded-xl hover:bg-emerald-700 transition inline-flex justify-center items-center gap-2">
              <i data-lucide="save" class="w-4 h-4"></i>
              Lưu Folder ID
            </button>

            <button id="btnTestGDrive"
              class="px-4 py-3 border rounded-xl hover:bg-gray-50 transition inline-flex justify-center items-center gap-2">
              <i data-lucide="check-circle" class="w-4 h-4"></i>
              Test kết nối
            </button>
          </div>

          <div class="rounded-xl border bg-emerald-50 p-4 text-sm text-emerald-900">
            <div class="flex items-start gap-2">
              <i data-lucide="route" class="w-4 h-4 mt-[2px]"></i>
              <div>
                <div class="font-semibold">Quy trình đổi Drive đích</div>
                <ol class="list-decimal ml-5 mt-1 space-y-1 text-sm">
                  <li>Tạo folder trên Drive tài khoản mới.</li>
                  <li>Share folder cho Service Account Email (Editor).</li>
                  <li>Dán Folder ID → Lưu → Test.</li>
                </ol>
              </div>
            </div>
          </div>

        </div>
      </div>
      <!-- Card 4: Zalo OA config -->
      <div class="bg-white border rounded-2xl shadow-card overflow-hidden">
        <div class="p-6 border-b bg-gradient-to-br from-indigo-50 to-white">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-indigo-600 text-white flex items-center justify-center shrink-0">
              <i data-lucide="headset"></i>
            </div>
            <div>
              <h2 class="font-semibold text-lg text-gray-900">Hỗ trợ trực tuyến (Zalo OA)</h2>
              <p class="text-sm text-gray-600 mt-1">
                Bật/tắt widget chat và cấu hình OA ID hiển thị trên website.
              </p>
            </div>
          </div>
        </div>

        <div class="p-6 space-y-4">
          <label class="flex items-start gap-2 text-sm text-gray-700">
            <input id="oaEnabled" type="checkbox" class="mt-1">
            <span>
              <b>Bật Zalo OA Widget</b>
              <div class="text-xs text-gray-500 mt-1">Nếu tắt, widget sẽ không hiển thị trên website.</div>
            </span>
          </label>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">OA ID</label>
            <input id="oaId" class="w-full border rounded-xl p-2 bg-white" placeholder="Ví dụ: 1670103838041335253">
            <div class="mt-2 text-xs text-gray-500">
              OA ID là số trong đoạn nhúng: <code>data-oaid="..."</code>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Welcome message</label>
            <input id="oaWelcome" class="w-full border rounded-xl p-2 bg-white"
              placeholder="Rất vui khi được hỗ trợ bạn!">
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tự bật khung chat</label>
            <select id="oaAutopopup" class="w-full border rounded-xl p-2 bg-white">
              <option value="0">Không tự bật</option>
              <option value="1">Tự bật</option>
            </select>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button id="btnSaveOA"
              class="px-4 py-3 bg-indigo-600 text-white font-semibold rounded-xl hover:bg-indigo-700 transition inline-flex justify-center items-center gap-2">
              <i data-lucide="save" class="w-4 h-4"></i>
              Lưu cấu hình
            </button>

            <button id="btnPreviewOA" type="button"
              class="px-4 py-3 border rounded-xl hover:bg-gray-50 transition inline-flex justify-center items-center gap-2">
              <i data-lucide="play-circle" class="w-4 h-4"></i>
              Xem thử tại trang
            </button>
          </div>


        </div>
      </div>

    </div>
  </div>

  <!-- TOAST -->
  <div id="backupToast"
    class="fixed bottom-6 right-6 z-[9999] hidden bg-gray-900 text-white px-5 py-3 rounded-xl shadow-lg flex items-center gap-3">
    <i data-lucide="loader" class="w-5 h-5 animate-spin"></i>
    <span id="backupToastText">Đang xử lý, vui lòng chờ...</span>
  </div>
</section>

<script src="https://unpkg.com/lucide@latest"></script>

<script>
  lucide.createIcons();

  const DRIVE_CONFIG_API = 'controllers/drive_config.php';
  function escHtml(s) {
    return String(s ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function setGDriveInfo(html, ok = true) {
    const el = document.getElementById('gdriveInfo');
    if (!el) return;
    el.classList.remove('hidden');
    el.className = ok
      ? 'mt-2 text-xs text-emerald-700'
      : 'mt-2 text-xs text-red-600';
    el.innerHTML = html;
  }

  function showToast(text) {
    const backupToast = document.getElementById('backupToast');
    const toastText = document.getElementById('backupToastText');
    toastText.textContent = text;
    backupToast.classList.remove('hidden');
  }
  function hideToast() {
    document.getElementById('backupToast')?.classList.add('hidden');
  }

  // =========================
  // BACKUP
  // =========================
  document.getElementById('btnBackupDB')?.addEventListener('click', async () => {
    showToast('Đang sao lưu database...');
    try {
      const res = await fetch('controllers/backup_restore.php?action=export');

      const disposition = res.headers.get('Content-Disposition') || '';
      let filename = 'backup.sql';
      const match = disposition.match(/filename="?([^"]+)"?/);
      if (match) filename = match[1];

      const blob = await res.blob();

      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);

    } catch (e) {
      showErrorModal('Không thể sao lưu database');
    } finally {
      hideToast();
    }
  });

  // =========================
  // RESTORE
  // =========================
  document.getElementById('formRestore').addEventListener('submit', (e) => {
    e.preventDefault();

    const confirmBox = document.createElement('div');
    confirmBox.innerHTML = `
      <div class="space-y-4">
        <p class="text-sm text-gray-700">
          ⚠️ Thao tác này sẽ <b class="text-red-600">ghi đè toàn bộ dữ liệu hiện tại</b>.
          Bạn có chắc chắn muốn tiếp tục?
        </p>
        <div class="flex justify-end gap-3">
          <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
          <button class="px-4 py-2 bg-red-600 text-white rounded-lg" data-primary>Xác nhận phục hồi</button>
        </div>
      </div>
    `;

    modal(confirmBox, 'Xác nhận phục hồi', 'small');

    confirmBox.querySelector('[data-primary]').onclick = async () => {
      closeModal();
      await runRestore(e.target);
    };
  });

  async function runRestore(form) {
    showToast('Đang phục hồi dữ liệu, vui lòng chờ...');

    const fd = new FormData(form);
    fd.append('action', 'import');

    try {
      const res = await fetch('controllers/backup_restore.php', { method: 'POST', body: fd });
      const j = await res.json();
      hideToast();

      if (j.ok) {
        const okBox = document.createElement('div');
        okBox.innerHTML = `
          <p class="text-sm text-gray-700">
            ✅ Phục hồi database <b class="text-green-600">thành công</b>.
          </p>
          <div class="flex justify-end mt-4">
            <button class="px-4 py-2 bg-primary text-white rounded-lg" data-primary>Tải lại trang</button>
          </div>
        `;
        modal(okBox, 'Hoàn tất', 'small');
        okBox.querySelector('[data-primary]').onclick = () => { closeModal(); location.reload(); };
      } else {
        showErrorModal(j.error || 'Có lỗi xảy ra');
      }

    } catch (err) {
      hideToast();
      showErrorModal('Không thể kết nối máy chủ');
    }
  }

  // =========================
  // GOOGLE DRIVE CONFIG
  // =========================
  async function loadDriveStatus() {
    try {
      const res = await fetch(`${DRIVE_CONFIG_API}?action=status`);
      const j = await res.json();
      if (!j.ok) return;

      const sa = j.data.service_email || '(chưa có service-account.json)';
      const fid = (j.data.folder_id_db || '').trim();

      document.getElementById('saEmail').textContent = sa;

      const inp = document.getElementById('gdriveFolderId');
      if (inp) inp.value = fid;

      // Hiển thị y như lúc test
      if (fid && j.data.folder && j.data.folder.id) {
        const name = j.data.folder.name || 'Folder';
        const driveId = j.data.folder.driveId || '';
        setGDriveInfo(
          `Đã kết nối: <b>${escHtml(name)}</b> — ID: <span class="font-mono">${escHtml(fid)}</span>` +
          (driveId ? ` — DriveId: <span class="font-mono">${escHtml(driveId)}</span>` : ''),
          true
        );
      } else if (fid) {
        // có ID nhưng không lấy được info (chưa share đúng, hoặc lỗi API)
        setGDriveInfo(
          `Đã cấu hình ID: <span class="font-mono">${escHtml(fid)}</span>` +
          (j.data.folder_error ? `<div class="mt-1">Không đọc được folder: ${escHtml(j.data.folder_error)}</div>` : ''),
          false
        );
      } else {
        setGDriveInfo('Chưa cấu hình Folder ID.', false);
      }
    } catch (e) { }
  }



  document.getElementById('btnSaveGDrive')?.addEventListener('click', async () => {
    const folderId = document.getElementById('gdriveFolderId').value.trim();
    if (!folderId) return showErrorModal('Vui lòng nhập Folder ID');

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('folder_id', folderId);

    showToast('Đang lưu cấu hình Google Drive...');
    try {
      const res = await fetch(DRIVE_CONFIG_API, { method: 'POST', body: fd });
      const j = await res.json();
      hideToast();

      if (j.ok) {
        await loadDriveStatus();
        showOkModal('✅ Đã lưu Folder ID.');
      } else {
        showErrorModal(j.error || 'Lưu thất bại');
      }
    } catch (e) {
      hideToast();
      showErrorModal('Không thể kết nối máy chủ');
    }
  });

  document.getElementById('btnTestGDrive')?.addEventListener('click', async () => {
    const folderId = document.getElementById('gdriveFolderId')?.value?.trim() || '';
    if (!folderId) return showErrorModal('Vui lòng nhập Folder ID');

    showToast('Đang test kết nối Google Drive...');
    try {
      const url = `${DRIVE_CONFIG_API}?action=test&folder_id=${encodeURIComponent(folderId)}`;
      const res = await fetch(url);
      const j = await res.json();
      hideToast();

      if (j.ok) {
        const used = j.data.folder_id_used || folderId;
        const folderName = j.data.folder?.name || '';
        const driveId = j.data.folder?.driveId || '';

        setGDriveInfo(
          `Đã kết nối: <b>${escHtml(folderName || 'Folder')}</b>
         — ID: <span class="font-mono">${escHtml(used)}</span>
         ${driveId ? `— DriveId: <span class="font-mono">${escHtml(driveId)}</span>` : ''}`,
          true
        );

        showOkModal(`✅ ${escHtml(j.data.msg || 'Kết nối Google Drive OK')}`);
      } else {
        setGDriveInfo(`Test thất bại: ${escHtml(j.error || 'Unknown error')}`, false);
        showErrorModal(j.error || 'Test thất bại');
      }
    } catch (e) {
      hideToast();
      setGDriveInfo('Test thất bại: Không thể kết nối máy chủ', false);
      showErrorModal('Không thể kết nối máy chủ');
    }
  });


  document.getElementById('btnCopySAEmail')?.addEventListener('click', async () => {
    const txt = document.getElementById('saEmail')?.textContent?.trim() || '';
    if (!txt || txt.startsWith('(')) return showErrorModal('Chưa có Service Account Email');

    try {
      await navigator.clipboard.writeText(txt);
      showOkModal('✅ Đã copy Service Account Email.');
    } catch (e) {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = txt;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
      showOkModal('✅ Đã copy Service Account Email.');
    }
  });

  document.getElementById('btnHowToFolderId')?.addEventListener('click', () => {
    const box = document.createElement('div');
    box.innerHTML = `
      <div class="space-y-3 text-sm text-gray-700">
        <div class="font-semibold">Cách lấy Folder ID</div>
        <ol class="list-decimal ml-5 space-y-1">
          <li>Mở thư mục Google Drive cần dùng.</li>
          <li>Nhìn URL dạng: <code>https://drive.google.com/drive/folders/XXXX</code></li>
          <li>Copy phần <b>XXXX</b> (sau <code>/folders/</code>).</li>
        </ol>
        <div class="flex justify-end">
          <button class="px-4 py-2 bg-primary text-white rounded-lg" data-primary>Đã hiểu</button>
        </div>
      </div>
    `;
    modal(box, 'Hướng dẫn', 'small');
    box.querySelector('[data-primary]').onclick = closeModal;
  });

  loadDriveStatus();

  // =========================
  // MODALS
  // =========================
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
      <p class="text-sm text-red-600">❌ ${message}</p>
      <div class="flex justify-end mt-4">
        <button class="px-4 py-2 bg-gray-800 text-white rounded-lg" data-primary>Đóng</button>
      </div>
    `;
    modal(box, 'Lỗi', 'small');
    box.querySelector('[data-primary]').onclick = closeModal;
  }
  const ZALO_OA_API = 'controllers/zalo_oa_config.php';

  async function loadOAStatus() {
    try {
      const res = await fetch(`${ZALO_OA_API}?action=status`);
      const j = await res.json();
      if (!j.ok) return;

      document.getElementById('oaEnabled').checked = Number(j.data.enabled || 0) === 1;
      document.getElementById('oaId').value = j.data.oaid || '';
      document.getElementById('oaWelcome').value = j.data.welcome || '';
      document.getElementById('oaAutopopup').value = String(j.data.autopopup ?? 0);
    } catch (e) { }
  }

  document.getElementById('btnSaveOA')?.addEventListener('click', async () => {
    const enabled = document.getElementById('oaEnabled').checked ? 1 : 0;
    const oaid = document.getElementById('oaId').value.trim();
    const welcome = document.getElementById('oaWelcome').value.trim();
    const autopopup = document.getElementById('oaAutopopup').value;

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('enabled', String(enabled));
    fd.append('oaid', oaid);
    fd.append('welcome', welcome);
    fd.append('autopopup', autopopup);

    showToast('Đang lưu cấu hình Zalo OA...');
    try {
      const res = await fetch(ZALO_OA_API, { method: 'POST', body: fd });
      const j = await res.json();
      hideToast();

      if (j.ok) {
        await loadOAStatus();
        showOkModal('✅ Đã lưu cấu hình Zalo OA. Nếu bạn nhúng widget trong layout_headd, cấu hình sẽ áp dụng toàn hệ thống.');
      } else {
        showErrorModal(j.error || 'Lưu thất bại');
      }
    } catch (e) {
      hideToast();
      showErrorModal('Không thể kết nối máy chủ');
    }
  });

  // Xem thử ngay trong trang (không ghi DB)
  document.getElementById('btnPreviewOA')?.addEventListener('click', () => {
    const enabled = document.getElementById('oaEnabled').checked ? 1 : 0;
    const oaid = document.getElementById('oaId').value.trim();
    const welcome = document.getElementById('oaWelcome').value.trim() || 'Rất vui khi được hỗ trợ bạn!';
    const autopopup = document.getElementById('oaAutopopup').value;

    if (enabled === 1 && !/^\d+$/.test(oaid)) {
      return showErrorModal('OA ID không hợp lệ (phải là số)');
    }

    if (enabled === 0) {
      // tắt preview trên trang
      document.querySelectorAll('.zalo-chat-widget, #zaloChatWidget').forEach(el => el.remove());
      showOkModal('✅ Đã tắt widget trên trang (preview).');
      return;
    }

    mountZaloWidget({ oaid, welcome, autopopup });
    showOkModal('✅ Đã bật widget để xem thử trên trang.');
  });

  function mountZaloWidget({ oaid, welcome, autopopup }) {
    // Xoá widget cũ (tránh nhân đôi)
    document.querySelectorAll('.zalo-chat-widget, #zaloChatWidget').forEach(el => el.remove());

    // Tạo widget container (append vào BODY để tránh bị ảnh hưởng layout/transform)
    const div = document.createElement('div');
    div.id = 'zaloChatWidget';
    div.className = 'zalo-chat-widget';
    div.setAttribute('data-oaid', oaid);
    div.setAttribute('data-welcome-message', welcome);
    div.setAttribute('data-autopopup', String(autopopup));
    div.setAttribute('data-width', '');
    div.setAttribute('data-height', '');
    document.body.appendChild(div);

    // Ép góc phải dưới
    div.style.position = 'fixed';
    div.style.right = '20px';
    div.style.left = 'auto';
    div.style.bottom = '20px';
    div.style.zIndex = '999999';

    // Reload sdk để re-scan widget
    const old = document.querySelector('script[src="https://sp.zalo.me/plugins/sdk.js"]');
    if (old) old.remove();

    const s = document.createElement('script');
    s.src = 'https://sp.zalo.me/plugins/sdk.js';
    s.async = true;
    document.body.appendChild(s);
  }

  loadOAStatus();

  // =========================
  // ADMIN OTP (GMAIL) CONFIG
  // =========================
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
    } catch (e) { }
  }

  document.getElementById('btnSaveAdminOtp')?.addEventListener('click', async () => {
    const enabled = document.getElementById('adminOtpEnabled').checked ? 1 : 0;
    const email = document.getElementById('adminOtpEmail').value.trim();
    const mode = document.getElementById('adminOtpMode').value;

    if (enabled === 1 && !isEmail(email)) {
      return showErrorModal('Vui lòng nhập email hợp lệ');
    }


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
      } else {
        showErrorModal(j.error || 'Lưu thất bại');
      }
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
        Bạn có chắc muốn <b class="text-red-600">tắt OTP</b> cho admin đang đăng nhập?
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
        } else {
          showErrorModal(j.error || 'Thao tác thất bại');
        }
      } catch (e) {
        hideToast();
        showErrorModal('Không thể kết nối máy chủ');
      }
    };
  });

  document.getElementById('btnSendTestOtp')?.addEventListener('click', async () => {
    const enabled = document.getElementById('adminOtpEnabled').checked ? 1 : 0;
    const email = document.getElementById('adminOtpEmail').value.trim();

    if (enabled !== 1) {
      return showErrorModal('Bạn cần bật OTP trước khi gửi OTP thử.');
    }
    if (!isEmail(email)) {
      return showErrorModal('Vui lòng nhập email hợp lệ');
    }


    showToast('Đang gửi OTP xác minh về Gmail...');
    try {
      const res = await fetch(`${ADMIN_OTP_API}?action=send_test`);
      const j = await res.json();
      hideToast();

      if (!j.ok) return showErrorModal(j.error || 'Gửi OTP thất bại');

      // Prompt nhập OTP để verify
      const box = document.createElement('div');
      box.innerHTML = `
      <div class="space-y-3">
        <p class="text-sm text-gray-700">
          Đã gửi OTP về <b>${email}</b>. Nhập mã để xác minh.
        </p>
        <input id="otpCodeInput" class="w-full border rounded-xl p-2 bg-white"
          placeholder="Nhập OTP (6 số)" inputmode="numeric" maxlength="6">
        <div class="flex justify-end gap-3 pt-2">
          <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
          <button class="px-4 py-2 bg-amber-600 text-white rounded-lg" data-primary>Xác minh</button>
        </div>
        <div class="text-xs text-gray-500">
          Lưu ý: OTP có hạn dùng ngắn. Nếu chưa nhận được, hãy thử gửi lại sau.
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
          } else {
            showErrorModal(j2.error || 'Xác minh thất bại');
          }
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

  // Load status on page load
  loadAdminOtpStatus();
  // =========================
  // SYSTEM SMTP CONFIG (DB + ENCRYPT)
  // =========================
  const SMTP_API = 'controllers/smtp_config.php';

  function setSmtpBadge(ok, hasPass) {
    const el = document.getElementById('smtpStatusBadge');
    if (!el) return;

    if (!ok) {
      el.textContent = 'Chưa cấu hình';
      el.className = 'ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-red-50 text-red-700 border-red-200';
      return;
    }

    el.textContent = hasPass ? 'Sẵn sàng' : 'Thiếu mật khẩu';
    el.className = hasPass
      ? 'ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-emerald-50 text-emerald-800 border-emerald-200'
      : 'ml-1 inline-flex items-center px-2 py-1 rounded-lg text-xs border bg-amber-50 text-amber-900 border-amber-200';
  }

  function setHasPassHint(hasPass) {
    const el = document.getElementById('smtpHasPassHint');
    if (!el) return;
    if (hasPass) {
      el.className = 'mt-2 text-xs text-emerald-700';
      el.textContent = '✓ Đã có mật khẩu lưu trong hệ thống (đang được mã hoá).';
    } else {
      el.className = 'mt-2 text-xs text-red-600';
      el.textContent = '✕ Chưa có mật khẩu. Bạn cần nhập App Password/SMTP Password để gửi email.';
    }
  }

  async function loadSmtpStatus() {
    try {
      const res = await fetch(`${SMTP_API}?action=status`);
      const j = await res.json();
      if (!j.ok) return;

      const d = j.data || {};
      document.getElementById('smtpHost').value = d.host || '';
      document.getElementById('smtpPort').value = d.port || '';
      document.getElementById('smtpEnc').value = String(d.encryption || 'tls');
      document.getElementById('smtpUser').value = d.username || '';
      document.getElementById('smtpFromEmail').value = d.from_email || '';
      document.getElementById('smtpFromName').value = d.from_name || '';

      document.getElementById('smtpUpdatedAt').textContent = d.updated_at || '—';

      const ok = !!d.host && !!d.port && !!d.username && !!d.from_email;
      const hasPass = Number(d.has_password || 0) === 1;

      setSmtpBadge(ok, hasPass);
      setHasPassHint(hasPass);

      // không fill password
      const passEl = document.getElementById('smtpPass');
      if (passEl) passEl.value = '';
    } catch (e) { }
  }

  document.getElementById('btnSaveSMTP')?.addEventListener('click', async () => {
    const host = document.getElementById('smtpHost').value.trim();
    const port = document.getElementById('smtpPort').value.trim();
    const enc = document.getElementById('smtpEnc').value;
    const username = document.getElementById('smtpUser').value.trim();
    const fromEmail = document.getElementById('smtpFromEmail').value.trim();
    const fromName = document.getElementById('smtpFromName').value.trim();
    const pass = document.getElementById('smtpPass').value; // cho phép trống (giữ nguyên)

    if (!host || !port || !username || !fromEmail) {
      return showErrorModal('Vui lòng nhập Host, Port, Username và From email.');
    }

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('host', host);
    fd.append('port', String(port));
    fd.append('encryption', enc);
    fd.append('username', username);
    fd.append('from_email', fromEmail);
    fd.append('from_name', fromName);
    fd.append('password', pass); // trống => giữ nguyên

    showToast('Đang lưu cấu hình Email hệ thống (SMTP)...');
    try {
      const res = await fetch(SMTP_API, { method: 'POST', body: fd });
      const j = await res.json();
      hideToast();

      if (j.ok) {
        await loadSmtpStatus();
        showOkModal('✅ Đã lưu cấu hình SMTP.');
      } else {
        showErrorModal(j.error || 'Lưu thất bại');
      }
    } catch (e) {
      hideToast();
      showErrorModal('Không thể kết nối máy chủ');
    }
  });

  document.getElementById('btnTestSMTP')?.addEventListener('click', async () => {
    const box = document.createElement('div');
    box.innerHTML = `
      <div class="space-y-3">
        <p class="text-sm text-gray-700">
          Nhập email nhận để test gửi email từ <b>Email hệ thống</b>.
        </p>
        <input id="smtpTestTo" type="email" class="w-full border rounded-xl p-2 bg-white"
          placeholder="yourname@gmail.com">
        <div class="flex justify-end gap-3 pt-2">
          <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
          <button class="px-4 py-2 bg-sky-600 text-white rounded-lg" data-primary>Gửi test</button>
        </div>
      </div>
    `;
    modal(box, 'Test gửi email', 'small');

    box.querySelector('[data-primary]').onclick = async () => {
      const to = (box.querySelector('#smtpTestTo')?.value || '').trim();
      if (!to || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) return showErrorModal('Email nhận không hợp lệ');

      closeModal();
      showToast('Đang gửi email test...');
      try {
        const url = `${SMTP_API}?action=test&to=${encodeURIComponent(to)}`;
        const res = await fetch(url);
        const text = await res.text();
        let j;
        try { j = JSON.parse(text); }
        catch (e) {
          hideToast();
          return showErrorModal('API trả về không phải JSON: ' + escHtml(text.slice(0, 400)));
        }
        hideToast();

        if (j.ok) {
          showOkModal(`✅ ${escHtml(j.data?.msg || 'Đã gửi email test thành công')}`);
        } else {
          showErrorModal(j.error || 'Gửi test thất bại');
        }
      } catch (e) {
        hideToast();
        showErrorModal('Không thể kết nối máy chủ');
      }
    };
  });

  document.getElementById('btnResetSMTP')?.addEventListener('click', () => {
    const box = document.createElement('div');
    box.innerHTML = `
      <div class="space-y-4">
        <p class="text-sm text-gray-700">
          Khôi phục mặc định chỉ điền lại các giá trị gợi ý (<b>không tự tạo mật khẩu</b>).
          Bạn vẫn cần lưu lại sau khi chỉnh.
        </p>
        <div class="flex justify-end gap-3">
          <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
          <button class="px-4 py-2 bg-red-600 text-white rounded-lg" data-primary>Khôi phục</button>
        </div>
      </div>
    `;
    modal(box, 'Xác nhận', 'small');

    box.querySelector('[data-primary]').onclick = async () => {
      closeModal();
      document.getElementById('smtpHost').value = 'smtp.gmail.com';
      document.getElementById('smtpPort').value = '587';
      document.getElementById('smtpEnc').value = 'tls';
      // username/from/pass để admin tự nhập
      showOkModal('✅ Đã điền mặc định. Hãy nhập Gmail gửi + App Password rồi bấm Lưu SMTP.');
    };
  });

  // Load SMTP status on page load
  loadSmtpStatus();
  // --- Hướng dẫn: App Password ---
  document.getElementById('btnHowToAppPassword')?.addEventListener('click', () => {
    const box = document.createElement('div');
    box.innerHTML = `
    <div class="space-y-3 text-sm text-gray-700">
      <div class="font-semibold">App Password là gì?</div>
      <p>
        <b>App Password</b> là mật khẩu 16 ký tự do Google tạo ra để ứng dụng (SMTP) đăng nhập,
        <b>chỉ áp dụng khi bạn dùng Gmail/Google Workspace</b> và đã bật <b>Xác minh 2 bước (2FA)</b>.
        Với SMTP của hosting/dịch vụ mail khác, bạn dùng mật khẩu SMTP bình thường.
      </p>

      <div class="rounded-lg border bg-amber-50 p-3 text-xs text-amber-900">
        Link tạo App Password nhanh:
        <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer"
           class="underline font-medium">
          https://myaccount.google.com/apppasswords
        </a>
      </div>

      <div class="font-semibold mt-2">Cách lấy App Password (Gmail)</div>
      <ol class="list-decimal ml-5 space-y-1">
        <li>Bật <b>Xác minh 2 bước</b> cho Gmail gửi.</li>
        <li>Mở link <b>App passwords</b> ở trên (hoặc vào Google Account).</li>
        <li>Tạo App Password → copy chuỗi <b>16 ký tự</b>.</li>
        <li>Dán vào ô <b>App Password / SMTP Password</b> rồi bấm <b>Lưu SMTP</b>.</li>
      </ol>

      <div class="rounded-lg border bg-gray-50 p-3 text-xs text-gray-600">
        <b>Lưu ý:</b> Nếu không thấy mục App Password, thường là do chưa bật 2FA
        hoặc tài khoản bị chính sách hạn chế. Khi đó hãy dùng SMTP của hosting/dịch vụ mail.
      </div>

      <div class="flex justify-end pt-2">
        <button class="px-4 py-2 bg-primary text-white rounded-lg" data-primary>Đã hiểu</button>
      </div>
    </div>
  `;
    modal(box, 'Hướng dẫn App Password', 'small');
    box.querySelector('[data-primary]').onclick = closeModal;
  });


  // --- Preset SMTP ---
  document.getElementById('btnSmtpPresets')?.addEventListener('click', () => {
    const box = document.createElement('div');
    box.innerHTML = `
      <div class="space-y-3 text-sm text-gray-700">
        <div class="font-semibold">Chọn cấu hình mẫu</div>
        <div class="grid grid-cols-1 gap-2">
          <button class="px-3 py-2 border rounded-xl hover:bg-gray-50 text-left" data-preset="gmail_tls">
            <b>Gmail (TLS)</b><div class="text-xs text-gray-500">smtp.gmail.com — 587 — TLS</div>
          </button>
          <button class="px-3 py-2 border rounded-xl hover:bg-gray-50 text-left" data-preset="gmail_ssl">
            <b>Gmail (SSL)</b><div class="text-xs text-gray-500">smtp.gmail.com — 465 — SSL</div>
          </button>
          <button class="px-3 py-2 border rounded-xl hover:bg-gray-50 text-left" data-preset="custom">
            <b>SMTP khác</b><div class="text-xs text-gray-500">Bạn tự nhập host/port theo nhà cung cấp</div>
          </button>
        </div>
        <div class="flex justify-end pt-2">
          <button class="px-4 py-2 bg-primary text-white rounded-lg" data-primary>Đóng</button>
        </div>
      </div>
    `;
    modal(box, 'Cấu hình mẫu', 'small');

    box.querySelectorAll('[data-preset]').forEach(btn => {
      btn.addEventListener('click', () => {
        const preset = btn.getAttribute('data-preset');
        if (preset === 'gmail_tls') {
          document.getElementById('smtpHost').value = 'smtp.gmail.com';
          document.getElementById('smtpPort').value = '587';
          document.getElementById('smtpEnc').value = 'tls';
        } else if (preset === 'gmail_ssl') {
          document.getElementById('smtpHost').value = 'smtp.gmail.com';
          document.getElementById('smtpPort').value = '465';
          document.getElementById('smtpEnc').value = 'ssl';
        }
        closeModal();
        showOkModal('✅ Đã áp dụng cấu hình mẫu. Hãy nhập Username/From email và mật khẩu rồi bấm Lưu SMTP.');
      });
    });

    box.querySelector('[data-primary]').onclick = closeModal;
  });

</script>