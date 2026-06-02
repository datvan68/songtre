<?php
require __DIR__ . '/../config/db.php';

function formatDate($dateStr)
{
  if (empty($dateStr) || $dateStr === '0000-00-00')
    return '-';
  try {
    return (new DateTime($dateStr))->format('d/m/Y');
  } catch (Exception $e) {
    return htmlspecialchars($dateStr);
  }
}

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
  echo "<section class='p-6'>Bạn chưa đăng nhập.</section>";
  exit;
}

/* ✅ BCH = có record trong user_profiles */
$stmt = $pdo->prepare("SELECT 1 FROM user_profiles WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$isBCH = (bool) $stmt->fetchColumn();

if (!$isBCH) {
  echo "<section class='p-6'>
          <div class='max-w-3xl mx-auto bg-white rounded-2xl border shadow-sm p-6'>
            <h1 class='text-xl font-bold text-gray-800'>403 - Không có quyền</h1>
            <p class='text-sm text-gray-500 mt-1'>Trang này chỉ dành cho Ban Chấp Hành.</p>
          </div>
        </section>";
  exit;
}

/* ✅ Load info từ users + roles + user_profiles (KHÔNG members) */
$stmt = $pdo->prepare("
  SELECT
    u.id,
    u.username,
    u.fullname,
    u.avatar_url,
    r.name AS role_name,
    up.birth,
    up.phone,
    up.email,
    up.address
  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  INNER JOIN user_profiles up ON up.user_id = u.id
  WHERE u.id = ?
  LIMIT 1
");
$stmt->execute([$userId]);
$info = $stmt->fetch(PDO::FETCH_ASSOC);

$displayFullname = $info['fullname'] ?: $info['username'];
?>

<section class="p-6">
  <div class="w-full">

    <!-- Header -->
    <div class="flex items-center gap-3 mb-2">
      <i data-lucide="shield" class="w-7 h-7 text-blue-600"></i>
      <h1 class="font-heading text-2xl md:text-3xl font-extrabold text-gray-800">
        Hồ sơ cá nhân
      </h1>
    </div>

    <!-- PROFILE CARD -->
    <div class="bg-white rounded-2xl shadow-md border border-slate-200 overflow-hidden">

      <!-- TOP -->
      <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 sm:gap-6 p-6
                  bg-gradient-to-r from-blue-600 to-blue-500 text-white">

        <div class="relative shrink-0">
          <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-full bg-white/20
              flex items-center justify-center text-2xl font-bold
              shadow-md overflow-hidden">

            <?php if (!empty($info['avatar_url'])): ?>
              <img id="profileAvatar" src="<?= htmlspecialchars($info['avatar_url']) ?>"
                class="w-full h-full object-cover cursor-pointer hover:opacity-90 transition" alt="Avatar">
            <?php else: ?>
              <?= strtoupper(substr($info['username'], 0, 2)) ?>
            <?php endif; ?>

          </div>

          <!-- STATUS -->
          <span class="absolute bottom-1 right-1 w-3.5 h-3.5 bg-green-400
              border-2 border-white rounded-full animate-pulse z-10"></span>
        </div>

        <!-- INFO -->
        <div class="flex flex-col justify-center text-center sm:text-left max-w-full">
          <div class="text-lg md:text-xl font-semibold flex items-center justify-center sm:justify-start gap-2">
            <i data-lucide="badge-check" class="w-5 h-5"></i>
            <span class="truncate max-w-[220px] sm:max-w-none">
              <?= htmlspecialchars($displayFullname) ?>
            </span>
          </div>

          <div class="text-sm opacity-90 flex items-center justify-center sm:justify-start gap-2 mt-1 truncate">
            <i data-lucide="mail" class="w-4 h-4"></i>
            <?= htmlspecialchars($info['email'] ?? '-') ?>
          </div>

          <div class="text-sm mt-2 flex items-center justify-center sm:justify-start gap-2">
            <i data-lucide="shield" class="w-4 h-4"></i>
            Vai trò:
            <span class="text-yellow-300 font-semibold">
              <?= strtoupper($info['role_name'] ?? 'BCH') ?>
            </span>
          </div>
        </div>
      </div>

      <!-- BODY -->
      <div class="p-4 md:p-6 space-y-6">

        <div>
          <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
            <i data-lucide="user-circle" class="w-5 h-5 text-blue-600"></i>
            Thông tin cá nhân
          </h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php
            $fields = [
              ["icon" => "cake", "label" => "Ngày sinh", "value" => formatDate($info['birth'] ?? '')],
              ["icon" => "phone", "label" => "Điện thoại", "value" => $info['phone'] ?? '-'],
              ["icon" => "mail-open", "label" => "Email", "value" => $info['email'] ?? '-'],
              ["icon" => "map-pin", "label" => "Địa chỉ", "value" => $info['address'] ?? '-'],
            ];

            foreach ($fields as $f): ?>
              <div class="bg-slate-50 hover:bg-slate-100 transition p-3 rounded-xl border border-slate-200
                          flex items-start gap-3">
                <i data-lucide="<?= $f['icon'] ?>" class="w-5 h-5 text-blue-600"></i>
                <div>
                  <span class="text-xs text-gray-500 uppercase font-medium"><?= $f['label'] ?></span>
                  <div class="text-sm font-semibold text-gray-800 break-all">
                    <?= htmlspecialchars($f['value']) ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- FOOTER -->
      <div class="flex justify-end gap-3 p-3 md:p-4 border-t border-slate-200 bg-slate-50">

        <!-- NÚT ĐỔI MẬT KHẨU -->
        <button id="btnChangeBCHPassword" class="flex items-center gap-2 px-4 py-2 bg-amber-600 text-white font-medium rounded-lg shadow
             hover:bg-amber-700 hover:scale-105 transition">
          <i data-lucide="key" class="w-4 h-4"></i>
          Đổi mật khẩu
        </button>

        <!-- NÚT CHỈNH SỬA BCH (giữ nguyên) -->
        <button id="btnEditBCHProfile" class="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white font-medium rounded-lg shadow
             hover:bg-blue-700 hover:scale-105 transition">
          <i data-lucide="edit-3" class="w-4 h-4"></i>
          Chỉnh sửa BCH
        </button>

      </div>

    </div>
  </div>
</section>

<script src="https://unpkg.com/lucide@latest"></script>
<script> lucide.createIcons(); </script>

<script>
  // preview avatar fullscreen giống account
  document.addEventListener("DOMContentLoaded", () => {
    const avatar = document.getElementById("profileAvatar");
    if (!avatar) return;

    avatar.addEventListener("click", () => {
      const src = avatar.src;
      if (!src) return;

      const html = `
        <div class="avatar-overlay fixed inset-0 bg-black/70 flex items-center justify-center z-[9999]"
             onclick="this.remove()">
          <img src="${src}"
               class="max-w-[95vw] max-h-[95vh] object-contain"
               onclick="event.stopPropagation()">
        </div>
      `;

      document.body.insertAdjacentHTML("beforeend", html);
    });
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      const overlay = document.querySelector(".avatar-overlay");
      if (overlay) overlay.remove();
    }
  });
</script>
<script src="<?= BASE_URL ?>assets/js/bch_account.js?v=<?= time() ?>"></script>