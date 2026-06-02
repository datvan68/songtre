<?php
require __DIR__ . '/../config/db.php';
// display_errors controlled centrally in index.php / bootstrap
error_reporting(E_ALL);

$user = auth_user();
$isGuest = !$user;
$userId = $user['id'] ?? null;

// ======================
// DISPLAY NAME (members -> users)
// ======================
$displayName = null;

if (!$isGuest && $userId) {
  // 1) Ưu tiên fullname trong members
  $st = $pdo->prepare("
    SELECT fullname
    FROM members
    WHERE user_id = :uid
      AND fullname IS NOT NULL
      AND fullname <> ''
    LIMIT 1
  ");
  $st->execute([':uid' => (int) $userId]);
  $displayName = trim((string) $st->fetchColumn());

  if ($displayName === '')
    $displayName = null;

  // 2) Fallback fullname/username trong users (auth_user)
  if (!$displayName) {
    $displayName = trim((string) ($user['fullname'] ?? ''));
    if ($displayName === '') {
      $displayName = trim((string) ($user['username'] ?? 'bạn'));
    }
  }
}

/**
 * Dashboard quản lý:
 * Có ÍT NHẤT 1 quyền quản trị
 */
$canManageDashboard =
  can('dashboard', 'review');

/**
 * Dashboard cá nhân:
 * User thường
 */
$isUserDashboard = !$canManageDashboard;


// ====================================================================
// 1) DỮ LIỆU CHUNG (ADMIN)
// ====================================================================
if ($canManageDashboard) {

  $totMembers = (int) $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
  $totCampaigns = (int) $pdo->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();
  $totRegistrations = (int) $pdo->query("SELECT COUNT(*) FROM registrations")->fetchColumn();



  // ================================
  // THỐNG KÊ KHOA THAM GIA PHONG TRÀO
  // ================================
  $deptCampaignStats = $pdo->query("
    SELECT 
      d.id,
      d.name AS dept,
      COUNT(DISTINCT r.campaign_id) AS total_campaigns
    FROM departments d
    LEFT JOIN members m ON m.department_id = d.id
    LEFT JOIN registrations r ON r.user_id = m.user_id
    WHERE d.type = 'khoa'
    GROUP BY d.id, d.name
    ORDER BY total_campaigns DESC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $totalDepts = count($deptCampaignStats);

  $topDepts = $pdo->query("
  SELECT 
    d.name AS dept,
    COUNT(DISTINCT r.campaign_id) AS total_campaigns
  FROM departments d
  LEFT JOIN members m ON m.department_id = d.id
  LEFT JOIN registrations r ON r.user_id = m.user_id
  WHERE d.type = 'khoa'
  GROUP BY d.id, d.name
  ORDER BY total_campaigns DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);


  $topCampaigns = $pdo->query("
  SELECT 
    c.title,
    COUNT(r.id) AS total_regs
  FROM campaigns c
  LEFT JOIN registrations r ON r.campaign_id = c.id
  GROUP BY c.id, c.title
  HAVING COUNT(r.id) > 0
  ORDER BY total_regs DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

  $topYouthAges = $pdo->query("
  SELECT 
    m.fullname,

    -- TÊN CHI ĐOÀN
    COALESCE(cl.name, m.class_name, d.name, '—') AS chidoan_name,

    -- NHÃN: PHÒNG (GV) / KHOA (HS)
    CASE
      WHEN (m.class_id IS NOT NULL OR m.class_name IS NOT NULL)
        THEN 'Khoa'
      WHEN m.department_id IS NOT NULL
        THEN 'Phòng'
      ELSE ''
    END AS chidoan_label,

    m.ethnicity,

    TIMESTAMPDIFF(YEAR, m.join_date, CURDATE()) AS age_youth,
    TIMESTAMPDIFF(YEAR, m.birth, CURDATE()) AS age_life

  FROM members m
  LEFT JOIN classes cl ON cl.id = m.class_id
  LEFT JOIN departments d ON d.id = m.department_id

  WHERE m.type = 'member'
    AND m.join_date IS NOT NULL
    AND m.birth IS NOT NULL

  ORDER BY age_youth DESC, m.join_date ASC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);



}

// ====================================================================
// 2) DỮ LIỆU RIÊNG CHO USER
// ====================================================================
if ($isUserDashboard) {

  if ($isGuest) {
    // ======================
    // DASHBOARD GUEST (PUBLIC)
    // ======================
    $regTotal = 0;
    $regApproved = 0;
    $regPoints = 0;

    $active = $pdo->query("
  SELECT id, code, title, start_date, end_date, image
  FROM campaigns
  WHERE (status = 'hidden' OR status IS NULL)
    AND start_date IS NOT NULL
    AND start_date > NOW()
  ORDER BY start_date ASC
  LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);



    $recent = []; // guest không có lịch sử
  } else {
    // ======================
    // DASHBOARD USER
    // ======================
    $regTotal = (int) $pdo->query("
      SELECT COUNT(*)
      FROM registrations
      WHERE user_id = $userId
    ")->fetchColumn();

    $regApproved = (int) $pdo->query("
      SELECT COUNT(*)
      FROM registrations
      WHERE user_id = $userId
        AND status IN ('good','excellent')
    ")->fetchColumn();

    $regPoints = (int) $pdo->query("
      SELECT COALESCE(SUM(score), 0)
      FROM registrations
      WHERE user_id = $userId
        AND status IN ('good','excellent')
    ")->fetchColumn();

    $recent = $pdo->query("
      SELECT c.title, r.status, r.score, r.registered_at
      FROM registrations r
      JOIN campaigns c ON c.id = r.campaign_id
      WHERE r.user_id = $userId
      ORDER BY r.registered_at DESC
      LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    $regTotalThisWeek = (int) $pdo->query("
  SELECT COUNT(*)
  FROM registrations
  WHERE user_id = $userId
    AND registered_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
")->fetchColumn();

    $regTotalLastWeek = (int) $pdo->query("
  SELECT COUNT(*)
  FROM registrations
  WHERE user_id = $userId
    AND registered_at >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
    AND registered_at <  DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
")->fetchColumn();

    $regApprovedThisWeek = (int) $pdo->query("
  SELECT COUNT(*)
  FROM registrations
  WHERE user_id = $userId
    AND status IN ('good','excellent')
    AND registered_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
")->fetchColumn();

    $regApprovedLastWeek = (int) $pdo->query("
  SELECT COUNT(*)
  FROM registrations
  WHERE user_id = $userId
    AND status IN ('good','excellent')
    AND registered_at >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
    AND registered_at <  DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
")->fetchColumn();

    $regPointsThisWeek = (int) $pdo->query("
  SELECT COALESCE(SUM(score),0)
  FROM registrations
  WHERE user_id = $userId
    AND status IN ('good','excellent')
    AND registered_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
")->fetchColumn();

    $regPointsLastWeek = (int) $pdo->query("
  SELECT COALESCE(SUM(score),0)
  FROM registrations
  WHERE user_id = $userId
    AND status IN ('good','excellent')
    AND registered_at >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
    AND registered_at <  DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
")->fetchColumn();

    $stmt = $pdo->prepare("
  SELECT
    c.id, c.code, c.title, c.start_date, c.end_date, c.image,
    (
      SELECT r.status
      FROM registrations r
      WHERE r.user_id = :uid AND r.campaign_id = c.id
      LIMIT 1
    ) AS user_status
  FROM campaigns c
  WHERE (c.status = 'hidden' OR c.status IS NULL)
    AND c.start_date IS NOT NULL
    AND c.start_date > NOW()
  ORDER BY c.start_date ASC
  LIMIT 3
");

    $stmt->execute([':uid' => (int) $userId]);
    $active = $stmt->fetchAll(PDO::FETCH_ASSOC);


  }
}

?>
<?php

// helper base path (để chạy đúng khi app nằm trong thư mục con)
if (!function_exists('app_base_path')) {
  function app_base_path(): string
  {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $base === '/' ? '' : $base;
  }
}

// helper build src ảnh campaign
if (!function_exists('campaign_img_src')) {
  function campaign_img_src(array $c): ?string
  {
    // 1) nếu DB có URL tuyệt đối
    if (!empty($c['image_url']))
      return (string) $c['image_url'];

    // 2) nếu DB lưu tên file / path tương đối
    $img = $c['image'] ?? null; // <-- đổi tên cột nếu cần
    if (!$img)
      return null;

    $img = trim((string) $img);
    if ($img === '')
      return null;

    // nếu đã là http(s) thì dùng luôn
    if (preg_match('~^https?://~i', $img))
      return $img;

    // đảm bảo chỉ lấy tên file
    $file = rawurlencode(basename($img));

    // sửa đúng thư mục bạn đang lưu ảnh
    // ví dụ: /uploads/campaigns/cp_xxx.jpg
    return app_base_path() . "/uploads/campaigns/$file";
  }
}

function status_badge(string $status): array
{
  // Map theo hệ thống của bạn
  $map = [
    'excellent' => ['Hoàn thành xuất sắc', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'good' => ['Thành công', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'approved' => ['Đang tham gia', 'bg-blue-50 text-blue-700 border-blue-200'],
    'pending' => ['Đang chờ', 'bg-amber-50 text-amber-700 border-amber-200'],
    'rejected' => ['Bị từ chối', 'bg-rose-50 text-rose-700 border-rose-200'],
  ];
  return $map[$status] ?? [$status, 'bg-gray-50 text-gray-700 border-gray-200'];
}
function week_badge(int $thisWeek, int $lastWeek): string
{
  $diff = $thisWeek - $lastWeek;

  if ($diff === 0)
    return '<span class="text-xs rounded-full bg-gray-50 text-gray-700 px-2 py-1 border border-gray-200">= tuần này</span>';

  if ($diff > 0)
    return '<span class="text-xs rounded-full bg-emerald-50 text-emerald-700 px-2 py-1 border border-emerald-200">+' . $diff . ' tuần này</span>';

  return '<span class="text-xs rounded-full bg-rose-50 text-rose-700 px-2 py-1 border border-rose-200">' . $diff . ' tuần này</span>';
}

function fmt_date_vn(?string $s): string
{
  if (!$s)
    return '—';
  return date('d/m/Y', strtotime($s));
}
?>

<section class="p-6">
  <div class="w-full">

    <?php if ($canManageDashboard): ?>
      <!-- ================= ADMIN DASHBOARD ================= -->

      <div class="mb-8 text-center">
        <h1 class="text-4xl font-bold text-primary flex items-center justify-center gap-3">
          <i data-lucide="layout-dashboard" class="w-8 h-8"></i>
          Dashboard Tổng Quan
        </h1>
        <p class="text-subtext mt-1">Thống kê mức độ tham gia phong trào theo khoa</p>
      </div>

      <!-- ================= QUICK INSIGHTS ================= -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">

        <!-- 🔹 A. Top 5 khoa -->
        <div class="bg-card p-5 rounded-xl shadow-card">
          <h3 class="font-semibold text-lg mb-4 text-blue-700 flex items-center gap-2">
            <i data-lucide="award" class="w-5 h-5"></i>
            Top 5 khoa tham gia nhiều nhất
          </h3>


          <div class="overflow-x-auto">
            <table class="min-w-full text-sm border">
              <thead class="bg-blue-50">
                <tr>
                  <th class="border px-3 py-2 text-center w-12">#</th>
                  <th class="border px-3 py-2 text-left">Khoa</th>
                  <th class="border px-3 py-2 text-center">Số phong trào</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topDepts as $i => $d): ?>
                  <tr class="hover:bg-gray-50">
                    <td class="border px-3 py-2 text-center font-semibold">
                      <?= $i + 1 ?>
                    </td>
                    <td class="border px-3 py-2 font-medium">
                      <?= htmlspecialchars($d['dept']) ?>
                    </td>
                    <td class="border px-3 py-2 text-center font-bold text-blue-600">
                      <?= (int) $d['total_campaigns'] ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 🔹 B. Top 5 phong trào hiệu quả -->
        <div class="bg-card p-5 rounded-xl shadow-card">
          <h3 class="font-semibold text-lg mb-4 text-emerald-700 flex items-center gap-2">
            <i data-lucide="rocket" class="w-5 h-5"></i>
            Top 5 phong trào hiệu quả nhất
          </h3>

          <?php if (!empty($topCampaigns)): ?>
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm border">
                <thead class="bg-emerald-50">
                  <tr>
                    <th class="border px-3 py-2 text-center w-12">#</th>
                    <th class="border px-3 py-2 text-left">Phong trào</th>
                    <th class="border px-3 py-2 text-center whitespace-nowrap">Lượt đăng ký</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($topCampaigns as $i => $c): ?>
                    <tr class="hover:bg-gray-50">
                      <td class="border px-3 py-2 text-center font-semibold">
                        <?= $i + 1 ?>
                      </td>
                      <td class="border px-3 py-2 font-medium">
                        <?= htmlspecialchars($c['title']) ?>
                      </td>
                      <td class="border px-3 py-2 text-center font-bold text-emerald-600">
                        <?= (int) $c['total_regs'] ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="text-sm text-gray-500 italic">
              Chưa có dữ liệu phong trào
            </p>
          <?php endif; ?>
        </div>
      </div>
      <!-- ================= BẢNG TUỔI ĐOÀN CAO NHẤT ================= -->
      <div class="bg-card p-6 rounded-xl shadow-card mb-10">
        <h3 class="text-xl font-bold mb-4 text-center text-primary">
          Top 5 đoàn viên có tuổi đoàn cao nhất
        </h3>

        <div class="overflow-x-auto">
          <table class="min-w-full border text-sm">
            <thead class="bg-gray-100">
              <tr>
                <th class="border px-3 py-2">#</th>
                <th class="border px-3 py-2 text-left">Họ tên</th>
                <th class="border px-3 py-2 text-left">Chi đoàn GV / Lớp</th>
                <th class="border px-3 py-2 text-center">Dân tộc</th>
                <th class="border px-3 py-2 text-center">Tuổi đoàn</th>
                <th class="border px-3 py-2 text-center">Tuổi đời</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topYouthAges as $i => $m): ?>
                <tr class="hover:bg-gray-50">
                  <td class="border px-3 py-2 text-center"><?= $i + 1 ?></td>

                  <td class="border px-3 py-2 font-medium">
                    <?= htmlspecialchars($m['fullname']) ?>
                  </td>

                  <td class="border px-3 py-2">
                    <?=
                      $m['chidoan_label'] === 'Phòng'
                      ? 'GV – ' . htmlspecialchars($m['chidoan_name'])
                      : 'Lớp – ' . htmlspecialchars($m['chidoan_name'])
                      ?>
                  </td>

                  <td class="border px-3 py-2 text-center">
                    <?= htmlspecialchars($m['ethnicity'] ?? '-') ?>
                  </td>

                  <td class="border px-3 py-2 text-center font-bold text-emerald-600">
                    <?= (int) $m['age_youth'] ?>
                  </td>

                  <td class="border px-3 py-2 text-center font-semibold text-blue-600">
                    <?= (int) $m['age_life'] ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <!-- ================= BẢNG THỐNG KÊ KHOA ================= -->
      <div class="bg-card p-6 rounded-xl shadow-card mb-10">
        <h3 class="text-xl font-bold mb-4 text-center text-primary">
          Thống kê khoa tham gia phong trào (<?= $totalDepts ?> khoa)
        </h3>

        <div class="overflow-x-auto">
          <table class="min-w-full border text-sm">
            <thead class="bg-gray-100">
              <tr>
                <th class="border px-3 py-2">#</th>
                <th class="border px-3 py-2 text-left">Khoa</th>
                <th class="border px-3 py-2 text-center">Số phong trào tham gia</th>
                <th class="border px-3 py-2 text-center">% so với tổng phong trào</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($deptCampaignStats as $i => $d): ?>
                <?php
                $percent = $totCampaigns > 0
                  ? round(($d['total_campaigns'] / $totCampaigns) * 100, 1)
                  : 0;
                ?>
                <tr class="hover:bg-gray-50">
                  <td class="border px-3 py-2 text-center"><?= $i + 1 ?></td>
                  <td class="border px-3 py-2 font-medium"><?= htmlspecialchars($d['dept']) ?></td>
                  <td class="border px-3 py-2 text-center font-bold text-blue-600">
                    <?= (int) $d['total_campaigns'] ?>
                  </td>
                  <td class="border px-3 py-2 text-center font-semibold text-emerald-600">
                    <?= $percent ?>%
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>


    <?php else: ?>
      <!-- ============================ -->
      <!-- DASHBOARD USER / GUEST (UI mới) -->
      <!-- ============================ -->

      <div class="w-full">

        <!-- Header greeting + search -->
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between mb-6">
          <div>
            <h1 class="text-3xl md:text-4xl font-bold text-gray-900">
              <?= $isGuest ? 'Chào bạn!' : ('Chào buổi sáng, ' . htmlspecialchars($displayName ?? 'bạn') . '!') ?>
              <span class="align-middle">👋</span>
            </h1>
            <p class="text-gray-500 mt-1">
              Dưới đây là tóm tắt hoạt động và tiến độ của bạn trong tháng này.
            </p>

            <?php if ($isGuest): ?>
              <div
                class="mt-3 inline-flex items-center gap-2 rounded-xl border bg-amber-50 text-amber-700 border-amber-200 px-3 py-2 text-sm">
                Bạn đang ở chế độ khách. Đăng nhập để xem điểm và lịch sử tham gia.
              </div>
            <?php endif; ?>
          </div>


        </div>

        <!-- KPI cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8 <?= $isGuest ? 'opacity-60' : '' ?>">

          <!-- Đã đăng ký -->
          <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
              <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center">
                <i data-lucide="clipboard-check" class="w-5 h-5 text-blue-600"></i>
              </div>

              <?= $isGuest
                ? '<span class="text-xs rounded-full bg-gray-50 text-gray-700 px-2 py-1 border border-gray-200">—</span>'
                : week_badge((int) $regTotalThisWeek, (int) $regTotalLastWeek)
                ?>
            </div>

            <div class="mt-4 text-sm text-gray-500">Đã đăng ký</div>
            <div class="text-4xl font-bold text-gray-900 mt-1"><?= (int) $regTotal ?></div>
          </div>

          <!-- Đã được duyệt -->
          <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
              <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center">
                <i data-lucide="badge-check" class="w-5 h-5 text-indigo-600"></i>
              </div>

              <?= $isGuest
                ? '<span class="text-xs rounded-full bg-gray-50 text-gray-700 px-2 py-1 border border-gray-200">—</span>'
                : week_badge((int) $regApprovedThisWeek, (int) $regApprovedLastWeek)
                ?>
            </div>

            <div class="mt-4 text-sm text-gray-500">Đã được duyệt</div>
            <div class="text-4xl font-bold text-gray-900 mt-1"><?= (int) $regApproved ?></div>
          </div>

          <!-- Tổng điểm -->
          <div class="rounded-2xl border bg-blue-600 p-5 shadow-lg text-white">
            <div class="flex items-start justify-between">
              <div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center">
                <i data-lucide="star" class="w-5 h-5 text-white"></i>
              </div>

              <?php if ($isGuest): ?>
                <span class="text-xs rounded-full bg-white/10 text-white px-2 py-1 border border-white/15">—</span>
              <?php else: ?>
                <?php
                $diff = (int) $regPointsThisWeek - (int) $regPointsLastWeek;
                $badgeClass = $diff === 0
                  ? 'bg-white/10 border-white/15'
                  : ($diff > 0 ? 'bg-emerald-500/20 border-emerald-200/20' : 'bg-rose-500/20 border-rose-200/20');

                $badgeText = $diff === 0 ? '= tuần này' : (($diff > 0 ? '+' : '') . $diff . ' tuần này');
                ?>
                <span class="text-xs rounded-full text-white px-2 py-1 border <?= $badgeClass ?>">
                  <?= $badgeText ?>
                </span>
              <?php endif; ?>
            </div>

            <div class="mt-4 text-sm text-white/80">Tổng điểm tích lũy</div>
            <div class="text-4xl font-bold mt-1"><?= (int) $regPoints ?></div>
          </div>

        </div>


        <!-- Phong trào đang diễn ra -->
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-semibold text-gray-900">Phong trào sắp diễn ra</h2>
          <a href="?p=campaigns&tab=all" class="text-sm font-medium text-blue-600 hover:underline">Xem tất cả</a>
        </div>

        <?php if (empty($active)): ?>
          <div class="rounded-2xl border bg-white p-6 text-gray-500 italic">
            Không có phong trào nào đang diễn ra.
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-10">
            <?php foreach ($active as $c): ?>
              <?php $imgSrc = campaign_img_src($c); ?>

              <div class="rounded-2xl border bg-white overflow-hidden shadow-sm">
                <!-- Thumbnail -->
                <div class="h-40 bg-gray-100">
                  <?php if ($imgSrc): ?>
                    <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($c['title'] ?? 'Campaign') ?>"
                      class="w-full h-full object-cover" loading="lazy"
                      onerror="this.onerror=null;this.style.display='none'; this.parentElement.classList.add('flex','items-center','justify-center'); this.parentElement.innerHTML='<i data-lucide=&quot;image&quot; class=&quot;w-8 h-8 text-gray-400&quot;></i>'; if(window.lucide) lucide.createIcons();" />
                  <?php else: ?>
                    <div class="h-full flex items-center justify-center">
                      <i data-lucide="image" class="w-8 h-8 text-gray-400"></i>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Content -->
                <div class="p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <span
                      class="text-[11px] uppercase tracking-wide px-2 py-1 rounded-full border bg-blue-50 text-blue-700 border-blue-200">
                      Sắp diễn ra
                    </span>
                  </div>

                  <div class="font-semibold text-gray-900 leading-snug">
                    <?= htmlspecialchars($c['title'] ?? '') ?>
                  </div>

                  <div class="text-xs text-gray-500 mt-2">
                    <?= fmt_date_vn($c['start_date'] ?? null) ?> → <?= fmt_date_vn($c['end_date'] ?? null) ?>
                  </div>

                  <div class="mt-4">
                    <?php
                    $uStatus = (string) ($c['user_status'] ?? ''); // '', approved, good, excellent, pending, rejected...
                    $ctaText = 'Tham gia ngay';
                    $ctaHref = '?p=campaigns&tab=all';
                    $ctaClass = 'bg-blue-600 hover:bg-blue-700 text-white';
                    $ctaDisabled = false;

                    if ($isGuest) {
                      $ctaText = 'Đăng nhập để tham gia';
                      $ctaHref = 'index.php?p=login'; // đổi theo route login của bạn
                      $ctaClass = 'bg-gray-200 text-gray-600';
                    } else {
                      if ($uStatus !== '') {
                        // đã đăng ký rồi
                        if (in_array($uStatus, ['approved'], true)) {
                          $ctaText = 'Đã tham gia';
                          $ctaHref = '?p=campaigns&tab=registered';
                          $ctaClass = 'bg-gray-200 text-gray-700';
                        } elseif (in_array($uStatus, ['good', 'excellent'], true)) {
                          $ctaText = 'Đã hoàn thành';
                          $ctaHref = '?p=campaigns&tab=registered';
                          $ctaClass = 'bg-emerald-600 hover:bg-emerald-700 text-white';
                        } elseif ($uStatus === 'pending') {
                          $ctaText = 'Đang chờ duyệt';
                          $ctaHref = '?p=campaigns&tab=registered';
                          $ctaClass = 'bg-amber-500 hover:bg-amber-600 text-white';
                        } elseif ($uStatus === 'rejected') {
                          $ctaText = 'Bị từ chối';
                          $ctaHref = '?p=campaigns&tab=registered';
                          $ctaClass = 'bg-rose-500 hover:bg-rose-600 text-white';
                        } else {
                          // fallback nếu có status khác
                          $ctaText = 'Đã đăng ký';
                          $ctaHref = '?p=campaigns&tab=registered';
                          $ctaClass = 'bg-gray-200 text-gray-700';
                        }
                      }
                    }
                    ?>

                    <a href="<?= htmlspecialchars($ctaHref) ?>"
                      class="inline-flex w-full items-center justify-center rounded-xl font-medium py-2 text-sm <?= $ctaClass ?> <?= $ctaDisabled ? 'pointer-events-none opacity-60' : '' ?>"
                      title="<?= $isGuest ? 'Đăng nhập để tham gia' : $ctaText ?>">
                      <?= htmlspecialchars($ctaText) ?>
                    </a>

                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Lịch sử đăng ký gần đây -->
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-semibold text-gray-900">Lịch sử đăng ký gần đây</h2>
          <a href="?p=campaigns&tab=registered" class="text-sm font-medium text-blue-600 hover:underline">Xem tất cả</a>
        </div>

        <?php if ($isGuest): ?>
          <div class="rounded-2xl border bg-white p-6 text-gray-500 italic">
            Đăng nhập để xem lịch sử đăng ký.
          </div>
        <?php elseif (empty($recent)): ?>
          <div class="rounded-2xl border bg-white p-6 text-gray-500 italic">
            Chưa có dữ liệu đăng ký.
          </div>
        <?php else: ?>
          <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                  <tr>
                    <th class="text-left font-semibold px-4 py-3">HOẠT ĐỘNG</th>
                    <th class="text-left font-semibold px-4 py-3 whitespace-nowrap">NGÀY ĐĂNG KÝ</th>
                    <th class="text-left font-semibold px-4 py-3">TRẠNG THÁI</th>
                    <th class="text-right font-semibold px-4 py-3 whitespace-nowrap">ĐIỂM</th>
                  </tr>
                </thead>
                <tbody class="divide-y">
                  <?php foreach ($recent as $r): ?>
                    <?php [$vn, $cls] = status_badge((string) $r['status']); ?>
                    <tr class="hover:bg-gray-50">
                      <td class="px-4 py-3 font-medium text-gray-900">
                        <?= htmlspecialchars($r['title']) ?>
                      </td>
                      <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                        <?= fmt_date_vn($r['registered_at']) ?>
                      </td>
                      <td class="px-4 py-3">
                        <span
                          class="inline-flex items-center px-2.5 py-1 rounded-full border text-xs font-semibold <?= $cls ?>">
                          <?= htmlspecialchars($vn) ?>
                        </span>
                      </td>
                      <td class="px-4 py-3 text-right font-semibold text-gray-900">
                        <?= (int) ($r['score'] ?? 0) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

      </div>
    <?php endif; ?>

  </div>

</section>
<script>
  window.__PERM__ = window.__PERM__ || {};
  window.__PERM__.duty_view = <?= can('duty', 'view') ? 'true' : 'false' ?>;
</script>