<?php
// Lấy thứ 2 của TUẦN SAU
$weekStart = new DateTime();
$weekStart->setISODate(
    (int) date('o'),
    (int) date('W'),
    1
);
$weekStart->modify('+1 week'); // 🔥 BẮT BUỘC

$days = [];

for ($i = 0; $i < 5; $i++) {
    $d = clone $weekStart;
    $d->modify("+$i day");

    $days[] = [
        'num' => 2 + $i,           // 2 → 6
        'label' => 'T' . (2 + $i),   // T2 → T6
        'date' => $d->format('d/m/Y'),
    ];
}

$weekRange = $days[0]['date'] . ' – ' . $days[4]['date'];

?>

<section>

    <div class="w-full">

        <!-- CARD CHÍNH -->
        <div class="bg-white rounded-2xl shadow-sm">
            <div>

                <!-- ================= HEADER ================= -->
                <div class="px-6 pt-6 pb-4 border-b">
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">

                        <!-- LEFT -->
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">
                                LỊCH TRỰC BAN CHẤP HÀNH
                            </h1>
                            <p class="text-sm text-gray-500 mt-1">
                                Đăng ký lịch rảnh – khai báo lịch học – xem lịch trực
                            </p>
                        </div>

                        <!-- RIGHT -->
                        <div class="flex gap-2 export-hide shrink-0">
                            <button id="btnUserExportImg" class="px-4 py-2 rounded-lg border text-sm font-medium
                       hover:bg-gray-100 transition">
                                🖼 Xuất ảnh
                            </button>

                            <button id="btnUserExportPdf" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium
                       hover:bg-red-700 transition">
                                📄 Xuất PDF
                            </button>
                        </div>

                    </div>
                </div>


                <!-- ================= TABS ================= -->
                <div class="px-6 pt-4">
                    <div class="flex gap-2 border-b overflow-x-auto whitespace-nowrap">
                        <button class="tab-btn tab-active px-4 py-2 rounded-t-lg text-sm font-medium"
                            data-tab="register">
                            Đăng ký lịch
                        </button>

                        <button class="tab-btn px-4 py-2 rounded-t-lg text-sm font-medium" data-tab="study">
                            Lịch học chính thức
                        </button>

                        <button class="tab-btn px-4 py-2 rounded-t-lg text-sm font-medium" data-tab="my">
                            Lịch trực của tôi
                        </button>
                    </div>
                </div>
                <!-- ================= CONTENT ================= -->
                <div class="px-6 py-6 grid-container w-full overflow-x-hidden">

                    <div id="availabilityGrid" class="user-view" data-view="register">

                        <header class="mb-6 flex items-start justify-between gap-4">

                            <div>
                                <h2 class="text-lg font-semibold text-gray-800">
                                    Đăng ký lịch rảnh trực
                                </h2>
                                <p class="text-sm text-gray-500">
                                    Tối thiểu 3 buổi / tuần
                                </p>
                            </div>

                            <!-- WEEK RANGE -->
                            <div
                                class="shrink-0 mt-1 px-3 py-1 rounded-lg border text-sm font-medium text-blue-700 bg-blue-50 border-blue-200">
                                <?= $weekRange ?>
                            </div>

                        </header>

                        <!-- PC TABLE -->
                        <div class="hidden md:block overflow-x-auto">
                            <div class="overflow-x-auto">
                                <div class="w-full min-w-[720px] border rounded-xl overflow-hidden bg-white">

                                    <!-- HEADER -->
                                    <div
                                        class="grid grid-cols-[140px_minmax(160px,1fr)_minmax(160px,1fr)_minmax(160px,1fr)_minmax(160px,1fr)] bg-gray-50 border-b text-sm font-semibold text-gray-600">
                                        <div class="px-4 py-3 text-center">Ngày</div>
                                        <div class="px-4 py-3 text-center">Sáng</div>
                                        <div class="px-4 py-3 text-center">Chiều</div>
                                        <div class="px-4 py-3 text-center">Ra chơi S</div>
                                        <div class="px-4 py-3 text-center">Ra chơi C</div>
                                    </div>

                                    <?php foreach ($days as $day): ?>
                                        <div
                                            class="grid grid-cols-[140px_minmax(160px,1fr)_minmax(160px,1fr)_minmax(160px,1fr)_minmax(160px,1fr)] border-b items-center">

                                            <!-- NGÀY -->
                                            <div class="px-2 py-4">
                                                <div class="font-semibold text-blue-600 text-center">
                                                    <?= $day['label'] ?>
                                                </div>
                                                <div class="text-xs text-gray-500 text-center">
                                                    <?= $day['date'] ?>
                                                </div>
                                            </div>

                                            <!-- SÁNG -->
                                            <div class="px-2 py-3">
                                                <label class="duty-cell">
                                                    <input type="checkbox" class="duty-checkbox hidden"
                                                        data-day="<?= $day['num'] ?>" data-shift="morning">
                                                    <span class="cell-box"></span>
                                                </label>
                                            </div>

                                            <!-- CHIỀU -->
                                            <div class="px-2 py-3">
                                                <label class="duty-cell">
                                                    <input type="checkbox" class="duty-checkbox hidden"
                                                        data-day="<?= $day['num'] ?>" data-shift="afternoon">
                                                    <span class="cell-box"></span>
                                                </label>
                                            </div>
                                            <!-- RA CHƠI S -->
                                            <div class="px-2 py-3">
                                                <label class="duty-cell">
                                                    <input type="checkbox" class="duty-checkbox hidden"
                                                        data-day="<?= $day['num'] ?>" data-shift="rachoi_s">
                                                    <span class="cell-box"></span>
                                                </label>
                                            </div>

                                            <!-- RA CHƠI C -->
                                            <div class="px-2 py-3">
                                                <label class="duty-cell">
                                                    <input type="checkbox" class="duty-checkbox hidden"
                                                        data-day="<?= $day['num'] ?>" data-shift="rachoi_c">
                                                    <span class="cell-box"></span>
                                                </label>
                                            </div>

                                        </div>
                                    <?php endforeach; ?>



                                </div>
                            </div>
                        </div>
                        <!-- MOBILE CARDS -->
                        <div class="md:hidden space-y-4">
                            <?php foreach ($days as $day): ?>
                                <div class="border rounded-xl p-4 bg-white shadow-sm">
                                    <div class="mb-3">
                                        <div class="font-semibold text-blue-600">
                                            <?= $day['label'] ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= $day['date'] ?>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 gap-3">
                                        <!-- SÁNG -->
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" class="duty-checkbox" data-day="<?= $day['num'] ?>"
                                                data-shift="morning">
                                            <span>Sáng</span>
                                        </label>

                                        <!-- CHIỀU -->
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" class="duty-checkbox" data-day="<?= $day['num'] ?>"
                                                data-shift="afternoon">
                                            <span>Chiều</span>
                                        </label>

                                        <!-- RA CHƠI S -->
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" class="duty-checkbox" data-day="<?= $day['num'] ?>"
                                                data-shift="rachoi_s">
                                            <span>Ra chơi sáng</span>
                                        </label>

                                        <!-- RA CHƠI C -->
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" class="duty-checkbox" data-day="<?= $day['num'] ?>"
                                                data-shift="rachoi_c">
                                            <span>Ra chơi chiều</span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-6 flex items-start gap-6">
                            <!-- NÚT BÊN TRÁI -->
                            <button id="btnSaveAvailability"
                                class="w-full md:w-auto px-5 py-3 bg-blue-600 text-white rounded-xl font-medium hover:bg-blue-700 shrink-0">
                                Lưu đăng ký
                            </button>

                            <!-- CỘT TEXT BÊN PHẢI -->
                            <div class="flex flex-col gap-1">
                                <span id="availabilityHint" class="text-sm text-gray-500">
                                    Đã chọn: 0 buổi
                                </span>

                                <p class="text-sm text-gray-500">
                                    2 buổi ra chơi = 1 buổi thường
                                </p>
                            </div>
                        </div>
                    </div>


                    <div id="studyGrid" class="user-view hidden" data-view="study">

                        <header class="mb-6 flex items-start justify-between gap-4">

                            <div>
                                <h2 class="text-lg font-semibold text-gray-800">
                                    Lịch học chính thức của bạn
                                </h2>
                                <p class="text-sm text-gray-500">
                                    Đánh dấu các buổi bạn có học (Sáng / Chiều)
                                </p>
                            </div>

                            <!-- WEEK RANGE -->
                            <div
                                class="shrink-0 mt-1 px-3 py-1 rounded-lg border text-sm font-medium text-orange-700 bg-orange-50 border-orange-200">
                                <?= $weekRange ?>
                            </div>

                        </header>

                        <div class="hidden md:block">
                            <div class="overflow-x-auto">
                                <div class="w-full min-w-[560px] border rounded-xl overflow-hidden bg-white">

                                    <!-- HEADER -->
                                    <div
                                        class="grid grid-cols-[140px_minmax(160px,1fr)_minmax(160px,1fr)] bg-gray-50 border-b text-sm font-semibold text-gray-600">
                                        <div class="px-4 py-3 text-center">Ngày</div>
                                        <div class="px-4 py-3 text-center">Sáng</div>
                                        <div class="px-4 py-3 text-center">Chiều</div>
                                    </div>

                                    <?php foreach ($days as $day): ?>
                                        <div
                                            class="grid grid-cols-[140px_minmax(160px,1fr)_minmax(160px,1fr)] border-b items-center">

                                            <!-- NGÀY -->
                                            <div class="px-4 py-3">
                                                <div class="font-semibold text-orange-600 text-center">
                                                    <?= $day['label'] ?>
                                                </div>
                                                <div class="text-xs text-gray-500 text-center">
                                                    <?= $day['date'] ?>
                                                </div>
                                            </div>

                                            <!-- SÁNG -->
                                            <div class="px-4 py-3 flex justify-center">
                                                <label class="study-cell">
                                                    <input type="checkbox" class="study-checkbox hidden"
                                                        data-day="<?= $day['num'] ?>" data-shift="morning">
                                                    <span class="study-box"></span>
                                                </label>
                                            </div>

                                            <!-- CHIỀU -->
                                            <div class="px-4 py-3 flex justify-center">
                                                <label class="study-cell">
                                                    <input type="checkbox" class="study-checkbox hidden"
                                                        data-day="<?= $day['num'] ?>" data-shift="afternoon">
                                                    <span class="study-box"></span>
                                                </label>
                                            </div>

                                        </div>
                                    <?php endforeach; ?>

                                </div>
                            </div>
                        </div>
                        <div class="md:hidden space-y-4">
                            <?php foreach ($days as $day): ?>
                                <div class="border rounded-xl p-4 bg-white">
                                    <div class="mb-3">
                                        <div class="font-semibold text-orange-600">
                                            <?= $day['label'] ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= $day['date'] ?>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 gap-3">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" class="study-checkbox" data-day="<?= $day['num'] ?>"
                                                data-shift="morning">
                                            <span>Sáng</span>
                                        </label>

                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" class="study-checkbox" data-day="<?= $day['num'] ?>"
                                                data-shift="afternoon">
                                            <span>Chiều</span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-6 flex items-center gap-4">
                            <button id="btnSaveStudy"
                                class="w-full md:w-auto px-5 py-3 bg-orange-500 text-white rounded-xl font-medium hover:bg-orange-600">
                                💾 Lưu lịch học
                            </button>

                            <span id="studyHint" class="text-sm text-gray-500">
                                Đã chọn: 0 buổi
                            </span>
                        </div>

                    </div>




                    <div class="user-view" data-view="my">

                        <header class="mb-6 flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-800">Lịch trực của tôi</h2>
                                <p class="text-sm text-gray-500">Các buổi bạn được phân công trực trong tuần</p>
                            </div>

                            <div
                                class="shrink-0 mt-1 px-3 py-1 rounded-lg border text-sm font-medium text-purple-700 bg-purple-50 border-purple-200">
                                <?= $weekRange ?>
                            </div>
                        </header>

                        <!-- ================= PC VIEW ================= -->
                        <div class="hidden md:block">
                            <div
                                class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden duty-export-target">
                                <div class="overflow-x-auto">
                                    <!-- EMPTY -->
                                    <div id="myDutyEmpty" class="p-6 border rounded-xl bg-gray-50 text-gray-500">
                                        Chưa có lịch trực được phân công.
                                    </div>

                                    <div id="userExportArea" class="overflow-x-auto">
                                        <!-- GRID HORIZONTAL LIKE ADMIN -->

                                        <div id="myDutyGridWrap"
                                            class="min-w-[820px] border rounded-xl overflow-hidden bg-white">

                                            <!-- HEADER ROW -->
                                            <div
                                                class="grid grid-cols-[130px_repeat(5,minmax(120px,1fr))] bg-gray-50 border-b text-sm font-semibold text-gray-600">
                                                <div class="px-4 py-3 text-center">Ca</div>

                                                <?php foreach ($days as $day): ?>
                                                    <div class="px-4 py-3 text-center">
                                                        <div><?= $day['label'] ?></div>
                                                        <div class="text-xs font-normal text-gray-500">
                                                            <?= $day['date'] ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <?php
                                            $shiftRows = [
                                                ['key' => 'sang', 'label' => 'Sáng', 'color' => 'text-blue-600 bg-blue-50'],
                                                ['key' => 'chieu', 'label' => 'Chiều', 'color' => 'text-purple-600 bg-purple-50'],
                                                ['key' => 'rachoi_s', 'label' => 'Ra chơi S', 'color' => 'text-orange-600 bg-orange-50'],
                                                ['key' => 'rachoi_c', 'label' => 'Ra chơi C', 'color' => 'text-orange-600 bg-orange-50'],
                                            ];
                                            ?>

                                            <?php foreach ($shiftRows as $row): ?>
                                                <div class="grid grid-cols-[130px_repeat(5,minmax(120px,1fr))] border-b">

                                                    <!-- SHIFT LABEL -->
                                                    <div class="px-3 py-3 flex items-center justify-center ">
                                                        <div
                                                            class="w-full px-3 py-3 rounded-lg <?= $row['color'] ?> flex items-center justify-center font-medium">
                                                            <?= $row['label'] ?>
                                                        </div>
                                                    </div>

                                                    <!-- CELLS T2..T6 -->
                                                    <?php foreach ($days as $day): ?>
                                                        <?php
                                                        // $day['label'] đang là T2..T6
                                                        $dayEnum = $day['label'];
                                                        ?>
                                                        <div class="px-2 py-3">
                                                            <div class="my-duty-cell user-duty-cell min-h-[72px] rounded-lg border border-gray-300 p-2 bg-white flex items-center justify-center "
                                                                data-day="<?= $dayEnum ?>" data-shift="<?= $row['key'] ?>">
                                                                <div class="my-duty-empty text-center text-gray-400 text-xs ">
                                                                    Chưa phân công
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>

                                                </div>
                                            <?php endforeach; ?>

                                        </div>
                                    </div>
                                </div>



                                <!-- legend -->
                                <div class="mt-3 text-xs text-gray-500 flex items-center gap-4 mb-2 ml-4">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="w-3 h-3 rounded border border-green-300 bg-green-100"></span>
                                        Trực thường
                                    </span>
                                    <span class="inline-flex items-center gap-2">
                                        <span class="w-3 h-3 rounded border border-orange-300 bg-orange-100"></span>
                                        Trực giờ ra chơi
                                    </span>
                                </div>

                            </div>

                        </div>
                        <!-- ================= MOBILE VIEW (RENDER BẰNG JS) ================= -->
                        <div class="md:hidden space-y-4" id="myDutyMobileWrap">

                            <!-- EMPTY MOBILE -->
                            <div id="myDutyMobileEmpty" class="p-4 border rounded-xl bg-gray-50 text-gray-500 hidden">
                                Chưa có lịch trực được phân công.
                            </div>

                            <!-- LIST MOBILE -->
                            <div id="myDutyMobileList" class="space-y-4">
                                <?php foreach ($days as $day): ?>
                                    <?php $dayEnum = $day['label']; // T2..T6 ?>
                                    <div class="border rounded-xl p-4 bg-white">
                                        <div class="mb-3 font-semibold text-purple-600">
                                            <?= $day['label'] ?> – <?= $day['date'] ?>
                                        </div>

                                        <div class="grid grid-cols-1 gap-3">
                                            <?php foreach ($shiftRows as $row): ?>
                                                <div class="flex items-start gap-3">
                                                    <!-- Label ca -->
                                                    <div class="w-24 shrink-0 text-xs font-medium text-gray-600 pt-2">
                                                        <?= $row['label'] ?>
                                                    </div>

                                                    <!-- Cell để JS bơm dữ liệu -->
                                                    <div class="my-duty-cell user-duty-cell min-h-[56px] flex-1"
                                                        data-day="<?= $dayEnum ?>" data-shift="<?= $row['key'] ?>">
                                                        <div class="my-duty-empty text-center text-gray-400 text-xs">
                                                            Chưa phân công
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- DUTY WEEK CHOICE MODAL -->

</section>
<!-- ================= TAB SCRIPT (CHỈ CHO USER) ================= -->
<script>
    
    function getTabFromUrl() {
        const params = new URLSearchParams(window.location.search);
        return params.get('tab');
    }
    function setTabToUrl(tab) {
        const params = new URLSearchParams(window.location.search);
        params.set('tab', tab);

        const newUrl = window.location.pathname + '?' + params.toString();
        history.pushState({ tab }, '', newUrl);
    }
    document.addEventListener('DOMContentLoaded', () => {
        const tabs = document.querySelectorAll('#duty-app .tab-btn');
        const views = document.querySelectorAll('#duty-app .user-view');

        function showTab(tab, pushUrl = true) {
            views.forEach(v => {
                if (v.dataset.view === tab) {
                    v.classList.remove('is-hidden');
                } else {
                    v.classList.add('is-hidden');
                }
            });

            tabs.forEach(b => b.classList.remove('tab-active'));
            const activeBtn = document.querySelector(
                `.tab-btn[data-tab="${tab}"]`
            );
            if (activeBtn) activeBtn.classList.add('tab-active');

            if (pushUrl) setTabToUrl(tab);

            if (tab === 'register') loadAvailability?.();
            if (tab === 'study') loadStudySchedule?.();
            if (tab === 'my') loadMyDutySchedule?.();
        }



        tabs.forEach(btn => {
            btn.addEventListener('click', () => {
                showTab(btn.dataset.tab, true);
            });
        });

        const initialTab = getTabFromUrl() || 'register';
        showTab(initialTab, false);
    });
</script>
<style>
    .duty-cell {
        width: 100%;
        display: flex;
    }

    .cell-box {
        width: 100%;
        min-width: 160px;
        max-width: none;
        /* 🔥 cho dãn thật */
        height: 42px;

        border-radius: 0.75rem;
        border: 2px solid #22c55e;
        background: #fff;
        cursor: pointer;

        display: flex;
        align-items: center;
        justify-content: center;

        transition: all 0.2s ease;
    }


    /* hover */
    .cell-box:hover {
        background: #f0fdf4;
    }

    /* checked */
    .duty-checkbox:checked+.cell-box {
        background: #22c55e;
        border-color: #22c55e;
    }

    .duty-checkbox:checked+.cell-box::after {
        content: "✓";
        color: #fff;
        font-size: 1.25rem;
        font-weight: 700;
    }




    .tab-btn {
        position: relative;
        padding: 0.75rem 1.25rem;
        font-weight: 500;
        color: #6b7280;
        border-bottom: 2px solid transparent;
        transition: all 0.2s ease;
    }

    .tab-btn:hover {
        color: #2563eb;
    }

    .tab-btn.tab-active {
        color: #2563eb;
        border-bottom-color: #2563eb;
        background: #eff6ff;
    }

    .study-cell {
        width: 100%;
        display: flex;
    }


    .study-box {
        width: 100%;
        max-width: none;
        /* 🔥 CHO DÃN THẬT */
        min-width: 160px;
        /* 🔒 KHÔNG QUÁ NHỎ */
        height: 42px;

        border-radius: 0.75rem;
        border: 2px solid #fb923c;
        background: #fff;
        cursor: pointer;

        display: flex;
        align-items: center;
        justify-content: center;
    }



    .study-box:hover {
        background: #fff7ed;
    }

    .study-checkbox:checked+.study-box {
        background: #fb923c;
        border-color: #fb923c;
    }

    .study-checkbox:checked+.study-box::after {
        content: "✓";
        color: #fff;
        font-size: 1.25rem;
        font-weight: 700;
    }


    .inner-panel {
        background: #f9fafb;
        /* gray-50 */
        border: 1px solid #e5e7eb;
        /* gray-200 */
        border-radius: 1rem;
        padding: 1.5rem;
    }

    .export-hide.exporting {
        display: none !important;
    }

    .user-view {
        display: block;
    }

    .user-view.is-hidden {
        position: absolute;
        left: -99999px;
        top: 0;
        width: max-content;
        opacity: 0;
        pointer-events: none;
    }

    /* ===== USER DUTY TABLE LOOK LIKE ADMIN ===== */
    .user-duty-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 25px rgba(0, 0, 0, .06);
    }

    .user-duty-table {
        width: 100%;
        border-collapse: collapse;
    }

    .user-duty-table th,
    .user-duty-table td {
        border: 1px solid #e5e7eb;
    }

    .user-duty-table thead th {
        background: #ffffff;
        position: sticky;
        top: 0;
        z-index: 5;
        box-shadow: 0 1px 0 rgba(0, 0, 0, 0.06);
    }

    .user-shift-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 14px;
        border-radius: 14px;
        font-weight: 800;
        border: 1px solid;
        white-space: nowrap;
        /* ✅ không bị rớt dòng */
    }

    .user-badge-sang {
        color: #1d4ed8;
        background: #eff6ff;
        border-color: #bfdbfe;
    }

    .user-badge-chieu {
        color: #6d28d9;
        background: #f5f3ff;
        border-color: #ddd6fe;
    }

    .user-badge-rc {
        color: #c2410c;
        background: #fff7ed;
        border-color: #fed7aa;
    }

    /* ô lịch trực */
    .user-duty-cell {
        min-height: 92px;
        border: 1px solid #e5e7eb;
        background: #f9fafb;
        border-radius: 14px;
        padding: 10px;

        display: flex;
        flex-direction: column;
        gap: 8px;

        justify-content: center;
        align-items: center;
        /* ✅ căn giữa ngang */

        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .8);
    }
</style>
<script>
    window.CURRENT_USER_ID = <?= (int) $_SESSION['user_id'] ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>



<script>
    (function () {
        const exportEls = document.querySelectorAll(".export-hide");
        const btnImg = document.getElementById("btnUserExportImg");
        const btnPdf = document.getElementById("btnUserExportPdf");

        function hideExportButtons() {
            document.body.classList.add("exporting");
            exportEls.forEach(el => el.classList.add("exporting"));
        }
        function showExportButtons() {
            document.body.classList.remove("exporting");
            exportEls.forEach(el => el.classList.remove("exporting"));
        }

        async function switchToMyTabIfNeeded() {
            const myView = document.querySelector('.user-view[data-view="my"]');
            if (!myView) return;

            if (myView.classList.contains("is-hidden")) {
                document.querySelector('.tab-btn[data-tab="my"]')?.click();
                await new Promise(r => setTimeout(r, 250));
            }

            // đợi render thêm nhịp
            await new Promise(r => setTimeout(r, 200));
        }

        function waitForGridReady(timeoutMs = 2500) {
            return new Promise((resolve) => {
                const start = Date.now();
                const tick = () => {
                    const grid = document.getElementById("myDutyGridWrap");
                    const empty = document.getElementById("myDutyEmpty");

                    // nếu empty đang hiện -> chưa có lịch
                    if (empty && getComputedStyle(empty).display !== "none") {
                        resolve({ ok: false, reason: "empty" });
                        return;
                    }

                    if (grid) {
                        const rect = grid.getBoundingClientRect();
                        if (rect.width > 10 && rect.height > 10) {
                            resolve({ ok: true, grid });
                            return;
                        }
                    }

                    if (Date.now() - start > timeoutMs) {
                        resolve({ ok: false, reason: "timeout" });
                        return;
                    }
                    requestAnimationFrame(tick);
                };
                tick();
            });
        }

        function makeStageClone(node) {
            const stage = document.createElement("div");
            stage.style.position = "fixed";
            stage.style.left = "0";
            stage.style.top = "0";
            stage.style.opacity = "0";
            stage.style.pointerEvents = "none";
            stage.style.zIndex = "-1";
            stage.style.background = "#ffffff";

            // wrapper để tránh bị cắt box-shadow / padding đẹp hơn
            const wrap = document.createElement("div");
            wrap.style.background = "#ffffff";
            wrap.style.padding = "16px";
            wrap.style.display = "inline-block";

            const clone = node.cloneNode(true);
            clone.style.display = "block";
            clone.style.visibility = "visible";

            wrap.appendChild(clone);
            stage.appendChild(wrap);
            document.body.appendChild(stage);

            return { stage, clone };
        }

        async function captureDataUrl() {
            await switchToMyTabIfNeeded();

            const ready = await waitForGridReady(3000);
            if (!ready.ok) {
                if (ready.reason === "empty") {
                    alert("Chưa có lịch trực được phân công nên không thể export.");
                    return null;
                }
                alert("Không thể export vì bảng lịch chưa render xong.");
                return null;
            }

            const exportTarget = ready.grid;

            // clone ra ngoài body để không dính overflow/display none
            const { stage, clone } = makeStageClone(exportTarget);

            try {
                hideExportButtons();

                await new Promise(r => setTimeout(r, 120));
                if (document.fonts?.ready) await document.fonts.ready;

                const dataUrl = await htmlToImage.toPng(clone, {
                    pixelRatio: 2,
                    backgroundColor: "#ffffff",
                    cacheBust: true,
                    skipFonts: true,
                    filter: (node) => {
                        if (node?.classList?.contains("export-hide")) return false;
                        return true;
                    }
                });

                return dataUrl;
            } catch (e) {
                console.error(e);
                alert("Export lỗi. Mở console coi log.");
                return null;
            } finally {
                showExportButtons();
                stage.remove();
            }
        }

        function downloadDataUrl(dataUrl, filename) {
            const a = document.createElement("a");
            a.href = dataUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }

        // ===== EXPORT PNG =====
        btnImg?.addEventListener("click", async () => {
            const dataUrl = await captureDataUrl();
            if (!dataUrl) return;
            downloadDataUrl(dataUrl, "lich-truc-cua-toi.png");
        });

        // ===== EXPORT PDF =====
        btnPdf?.addEventListener("click", async () => {
            const dataUrl = await captureDataUrl();
            if (!dataUrl) return;

            const { jsPDF } = window.jspdf;

            const img = new Image();
            img.src = dataUrl;

            img.onload = () => {
                const pdf = new jsPDF({
                    orientation: img.width >= img.height ? "landscape" : "portrait",
                    unit: "px",
                    format: [img.width, img.height],
                });

                pdf.addImage(dataUrl, "PNG", 0, 0, img.width, img.height);
                pdf.save("lich-truc-cua-toi.pdf");
            };
        });

    })();
</script>