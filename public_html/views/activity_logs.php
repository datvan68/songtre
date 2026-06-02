<?php
// display_errors controlled centrally in index.php / bootstrap
error_reporting(E_ALL);
require __DIR__ . '/../config/db.php';

// auth_guard();

// // Quyền tối thiểu
// $canView = is_admin() || (function_exists('can') && (can('activity_logs','view') || can('activity_logs','view_all')));
// if (!$canView) {
//   http_response_code(403);
//   echo "<section class='p-6 text-center text-red-600 font-semibold'>403 – Bạn không có quyền xem lịch sử hoạt động.</section>";
//   exit;
// }

$canExport = is_admin() || (function_exists('can') && can('activity_logs', 'export'));
?>

<section class="p-6">
    <div class="w-full">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="font-heading text-3xl font-bold">Lịch sử hoạt động</h1>
                <p class="text-subtext">Theo dõi thao tác hệ thống theo người dùng và role</p>
            </div>

            <div class="flex items-center gap-2">
                <button id="btnReloadLogs" class="btn btn-outline">
                    Tải lại
                </button>

                <?php if ($canExport): ?>
                    <button id="btnExportLogs" class="btn btn-primary">
                        Export CSV
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-card border rounded-xl p-4 shadow-card mb-6">
            <div class="grid grid-cols-1 md:grid-cols-6 gap-3">

                <!-- Vai trò -->
                <div>
                    <label class="text-sm text-subtext">Vai trò</label>
                    <select id="fRole" class="select w-full">
                        <option value="">-- Tất cả --</option>
                    </select>
                </div>

                <!-- Người dùng -->
                <div class="relative">
                    <label class="text-sm text-subtext">Người dùng</label>

                    <input id="fUserInput" type="text" class="input w-full" placeholder="Nhập tên người dùng..."
                        autocomplete="off" />

                    <input type="hidden" id="fUser" />

                    <div id="fUserDropdown"
                        class="absolute z-20 mt-1 w-full bg-white border rounded-md shadow max-h-56 overflow-y-auto hidden">
                    </div>
                </div>


                <!-- Chức năng -->
                <div>
                    <label class="text-sm text-subtext">Chức năng</label>
                    <select id="fModule" class="select w-full">
                        <option value="">-- Tất cả --</option>
                    </select>
                </div>

                <!-- Hành động -->
                <div>
                    <label class="text-sm text-subtext">Hành động</label>
                    <select id="fAct" class="select w-full">
                        <option value="">-- Tất cả --</option>
                    </select>
                </div>

                <!-- Từ ngày -->
                <div>
                    <label class="text-sm text-subtext">Từ ngày</label>
                    <input id="fFrom" type="date" class="input w-full" />
                </div>

                <!-- Đến ngày -->
                <div>
                    <label class="text-sm text-subtext">Đến ngày</label>
                    <input id="fTo" type="date" class="input w-full" />
                </div>

                <!-- Hiển thị / Xóa lọc -->
                <div class="md:col-span-2">
                    <label class="text-sm text-subtext">Số dòng hiển thị</label>
                    <div class="flex gap-2">
                        <select id="fPerPage" class="select w-full">
                            <option value="10">10 dòng / trang</option>
                            <option value="15">15 dòng / trang</option>
                            <option value="20">20 dòng / trang</option>
                            <option value="30">30 dòng / trang</option>
                            <option value="50">50 dòng / trang</option>
                        </select>
                        <button id="btnClearFilters" class="btn btn-outline w-full">
                            Xóa bộ lọc
                        </button>
                    </div>
                </div>

            </div>
        </div>


        <!-- Table -->
        <div class="bg-card border rounded-xl shadow-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table w-full">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="whitespace-nowrap">Thời gian</th>
                            <th class="whitespace-nowrap">Người dùng</th>
                            <th class="whitespace-nowrap">Vai trò</th>
                            <th class="whitespace-nowrap">Hành động</th>
                            <th class="whitespace-nowrap">Chức năng</th>
                            <th class="whitespace-nowrap">Đối tượng</th>
                            <th>Mô tả</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyLogs">
                        <tr>
                            <td colspan="7" class="text-center py-10 text-subtext">
                                Đang tải dữ liệu…
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>


            <!-- Pager -->
            <div class="flex items-center gap-1 text-sm select-none justify-center">
                <button id="btnFirst" class="btn btn-outline px-2 h-8 min-w-[32px] flex items-center justify-center">
                    &laquo;
                </button>

                <button id="btnPrev" class="btn btn-outline px-2 h-8 min-w-[32px] flex items-center justify-center">
                    &lsaquo;
                </button>

                <div class="flex items-center gap-1 mx-1">
                    <input id="pageInput" type="number" min="1"
                        class="h-8 w-14 text-center border rounded-md focus:ring-2 focus:ring-primary focus:border-primary outline-none"
                        value="1" />
                    <span class="text-gray-500">/</span>
                    <span id="pageTotal" class="text-gray-600 min-w-[32px] text-center">
                        1
                    </span>
                </div>

                <button id="btnNext" class="btn btn-outline px-2 h-8 min-w-[32px] flex items-center justify-center">
                    &rsaquo;
                </button>

                <button id="btnLast" class="btn btn-outline px-2 h-8 min-w-[32px] flex items-center justify-center">
                    &raquo;
                </button>
            </div>


        </div>

    </div>
</section>

<script>
    window.ACTIVITY_LOGS_CAN_EXPORT = <?= json_encode($canExport ? 1 : 0) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/activity_logs.js?v=<?= time() ?>"></script>

<style>
    /* Bảng lịch sử hoạt động */
    .table th,
    .table td {
        padding: 10px 14px;
        /* giãn ô */
        vertical-align: middle;
        /* bỏ align-top */
    }

    /* Cột mô tả */
    .table td:nth-child(7) {
        text-align: left;
        line-height: 1.6;
    }

    /* Cột IP */
    .table td:nth-child(8) {
        font-size: 12px;
        color: #6b7280;
        line-height: 1.4;
        word-break: break-all;
    }

    /* Badge hành động */
    .table .rounded.text-xs {
        padding: 4px 8px;
        line-height: 1.2;
        white-space: nowrap;
    }
</style>