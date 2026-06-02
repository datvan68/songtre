<?php
extract(require __DIR__ . '/../controllers/account.php');

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

$chidoanGroups = $pdo->query("
  SELECT id, name
  FROM chidoan_groups
  ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);
$isPartyMember =
  !empty($info['party_probation_date']) ||
  !empty($info['party_official_date']);
?>

<section class="p-6">
  <div class="w-full">

    <!-- Header -->
    <div class="flex items-center gap-3 mb-2">
      <i data-lucide="user-round" class="w-7 h-7 text-blue-600"></i>
      <h1 class="font-heading text-2xl md:text-3xl font-extrabold text-gray-800">
        Hồ sơ cá nhân
      </h1>
    </div>

    <!-- PROFILE CARD -->
    <div class="bg-white rounded-2xl shadow-md border border-slate-200 overflow-hidden">

      <!-- PROFILE TOP -->
      <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 sm:gap-6 p-6 
                  bg-gradient-to-r from-blue-600 to-blue-500 text-white">

        <div class="relative shrink-0">

          <!-- AVATAR -->
          <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-full bg-white/20
              flex items-center justify-center text-2xl font-bold
              shadow-md overflow-hidden">
            <?php if (!empty($u['avatar_url'])): ?>
              <img id="profileAvatar" src="<?= htmlspecialchars($u['avatar_url']) ?>"
                class="w-full h-full object-cover cursor-pointer hover:opacity-90 transition" alt="Avatar">
            <?php else: ?>
              <?= strtoupper(substr($u['username'], 0, 2)) ?>
            <?php endif; ?>

          </div>

          <!-- STATUS DOT (NẰM NGOÀI AVATAR) -->
          <span class="absolute bottom-1 right-1 w-3.5 h-3.5 bg-green-400
         border-2 border-white rounded-full animate-pulse
         z-10">
          </span>


        </div>



        <!-- INFO -->
        <div class="flex flex-col justify-center text-center sm:text-left max-w-full">
          <div class="text-lg md:text-xl font-semibold flex items-center justify-center sm:justify-start gap-2">
            <i data-lucide="badge-check" class="w-5 h-5"></i>
            <span class="truncate max-w-[180px] sm:max-w-none">
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
            <span
              class="<?= $u['role_name'] === 'admin' ? 'text-yellow-300 font-semibold' : 'text-green-300 font-semibold' ?>">
              <?= strtoupper($u['role_name']) ?>
            </span>
          </div>
        </div>
      </div>

      <!-- INFO SECTIONS -->
      <div class="p-4 md:p-6 space-y-6">

        <!-- Personal Info -->
        <div>
          <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
            <i data-lucide="user-circle" class="w-5 h-5 text-blue-600"></i>
            Thông tin cá nhân
          </h2>

          <!-- MOBILE 1 COLUMN / TABLET 2 / DESKTOP 3 -->
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">

            <?php
            $fields = [
              ["icon" => "cake", "label" => "Ngày sinh", "value" => formatDate($info['birth'] ?? '')],
              ["icon" => "map-pin", "label" => "Nguyên quán", "value" => $info['native_place'] ?? '-'],
              ["icon" => "home", "label" => "Nơi ở hiện tại", "value" => $info['current_address'] ?? '-'],
              ["icon" => "phone", "label" => "Điện thoại", "value" => $info['phone'] ?? '-'],
              ["icon" => "mail-open", "label" => "Email", "value" => $info['email'] ?? '-'],
              ["icon" => "handshake", "label" => "Ngày vào Đoàn", "value" => formatDate($info['join_date'] ?? '')],
              ["icon" => "users", "label" => "Dân tộc", "value" => $info['ethnicity'] ?? '-'],
              ["icon" => "heart", "label" => "Tôn giáo", "value" => $info['religion'] ?? '-'],
            ];


            foreach ($fields as $f): ?>
              <div class="bg-slate-50 hover:bg-slate-100 transition p-3 rounded-xl border border-slate-200 
                          flex items-start gap-3">
                <i data-lucide="<?= $f['icon'] ?>" class="w-5 h-5 text-blue-600"></i>
                <div>
                  <span class="text-xs text-gray-500 uppercase font-medium"><?= $f['label'] ?></span>
                  <div class="text-sm font-semibold text-gray-800 break-all"><?= htmlspecialchars($f['value']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($isPartyMember): ?>
          <div class="mt-4">
            <h3 class="text-sm font-semibold text-red-700 mb-2 flex items-center gap-2">
              <i data-lucide="flag" class="w-4 h-4"></i>
              Thông tin Đảng viên
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">

              <!-- ĐẢNG VIÊN -->
              <div class="bg-red-50 border border-red-200 p-3 rounded-xl
                  flex items-start gap-3">
                <i data-lucide="badge-check" class="w-5 h-5 text-red-600"></i>
                <div>
                  <span class="text-xs text-red-700 uppercase font-medium">Đảng viên</span>
                  <div class="text-sm font-semibold text-red-800">Có</div>
                </div>
              </div>

              <?php if (!empty($info['party_probation_date'])): ?>
                <div class="bg-red-50 border border-red-200 p-3 rounded-xl
                  flex items-start gap-3">
                  <i data-lucide="calendar-clock" class="w-5 h-5 text-red-600"></i>
                  <div>
                    <span class="text-xs text-red-700 uppercase font-medium">Ngày dự bị</span>
                    <div class="text-sm font-semibold text-red-800">
                      <?= formatDate($info['party_probation_date']) ?>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <?php if (!empty($info['party_official_date'])): ?>
                <div class="bg-red-50 border border-red-200 p-3 rounded-xl
                  flex items-start gap-3">
                  <i data-lucide="calendar-check" class="w-5 h-5 text-red-600"></i>
                  <div>
                    <span class="text-xs text-red-700 uppercase font-medium">Ngày chính thức</span>
                    <div class="text-sm font-semibold text-red-800">
                      <?= formatDate($info['party_official_date']) ?>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

            </div>
          </div>
        <?php endif; ?>

        <!-- Academic Info -->
        <div>
          <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
            <i data-lucide="graduation-cap" class="w-5 h-5 text-blue-600"></i>
            Thông tin học tập
          </h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php
            $chidoanGroupId = (int) ($info['chidoan_group_id'] ?? 1);
            $chidoanLabel = $chidoanGroupId === 2 ? 'Chi đoàn giáo viên' : 'Chi đoàn lớp';

            $study = [
              ["icon" => "id-card", "label" => "MSSV", "value" => $info['mssv'] ?? '-'],
              ["icon" => "layers", "label" => "Nhóm chi đoàn", "value" => $chidoanLabel],
              ["icon" => "building-2", "label" => "Khoa/Phòng", "value" => $deptName],
            ];

            // 👉 chỉ chi đoàn lớp mới có Khóa + Lớp
            if ($chidoanGroupId === 1) {
              $study[] = ["icon" => "book-open", "label" => "Khóa", "value" => $course];
              $study[] = ["icon" => "users", "label" => "Lớp", "value" => $className];
            }


            foreach ($study as $f): ?>
              <div class="bg-slate-50 hover:bg-slate-100 transition p-3 rounded-xl border border-slate-200 
                          flex items-start gap-3">
                <i data-lucide="<?= $f['icon'] ?>" class="w-5 h-5 text-blue-600"></i>
                <div>
                  <span class="text-xs text-gray-500 uppercase font-medium"><?= $f['label'] ?></span>
                  <div class="text-sm font-semibold text-gray-800 break-all"><?= htmlspecialchars($f['value']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- FOOTER -->
      <div class="flex justify-end gap-3 p-3 md:p-4 border-t border-slate-200 bg-slate-50">

        <button id="btnChangePassword" class="flex items-center gap-2 px-4 py-2 bg-amber-500 text-white font-medium rounded-lg shadow
           hover:bg-amber-600 hover:scale-105 transition">
          <i data-lucide="key" class="w-4 h-4"></i>
          Đổi mật khẩu
        </button>

        <button id="btnEditProfile" class="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white font-medium rounded-lg shadow
           hover:bg-blue-700 hover:scale-105 transition">
          <i data-lucide="edit-3" class="w-4 h-4"></i>
          Chỉnh sửa
        </button>

      </div>


    </div>
  </div>
</section>


<script src="https://unpkg.com/lucide@latest"></script>
<script> lucide.createIcons(); </script>
<script src="<?= BASE_URL ?>assets/js/account.js?v=<?= time() ?>"></script>



<script>
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