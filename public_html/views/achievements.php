<?php
require __DIR__ . '/../config/db.php';
error_reporting(E_ALL);
// display_errors controlled centrally in index.php / bootstrap
auth_guard();

if (!can('achievements', 'view')) {
  http_response_code(403);
  echo "<section class='p-6'>403 - Forbidden</section>";
  exit;
}

$canCreate = can('achievements', 'create');
$canUpdate = can('achievements', 'update');
$canDelete = can('achievements', 'delete');
$canReview = can('achievements', 'review');
$canPrint = can('achievements', 'print');
?>

<section class="p-6">
  <div class="w-full">

    <!-- WRAPPER -->
    <div class="bg-white rounded-2xl border shadow-card p-5">

      <!-- TOP: Title + actions -->
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="font-heading font-bold leading-tight text-lg sm:text-2xl break-words">
            Quản lý Thành tích / Khen thưởng
          </h1>

        </div>

        <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end sm:gap-2 shrink-0">
          <?php if ($canCreate): ?>
            <button id="btnAddAchievement" class="inline-flex items-center justify-center gap-2 bg-primary text-white px-3 py-2 sm:px-4 sm:py-2
                     rounded-lg hover:bg-blue-800 text-sm font-medium shadow-sm">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m7-7H5" />
              </svg>
              Thêm
            </button>
          <?php endif; ?>

          <?php if ($canPrint): ?>
            <button id="btnExportPdf" class="inline-flex items-center justify-center gap-2 bg-rose-600 text-white px-3 py-2 sm:px-4 sm:py-2
           rounded-lg hover:bg-rose-700 text-sm font-medium shadow-sm">
              <i data-lucide="file-text" class="w-4 h-4"></i>
              PDF
            </button>

            <button id="btnExportXlsx" class="inline-flex items-center justify-center gap-2 bg-emerald-600 text-white px-3 py-2 sm:px-4 sm:py-2
           rounded-lg hover:bg-emerald-700 text-sm font-medium shadow-sm">
              <i data-lucide="sheet" class="w-4 h-4"></i>
              XLSX
            </button>
          <?php endif; ?>

        </div>
      </div>

      <!-- STAT CARDS -->
      <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="rounded-2xl border bg-slate-50 p-3">
          <div class="text-xs text-slate-500">Tổng</div>
          <div class="mt-1 flex items-center gap-2 text-blue-700">
            <i data-lucide="award" class="w-4 h-4"></i>
            <div class="text-xl font-semibold" id="statTotal">0</div>
          </div>
        </div>

        <div class="rounded-2xl border bg-slate-50 p-3">
          <div class="text-xs text-slate-500">Chờ duyệt</div>
          <div class="mt-1 flex items-center gap-2 text-amber-700">
            <i data-lucide="clock" class="w-4 h-4"></i>
            <div class="text-xl font-semibold" id="statPending">0</div>
          </div>
        </div>

        <div class="rounded-2xl border bg-slate-50 p-3">
          <div class="text-xs text-slate-500">Đã duyệt</div>
          <div class="mt-1 flex items-center gap-2 text-emerald-700">
            <i data-lucide="check-circle" class="w-4 h-4"></i>
            <div class="text-xl font-semibold" id="statApproved">0</div>
          </div>
        </div>

        <div class="rounded-2xl border bg-slate-50 p-3">
          <div class="text-xs text-slate-500">Từ chối</div>
          <div class="mt-1 flex items-center gap-2 text-rose-700">
            <i data-lucide="x-circle" class="w-4 h-4"></i>
            <div class="text-xl font-semibold" id="statRejected">0</div>
          </div>
        </div>
      </div>

      <!-- TABS -->
      <div class="mt-5 border-b">
        <div class="flex items-center gap-2">
          <button type="button" id="tabList"
            class="px-3 py-2 text-sm font-medium rounded-t-lg border border-b-0 bg-white">
            Thành tích khen thưởng
          </button>

          <?php if ($canReview): ?>
            <button type="button" id="tabReview"
              class="px-3 py-2 text-sm font-medium rounded-t-lg border border-b-0 bg-slate-50 text-slate-700">
              Duyệt thành tích
              <span id="tabPendingBadge"
                class="ml-2 inline-flex items-center justify-center min-w-[22px] h-5 px-1.5 rounded-full text-xs bg-amber-100 text-amber-700">
                0
              </span>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- PANELS -->
      <div class="pt-4">

        <!-- PANEL 1: LIST -->
        <div id="panelList">

          <!-- FILTERS -->
          <div class="space-y-3 mb-3">
            <div class="flex gap-3 items-center">
              <input id="fKeyword" class="flex-1 min-w-0 px-3 py-2 border rounded-lg"
                placeholder="Tìm theo tên thành tích / nội dung / cá nhân / tập thể...">

              <select id="fRecipientType" class="w-[170px] px-3 py-2 border rounded-lg">
                <option value="">Tất cả đơn vị</option>
                <option value="individual">Cá nhân</option>
                <option value="collective">Tập thể</option>
              </select>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 w-full">
                <input id="fSchoolYear" class="w-full px-3 py-2 border rounded-lg text-sm"
                  placeholder="Năm học (VD 2025-2026)">
                <input id="fAwardLevel" class="w-full px-3 py-2 border rounded-lg text-sm"
                  placeholder="Cấp khen (Trường/Tỉnh...)">
                <select id="fVisibility" class="w-full px-3 py-2 border rounded-lg text-sm">
                  <option value="">-- Hiển thị --</option>
                  <option value="public">Công khai</option>
                  <option value="hidden">Ẩn</option>
                </select>
              </div>

              <div class="flex items-center gap-2 sm:ml-auto shrink-0 w-full sm:w-auto justify-start sm:justify-end">
                <button id="btnReset" class="px-3 py-2 rounded-lg border text-sm">Reset</button>
              </div>

            </div>
          </div>

          <!-- TABLE -->
          <div class="bg-card rounded-2xl shadow-card border w-full overflow-x-auto">
            <table class="w-full text-sm border-collapse min-w-[1200px] table-fixed">
              <colgroup>
                <col style="width:70px">
                <col style="width:320px">
                <col style="width:260px">
                <col style="width:200px">
                <col style="width:140px">
                <col style="width:120px">
                <col style="width:120px">
                <col style="width:120px">
                <col style="width:120px">
                <col style="width:160px">
              </colgroup>

              <thead class="bg-gray-50 text-xs text-subtext uppercase">
                <tr>
                  <th class="px-3 py-2">ID</th>
                  <th class="px-3 py-2">Thành tích</th>
                  <th class="px-3 py-2">Đơn vị đạt</th>
                  <th class="px-3 py-2">Cấp / Hình thức</th>
                  <th class="px-3 py-2">Năm học</th>
                  <th class="px-3 py-2">Ngày đạt</th>
                  <th class="px-3 py-2 text-center">Hiển thị</th>
                  <th class="px-3 py-2 text-center">Trạng thái</th>
                  <th class="px-3 py-2 text-center">Ghi chú</th>
                  <th
                    class="px-3 py-2 text-center sticky right-0 z-30 bg-gray-100 border-l shadow-[-8px_0_12px_-12px_rgba(0,0,0,0.35)]">
                    Thao tác
                  </th>
                </tr>
              </thead>

              <tbody id="tbodyAchievements"></tbody>
            </table>
          </div>

          <div id="pagination" class="flex justify-center items-center gap-2 mt-6"></div>
        </div>

        <!-- PANEL 2: REVIEW (only can_review) -->
        <?php if ($canReview): ?>
          <div id="panelReview" class="hidden">

            <div class="mb-3 p-3 rounded-2xl border bg-amber-50 text-sm text-amber-900 flex items-start gap-2">
              <i data-lucide="shield-check" class="w-4 h-4 mt-0.5"></i>
              <div>
                Đây là danh sách <b>Chờ duyệt</b>. Bạn có thể xem chi tiết và duyệt/từ chối.
              </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 items-center mb-3">
              <input id="rKeyword" class="flex-1 min-w-0 px-3 py-2 border rounded-lg" placeholder="Tìm nhanh...">

              <select id="rStatus" class="w-full sm:w-[190px] px-3 py-2 border rounded-lg">
                <option value="">-- Trạng thái --</option>
                <option value="submitted">Chờ duyệt</option>
                <option value="approved">Đã duyệt</option>
                <option value="rejected">Từ chối</option>
                <option value="draft">Nháp</option>
              </select>

              <button id="btnReviewReset" class="px-3 py-2 rounded-lg border text-sm w-full sm:w-auto">Reset</button>
            </div>


            <div class="bg-card rounded-2xl shadow-card border w-full overflow-x-auto">
              <table class="w-full text-sm border-collapse min-w-[1050px] table-fixed">
                <colgroup>
                  <col style="width:70px">
                  <col style="width:360px">
                  <col style="width:260px">
                  <col style="width:140px">
                  <col style="width:140px"> <!-- Trạng thái -->
                  <col style="width:170px">
                  <col style="width:170px">
                  <col style="width:160px">
                </colgroup>

                <thead class="bg-gray-50 text-xs text-subtext uppercase">
                  <tr>
                    <th class="px-3 py-2">ID</th>
                    <th class="px-3 py-2">Thành tích</th>
                    <th class="px-3 py-2">Đơn vị đạt</th>
                    <th class="px-3 py-2">Năm học</th>
                    <th class="px-3 py-2 text-center">Trạng thái</th>
                    <th class="px-3 py-2">Người nhập</th>
                    <th class="px-3 py-2">Ghi chú</th>
                    <th
                      class="px-3 py-2 text-center sticky right-0 z-30 bg-gray-100 border-l shadow-[-8px_0_12px_-12px_rgba(0,0,0,0.35)]">
                      Thao tác
                    </th>
                  </tr>
                </thead>

                <tbody id="tbodyReview"></tbody>
              </table>
            </div>

            <div id="paginationReview" class="flex justify-center items-center gap-2 mt-6"></div>
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</section>

<script>
  window.ACHV = {
    baseUrl: "<?= BASE_URL ?>",
    ctrl: "<?= BASE_URL ?>controllers/achievements.php",
    caps: <?= json_encode([
      'can_create' => (int) $canCreate,
      'can_update' => (int) $canUpdate,
      'can_delete' => (int) $canDelete,
      'can_review' => (int) $canReview,
      'can_print' => (int) $canPrint,
    ], JSON_UNESCAPED_UNICODE) ?>
  };
</script>

<script src="<?= BASE_URL ?>assets/js/achievements.js?v=<?= time() ?>"></script>