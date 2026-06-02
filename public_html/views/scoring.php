<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

auth_guard();
?>
<div class="p-6">
    <div class="mb-6 flex items-center gap-3">
        <div class="w-1.5 h-8 bg-indigo-600 rounded-full"></div>
        <h2 class="text-xl font-bold text-gray-800">Tính điểm</h2>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="relative">
                <label class="block text-sm font-medium text-gray-600 mb-1">Năm học</label>

                <!-- input search -->
                <input id="filterYearSearch" type="text" autocomplete="off"
                    class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Nhập để tìm năm học...">

                <!-- select thật (ẩn) -->
                <select id="filterYear" class="hidden">
                    <option value="">-- Chọn năm học --</option>
                </select>

                <div id="filterYearDropdown"
                    class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
                    <div id="filterYearList" class="max-h-64 overflow-auto"></div>
                </div>
            </div>


            <div class="relative">
                <label class="block text-sm font-medium text-gray-600 mb-1">Học kỳ</label>

                <input id="filterSemesterSearch" type="text" autocomplete="off"
                    class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Nhập để tìm học kỳ...">

                <select id="filterSemester" class="hidden">
                    <option value="">-- Chọn học kỳ --</option>
                </select>

                <div id="filterSemesterDropdown"
                    class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
                    <div id="filterSemesterList" class="max-h-64 overflow-auto"></div>
                </div>
            </div>


            <div class="flex items-end justify-end gap-2">
                <button id="btnAuto"
                    class="px-4 py-2 rounded-lg border border-indigo-600 text-indigo-700 text-sm font-semibold hover:bg-indigo-50 transition">
                    Chia đều phần còn lại
                </button>
                <button id="btnExport"
                    class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition">
                    Xuất Excel
                </button>
            </div>
        </div>

        <div class="mt-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
            <!-- <div class="flex items-center gap-2">
                <input id="includeFees" type="checkbox" class="h-4 w-4" />
                <label for="includeFees" class="text-sm text-gray-700">
                    Tính điểm cho Khoản thu (nếu bỏ chọn: Khoản thu mặc định 0 điểm)
                </label>
            </div> -->

            <div class="text-sm">
                <span class="text-gray-600">Tổng điểm:</span>
                <span id="sumPoint" class="font-bold text-gray-900">0</span>
                <span class="text-gray-500">/ 10</span>
                <span class="mx-2 text-gray-300">|</span>
                <span class="text-gray-600">Còn lại:</span>
                <span id="remainPoint" class="font-bold text-indigo-700">0</span>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-5">
        <div id="scoringStatus" class="text-gray-500">Hãy chọn Năm học và Học kỳ.</div>

        <div class="overflow-x-auto mt-4 hidden" id="scoringTableWrap">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left w-[18%]">Loại</th>
                        <th class="px-4 py-2 text-left">Tên</th>
                        <th class="px-4 py-2 text-center w-[18%]">Điểm tối đa</th>
                        <th class="px-4 py-2 text-center w-[12%]">Khóa</th>
                    </tr>
                </thead>
                <tbody id="scoringBody"></tbody>
            </table>
        </div>

        <div id="scoringError" class="hidden mt-3 text-sm text-rose-600 font-medium"></div>
    </div>
</div>

<script>
    window.SCORING_BASE_API = "controllers/scoring.php";
</script>


<script src="<?= BASE_URL ?>assets/js/scoring.js?v=<?= time() ?>"></script>