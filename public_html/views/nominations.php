<?php
require __DIR__ . '/../config/db.php';


// ✅ Lấy user_id đúng kiểu
$user_id = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0);

$positions = $pdo->query("
  SELECT id, name
  FROM reward_positions
  ORDER BY sort_order ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$titles = $pdo->query("
  SELECT id, name
  FROM reward_titles
  WHERE is_active = 1
  ORDER BY sort_order ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);



$groups = $pdo->query("
  SELECT
  g.id   AS group_id,
  g.name AS group_name,

  c.id   AS chidoan_id,
  c.unit_id AS dept_id,

CASE  
  WHEN d.type = 'phong' THEN
    CASE
      WHEN d.name LIKE 'Phòng %' THEN d.name
      ELSE CONCAT('Phòng ', d.name)
    END

  WHEN d.type = 'khoa' THEN
    CASE
      WHEN d.name LIKE 'Khoa %' THEN d.name
      ELSE CONCAT('Khoa ', d.name)
    END

  ELSE d.name
END AS chidoan_name


FROM chidoan_groups g
JOIN chidoans c 
  ON c.group_id = g.id 
 AND c.is_active = 1
JOIN departments d
  ON d.id = c.unit_id

ORDER BY
  CASE 
    WHEN g.id = 2 THEN 0
    WHEN g.id = 1 THEN 1
    ELSE 2
  END,
  CASE
    WHEN d.type = 'khoa'  THEN 0
    WHEN d.type = 'phong' THEN 1
    ELSE 2
  END,
  d.name,
  c.id

")->fetchAll(PDO::FETCH_ASSOC);


// Gom lại theo group
$grouped = [];

foreach ($groups as $r) {
  $gid = $r['group_id'];

  if (!isset($grouped[$gid])) {
    $grouped[$gid] = [
      'name' => $r['group_name'],
      'items' => []
    ];
  }

  $grouped[$gid]['items'][] = [
    'chidoan_id' => $r['chidoan_id'],
    'dept_id' => $r['dept_id'],
    'name' => $r['chidoan_name']
  ];
}

?>

<div class="flex">

  <!-- MAIN -->
  <main class="flex-1 bg-bg min-h-screen p-6">

    <!-- CONTAINER GIỐNG DASHBOARD -->
    <div class="w-full">

      <!-- ===== HEADER ===== -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="font-heading text-3xl font-bold">Thi đua – Khen thưởng</h1>
      </div>

      <!-- ===== TABS ===== -->
      <div class="border-b border-gray-300 mb-6">
        <nav class="flex gap-8 text-base font-medium">



          <button id="tabForm" class="tab-btn relative inline-flex items-center gap-2 py-2 px-3 text-gray-500 border-b-2 border-transparent rounded-t-md
  transition-all duration-200 hover:text-blue-600 hover:bg-blue-50">
            <i data-lucide="file-text" class="w-4 h-4"></i>
            <span>Đăng ký</span>
          </button>


          <?php if (can('nominations', 'review')): ?>
            <button id="tabList" class="tab-btn relative inline-flex items-center gap-2 py-2 px-3 text-gray-500 border-b-2 border-transparent
  rounded-t-md transition-all duration-200 hover:text-blue-600 hover:bg-blue-50">
              <i data-lucide="clipboard-list" class="w-4 h-4"></i>
              <span>Danh sách yêu cầu</span>

              <?php if (can('nominations', 'update')): ?>
                <?php
                $pendingCount = $pdo->query("SELECT COUNT(*) FROM nominations WHERE status='pending'")->fetchColumn();
                if ($pendingCount > 0) {
                  echo "<span id='pendingBadge'
                  class='absolute -top-1 -right-3 min-w-[18px] h-[18px] text-[10px] leading-[18px]
                  bg-red-500 text-white rounded-full font-semibold text-center shadow-[0_0_0_1px_white]'>
                  $pendingCount
                </span>";
                }
                ?>
              <?php endif; ?>

            </button>

          <?php else: ?>

            <?php if (can('nominations', 'create') || can('nominations', 'view')): ?>
              <button id="tabUserList" class="tab-btn relative py-2 px-3 text-gray-500 border-b-2 border-transparent rounded-t-md 
              transition-all duration-200 hover:text-blue-600 hover:bg-blue-50">
                📤 Danh sách đã gửi
              </button>
            <?php endif; ?>

          <?php endif; ?>

        </nav>
      </div>

      <!-- === TAB 1: FORM === -->

      <section id="nmForm" class="bg-card rounded-2xl shadow-card p-6">
        <form id="nominationForm" enctype="multipart/form-data" class="space-y-6">
          <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-primary uppercase">
              ĐỀ NGHỊ DANH HIỆU THI ĐUA,<br>HÌNH THỨC KHEN THƯỞNG NĂM HỌC
            </h1>
          </div>

          <div class="p-4 bg-gray-50 rounded-xl">
            <h2 class="text-lg font-semibold mb-3">Thông tin người đề nghị</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

              <!-- HÀNG 1 -->
              <div>
                <label class="block text-sm font-medium mb-1">Họ và tên *</label>
                <input name="fullname" class="w-full px-3 py-2 border rounded-lg" required>
              </div>

              <div>
                <label class="block text-sm font-medium mb-1">Năm học *</label>
                <select name="school_year" id="schoolYearSelect" class="w-full px-3 py-2 border rounded-lg" required>
                  <option value="">-- Chọn năm học --</option>
                </select>
              </div>
              <!-- Học kỳ MỚI -->
              <div>
                <label class="block text-sm font-medium mb-1">Học kỳ *</label>
                <select name="semester" class="w-full px-3 py-2 border rounded-lg" required>
                  <option value="">-- Chọn học kỳ --</option>
                  <option value="HK1">Học kỳ 1</option>
                  <option value="HK2">Học kỳ 2</option>
                </select>
              </div>

              <!-- HÀNG 2 -->
              <div>
                <label class="block text-sm font-medium mb-1">Chức vụ *</label>
                <select name="proposer_pos" class="w-full px-3 py-2 border rounded-lg" required>
                  <option value="">-- Chọn chức vụ --</option>
                  <?php foreach ($positions as $p): ?>
                    <option value="<?= htmlspecialchars($p['name']) ?>">
                      <?= htmlspecialchars($p['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label class="block text-sm font-medium mb-1">Đơn vị *</label>
                <select name="dept" class="w-full px-3 py-2 border rounded-lg" required>
                  <option value="">-- Chọn đơn vị --</option>

                  <?php foreach ($grouped as $groupId => $g): ?>
                    <optgroup label="<?= htmlspecialchars($g['name']) ?>">
                      <?php foreach ($g['items'] as $item): ?>
                        <option value="<?= htmlspecialchars($item['name']) ?>" data-group="<?= $groupId ?>"
                          data-dept-id="<?= $item['dept_id'] ?>">
                          <?= htmlspecialchars($item['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>

              </div>

              <!-- HÀNG 3 – CHỈ HIỆN KHI CHI ĐOÀN LỚP -->
              <div id="wrapCourse" class="hidden">
                <label class="block text-sm font-medium mb-1">Khóa *</label>
                <select name="course" id="courseSelect" class="w-full px-3 py-2 border rounded-lg">
                  <option value="">-- Chọn khóa --</option>
                </select>
              </div>

              <div id="wrapClass" class="hidden">
                <label class="block text-sm font-medium mb-1">Lớp *</label>
                <select name="class" id="classSelect" class="w-full px-3 py-2 border rounded-lg">
                  <option value="">-- Chọn lớp --</option>
                </select>
              </div>

            </div>

          </div>

          <div class="p-4 bg-gray-50 rounded-xl">
            <label class="block text-sm font-medium mb-2">Danh hiệu, hình thức đề nghị *</label>
            <select name="title_id" class="w-full px-3 py-2 border rounded-lg" required>
              <option value="">-- Chọn danh hiệu đề nghị --</option>
              <?php foreach ($titles as $t): ?>
                <option value="<?= (int) $t['id'] ?>">
                  <?= htmlspecialchars($t['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>


          <div class="p-4 bg-gray-50 rounded-xl">
            <div class="flex justify-between items-center mb-3">
              <h2 class="text-lg font-semibold">Hồ sơ đính kèm</h2>

              <!-- Nút quản lý -->
              <?php if (can('nominations', 'update')): ?>
                <button type="button" onclick="openAttachmentManager()"
                  class="px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                  Quản lý loại hồ sơ
                </button>
              <?php endif; ?>
            </div>

            <p class="text-sm text-subtext mb-4">
              Vui lòng đính kèm các hồ sơ được yêu cầu:
            </p>

            <!-- 🔑 BỌC KHU UPLOAD BẰNG ID ĐỂ JS RENDER -->
            <div id="attachment-upload-list" class="space-y-3">
              <p class="text-sm text-gray-500">Vui lòng chọn danh hiệu để hiện danh sách hồ sơ cần nộp.</p>
            </div>
          </div>


          <div class="flex justify-end gap-2">
            <button type="reset" class="px-6 py-2 border rounded-lg hover:bg-gray-100">Làm mới</button>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
              Nộp hồ sơ
            </button>
          </div>
        </form>
      </section>

      <!-- === TAB 2: ADMIN === -->
      <?php if (can('nominations', 'review')): ?>
        <section id="nmList" class="hidden bg-card rounded-2xl shadow-card p-6">

          <!-- PHẦN MỚI – Toolbar chứa tất cả bộ lọc -->
          <div id="adminToolbar" class="flex flex-wrap items-end gap-4 bg-gray-50 p-5 rounded-2xl mb-6">
            <!-- JS sẽ tự render 5 filter vào đây -->
          </div>


          <!-- JS sẽ render -->
          <div id="adminCards" class="space-y-4"></div>
          <div id="adminPagination" class="mt-6 flex justify-center"></div>

        </section>


      <?php else: ?>
        <!-- === TAB 2: USER === -->
        <section id="nmUserList" class="hidden bg-card rounded-2xl shadow-card p-6">
          <h2 class="text-xl font-semibold mb-4">Danh sách hồ sơ đã gửi</h2>

          <div id="userCards" class="space-y-4"></div>
          <div id="userPagination" class="mt-6 flex justify-center"></div>
        </section>


      <?php endif; ?>
    </div>

    <!-- Modal xem & duyệt -->
    <div id="reviewModal" class="hidden fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b">
          <h2 class="text-xl font-semibold">Chi tiết hồ sơ</h2>
          <button onclick="closeReviewModal()" class="text-gray-400 hover:text-gray-700 text-2xl">&times;</button>
        </div>

        <div id="reviewContent" class="p-6 max-h-[80vh] overflow-y-auto text-gray-700">
          <div class="text-center text-gray-400 py-6">Đang tải dữ liệu...</div>
        </div>
      </div>
    </div>

    <script>
      window.NOMINATIONS_CAN = {
        view: <?= can('nominations', 'view') ? 'true' : 'false' ?>,
        create: <?= can('nominations', 'create') ? 'true' : 'false' ?>,
        update: <?= can('nominations', 'update') ? 'true' : 'false' ?>,
        review: <?= can('nominations', 'review') ? 'true' : 'false' ?>,
        delete: <?= can('nominations', 'delete') ? 'true' : 'false' ?>,
        print: <?= can('nominations', 'print') ? 'true' : 'false' ?>
      };
    </script>

    <script src="<?= BASE_URL ?>assets/js/nominations.js?v=<?= time() ?>"></script>