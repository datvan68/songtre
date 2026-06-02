<?php
require __DIR__ . '/../config/db.php';

auth_guard();

$roleName = '';
try {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $st = $pdo->prepare("
            SELECT COALESCE(r.name,'')
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $st->execute([$uid]);
        $roleName = (string) $st->fetchColumn();
    }
} catch (Throwable $e) {
    $roleName = '';
}
$isAdmin = (strtolower(trim($roleName)) === 'admin');

$FIN_CAN = [
    'view' => function_exists('can') ? can('finance', 'view') : true,
    'create' => function_exists('can') ? can('finance', 'create') : true,
    'update' => function_exists('can') ? can('finance', 'update') : true,
    'delete' => function_exists('can') ? can('finance', 'delete') : true,
    'review' => function_exists('can') ? can('finance', 'review') : true,
    'print' => function_exists('can') ? can('finance', 'print') : true,
];
?>

<section class="px-3 pb-3" id="finance-app">

    <div class="bg-white rounded-2xl shadow-sm border">
        <div class="px-6 py-6">

            <!-- Header -->
            <div class="flex items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">QUẢN LÝ THU - CHI</h1>
                    <p class="text-sm text-gray-500 mt-1">Ghi nhận khoản thu / khoản chi và thống kê nhanh</p>
                </div>

                <div class="flex items-center gap-2">
                    <?php if ($isAdmin): ?>
                        <button id="btnVoucherSettings"
                            class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-sm font-semibold">
                            Cấu hình phiếu
                        </button>
                    <?php endif; ?>
                    <button id="btnCreateIncome"
                        class="px-4 py-2 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700">
                        Khoản thu
                    </button>

                    <button id="btnCreateExpense"
                        class="px-4 py-2 rounded-xl bg-rose-600 text-white font-semibold hover:bg-rose-700">
                        Khoản chi
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="rounded-2xl border bg-gray-50 p-4">
                    <div class="text-sm text-gray-500">Tổng thu</div>
                    <div id="statIncome" class="text-2xl font-bold text-emerald-700 mt-1">0</div>
                </div>
                <div class="rounded-2xl border bg-gray-50 p-4">
                    <div class="text-sm text-gray-500">Tổng chi</div>
                    <div id="statExpense" class="text-2xl font-bold text-rose-700 mt-1">0</div>
                </div>
                <div class="rounded-2xl border bg-gray-50 p-4">
                    <div class="text-sm text-gray-500">Chênh lệch</div>
                    <div id="statBalance" class="text-2xl font-bold text-gray-800 mt-1">0</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-4">
                <select id="filterType" class="border rounded-xl px-3 py-2 text-sm">
                    <option value="all">Tất cả</option>
                    <option value="income">Khoản thu</option>
                    <option value="expense">Khoản chi</option>
                </select>

                <select id="filterDept" class="border rounded-xl px-3 py-2 text-sm">
                    <option value="">Tất cả khoa</option>
                </select>

                <div class="relative md:col-span-2">
                    <input id="filterClass" class="border rounded-xl px-3 py-2 text-sm w-full"
                        placeholder="Lớp (gợi ý theo khoa)" autocomplete="off" />

                    <div id="filterClassSug"
                        class="absolute z-[80] w-full bg-white border rounded-xl shadow mt-1 hidden max-h-60 overflow-auto">
                    </div>
                </div>


                <input id="filterFrom" type="date" class="border rounded-xl px-3 py-2 text-sm" />
                <input id="filterTo" type="date" class="border rounded-xl px-3 py-2 text-sm" />
            </div>

            <div class="flex gap-3 mb-6">
                <input id="filterQ" class="border rounded-xl px-3 py-2 text-sm flex-1"
                    placeholder="Tìm khoản, người nộp/nhận, số phiếu, ghi chú..." />
                <button id="btnRefresh"
                    class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-sm font-semibold">
                    Refresh
                </button>
                <!-- <button id="btnExportUnpaidSummary"
                    class="px-4 py-2 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700 text-sm flex items-center gap-1.5 transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 3v12" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M8 11l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M5 21h14" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Xuất Excel lớp chưa đóng
                </button> -->
            </div>

            <!-- Table + Mobile Cards -->
            <div class="border rounded-2xl overflow-hidden">

                <!-- ✅ MOBILE: Card list -->
                <div class="md:hidden p-3 bg-gray-50">
                    <div id="financeMobileList" class="space-y-3"></div>
                </div>

                <!-- ✅ PC: Table như cũ -->
                <div class="hidden md:block overflow-auto">
                    <table class="min-w-[2000px] w-full text-sm table-fixed">
                        <colgroup>
                            <col class="w-[60px]" /> <!-- Thu/Chi -->
                            <col class="w-[90px]" /> <!-- Ngày -->
                            <col class="w-[130px]" /> <!-- HK • Năm học -->
                            <col class="w-[160px]" /> <!-- Nội dung -->
                            <col class="w-[140px]" /> <!-- Số tiền -->
                            <col class="w-[160px]" /> <!-- Khoa -->
                            <col class="w-[140px]" /> <!-- Lớp -->
                            <col class="w-[180px]" /> <!-- Người nộp/chi -->
                            <col class="w-[180px]" /> <!-- Người nhận -->
                            <col class="w-[220px]" /> <!-- Ghi chú -->
                            <col class="w-[140px]" /> <!-- Thao tác -->
                        </colgroup>

                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold text-gray-600 uppercase">
                                <th class="px-4 py-3 text-center whitespace-nowrap">Thu/Chi</th>
                                <th class="px-4 py-3 whitespace-nowrap">Ngày</th>
                                <th class="px-4 py-3">HK • Năm học</th>
                                <th class="px-4 py-3">Nội dung</th>
                                <th class="px-4 py-3 text-right whitespace-nowrap">Số tiền</th>
                                <th class="px-4 py-3 whitespace-nowrap">Khoa</th>
                                <th class="px-4 py-3 whitespace-nowrap">Lớp</th>
                                <th class="px-4 py-3 whitespace-nowrap">Người nộp/chi</th>
                                <th class="px-4 py-3 whitespace-nowrap">Người nhận</th>
                                <th class="px-4 py-3">Ghi chú</th>
                                <th class="px-4 py-3 text-right sticky right-0 bg-gray-50 whitespace-nowrap">
                                    Thao tác
                                </th>
                            </tr>
                        </thead>

                        <tbody id="financeTbody" class="divide-y"></tbody>
                    </table>
                </div>

            </div>


            <div id="financePaging" class="flex items-center justify-between mt-4 text-sm text-gray-600">
                <div id="pagingInfo">--</div>
                <div class="flex items-center gap-2">
                    <button id="btnPrev" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50">Prev</button>
                    <div id="pagingPage">1/1</div>
                    <button id="btnNext" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50">Next</button>
                </div>
            </div>

        </div>
    </div>


</section>


<script>
    window.FINANCE_API = "<?= BASE_URL ?>controllers/finance.php";
</script>
<script src="<?= BASE_URL ?>assets/js/finance.js?v=<?= time() ?>"></script>