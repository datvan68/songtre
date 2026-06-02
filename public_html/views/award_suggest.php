<?php
// views/award_suggest.php
// Trang Admin: Trung tâm gợi ý danh hiệu

if (!isset($pdo)) {
    require __DIR__ . '/../config/db.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAdmin = false;
if (function_exists('is_admin'))
    $isAdmin = is_admin();

$baseUrl = defined('BASE_URL') ? BASE_URL : '/';

// Load campaigns để tab "Thiết lập điều kiện" chọn nhanh (không phụ thuộc controller)
$campaigns = [];
try {
    $campaigns = $pdo->query("
    SELECT id, title, school_year, semester, start_date, end_date, status
    FROM campaigns
    ORDER BY start_date DESC, id DESC
    LIMIT 800
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $campaigns = [];
}
?>

<section class="p-6" id="award-suggest-app" data-view="admin">
    <div class="w-full">

        <div class="bg-white rounded-2xl border shadow-sm overflow-hidden">

            <!-- HEADER -->
            <div class="px-6 py-6 border-b">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">
                            TRUNG TÂM GỢI Ý DANH HIỆU (ADMIN)
                        </h1>
                        <p class="text-sm text-gray-500 mt-1">
                            Hệ thống tự động đối chiếu tiêu chí danh hiệu dựa trên đánh giá tham gia phong trào
                            (registrations.status)
                        </p>
                    </div>

                    <?php if (!$isAdmin): ?>
                        <div class="shrink-0 text-sm px-4 py-2 rounded-xl bg-red-50 text-red-700 border border-red-200">
                            Bạn không có quyền truy cập trang này.
                        </div>
                    <?php else: ?>
                        <div class="shrink-0 text-sm px-4 py-2 rounded-xl bg-blue-50 text-blue-700 border border-blue-200">
                            Quyền: Admin
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TABS -->
                <!-- TABS -->
                <div class="mt-6 border-b flex items-center gap-6">
                    <button class="award-tab-btn px-1 pb-3 text-sm font-semibold border-b-2
           text-blue-600 hover:text-blue-600 border-blue-600 " data-tab="suggest">
                        Gợi ý ứng viên
                    </button>

                    <button class="award-tab-btn px-1 pb-3 text-sm font-semibold border-b-2 border-transparent
           text-gray-500 hover:text-blue-600 transition" data-tab="rule">
                        Thiết lập điều kiện
                    </button>
                </div>

            </div>

            <?php if (!$isAdmin): ?>
                <div class="p-6">
                    <div class="p-4 rounded-xl bg-gray-50 text-gray-700 border">
                        Trang này chỉ dành cho Admin.
                    </div>
                </div>
            <?php else: ?>

                <!-- ================= TAB: SUGGEST ================= -->
                <div class="award-tab-panel" data-panel="suggest">
                    <div class="p-6 space-y-6">

                        <!-- FILTER BAR -->
                        <div class="rounded-2xl border bg-gray-50 p-4">
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">

                                <div class="md:col-span-4">
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Danh hiệu (Khen
                                        thưởng)</label>
                                    <select id="asTitleSelect" class="w-full border rounded-xl px-3 py-2 text-sm bg-white">
                                        <option value="">-- Chọn danh hiệu --</option>
                                    </select>
                                </div>

                                <div class="md:col-span-3">
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Năm học</label>
                                    <select id="asYearSelect" class="w-full border rounded-xl px-3 py-2 text-sm bg-white">
                                        <option value="">-- Chọn năm học --</option>
                                    </select>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Học kỳ</label>
                                    <select id="asSemesterSelect"
                                        class="w-full border rounded-xl px-3 py-2 text-sm bg-white">
                                        <option value="ALL">Tất cả</option>
                                        <option value="HK1">HK1</option>
                                        <option value="HK2">HK2</option>
                                    </select>
                                </div>

                                <div class="md:col-span-3 flex gap-2">
                                    <button id="btnRunSuggest"
                                        class="flex-1 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                                        Chạy gợi ý
                                    </button>

                                    <button id="btnReloadSuggest"
                                        class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-sm font-semibold"
                                        title="Tải lại">
                                        Reset
                                    </button>
                                </div>

                            </div>

                        </div>

                        <!-- KPI -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="rounded-2xl border p-4 bg-white">
                                <div class="text-xs text-gray-500 font-semibold">Tổng ứng viên</div>
                                <div id="kpiTotal" class="text-2xl font-bold text-gray-800 mt-1">0</div>
                            </div>

                            <div class="rounded-2xl border p-4 bg-white">
                                <div class="text-xs text-gray-500 font-semibold">Đủ điều kiện</div>
                                <div id="kpiEligible" class="text-2xl font-bold text-green-700 mt-1">0</div>
                            </div>

                            <div class="rounded-2xl border p-4 bg-white">
                                <div class="text-xs text-gray-500 font-semibold">Gần đủ</div>
                                <div id="kpiNear" class="text-2xl font-bold text-orange-700 mt-1">0</div>
                            </div>

                            <div class="rounded-2xl border p-4 bg-white">
                                <div class="text-xs text-gray-500 font-semibold">Chưa chấm</div>
                                <div id="kpiPending" class="text-2xl font-bold text-blue-700 mt-1">0</div>
                            </div>
                        </div>

                        <!-- TABLE TOOLBAR -->
                        <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                            <div class="flex gap-2 items-center">
                                <div id="asSearchBox" class="relative w-full md:w-80">
                                    <input id="asSearch" type="text" placeholder="Tìm MSSV / Họ tên / Lớp..."
                                        autocomplete="off" class="w-full border rounded-xl px-3 py-2 text-sm" />

                                    <!-- dropdown gợi ý -->
                                    <div id="asSearchDropdown"
                                        class="hidden absolute left-0 top-full mt-2 w-full z-50 bg-white border rounded-xl shadow-lg overflow-auto max-h-72">
                                    </div>
                                </div>


                                <select id="asStatusFilter" class="border rounded-xl px-3 py-2 text-sm bg-white">
                                    <option value="">Tất cả trạng thái</option>
                                    <option value="ELIGIBLE">Đủ điều kiện</option>
                                    <option value="NEAR">Gần đủ</option>
                                    <!-- <option value="PENDING_GRADE" disabled>Chưa chấm</option>
                                    <option value="NOT_ELIGIBLE" disabled>Chưa đủ</option> -->
                                </select>
                            </div>

                            <div class="flex gap-2 items-center">
                                <span class="text-xs text-gray-500 font-semibold">Hiển thị:</span>
                                <select id="asPageSize" class="border rounded-xl px-3 py-2 text-sm bg-white">
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                </select>
                            </div>
                        </div>

                        <!-- TABLE -->
                        <div class="rounded-2xl border overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="min-w-[1400px] w-full text-sm table-fixed">
                                    <colgroup>
                                        <col class="w-[120px]"> <!-- MSSV -->
                                        <col class="w-[180px]"> <!-- Họ tên -->
                                        <col class="w-[160px]"> <!-- Lớp -->
                                        <col class="w-[160px]"> <!-- Khoa -->
                                        <col class="w-[120px]"> <!-- Trạng thái -->
                                        <col class="w-[180px]"> <!-- Sẵn sàng -->
                                        <col class="w-[360px]"> <!-- Thiếu/Chưa chấm -->
                                        <col class="w-[190px]"> <!-- Thao tác -->
                                    </colgroup>

                                    <thead class="bg-gray-100 border-b">
                                        <tr>
                                            <th class="text-left px-4 py-3 font-bold text-gray-700 whitespace-nowrap">MSSV
                                            </th>
                                            <th class="text-left px-4 py-3 font-bold text-gray-700">Họ tên</th>
                                            <th class="text-left px-4 py-3 font-bold text-gray-700 whitespace-nowrap">Lớp
                                            </th>
                                            <th class="text-left px-4 py-3 font-bold text-gray-700 whitespace-nowrap">Khoa
                                            </th>
                                            <th class="text-left px-4 py-3 font-bold text-gray-700 whitespace-nowrap">Trạng
                                                thái</th>
                                            <th class="text-left px-4 py-3 font-bold text-gray-700 whitespace-nowrap">Sẵn
                                                sàng</th>
                                            <th class="text-left px-4 py-3 font-bold text-gray-700">Thiếu / Chưa chấm</th>
                                            <th
                                                class="text-right px-4 py-3 font-bold text-gray-700 sticky right-0 bg-gray-100 whitespace-nowrap">
                                                Thao tác</th>
                                        </tr>
                                    </thead>

                                    <tbody id="asTableBody" class="divide-y bg-white">
                                        <tr>
                                            <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                                                Chọn danh hiệu + năm học và bấm “Chạy gợi ý”.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                            </div>

                            <!-- PAGINATION -->
                            <div class="flex items-center justify-between px-4 py-3 bg-white border-t">
                                <div id="asPagerInfo" class="text-xs text-gray-500">
                                    0 - 0 / 0
                                </div>

                                <div class="flex items-center gap-2">
                                    <button id="asPrevPage"
                                        class="px-3 py-1.5 rounded-lg border bg-white hover:bg-gray-50 text-sm font-semibold">
                                        Trước
                                    </button>
                                    <div id="asPageNums" class="flex items-center gap-1"></div>
                                    <button id="asNextPage"
                                        class="px-3 py-1.5 rounded-lg border bg-white hover:bg-gray-50 text-sm font-semibold">
                                        Sau
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ================= TAB: RULE ================= -->
                <div class="award-tab-panel hidden" data-panel="rule">
                    <div class="p-6 space-y-6">

                        <!-- RULE FILTER -->
                        <div class="rounded-2xl border bg-gray-50 p-4">
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">

                                <div class="md:col-span-4">
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Danh hiệu (Khen
                                        thưởng)</label>
                                    <select id="ruleTitleSelect"
                                        class="w-full border rounded-xl px-3 py-2 text-sm bg-white">
                                        <option value="">-- Chọn danh hiệu --</option>
                                    </select>
                                </div>

                                <div class="md:col-span-3">
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Năm học</label>
                                    <select id="ruleYearSelect" class="w-full border rounded-xl px-3 py-2 text-sm bg-white">
                                        <option value="">-- Chọn năm học --</option>
                                    </select>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Học kỳ</label>
                                    <select id="ruleSemesterSelect"
                                        class="w-full border rounded-xl px-3 py-2 text-sm bg-white">
                                        <option value="ALL">Tất cả</option>
                                        <option value="HK1">HK1</option>
                                        <option value="HK2">HK2</option>
                                    </select>
                                </div>

                                <div class="md:col-span-3 flex gap-2">
                                    <button id="btnLoadRule"
                                        class="flex-1 px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-sm font-semibold">
                                        Tải tiêu chí
                                    </button>

                                    <button id="btnSaveRule"
                                        class="px-4 py-2 rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                                        disabled>
                                        Lưu tiêu chí
                                    </button>
                                </div>

                            </div>

                        </div>

                        <!-- RULE CONTENT -->
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                            <!-- LEFT: ADD REQUIRED CAMPAIGN -->
                            <div class="lg:col-span-5">
                                <div class="rounded-2xl border bg-white p-4 space-y-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <h3 class="font-bold text-gray-800">Thêm phong trào bắt buộc</h3>
                                            <p class="text-xs text-gray-500 mt-1">Chọn phong trào từ danh sách nội bộ (tải
                                                nhanh).</p>
                                        </div>
                                    </div>

                                    <div class="relative w-full overflow-visible" id="ruleCampaignBox">
                                        <input id="ruleCampaignSearch" type="text"
                                            class="w-full px-4 py-2 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                            placeholder="Gõ tên phong trào hoặc ID..." autocomplete="off" />

                                        <!-- ✅ Dropdown custom -->
                                        <div id="ruleCampaignDropdown"
                                            class="absolute left-0 right-0 mt-2 bg-white border border-gray-200 rounded-xl shadow-lg max-h-72 overflow-auto hidden z-[9999]">
                                        </div>
                                    </div>


                                    <div>
                                        <label class="block text-xs font-semibold text-gray-600 mb-1">Mức yêu cầu</label>
                                        <select id="ruleRequiredStatus"
                                            class="w-full border rounded-xl px-3 py-2 text-sm bg-white">
                                            <option value="excellent" selected>Hoàn thành Xuất Sắc</option>
                                            <option value="good">Hoàn thành Tốt</option>
                                        </select>
                                    </div>

                                    <button id="btnAddRuleItem"
                                        class="w-full px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">
                                        Thêm vào danh sách bắt buộc
                                    </button>

                                    <div class="rounded-xl border bg-gray-50 p-3 text-xs text-gray-600 leading-relaxed">
                                        Mẹo: Nếu bạn muốn danh hiệu “bắt buộc Xuất sắc” thì để hết “excellent”.
                                        Nếu có danh hiệu chỉ cần “Tốt” thì chọn “good”.
                                    </div>

                                </div>
                            </div>

                            <!-- RIGHT: REQUIRED LIST -->
                            <div class="lg:col-span-7">
                                <div class="rounded-2xl border bg-white overflow-hidden">
                                    <div class="px-4 py-4 border-b">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <h3 class="font-bold text-gray-800">Danh sách phong trào bắt buộc</h3>
                                                <p class="text-xs text-gray-500 mt-1">Danh sách này sẽ được dùng để chạy gợi
                                                    ý.</p>
                                            </div>

                                            <div class="text-xs text-gray-500">
                                                Tổng: <span id="ruleItemCount" class="font-bold text-gray-800">0</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="overflow-x-auto">
                                        <table class="min-w-[720px] w-full text-sm">
                                            <colgroup>
                                                <col class="w-[120px]"> 
                                                <col class="w-[420px]">
                                                <col class="w-[120px]"> 
                                                <col class="w-[80px]"> 
                                            </colgroup>
                                            <thead class="bg-gray-100 border-b">
                                                <tr>
                                                    <th class="text-left px-4 py-3 font-bold text-gray-700">Campaign ID</th>
                                                    <th class="text-left px-4 py-3 font-bold text-gray-700">Phong trào</th>
                                                    <th class="text-left px-4 py-3 font-bold text-gray-700">Yêu cầu</th>
                                                    <th class="text-center px-4 py-3 font-bold text-gray-700">Xóa</th>
                                                </tr>
                                            </thead>
                                            <tbody id="ruleItemsBody" class="divide-y bg-white">
                                                <tr>
                                                    <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                                                        Bấm “Tải rule” để xem cấu hình hiện tại.
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="px-4 py-3 border-t bg-white text-xs text-gray-500">
                                        Lưu ý: Rule được khóa theo <span class="font-semibold">Danh hiệu + Năm học + Học
                                            kỳ</span>.
                                    </div>

                                </div>
                            </div>

                        </div>

                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <script>
        window.__AWARD_SUGGEST_BASE_URL__ = <?= json_encode($baseUrl) ?>;
        window.__AWARD_SUGGEST_CAMPAIGNS__ = <?= json_encode($campaigns, JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <script src="<?= htmlspecialchars($baseUrl) ?>assets/js/award_suggest.js?v=<?= time() ?>"></script>
</section>