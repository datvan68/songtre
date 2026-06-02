<?php
$u = auth_user();

$canView = can('schedule', 'view');
$canCreate = can('schedule', 'create');
$canUpdate = can('schedule', 'update');
$canDelete = can('schedule', 'delete');
$canReview = can('schedule', 'review');

if (!$canView) {
  echo '<div class="p-6 text-center text-red-600 font-semibold">
          403 – Bạn không có quyền xem lịch công tác.
        </div>';
  return;
}
?>

<section class="p-6">
  <div class="w-full">

    <!-- CARD WRAPPER -->
    <div class="bg-white rounded-2xl shadow-card border border-gray-200 p-6">


      <!-- ================= HEADER ================= -->
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-6">
        <h1 class="text-3xl font-bold font-heading">
          Lịch Công Tác Đoàn
        </h1>

        <?php if ($canCreate): ?>
          <button id="btnAddEvent"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow transition">
            + Thêm lịch
          </button>
        <?php endif; ?>
      </div>

      <!-- ================= TABS ================= -->
      <div class="flex gap-3 mb-4 border-b pb-2">
        <button data-view="list" class="view-btn active">Danh sách</button>
        <button data-view="week" class="view-btn">Tuần</button>
        <button data-view="month" class="view-btn">Tháng</button>
        <?php if (can('schedule', 'create') && !can('schedule', 'review')): ?>
          <button class="view-btn relative" data-view="my_pending">
            <span>Chờ duyệt</span>

            <span id="myPendingBadge" class="hidden absolute -top-1 -right-2
                 min-w-[18px] h-[18px]
                 text-[11px] font-bold
                 leading-[18px] text-center
                 bg-orange-500 text-white rounded-full">
              0
            </span>
          </button>
        <?php endif; ?>

        <?php if ($canReview): ?>
          <button data-view="approve" class="view-btn relative inline-flex items-center">

            <span>Duyệt đăng ký</span>

            <span id="approveBadge" class="hidden absolute
           -top-1 -right-2
           min-w-[18px] h-[18px]
           px-1
           text-[11px] font-bold
           leading-[18px] text-center
           bg-red-600 text-white
           rounded-full">
              1
            </span>
          </button>

        <?php endif; ?>
      </div>


      <!-- ================= TAB 1: DANH SÁCH THEO THÁNG ================= -->
      <div id="listBox" class="space-y-4">
        <!-- JS render accordion tháng -->
        <div class="text-gray-500 text-center py-6">
          Đang tải lịch công tác...
        </div>
      </div>
      <!-- ================= TAB 2: DANH SÁCH TUẦN ================= -->
      <div id="weekListBox" class="hidden mt-6">

        <!-- HEADER TUẦN -->
        <div class="mb-4">
          <div class="w-full px-4 py-3 rounded-xl
           bg-gradient-to-r from-indigo-500 to-purple-600
           text-white font-semibold shadow-sm">

            <div class="flex items-center justify-between">

              <!-- TUẦN TRƯỚC -->
              <button id="btnPrevWeek" class="p-2 rounded-full bg-white/20 hover:bg-white/30
               transition flex items-center justify-center">
                <i data-lucide="chevron-left" class="w-5 h-5"></i>
              </button>

              <!-- TITLE -->
              <div class="text-center leading-tight">
                <h2 id="weekTitle" class="text-lg font-bold tracking-wide">
                  TUẦN ...
                </h2>
                <p id="weekRange" class="text-sm opacity-90">
                  Từ ngày ... đến ngày ...
                </p>
              </div>

              <!-- TUẦN SAU -->
              <button id="btnNextWeek" class="p-2 rounded-full bg-white/20 hover:bg-white/30
               transition flex items-center justify-center">
                <i data-lucide="chevron-right" class="w-5 h-5"></i>
              </button>

            </div>
          </div>
        </div>

        <!-- BẢNG LỊCH TUẦN -->
        <div class="overflow-x-auto">
          <table class="w-full text-sm border border-gray-200 border-collapse table-fixed">
            <thead class="bg-gray-100">
              <tr>
                <th class="border px-3 py-2 text-left w-[120px]">Ngày</th>

                <th class="border px-3 py-2 text-center w-1/3">
                  SÁNG<br><span class="text-xs">(05:00 – 13:00)</span>
                </th>

                <th class="border px-3 py-2 text-center w-1/3">
                  CHIỀU<br><span class="text-xs">(13:00 – 18:00)</span>
                </th>

                <th class="border px-3 py-2 text-center w-1/3">
                  TỐI<br><span class="text-xs">(18:00 – 23:00)</span>
                </th>

              </tr>
            </thead>

            <tbody id="weekTableBody">
              <tr>
                <td colspan="4" class="text-center text-gray-400 py-6">
                  Đang tải lịch tuần...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ================= TAB 2 + 3: CALENDAR ================= -->
      <div id="calendarBox" class="hidden bg-card rounded-2xl shadow-card p-4 md:p-6 border border-gray-200 mt-4">
        <div id="calendar"></div>
      </div>
      <?php if ($canReview): ?>
        <div id="approveBox" class="hidden mt-6">
          <div class="text-gray-500 text-center py-6">
            Đang tải danh sách lịch chờ duyệt...
          </div>
        </div>
      <?php endif; ?>
      <div id="myPendingBox" class="hidden"></div>

    </div>


    <!--=================PERMISSION FLAGS=================-->
    <script>
      window.SCHEDULE_CAN = {
        view: <?= $canView ? 'true' : 'false' ?>,
        create: <?= $canCreate ? 'true' : 'false' ?>,
        update: <?= $canUpdate ? 'true' : 'false' ?>,
        review: <?= $canReview ? 'true' : 'false' ?>,
        delete: <?= $canDelete ? 'true' : 'false' ?>
      };
    </script>

    <!-- ================= FULLCALENDAR ================= -->
    <link href="https://unpkg.com/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://unpkg.com/fullcalendar@6.1.11/index.global.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/vn.js"></script>
    <!-- ================= STYLE ================= -->
    <style>
      /* ===== FullCalendar đẹp hơn ===== */
      #calendarBox {
        background: white;
      }

      .fc {
        font-family: inherit;
      }

      .fc .fc-toolbar-title {
        font-size: 18px;
        font-weight: 700;
        letter-spacing: 0.2px;
      }

      .fc .fc-button {
        border-radius: 10px !important;
        border: 1px solid #e5e7eb !important;
        background: #fff !important;
        color: #111827 !important;
        font-weight: 600 !important;
        padding: 6px 12px !important;
      }

      .fc .fc-button:hover {
        background: #f3f4f6 !important;
      }

      .fc .fc-button-primary:not(:disabled).fc-button-active {
        background: #2563eb !important;
        border-color: #2563eb !important;
        color: #fff !important;
      }

      .fc .fc-daygrid-day-number {
        font-size: 12px;
        font-weight: 600;
        color: #374151;
      }

      .fc .fc-daygrid-day.fc-day-today {
        background: #eff6ff !important;
      }

      .fc .fc-scrollgrid {
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid #e5e7eb;
      }

      .fc .fc-col-header-cell {
        background: #f9fafb;
        font-weight: 700;
        color: #374151;
        font-size: 12px;
      }

      /* chip event */
      .fc .fc-daygrid-event {
        border: 0 !important;
        border-radius: 10px !important;
        padding: 2px 6px !important;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;

        /* quan trọng: để event không tràn layout */
        overflow: hidden !important;
      }

      /* ✅ Cho chữ xuống hàng + giới hạn 2 dòng */
      .fc .fc-daygrid-event .fc-event-title {
        white-space: normal !important;
        word-break: break-word !important;
        line-height: 1.2 !important;

        display: -webkit-box !important;
        -webkit-box-orient: vertical !important;
        -webkit-line-clamp: 2 !important;
        /* tối đa 2 dòng */
        overflow: hidden !important;
      }

      /* ✅ FIX: sticky làm chữ nhảy lên ô trên */
      .fc .fc-daygrid-event .fc-event-title.fc-sticky {
        position: static !important;
      }


      .view-btn {
        padding: 6px 14px;
        border-radius: 9999px;
        font-weight: 500;
        color: #374151;
        transition: 0.15s;
      }

      .view-btn.active {
        background: #2563eb;
        color: white;
      }

      .animate-fade-in {
        animation: fade-in 0.2s ease-out;
      }

      @keyframes fade-in {
        from {
          opacity: 0;
          transform: translateY(-8px);
        }

        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
    </style>
    <script>
      window.AUTH_USER_ID = <?= (int) ($_SESSION['user_id'] ?? 0) ?>;
    </script>
    <!-- ================= JS ================= -->
    <script src="<?= BASE_URL ?>assets/js/schedule.js?v=<?= time() ?>"></script>