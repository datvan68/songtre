<div class="bg-card rounded-2xl
            w-full max-w-md
            shadow-[0_25px_50px_rgba(0,0,0,0.12),0_10px_20px_rgba(0,0,0,0.08)]
            ring-1 ring-black/5
            p-8 md:p-10">

  <!-- RIGHT FORM -->
  <div class="col-span-2 p-8 md:p-10 flex flex-col justify-center bg-white">

    <!-- LOGO -->
    <div class="flex items-center justify-center gap-4 mb-6">
      <img src="assets/images/logo_truong.png" class="h-14">
      <img src="assets/images/logo_doan.png" class="h-14">
    </div>

    <h1 class="text-2xl font-bold text-center text-primary font-heading">
      Đăng Nhập Hệ Thống
    </h1>

    <p class="text-center text-subtext text-sm mt-1 mb-6">
      Cổng thông tin quản lý Đoàn Thanh Niên
    </p>

    <!-- ERROR -->
    <div id="loginError" class="hidden mb-4 text-danger text-sm text-center"></div>

    <form id="loginModalForm" class="space-y-5">

      <div>
        <label class="block text-sm font-medium mb-1">
          Mã số sinh viên (MSSV)
        </label>
        <input name="username" class="w-full px-3 py-2 border rounded-lg
             focus:ring-2 focus:ring-primary focus:outline-none" required>
      </div>

      <div class="relative">
        <label class="block text-sm font-medium mb-1">
          Mật khẩu
        </label>

        <input type="password" name="password" id="modalPassword" class="w-full px-3 py-2 pr-10 border rounded-lg
             focus:ring-2 focus:ring-primary focus:outline-none" required>

        <button type="button" id="togglePwd" class="absolute right-3 top-[38px] text-gray-400 hover:text-gray-600">
          👁
        </button>
      </div>

      <button class="w-full bg-primary text-white py-2 rounded-lg
           font-semibold hover:bg-blue-800 transition">
        Đăng nhập
      </button>
    </form>

    <div class="text-center mt-6 text-xs text-subtext">
      © <?= date('Y') ?> Đoàn Thanh Niên – Cổng thông tin quản lý
    </div>
  </div>
</div>
    <script src="<?= BASE_URL ?>assets/js/auth.js?v=<?= time() ?>"></script>