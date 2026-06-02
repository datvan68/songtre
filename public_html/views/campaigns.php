<?php
require __DIR__ . '/../config/db.php';


$uid = $_SESSION['user_id'] ?? 0;
$cid = (int) ($_GET['campaign_id'] ?? 0);

?>
<?php
$cid = (int) ($cid ?? 0);
$campaignOptions = [];
$stmtC = $pdo->query("SELECT id, title FROM campaigns ORDER BY start_date DESC");
foreach ($stmtC as $r) {
  $campaignOptions[] = [
    'id' => (int) $r['id'],
    'title' => (string) $r['title'],
  ];
}

// title hiện trên input theo cid
$selectedTitle = '';
if ($cid > 0) {
  foreach ($campaignOptions as $c) {
    if ($c['id'] === $cid) {
      $selectedTitle = $c['title'];
      break;
    }
  }
}
?>
<section class="p-6">
  <div class="w-full">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="font-heading text-3xl font-bold">Phong trào</h1>
        <p class="text-subtext">Quản lý & danh sách tham gia</p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-4 mb-6 border-b border-gray-200 overflow-x-auto">
      <!-- Tab Danh sách phong trào -->
      <button id="tabAll" class="tab-btn relative inline-flex items-center gap-2 py-2 px-3
         text-gray-500 border-b-2 border-transparent rounded-t-md
         transition-all duration-200 hover:text-blue-600 hover:bg-blue-50
         flex-none whitespace-nowrap">
        <i data-lucide="leaf" class="w-4 h-4"></i>
        Danh sách phong trào
      </button>


      <!-- Tab Danh sách đăng ký -->


      <button id="tabRegistered" class="tab-btn relative inline-flex items-center gap-2 py-2 px-3
         text-gray-500 border-b-2 border-transparent rounded-t-md
         transition-all duration-200 hover:text-blue-600 hover:bg-blue-50
         flex-none whitespace-nowrap">
        <i data-lucide="clipboard-list" class="w-4 h-4"></i>
        Danh sách đăng ký
        
        <?php if (can('campaign_scoring', 'update')): ?>
          <span id="pendingBadge" class="absolute -top-1 -right-3 min-w-[18px] h-[18px] text-[10px] leading-[18px]
             bg-red-500 text-white rounded-full font-semibold text-center
             shadow-[0_0_0_1px_white] hidden"></span>
        <?php endif; ?>
      </button>

      <?php if (can('campaign_scoring', 'review')): ?>
        <button id="tabClassScore" class="tab-btn relative inline-flex items-center gap-2 py-2 px-3
     text-gray-500 border-b-2 border-transparent rounded-t-md
     transition-all duration-200 hover:text-blue-600 hover:bg-blue-50
     flex-none whitespace-nowrap">
          <i data-lucide="graduation-cap" class="w-4 h-4"></i>
          Chấm điểm lớp
        </button>
      <?php endif; ?>

    </div>


    <!-- TAB 1: DANH SÁCH PHONG TRÀO -->
    <div id="tabAllContent" class="tab-content">
      <!-- Thanh tìm kiếm + nút thêm -->
      <div class="flex flex-col gap-3 mb-6">

        <!-- SEARCH (TRÊN) -->
        <div class="w-full relative">
          <input id="searchCampaign" type="text" placeholder="Tìm phong trào..." autocomplete="off"
            class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-primary" />

          <div id="searchCampaignDropdown"
            class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
            <div id="searchCampaignList" class="max-h-64 overflow-auto"></div>
          </div>
        </div>


        <!-- DROPDOWNS (TRÁI) + BUTTON (PHẢI) -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 w-full">

          <!-- LEFT: FILTERS -->
          <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
            <select id="filterCampaignStatus" class="w-full sm:w-[160px] px-3 py-2 border rounded-lg text-sm">
              <option value="all">Trạng thái</option>
              <option value="active">Đang diễn ra</option>
              <option value="hidden">Sắp diễn ra</option>
              <option value="cancelled">Đã kết thúc</option>
            </select>

            <select id="filterSchoolYear" class="w-full sm:w-[140px] px-3 py-2 border rounded-lg text-sm">
              <option value="">Năm học</option>
            </select>

            <select id="filterSemester" class="w-full sm:w-[120px] px-3 py-2 border rounded-lg text-sm">
              <option value="">Học kỳ</option>
              <option value="HK1">HK I</option>
              <option value="HK2">HK II</option>
            </select>
          </div>

          <!-- RIGHT: ADD BUTTON -->
          <?php if (can('campaigns', 'create')): ?>
            <div class="shrink-0 w-full sm:w-auto">
              <button id="btnAddCampaign"
                class="w-full sm:w-auto bg-primary text-white px-4 py-2 rounded-lg hover:bg-blue-800">
                + Thêm phong trào
              </button>
            </div>
          <?php endif; ?>

        </div>
      </div>


      <!-- Grid danh sách phong trào -->

      <div id="campaignGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      </div>

      <div id="campaignPager" class="flex justify-center items-center gap-2 mt-8">
      </div>

      <!-- Xem thêm (mobile) -->
      <div class="md:hidden mt-4">
        <button id="btnShowMoreCampaigns" class="w-full px-4 py-2 rounded-lg border hover:bg-gray-50 text-sm">Xem
          thêm</button>
      </div>
    </div>

    <!-- TAB 2: DANH SÁCH ĐĂNG KÝ -->
    <div id="tabRegisteredContent" class="hidden tab-content">
      <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
        <h2 class="text-xl font-semibold shrink-0 whitespace-nowrap">
          Đánh giá & Chấm điểm
        </h2>

        <!-- ROW FILTER + BUTTONS -->
        <div class="flex items-center gap-3 flex-wrap">

          <!-- LEFT: per page + filter -->
          <div class="flex items-center gap-2 flex-wrap">
            <div class="flex items-center gap-2 text-sm whitespace-nowrap">
              <span class="text-gray-500">Số dòng hiển thị</span>
              <select id="regPerPage" class="px-2 py-1 border rounded-lg">
                <option value="10">10 dòng / trang</option>
                <option value="15">15 dòng / trang</option>
                <option value="20">20 dòng / trang</option>
                <option value="30">30 dòng / trang</option>
                <option value="50">50 dòng / trang</option>
              </select>
            </div>

            <div class="flex items-center gap-2 text-sm whitespace-nowrap">
              <span class="text-gray-500">Năm học</span>
              <select id="filterRegSchoolYear" class="px-2 py-1 border rounded-lg">
                <option value="">Tất cả năm học</option>
              </select>
            </div>

            <div class="flex items-center gap-3">
              <label class="text-gray-700 font-medium whitespace-nowrap">Phong trào:</label>

              <div class="relative w-[220px] sm:w-[260px] md:w-[320px]">
                <input id="filterCampaignSearch" type="text" class="px-3 py-2 border rounded-lg text-sm w-full bg-white"
                  placeholder="Nhập để tìm phong trào..." autocomplete="off"
                  value="<?= htmlspecialchars($selectedTitle) ?>" />

                <input type="hidden" id="filterCampaign" value="<?= (int) $cid ?>">

                <div id="filterCampaignDropdown"
                  class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
                  <div id="filterCampaignList" class="max-h-64 overflow-auto"></div>
                </div>
              </div>
            </div>

          </div>

          <!-- RIGHT: buttons -->
          <div class="flex items-center gap-2 ml-auto">
            
            <?php if (can('campaign_scoring', 'review')): ?>
              <button id="btnBulkReview" type="button"
              class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 whitespace-nowrap">
              📝 Đánh giá hàng loạt
            </button>
            <?php endif; ?>
            
            <?php if (can('registrations', 'view') || can('registrations', 'print')): ?>
              <button id="btnExportRegistrations" type="button"
                class="hidden px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700 whitespace-nowrap">
                📥 Export
              </button>
            <?php endif; ?>
          </div>

        </div>
      </div>
      <div class="bg-card rounded-2xl shadow-card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <?php if (can('registrations', 'view') || can('registrations', 'print') || can('campaign_scoring', 'review')): ?>
                <th class="px-3 py-2 text-center w-8">
                  <input type="checkbox" id="checkAllRegs">
                </th>
              <?php endif; ?>

              <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Phong trào</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Lớp</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Họ tên</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Ngày đăng ký</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Trạng thái</th>
              <th class="px-3 py-2 text-center text-xs font-medium text-subtext uppercase">SĐT</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Ghi chú</th>

              <?php if (
                can('registrations', 'update') ||
                can('registrations', 'delete') ||
                can('campaign_scoring', 'update')
              ): ?>
                <th class="px-3 py-2"></th>
              <?php endif; ?>
            </tr>
          </thead>

          <tbody id="tbodyRegistered"></tbody>


        </table>
        <div id="pagerRegistered" class="mt-4 mb-4"></div>
      </div>
    </div>


  </div>
  <!-- TAB 3: CHẤM ĐIỂM LỚP -->
  <div id="tabClassScoreContent" class="hidden tab-content">

    <!-- HEADER -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
      <div>
        <h2 class="text-xl font-semibold">Chấm điểm lớp theo phong trào</h2>
        <p class="text-sm text-gray-500">
          Chỉ hiển thị các lớp có đoàn viên đã được chấm điểm cá nhân
        </p>
      </div>

      <div class="flex gap-2 items-center">
        <div class="flex items-center gap-2 text-sm whitespace-nowrap">
          <!-- <span class="text-gray-500 font-medium">Năm học</span> -->
          <select id="filterClassScoreSchoolYear" class="px-3 py-2 border rounded-lg bg-white text-sm">
            <option value="">Tất cả năm học</option>
          </select>
        </div>

        <div class="relative min-w-[280px]">
          <input id="classScoreCampaignSearch" type="text" class="px-3 py-2 border rounded-lg text-sm w-full bg-white"
            placeholder="Nhập để tìm phong trào..." autocomplete="off" />

          <input type="hidden" id="classScoreCampaign" value="0">

          <div id="classScoreCampaignDropdown"
            class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
            <div id="classScoreCampaignList" class="max-h-64 overflow-auto"></div>
          </div>
        </div>

        <?php if (can('campaign_scoring', 'review')): ?>
          <!-- TÍNH ĐIỂM -->
          <button id="btnCalcClassScore" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm
               hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed">
            ⚙️ Tính điểm
          </button>

          <!-- CHỐT -->
          <button id="btnLockClassScore" class="px-3 py-2 bg-red-600 text-white rounded-lg text-sm
               hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
            🔒 Chốt điểm
          </button>
        <?php endif; ?>

        <?php if (($user['role_code'] ?? '') !== 'admin'): ?>
          <button id="btnUnlockClassScore"
            class="px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700 hidden">
            🔓 Mở chốt
          </button>
        <?php endif; ?>

        <button id="btnExportClassScore"
          class="hidden px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700 whitespace-nowrap">
          📥 Xuất Excel
        </button>
      </div>
    </div>

    <!-- TABLE -->
    <div class="bg-card rounded-2xl shadow-card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-center w-8">
              <input type="checkbox" id="checkAllClassScores">
            </th>
            <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">
              Lớp
            </th>
            <th class="px-3 py-2 text-center text-xs font-medium text-subtext uppercase">
              Năm học
            </th>
            <th class="px-3 py-2 text-center text-xs font-medium text-subtext uppercase">
              Đã tham gia
            </th>
            <th class="px-3 py-2 text-center text-xs font-medium text-subtext uppercase">
              Chỉ tiêu huy động
            </th>
            <th class="px-3 py-2 text-center text-xs font-medium text-subtext uppercase">
              Điểm lớp
            </th>
          </tr>
        </thead>

        <tbody id="tbodyClassScore">
          <tr>
            <td colspan="6" class="px-4 py-8 text-center text-gray-400">
              Vui lòng chọn phong trào để bắt đầu chấm điểm lớp
            </td>
          </tr>
        </tbody>
      </table>
    </div>

  </div>



  </div>
</section>

<style>
  #tabRegistered {
    position: relative;
    display: inline-flex;
    align-items: center;
  }

  #pendingBadge {
    position: absolute;
    top: 0px;
    /* nâng nhẹ lên */
    right: -6px;
    /* sát chữ hơn xíu */
    min-width: 18px;
    /* to hơn nhẹ */
    height: 18px;
    /* cao hơn */
    font-size: 10px;
    /* chữ to hơn chút */
    padding: 0;
    border-radius: 9999px;
    line-height: 18px;
    /* căn giữa chữ */
    background-color: #ef4444;
    /* đỏ Tailwind */
    color: white;
    text-align: center;
    font-weight: 600;
    box-shadow: 0 0 0 1px white;
    /* viền trắng mảnh */
  }
</style>

<style>
  @media (max-width: 768px) {
    #tabRegisteredContent table thead {
      display: none;
    }

    #tabRegisteredContent table tbody tr {
      display: block;
      margin-bottom: 12px;
      border-top: 1px solid #e5e7eb;
    }

    #tabRegisteredContent table tbody tr td {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 12px;
    }

    #tabRegisteredContent table tbody tr td::before {
      content: attr(data-label);
      font-weight: 600;
      color: #6b7280;
      margin-right: 12px;
    }
  }
</style>
<script>
  window.CAMPAIGN_OPTIONS = <?= json_encode($campaignOptions, JSON_UNESCAPED_UNICODE) ?>;
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
  window.CAN_REG_REVIEW = <?= can('registrations', 'review') ? 'true' : 'false' ?>;
  window.CAN_REG_SCORE = <?= can('registrations', 'review') ? 'true' : 'false' ?>;
  window.CAN_REG_CANCEL = <?= can('registrations', 'delete') ? 'true' : 'false' ?>;
  window.CAN_REG_BADGE = <?= can('registrations', 'view') ? 'true' : 'false' ?>;
</script>

<script>
  window.CAN_CAMPAIGN_VIEW = <?= can('campaigns', 'view') ? 'true' : 'false' ?>;
  window.CAN_CAMPAIGN_CREATE = <?= can('campaigns', 'create') ? 'true' : 'false' ?>;
  window.CAN_CAMPAIGN_UPDATE = <?= can('campaigns', 'update') ? 'true' : 'false' ?>;
  window.CAN_CAMPAIGN_DELETE = <?= can('campaigns', 'delete') ? 'true' : 'false' ?>;
  window.CAN_CAMPAIGN_REVIEW = <?= can('campaigns', 'review') ? 'true' : 'false' ?>;

  window.CAN_REG_REVIEW = <?= can('campaign_scoring', 'review') ? 'true' : 'false' ?>;
  window.CAN_REG_SCORE = <?= can('campaign_scoring', 'review') ? 'true' : 'false' ?>;
  window.CAN_REG_CANCEL = <?= can('campaign_scoring', 'delete') ? 'true' : 'false' ?>;
</script>

<script src="<?= BASE_URL ?>assets/js/campaigns.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/registrations.js?v=<?= time() ?>"></script>
