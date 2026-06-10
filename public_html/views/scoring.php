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

    <!-- THANH TABS HỆ THỐNG -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex gap-6" aria-label="Tabs">
            <button id="tabConfigBtn" type="button" class="border-indigo-600 text-indigo-600 whitespace-nowrap py-3 px-1 border-b-2 font-semibold text-sm flex items-center gap-2 transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Thiết lập tính điểm
            </button>
            <button id="tabOverviewBtn" type="button" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-3 px-1 border-b-2 font-semibold text-sm flex items-center gap-2 transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2z" />
                </svg>
                Tổng quan & Chi tiết lớp
            </button>
        </nav>
    </div>

    <!-- NỘI DUNG TAB 1: THIẾT LẬP TÍNH ĐIỂM -->
    <div id="tabConfigContent" class="space-y-6">
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

    <!-- NỘI DUNG TAB 2: TỔNG QUAN CÁC LỚP -->
    <div id="tabOverviewContent" class="hidden space-y-6">
        <div id="scoringPreviewWrap" class="bg-white rounded-xl shadow-sm border p-5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                <div class="flex items-center gap-2">
                    <div class="w-1.5 h-6 bg-emerald-600 rounded-full"></div>
                    <h3 class="text-lg font-bold text-gray-800">📊 Xem trước điểm số thi đua các lớp</h3>
                </div>
                
                <div class="flex flex-wrap items-center gap-3">
                    <input id="previewSearchClass" type="text" placeholder="Tìm tên lớp..."
                        class="border rounded-lg px-3 py-1.5 text-sm w-40 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
                    
                    <select id="previewFilterDept" class="border rounded-lg px-3 py-1.5 text-sm w-40 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="">-- Tất cả Khoa --</option>
                    </select>

                    <div class="flex items-center border rounded-lg bg-gray-100 p-0.5 shadow-sm">
                        <button id="btnViewModeSummary" type="button" 
                            class="px-3 py-1.5 text-xs font-semibold rounded-md bg-white text-indigo-700 shadow-sm transition">
                            Tổng quan
                        </button>
                        <button id="btnViewModeDetail" type="button" 
                            class="px-3 py-1.5 text-xs font-semibold rounded-md text-gray-600 hover:text-gray-800 transition">
                            Chi tiết
                        </button>
                    </div>
                    
                    <button id="btnReloadPreview" class="p-1.5 border rounded-lg text-gray-500 hover:bg-gray-50 transition" title="Tải lại">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89M9 11l3 3L22 4" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto border rounded-xl">
                <table class="min-w-full text-sm text-left border-collapse">
                    <thead class="bg-gray-50 text-gray-600 font-semibold border-b">
                        <tr id="previewTableHeader">
                            <!-- Sẽ render động ở JS -->
                        </tr>
                    </thead>
                    <tbody id="previewTableBody" class="divide-y text-gray-700">
                        <!-- Sẽ render động ở JS -->
                    </tbody>
                </table>
            </div>

            <!-- THANH PHÂN TRANG -->
            <div id="previewPagination" class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-4 pt-4 border-t text-sm text-gray-500">
                <!-- Sẽ render động ở JS -->
            </div>
        </div>
    </div>
</div>

<!-- MODAL XEM CHI TIẾT ĐIỂM SỐ LỚP -->
<div id="classDetailModal" class="fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-xl shadow-xl max-w-5xl w-full max-h-[85vh] flex flex-col mx-4 overflow-hidden">
        <!-- Header -->
        <div class="px-6 py-4 bg-gray-50 border-b flex items-center justify-between">
            <div>
                <h3 id="classDetailTitle" class="text-lg font-bold text-gray-800">Chi tiết tham gia thi đua</h3>
                <p id="classDetailSubtitle" class="text-xs text-gray-500 mt-0.5"></p>
            </div>
            <button onclick="closeClassDetail()" class="text-gray-400 hover:text-gray-600 transition focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="p-6 overflow-auto flex-1">
            <div id="modalLoading" class="text-center py-8 text-gray-500">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mb-2"></div>
                <div>Đang tải thông tin chi tiết lớp...</div>
            </div>
            
            <div id="modalEmpty" class="text-center py-8 text-gray-500 hidden">
                Không có thành viên trong lớp này.
            </div>

            <div id="modalContent" class="hidden">
                <div class="overflow-x-auto border rounded-xl">
                    <table class="min-w-full text-sm text-left border-collapse">
                        <thead class="bg-gray-50 text-gray-600 font-semibold border-b">
                            <tr id="detailTableHeader">
                                <!-- Sẽ render động ở JS -->
                            </tr>
                        </thead>
                        <tbody id="detailTableBody" class="divide-y text-gray-700">
                            <!-- Sẽ render động ở JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-gray-50 border-t flex justify-end">
            <button onclick="closeClassDetail()" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-300 transition">
                Đóng
            </button>
        </div>
    </div>
</div>

<script>
    window.SCORING_BASE_API = "controllers/scoring.php";
</script>


<script src="<?= BASE_URL ?>assets/js/scoring.js?v=<?= time() ?>"></script>