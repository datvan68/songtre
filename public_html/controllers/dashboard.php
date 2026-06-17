<?php
require __DIR__ . '/../config/db.php';

$user = auth_user();
$isGuest = !$user;
$userId = $user['id'] ?? null;

/**
 * Dashboard quản lý:
 * Có ÍT NHẤT 1 quyền quản trị
 */
$canManageDashboard =
  can('members', 'update')
  || can('members', 'delete')
  || can('campaigns', 'update')
  || can('campaigns', 'delete')
  || can('schedule', 'review');

/**
 * Dashboard cá nhân:
 * User thường
 */
$isUserDashboard = !$canManageDashboard;


// ====================================================================
// 1) DỮ LIỆU CHUNG (ADMIN)
// ====================================================================
if ($canManageDashboard) {

  $totMembers = (int) $pdo->query("
    SELECT COUNT(*) 
    FROM members m
    WHERE (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1))
      AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1))
  ")->fetchColumn();
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
      AND (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1))
      AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1))
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
    AND (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1))
    AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1))
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
    COALESCE(cl.name, m.class_name) AS class_name,
    m.ethnicity,

    -- TUỔI ĐOÀN
    TIMESTAMPDIFF(YEAR, m.join_date, CURDATE()) AS age_youth,

    -- TUỔI ĐỜI
    TIMESTAMPDIFF(YEAR, m.birth, CURDATE()) AS age_life

  FROM members m
  LEFT JOIN classes cl ON cl.id = m.class_id
  WHERE m.type = 'member'
    AND m.join_date IS NOT NULL
    AND m.birth IS NOT NULL
    AND (m.course_id IS NULL OR m.course_id IN (SELECT id FROM courses WHERE status = 1))
    AND (m.class_id IS NULL OR m.class_id IN (SELECT id FROM classes WHERE status = 1))
  ORDER BY age_youth DESC
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
      SELECT id, code, title, start_date, end_date
      FROM campaigns
      WHERE status='active'
      ORDER BY start_date DESC
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

    $active = $pdo->query("
      SELECT id, code, title, start_date, end_date
      FROM campaigns
      WHERE status='active'
      ORDER BY start_date DESC
      LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recent = $pdo->query("
      SELECT c.title, r.status, r.score, r.registered_at
      FROM registrations r
      JOIN campaigns c ON c.id = r.campaign_id
      WHERE r.user_id = $userId
      ORDER BY r.registered_at DESC
      LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
  }
}

?>

<section class="p-6">
  <div class="grid-container">

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
                <th class="border px-3 py-2 text-left">Lớp</th>
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
                    <?= htmlspecialchars($m['class_name'] ?? '-') ?>
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
      <!-- ==================================================================== -->
      <!-- DASHBOARD USER -->
      <!-- ==================================================================== -->

      <div class="mb-8 text-center">
        <h1 class="font-heading text-4xl font-bold text-primary flex items-center justify-center gap-3">
          <i data-lucide="target" class="w-8 h-8"></i>
          Hoạt động của bạn
        </h1>
        <p class="text-subtext mt-1">Tổng quan phong trào & điểm rèn luyện cá nhân</p>
      </div>

      <?php if ($isGuest): ?>
        <p class="mt-2 text-sm text-gray-500 italic mb-5">
          Đăng nhập để theo dõi điểm rèn luyện và lịch sử tham gia phong trào
        </p>
      <?php endif; ?>

      <!-- 3 ô: LUÔN HIỂN THỊ -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 <?= $isGuest ? 'opacity-60' : '' ?>">

        <div class="bg-blue-600 text-white p-6 rounded-xl shadow-lg text-center">
          <h3 class="text-sm uppercase opacity-90 mb-1">Đã đăng ký</h3>
          <p class="text-4xl font-bold"><?= (int) $regTotal ?></p>
        </div>

        <div class="bg-green-600 text-white p-6 rounded-xl shadow-lg text-center">
          <h3 class="text-sm uppercase opacity-90 mb-1">Được duyệt</h3>
          <p class="text-4xl font-bold"><?= (int) $regApproved ?></p>
        </div>

        <div class="bg-yellow-500 text-white p-6 rounded-xl shadow-lg text-center">
          <h3 class="text-sm uppercase opacity-90 mb-1">Tổng điểm</h3>
          <p class="text-4xl font-bold"><?= (int) $regPoints ?></p>
        </div>

      </div>


      <!-- Phong trào đang diễn ra -->
      <div class="bg-card p-6 rounded-xl shadow-card mb-6">
        <div class="flex justify-between items-center mb-3">
          <h3 class="font-semibold text-lg flex items-center gap-2">
            <i data-lucide="activity" class="w-5 h-5 text-blue-600"></i>
            Phong trào đang diễn ra
          </h3>

          <a href="?p=campaigns&tab=all" class="text-blue-600 hover:underline">Xem tất cả →</a>
        </div>

        <?php if (empty($active)): ?>
          <p class="text-subtext text-sm italic">Không có phong trào nào đang diễn ra.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($active as $c): ?>
              <div class="p-3 bg-blue-50 border rounded-lg">
                <div class="font-semibold text-blue-700"><?= $c['title'] ?></div>
                <div class="text-xs text-gray-600">
                  <?= date("d/m/Y", strtotime($c['start_date'])) ?> →
                  <?= date("d/m/Y", strtotime($c['end_date'])) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Lịch sử đăng ký gần đây -->
      <div class="bg-card p-6 rounded-xl shadow-card">
        <div class="flex justify-between items-center mb-3">
          <h3 class="font-semibold text-lg flex items-center gap-2">
            <i data-lucide="history" class="w-5 h-5 text-gray-600"></i>
            Lịch sử đăng ký gần đây
          </h3>

          <a href="?p=campaigns&tab=registered" class="text-blue-600 hover:underline">Xem tất cả →</a>
        </div>

        <?php if (empty($recent)): ?>
          <p class="text-subtext text-sm italic">Chưa có dữ liệu đăng ký.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($recent as $r): ?>
              <?php
              $mapStatus = [
                'approved' => 'Đã duyệt',
                'good' => 'Hoàn thành tốt',
                'excellent' => 'Hoàn thành xuất sắc',
              ];

              $vnStatus = $mapStatus[$r['status']] ?? $r['status'];
              ?>
              <div class="p-3 bg-gray-50 border rounded-lg">
                <div class="font-semibold"><?= $r['title'] ?></div>
                <div class="text-xs text-gray-500">
                  <?= date("d/m/Y", strtotime($r['registered_at'])) ?> —
                  Trạng thái: <span class="font-semibold"><?= $vnStatus ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>


    <?php endif; ?>
  </div>
</section>
