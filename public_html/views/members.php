<?php
require __DIR__ . '/../config/db.php';
error_reporting(E_ALL);
// display_errors controlled centrally in index.php / bootstrap
auth_guard();
// ===== ROLE + CLASS SCOPE =====
$uid = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("
  SELECT r.name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
");
$stmt->execute([$uid]);
$currentRole = $stmt->fetchColumn();
$gvcnClassIds = [];

/* ===== GVCN ===== */
if ($currentRole === 'gvcn') {
  $stmt = $pdo->prepare("
        SELECT class_id
        FROM gvcn_classes
        WHERE user_id = ?
    ");
  $stmt->execute([$uid]);
  $gvcnClassIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_id');

  if (empty($gvcnClassIds)) {
    forbidden(); // GVCN mà không có lớp
  }
}
$scope = null;

if ($currentRole === 'bithu') {
  $stmt = $pdo->prepare("
        SELECT chidoan_group_id, department_id, course_id, class_id
        FROM bithu_scopes
        WHERE user_id = ?
        LIMIT 1
    ");
  $stmt->execute([$uid]);
  $scope = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$scope) {
    http_response_code(403);
    echo "<section class='p-6'>403 - Bí thư chưa được phân phạm vi</section>";
    exit;
  }
}


if (!can('members', 'view')) {
  http_response_code(403);
  echo "<section class='p-6'>403 - Forbidden</section>";
  exit;
}
$canUpdate = can('members', 'update');
$canDelete = can('members', 'delete');
$showActionCol = $canUpdate || $canDelete;
$showLockCol = ($currentRole === 'admin' && $canUpdate); // giống canAdminLock()

/* ============================
   PHÂN TRANG – PHẢI ĐẶT TRÊN CÙNG
============================ */
$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = "WHERE 1=1";
$params = [];
if ($currentRole === 'gvcn') {
  $in = implode(',', array_fill(0, count($gvcnClassIds), '?'));
  $where .= " AND m.class_id IN ($in)";
  $params = array_merge($params, $gvcnClassIds);
}

if ($currentRole === 'bithu') {

  if ((int) $scope['chidoan_group_id'] === 1) {
    // Chi đoàn lớp
    $where .= " AND m.class_id = ?";
    $params[] = (int) $scope['class_id'];

  } elseif ((int) $scope['chidoan_group_id'] === 2) {
    // Chi đoàn giáo viên
    $where .= " AND m.chidoan_group_id = 2";

  }
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM members m $where");
$stmt->execute($params);
$totalRows = (int) $stmt->fetchColumn();
$totalPages = (int) ceil($totalRows / $perPage);


$rowsStmt = $pdo->prepare("
SELECT 
  m.*,
  d.name AS dept,
  c.name AS course,
  cl.name AS class_name2,
  IF(m.birth IS NOT NULL, TIMESTAMPDIFF(YEAR, m.birth, CURDATE()), NULL) AS age_life,
  IF(m.join_date IS NOT NULL, TIMESTAMPDIFF(YEAR, m.join_date, CURDATE()), NULL) AS age_youth,
  COALESCE(rs.total_score, 0) AS total_score
FROM members m
LEFT JOIN departments d ON d.id = m.department_id
LEFT JOIN courses c ON c.id = m.course_id
LEFT JOIN classes cl ON cl.id = m.class_id
LEFT JOIN (
  SELECT user_id, SUM(score) AS total_score
  FROM registrations
  WHERE status IN ('good','excellent')
  GROUP BY user_id
) rs ON rs.user_id = m.user_id
$where
ORDER BY total_score DESC, m.fullname
LIMIT ? OFFSET ?
");




$idx = 1;
foreach ($params as $v) {
  $rowsStmt->bindValue($idx++, $v, PDO::PARAM_INT);
}
$rowsStmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue($idx++, $offset, PDO::PARAM_INT);



$rowsStmt->execute();
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================
// THỐNG KÊ HEADER
// ========================

if ($currentRole === 'gvcn') {
  // ===== GVCN: CHỈ THỐNG KÊ CÁC LỚP PHỤ TRÁCH =====
  $stmt = $pdo->prepare("
    SELECT m.type, COUNT(*) AS total
    FROM members m
    $where
    AND m.stop_follow = 0
    GROUP BY m.type
  ");
  $stmt->execute($params);
  $statType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

  $cntMember = (int) ($statType['member'] ?? 0);
  $cntYouth = (int) ($statType['youth'] ?? 0);

} elseif (
  $currentRole === 'bithu'
  && (int) ($scope['chidoan_group_id'] ?? 0) === 2
) {
  // ===== BÍ THƯ CHI ĐOÀN GIÁO VIÊN =====
  $stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM members m
  $where
  AND m.stop_follow = 0
");

  $stmt->execute($params);

  $cntMember = (int) $stmt->fetchColumn();
  $cntYouth = 0;

} else {
  // ===== ADMIN + BÍ THƯ CHI ĐOÀN LỚP =====
  if ($currentRole === 'bithu') {
    $stmt = $pdo->prepare("
      SELECT m.type, COUNT(*) AS total
      FROM members m
      $where
      AND m.stop_follow = 0
      GROUP BY m.type
    ");
    $stmt->execute($params);
    $statType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
  } else {
    $statType = $pdo->query("
      SELECT type, COUNT(*) AS total
      FROM members
      WHERE stop_follow = 0
      GROUP BY type
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
  }

  $cntMember = (int) ($statType['member'] ?? 0);
  $cntYouth = (int) ($statType['youth'] ?? 0);
}



?>


<!-- CSS CHO STICKY COLUMN -->
<style>
  .sticky-col-right {
    position: sticky;
    right: 0;
    background: #f5f5f5ff;
    backdrop-filter: blur(4px);
    border-left: 1px solid #e5e7eb;
  }

  /* Fix lỗi bóng đổ khi scroll */
  .sticky-shadow {
    box-shadow: -4px 0 6px -2px rgba(0, 0, 0, 0.1);
  }

  #scrollTop,
  #scrollMain {
    scrollbar-width: thin;
  }

  #scrollTop::-webkit-scrollbar {
    height: 8px;
  }

  #scrollMain::-webkit-scrollbar {
    height: 8px;
  }
</style>

<section class="p-6">
  <div class="w-full">

    <!-- STAT INLINE (TRÊN HEADER) -->
    <div class="flex items-center gap-6 mb-2 text-sm">

      <!-- ===== ĐOÀN VIÊN (LUÔN HIỆN) ===== -->
      <div class="flex items-center gap-3 text-blue-700">
        <i data-lucide="user-check" class="w-4 h-4"></i>
        <span>Đoàn viên:</span>
        <span class="font-semibold" data-stat="member"><?= $cntMember ?></span>
      </div>

      <?php
      // ❌ Bí thư chi đoàn giáo viên → KHÔNG hiện Thanh niên
      $hideYouth =
        $currentRole === 'bithu'
        && (int) ($scope['chidoan_group_id'] ?? 0) === 2;
      ?>

      <?php if (!$hideYouth): ?>
        <span class="text-gray-300">|</span>

        <div class="flex items-center gap-3 text-emerald-700">
          <i data-lucide="users" class="w-4 h-4"></i>
          <span>Thanh niên:</span>
          <span class="font-semibold" data-stat="youth"><?= $cntYouth ?></span>
        </div>
      <?php endif; ?>

    </div>



    <!-- Header -->
    <div class="flex items-start justify-between mb-3 mt-3 gap-3">
      <!-- TITLE: giới hạn ngang + nhỏ lại trên mobile -->
      <h1 class="font-heading font-bold leading-tight
           text-lg sm:text-2xl
           min-w-0 max-w-[38%] sm:max-w-none
           break-words">
        Quản lý Đoàn viên
      </h1>

      <!-- Buttons: mobile 2 cột (2 hàng), desktop auto -->
      <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end sm:gap-2">

        <?php if (can('members', 'create')): ?>
          <button id="btnAddMember" class="inline-flex items-center justify-center gap-2 bg-primary text-white px-3 py-2 sm:px-4 sm:py-2
               rounded-lg hover:bg-blue-800 text-sm font-medium shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m7-7H5" />
            </svg>
            Thêm
          </button>
        <?php endif; ?>

        <?php if (can('members', 'review')): ?>
          <button id="btnReviewMembers" class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-3 py-2 sm:px-4 sm:py-2
           rounded-lg hover:bg-indigo-700 text-sm font-medium shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 12h6m-6 4h6M9 8h6M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H9L5 8v10a2 2 0 002 2z" />
            </svg>
            Đánh giá
          </button>
        <?php endif; ?>

        <?php if ($currentRole === 'admin'): ?>
          <button id="btnImport" class="inline-flex items-center justify-center gap-2 bg-amber-500 text-white px-3 py-2 sm:px-4 sm:py-2
       rounded-lg hover:bg-amber-600 text-sm font-medium shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 9l5-5 5 5M12 4v12" />
            </svg>
            Nhập
          </button>
        <?php endif; ?>


        <input type="file" id="xlsxInput" accept=".xlsx" class="hidden">

        <?php if (can('members', 'print')): ?>
          <button id="btnExport" class="inline-flex items-center justify-center gap-2 bg-emerald-600 text-white px-3 py-2 sm:px-4 sm:py-2
               rounded-lg hover:bg-emerald-700 text-sm font-medium shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 11l5 5 5-5M12 4v12" />
            </svg>
            Xuất
          </button>
        <?php endif; ?>

        <?php if ($currentRole === 'admin'): ?>
          <a href="<?= BASE_URL ?>controllers/members.php?action=sample_xlsx" class="inline-flex items-center justify-center gap-2 bg-slate-700 text-white px-3 py-2 sm:px-4 sm:py-2
               rounded-lg hover:bg-slate-800 text-sm font-medium shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M7 21h10a2 2 0 002-2V9l-6-6H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 3v6h6" />
            </svg>
            Mẫu
          </a>
          <?php if ($canUpdate): ?>
            <button id="btnLockAll" type="button" class="inline-flex items-center justify-center gap-2 bg-gray-900 text-white px-3 py-2 sm:px-4 sm:py-2
             rounded-lg hover:bg-black text-sm font-medium shadow-sm">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 11c1.657 0 3-1.343 3-3S13.657 5 12 5 9 6.343 9 8s1.343 3 3 3z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 11h14v10H5V11z" />
              </svg>
              Khóa tất cả
            </button>
            <button id="btnUnlockAll" type="button" class="inline-flex items-center justify-center gap-2 bg-gray-900 text-white px-3 py-2 sm:px-4 sm:py-2
           rounded-lg hover:bg-black text-sm font-medium shadow-sm">
              <i data-lucide="unlock" class="w-4 h-4"></i>
              Mở khóa tất cả
            </button>
          <?php endif; ?>
        <?php endif; ?>

      </div>
    </div>




    <!-- SEARCH + FILTER -->
    <div class="space-y-3 mb-2">

      <!-- HÀNG 1 -->
      <div class="flex gap-3 items-center">
        <input id="memberSearch" class="flex-1 min-w-0 px-3 py-2 border rounded-lg" placeholder="Tìm kiếm...">

        <select id="memberFilter" class="w-[160px] px-3 py-2 border rounded-lg">
          <option value="">Tất cả</option>
          <option value="member">Đoàn viên</option>
          <option value="youth">Thanh niên</option>
        </select>
      </div>

      <!-- HÀNG 2 -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-3">

        <!-- LEFT: 3 SELECT -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 w-full">
          <select id="filterDept" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">-- Khoa --</option>
          </select>

          <select id="filterCourse" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">-- Khóa --</option>
          </select>

          <select id="filterClass" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">-- Lớp --</option>
          </select>
        </div>

        <!-- RIGHT: LABEL -->
        <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none
           sm:ml-auto shrink-0 w-full sm:w-auto justify-start sm:justify-end">
          <input type="checkbox" id="hideStopped" class="w-4 h-4 accent-red-600">
          <span class="text-red-600 font-medium whitespace-nowrap">
            Ẩn đoàn viên ngừng theo dõi
          </span>
        </label>

      </div>



    </div>

    <?php if ($showLockCol): ?>
      <div id="bulkLockBar" class="hidden mb-3 p-3 rounded-xl border bg-amber-50 flex items-center justify-between gap-3">
        <div id="bulkLockText" class="text-sm text-slate-700">Đã chọn 0 đoàn viên</div>
        <div class="flex items-center gap-2">
          <button id="btnBulkLock" type="button" class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm">
            Khóa đã chọn
          </button>
          <button id="btnBulkUnlock" type="button" class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm">
            Mở khóa
          </button>
          <button id="btnBulkClear" type="button" class="px-3 py-2 rounded-lg border text-sm">
            Bỏ chọn
          </button>
        </div>
      </div>
    <?php endif; ?>

    <!-- Table wrapper -->
    <div class="relative">

      <!-- SCROLL BAR TRÊN -->
      <div id="scrollTop" class="overflow-x-auto w-full h-4">
        <div id="scrollDummy" style="height:1px;"></div>
      </div>

      <!-- BẢNG CHÍNH -->
      <div id="scrollMain" class="bg-card rounded-2xl shadow-card border w-full overflow-x-auto">
        <table class="w-full text-sm border-collapse min-w-[2000px] table-fixed">

          <!-- 🔒 KHÓA CỨNG WIDTH CỘT -->
          <colgroup>
            <?php if ($showLockCol): ?>
              <col style="width:60px"> <!-- Chọn -->
            <?php endif; ?>
            <col style="width:160px"> <!-- MSSV -->
            <col style="width:200px"> <!-- Họ tên -->
            <col style="width:160px"> <!-- Lớp -->
            <col style="width:120px"> <!-- Đối tượng -->
            <col style="width:120px"> <!-- Ngày sinh -->
            <col style="width:140px"> <!-- Ngày vào đoàn -->
            <col style="width:100px"> <!-- Tuổi đời -->
            <col style="width:100px"> <!-- Tuổi đoàn -->
            <col style="width:80px"> <!-- Điểm -->
            <col style="width:180px"> <!-- Nguyên quán -->
            <col style="width:360px"> <!-- Nơi ở hiện tại -->
            <col style="width:100px"> <!-- Dân tộc -->
            <col style="width:120px"> <!-- Tôn giáo -->
            <col style="width:140px"> <!-- SĐT -->
            <col style="width:240px"> <!-- Email -->
            <col style="width:140px"> <!-- Ngày dự bị -->
            <col style="width:160px"> <!-- Ngày chính thức -->
            <col style="width:120px"> <!-- Ngừng theo dõi -->
            <col style="width:240px"> <!-- Ghi chú -->
            <?php if ($showActionCol): ?>
              <col style="width:140px"> <!-- Thao tác -->
            <?php endif; ?>
          </colgroup>

          <thead class="bg-gray-50 text-xs text-subtext uppercase">
            <tr>
              <?php if ($showLockCol): ?>
                <th rowspan="2" class="px-3 py-2 text-center">
                  <input type="checkbox" id="chkSelectAll" class="w-4 h-4">
                </th>
              <?php endif; ?>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>MSSV</span>
                  <input class="js-th-filter" data-key="mssv" placeholder="Lọc MSSV..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Họ tên</span>
                  <input class="js-th-filter" data-key="fullname" placeholder="Lọc họ tên..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Lớp</span>
                  <input class="js-th-filter" data-key="class_name" placeholder="Lọc lớp..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Đối tượng</span>
                  <select class="js-th-filter" data-key="type">
                    <option value="">Tất cả</option>
                    <option value="member">Đoàn viên</option>
                    <option value="youth">Thanh niên</option>
                  </select>
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Ngày sinh</span>
                  <input class="js-th-filter" data-key="birth" type="date" />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Ngày vào Đoàn</span>
                  <input class="js-th-filter" data-key="join_date" type="date" />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2 text-center">
                <div class="th-head">
                  <span>Tuổi đời</span>
                  <input class="js-th-filter text-center" data-key="age_life_min" type="number" min="0"
                    placeholder=">= ..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2 text-center">
                <div class="th-head">
                  <span>Tuổi đoàn</span>
                  <input class="js-th-filter text-center" data-key="age_youth_min" type="number" min="0"
                    placeholder=">= ..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2 text-center">
                <div class="th-head">
                  <span>Điểm</span>
                  <input class="js-th-filter text-center" data-key="score_min" type="number" min="0"
                    placeholder=">= ..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Nguyên quán</span>
                  <input class="js-th-filter" data-key="native_place" placeholder="Lọc..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Nơi ở hiện tại</span>
                  <input class="js-th-filter" data-key="current_address" placeholder="Lọc..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Dân tộc</span>
                  <input class="js-th-filter" data-key="ethnicity" placeholder="Lọc..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Tôn giáo</span>
                  <input class="js-th-filter" data-key="religion" placeholder="Lọc..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>SĐT</span>
                  <input class="js-th-filter" data-key="phone" placeholder="Lọc..." />
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Email</span>
                  <input class="js-th-filter" data-key="email" placeholder="Lọc..." />
                </div>
              </th>

              <th colspan="2" class="px-3 py-2 text-center border-l">
                <div class="th-head">
                  <span>Đảng viên</span>
                  <select class="js-th-filter" data-key="party">
                    <option value="">Tất cả</option>
                    <option value="1">Là Đảng viên</option>
                    <option value="0">Không</option>
                  </select>
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2 text-center">
                <div class="th-head">
                  <span>Ngừng theo dõi</span>
                  <select class="js-th-filter" data-key="stop_follow">
                    <option value="">Tất cả</option>
                    <option value="0">Đang theo dõi</option>
                    <option value="1">Ngừng</option>
                  </select>
                </div>
              </th>

              <th rowspan="2" class="px-3 py-2">
                <div class="th-head">
                  <span>Ghi chú</span>
                  <input class="js-th-filter" data-key="note" placeholder="Lọc..." />
                </div>
              </th>

              <?php if ($showActionCol): ?>
                <th rowspan="2" class="px-3 py-2 sticky-col-right sticky-shadow z-30">
                  Thao tác
                </th>
              <?php endif; ?>
            </tr>

            <tr>
              <th class="px-3 py-2 border-l">
                <div class="th-head">
                  <span>Ngày dự bị</span>
                  <input class="js-th-filter" data-key="party_probation_date" type="date" />
                </div>
              </th>
              <th class="px-3 py-2">
                <div class="th-head">
                  <span>Ngày chính thức</span>
                  <input class="js-th-filter" data-key="party_official_date" type="date" />
                </div>
              </th>
            </tr>
          </thead>


          <tbody id="tbodyMembers"></tbody>

        </table>
      </div>
    </div>
    <div id="pagination" class="flex justify-center items-center gap-2 mt-6"></div>
    <div id="importOverlay" class="fixed inset-0 z-50 hidden
         bg-black/40 backdrop-blur-sm
         flex items-center justify-center">
      <div class="bg-white px-6 py-4 rounded-xl shadow-lg flex items-center gap-3">
        <svg class="w-6 h-6 animate-spin text-blue-600" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" opacity="0.25" />
          <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="4" fill="none" />
        </svg>
        <span>Đang import dữ liệu, vui lòng chờ...</span>
      </div>
    </div>
  </div>
</section>

<?php
$departments = $pdo->query("
  SELECT id, name, type
  FROM departments
  ORDER BY type, name
")->fetchAll(PDO::FETCH_ASSOC);
$courses = $pdo->query("SELECT id, name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $pdo->query("SELECT id, name, department_id, course_id FROM classes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$chidoanGroups = $pdo->query("
  SELECT id, name
  FROM chidoan_groups
  ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

?>

<script>
  window.memberOptions = {
    departments: <?= json_encode($departments) ?>,
    courses: <?= json_encode($courses) ?>,
    classes: <?= json_encode($classes) ?>,
    chidoan_groups: <?= json_encode($chidoanGroups) ?>
  };
</script>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const scrollTop = document.getElementById("scrollTop");
    const scrollMain = document.getElementById("scrollMain");
    const dummy = document.getElementById("scrollDummy");

    // Gán width theo bảng thật
    dummy.style.width = scrollMain.scrollWidth + "px";

    // Sync scroll 2 chiều
    scrollTop.addEventListener("scroll", () => {
      scrollMain.scrollLeft = scrollTop.scrollLeft;
    });

    scrollMain.addEventListener("scroll", () => {
      scrollTop.scrollLeft = scrollMain.scrollLeft;
    });
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bwip-js/dist/bwip-js-min.js"></script>


<?php


$gvcnScope = null;

if ($currentRole === 'gvcn') {
  // Lấy toàn bộ lớp GVCN phụ trách
  $stmt = $pdo->prepare("
        SELECT 
            c.id          AS class_id,
            c.department_id,
            c.course_id
        FROM gvcn_classes g
        JOIN classes c ON c.id = g.class_id
        WHERE g.user_id = ?
    ");
  $stmt->execute([$uid]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    forbidden(); // GVCN mà không có lớp
  }

  // 👉 Giả định: GVCN chỉ thuộc 1 khoa
  $gvcnScope = [
    'class_ids' => array_column($rows, 'class_id'),
    'department_id' => $rows[0]['department_id'],
    'course_id' => $rows[0]['course_id'], // khóa suy ra từ lớp
  ];
}

?>
<script>
  window.MEMBER_SCOPE = <?= json_encode(
    $currentRole === 'bithu'
    ? [
      'role' => 'bithu',
      'chidoan_group_id' => $scope['chidoan_group_id'] ?? null,
      'department_id' => $scope['department_id'] ?? null,
      'course_id' => $scope['course_id'] ?? null,
      'class_id' => $scope['class_id'] ?? null,
    ]
    : ($currentRole === 'gvcn'
      ? [
        'role' => 'gvcn',
        'class_ids' => $gvcnScope['class_ids'] ?? [],
        'department_id' => $gvcnScope['department_id'] ?? null,
        'course_id' => $gvcnScope['course_id'] ?? null,
      ]
      : [
        'role' => $currentRole
      ]
    ),
    JSON_UNESCAPED_UNICODE
  ) ?>;
</script>

<style>
  .th-head {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
  }

  .th-head>span {
    font-weight: 600;
    line-height: 1.1;
  }

  .th-head input,
  .th-head select {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 12px;
    background: #fff;
    text-transform: none;
    /* vì thead đang uppercase */
  }
</style>



<script src="<?= BASE_URL ?>assets/js/members.js?v=<?= time() ?>"></script>