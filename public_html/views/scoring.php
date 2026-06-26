<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

auth_guard();
?>
<style>
    main {
        overflow: visible !important;
    }
</style>
<div class="p-6 max-w-7xl mx-auto">
    <!-- Header Title -->
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-1.5 h-8 bg-indigo-600 rounded-full"></div>
            <h2 class="text-2xl font-bold text-gray-800">Tính Điểm Thi Đua Học Kỳ</h2>
        </div>
    </div>

    <!-- Top: Navigation Header Horizontal (Sticky) -->
    <div id="stickyNavHeader" class="sticky top-4 z-40 bg-white/95 backdrop-blur-sm rounded-xl shadow-md border p-4 mb-6 transition-all duration-300">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider px-1">Các bước thực hiện</span>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 w-full lg:w-auto">
                <a href="#section-filters" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm font-semibold text-indigo-700 bg-indigo-50 border border-indigo-600 transition nav-step-link" data-step="1">
                    <span class="w-5 h-5 flex items-center justify-center rounded-full bg-indigo-600 text-white text-xs">1</span>
                    Chọn bộ lọc & Kỳ học
                </a>
                <a href="#section-config" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 border border-transparent transition nav-step-link" data-step="2">
                    <span class="w-5 h-5 flex items-center justify-center rounded-full bg-gray-200 text-gray-700 text-xs">2</span>
                    ⚙️ Cấu hình tính điểm
                </a>
                <a href="#section-preview" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 border border-transparent transition nav-step-link" data-step="3">
                    <span class="w-5 h-5 flex items-center justify-center rounded-full bg-gray-200 text-gray-700 text-xs">3</span>
                    📊 Xem trước & Tổng hợp
                </a>
                <a href="#section-saved" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 border border-transparent transition nav-step-link" data-step="4">
                    <span class="w-5 h-5 flex items-center justify-center rounded-full bg-gray-200 text-gray-700 text-xs">4</span>
                    💾 Quản lý điểm đã lưu
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content Stack (Full Width) -->
    <div class="space-y-6">
            
            <!-- SECTION 1: BỘ LỌC (Luôn hiển thị trên cùng) -->
            <div id="section-filters" class="bg-white rounded-xl shadow-sm border p-5 scroll-mt-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Năm học -->
                    <div class="relative">
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Năm học</label>
                        <input id="filterYearSearch" type="text" autocomplete="off"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Nhập để tìm năm học...">
                        <select id="filterYear" class="hidden">
                            <option value="">-- Chọn năm học --</option>
                        </select>
                        <div id="filterYearDropdown"
                            class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
                            <div id="filterYearList" class="max-h-64 overflow-auto"></div>
                        </div>
                    </div>

                    <!-- Học kỳ -->
                    <div class="relative">
                        <label class="block text-sm font-semibold text-gray-600 mb-1">Học kỳ</label>
                        <input id="filterSemesterSearch" type="text" autocomplete="off"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Nhập để tìm học kỳ...">
                        <select id="filterSemester" class="hidden">
                            <option value="">-- Chọn học kỳ --</option>
                        </select>
                        <div id="filterSemesterDropdown"
                            class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
                            <div id="filterSemesterList" class="max-h-64 overflow-auto"></div>
                        </div>
                    </div>
                </div>

                <!-- Stats summary block -->
                <div class="mt-4 pt-4 border-t flex flex-col sm:flex-row items-center justify-between gap-3 bg-indigo-50/50 -mx-5 -mb-5 p-5 rounded-b-xl">
                    <div class="text-sm font-medium text-gray-700">
                        Phân bổ điểm hiện tại: 
                        <span id="sumPoint" class="font-bold text-indigo-700 text-lg">0.00</span><span class="text-gray-500 text-sm">/10.00</span>
                        <span class="mx-3 text-gray-300">|</span>
                        Còn lại: <span id="remainPoint" class="font-bold text-emerald-600 text-lg">10.00</span>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: CẤU HÌNH TÍNH ĐIỂM (Collapsible) -->
            <div id="section-config" class="bg-white rounded-xl shadow-sm border overflow-hidden scroll-mt-6">
                <!-- Card Header -->
                <div class="px-5 py-4 border-b flex items-center justify-between bg-gray-50 cursor-pointer section-toggle-btn" data-target="config-content">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">⚙️</span>
                        <h3 class="text-lg font-bold text-gray-800">Cấu hình tính điểm</h3>
                        <span id="configItemCountBadge" class="hidden px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800">0 mục</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-gray-400 font-normal text-sm toggle-indicator">▼ Thu gọn</span>
                    </div>
                </div>
                <!-- Card Content -->
                <div id="config-content" class="p-5 space-y-4">
                    <div id="scoringStatus" class="text-gray-500 text-center py-4">Hãy chọn Năm học và Học kỳ để bắt đầu cấu hình.</div>

                    <!-- Config Actions Row -->
                    <div id="configActionsRow" class="hidden flex flex-col sm:flex-row items-center justify-between gap-3 border-b pb-4">
                        <span class="text-sm font-semibold text-gray-700">Mục tính điểm thi đua:</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <button id="btnOpenAddConfigItemModal" class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-semibold transition-all shadow-sm flex items-center gap-1.5">
                                ➕ Thêm mục phong trào / khoản thu
                            </button>
                            <button id="btnAuto" class="px-3.5 py-2 border border-indigo-600 text-indigo-700 rounded-lg text-sm font-semibold hover:bg-indigo-50 transition-all flex items-center gap-1.5">
                                ⚖️ Chia đều phần còn lại
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto hidden border rounded-xl" id="scoringTableWrap">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600 border-b">
                                <tr>
                                    <th class="px-4 py-3 text-left w-[18%] font-semibold">Loại</th>
                                    <th class="px-4 py-3 text-left font-semibold">Tên phong trào / khoản thu</th>
                                    <th class="px-4 py-3 text-center w-[20%] font-semibold">Điểm tối đa</th>
                                    <th class="px-4 py-3 text-center w-[12%] font-semibold">Khóa</th>
                                    <th class="px-4 py-3 text-center w-[12%] font-semibold">Tác vụ</th>
                                </tr>
                            </thead>
                            <tbody id="scoringBody" class="divide-y text-gray-700"></tbody>
                        </table>
                    </div>

                    <div id="scoringError" class="hidden text-sm text-rose-600 font-semibold text-center bg-rose-5 p-3 rounded-lg border border-rose-200"></div>

                    <div id="configConfirmWrap" class="hidden flex flex-col items-center justify-center pt-4 border-t gap-2">
                        <div id="configConfirmStatus" class="text-sm text-amber-600 font-medium">Vui lòng xác nhận cấu hình điểm để xem trước kết quả.</div>
                        <button id="btnConfirmScoringConfig" disabled class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold transition-all shadow-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                            ✅ Xác nhận cấu hình điểm
                        </button>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: XEM TRƯỚC VÀ TỔNG HỢP (Collapsible) -->
            <div id="section-preview" class="bg-white rounded-xl shadow-sm border overflow-hidden scroll-mt-6">
                <!-- Card Header -->
                <div class="px-5 py-4 border-b flex items-center justify-between bg-gray-50 cursor-pointer section-toggle-btn" data-target="preview-content">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">📊</span>
                        <h3 class="text-lg font-bold text-gray-800">Xem trước & Tổng hợp kết quả các lớp</h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-gray-400 font-normal text-sm toggle-indicator">▼ Thu gọn</span>
                    </div>
                </div>
                <!-- Card Content -->
                <div id="preview-content" class="p-5 space-y-4">
                    <div id="previewFiltersRow" class="hidden flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b pb-4">
                        <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                            <input id="previewSearchClass" type="text" placeholder="Tìm tên lớp, GVCN..."
                                class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                            
                            <select id="previewFilterDept" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">-- Tất cả Khoa --</option>
                            </select>

                            <div class="flex items-center border border-gray-200 rounded-lg bg-gray-100 p-0.5 shadow-sm">
                                <button id="btnViewModeSummary" type="button" 
                                    class="px-3 py-1.5 text-xs font-bold rounded-md bg-white text-indigo-700 shadow-sm transition">
                                    Tổng quan
                                </button>
                                <button id="btnViewModeDetail" type="button" 
                                    class="px-3 py-1.5 text-xs font-semibold rounded-md text-gray-600 hover:text-gray-800 transition">
                                    Chi tiết
                                </button>
                            </div>
                            
                            <button id="btnReloadPreview" class="p-1.5 border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 transition shadow-sm" title="Tải lại">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89M9 11l3 3L22 4" />
                                </svg>
                            </button>
                        </div>
                        
                        <!-- Actions block -->
                        <div class="flex items-center gap-2">
                            <button id="btnExport" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold transition-all shadow-sm flex items-center gap-1.5">
                                📥 Xuất Excel
                            </button>
                            <?php if (is_admin() || can('scoring', 'update')): ?>
                            <button id="btnSaveSemester" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-semibold transition-all shadow-sm flex items-center gap-1.5">
                                💾 Lưu điểm học kỳ
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="previewEmptyMessage" class="text-gray-500 text-center py-4">Chọn Năm học và Học kỳ để cấu hình điểm.</div>

                    <div class="overflow-x-auto border rounded-xl hidden" id="previewTableWrap">
                        <table class="min-w-full text-sm text-left border-collapse">
                            <thead class="bg-gray-50 text-gray-600 font-semibold border-b">
                                <tr id="previewTableHeader"></tr>
                            </thead>
                            <tbody id="previewTableBody" class="divide-y text-gray-700"></tbody>
                        </table>
                    </div>

                    <!-- PREVIEW PAGINATION -->
                    <div id="previewPagination" class="hidden flex flex-col sm:flex-row items-center justify-between gap-4 mt-4 pt-4 border-t text-sm text-gray-500"></div>
                </div>
            </div>

            <!-- SECTION 4: QUẢN LÝ ĐIỂM TÍCH LŨY (Collapsible) -->
            <div id="section-saved" class="bg-white rounded-xl shadow-sm border overflow-hidden scroll-mt-6">
                <!-- Card Header -->
                <div class="px-5 py-4 border-b flex items-center justify-between bg-gray-50 cursor-pointer section-toggle-btn" data-target="saved-content">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">💾</span>
                        <h3 class="text-lg font-bold text-gray-800">Quản lý điểm tích lũy học kỳ</h3>
                    </div>
                    <span class="text-gray-400 font-normal text-sm toggle-indicator">▼ Thu gọn</span>
                </div>
                <!-- Card Content -->
                <div id="saved-content" class="p-5 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-2 items-end">
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">Kỳ điểm đã lưu</label>
                            <select id="savedSemesterSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">-- Chọn kỳ điểm --</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">Tìm lớp</label>
                            <input id="savedSearchClass" type="text" placeholder="Tìm tên lớp, GVCN..."
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-600 mb-1">Khoa</label>
                            <select id="savedFilterDept" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">-- Tất cả Khoa --</option>
                            </select>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button id="btnReloadSaved" class="px-3 py-2 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50 transition text-sm flex items-center gap-1.5 shadow-sm" title="Tải lại">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89M9 11l3 3L22 4" />
                                </svg>
                                Tải lại
                            </button>
                            <?php if (is_admin() || can('scoring', 'delete')): ?>
                            <button id="btnDeleteSavedSemester" class="px-3 py-2 bg-rose-600 hover:bg-rose-700 text-white font-semibold rounded-lg text-sm transition flex items-center gap-1.5 shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                                Xóa kỳ này
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="savedEmptyMessage" class="text-gray-500 text-center py-4">Vui lòng chọn kỳ điểm đã lưu.</div>

                    <div class="overflow-x-auto border rounded-xl hidden" id="savedTableWrap">
                        <table class="min-w-full text-sm text-left border-collapse">
                            <thead class="bg-gray-50 text-gray-600 font-semibold border-b">
                                <tr>
                                    <th class="px-4 py-3 text-center w-12 font-bold">STT</th>
                                    <th class="px-4 py-3 text-left w-48 font-bold">Khoa</th>
                                    <th class="px-4 py-3 text-left w-44 font-bold">Lớp</th>
                                    <th class="px-4 py-3 text-left w-48 font-bold">GVCN</th>
                                    <th class="px-4 py-3 text-center w-20 font-bold">Sĩ số</th>
                                    <th class="px-4 py-3 text-center w-24 font-bold">Tổng điểm</th>
                                    <th class="px-4 py-3 text-center w-36 font-bold">Tỉ lệ đạt</th>
                                    <th class="px-4 py-3 text-left max-w-[200px] font-bold">Ghi chú</th>
                                    <th class="px-4 py-3 text-center w-32 font-bold">Tác vụ</th>
                                </tr>
                            </thead>
                            <tbody id="savedTableBody" class="divide-y text-gray-700"></tbody>
                        </table>
                    </div>

                    <!-- SAVED PAGINATION -->
                    <div id="savedPagination" class="hidden flex flex-col sm:flex-row items-center justify-between gap-4 mt-4 pt-4 border-t text-sm text-gray-500"></div>
                </div>
            </div>

    </div>
</div>

<!-- ========================================== MODALS ========================================== -->

<!-- MODAL 1: THÊM MỤC TÍNH ĐIỂM (CẤU HÌNH) -->
<div id="addConfigItemModal" class="fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full flex flex-col mx-4 overflow-hidden border">
        <div class="px-6 py-4 bg-gray-50 border-b flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">➕ Thêm mục tính điểm</h3>
            <button id="btnCloseAddConfigItemModal" class="text-gray-400 hover:text-gray-600 transition focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[60vh] space-y-4">
            <div id="addConfigItemModalStatus" class="text-sm text-gray-500"></div>
            
            <!-- Tab selector inside modal -->
            <div class="flex border-b border-gray-200" id="addConfigItemModalTabs">
                <button type="button" class="border-indigo-600 text-indigo-600 flex-1 py-2 text-center border-b-2 font-semibold text-sm focus:outline-none" data-type="campaign">Phong trào</button>
                <button type="button" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 flex-1 py-2 text-center border-b-2 font-semibold text-sm focus:outline-none" data-type="fee">Khoản thu</button>
            </div>

            <!-- List inside modal -->
            <div class="space-y-2 max-h-60 overflow-y-auto" id="addConfigItemModalList">
                <!-- Chèn động checkboxes ở JS -->
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t flex justify-end gap-2">
            <button id="btnCancelAddConfigItem" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-100 transition shadow-sm">Hủy</button>
            <button id="btnConfirmAddConfigItem" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">Thêm các mục đã chọn</button>
        </div>
    </div>
</div>

<!-- MODAL 2: SỬA ĐIỂM LỚP ĐÃ LƯU (MỚI) -->
<div id="editSavedClassModal" class="fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full flex flex-col mx-4 overflow-hidden border">
        <!-- Header -->
        <div class="px-6 py-4 bg-gray-50 border-b flex items-center justify-between">
            <div>
                <h3 id="editSavedClassTitle" class="text-lg font-bold text-gray-800">✏️ Sửa điểm thi đua lớp</h3>
                <p id="editSavedClassSubtitle" class="text-xs text-gray-500 mt-0.5"></p>
            </div>
            <button id="btnCloseEditSavedClassModal" class="text-gray-400 hover:text-gray-600 transition focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="p-6 overflow-y-auto max-h-[60vh] space-y-4">
            <div id="editSavedClassLoading" class="text-center py-6 text-gray-500">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mb-2"></div>
                <div>Đang tải dữ liệu lớp học...</div>
            </div>

            <div id="editSavedClassContent" class="hidden space-y-4">
                <div class="overflow-x-auto border rounded-xl">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 border-b">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold">Loại</th>
                                <th class="px-4 py-2.5 text-left font-semibold">Mục phong trào / khoản thu</th>
                                <th class="px-4 py-2.5 text-center font-semibold w-28">Điểm đạt</th>
                                <th class="px-4 py-2.5 text-center font-semibold w-32">Chi tiết đóng/tham gia</th>
                            </tr>
                        </thead>
                        <tbody id="editSavedClassBody" class="divide-y text-gray-700"></tbody>
                    </table>
                </div>

                <!-- Total Score Summary in Modal -->
                <div class="flex items-center justify-between bg-indigo-50/50 p-4 rounded-xl border border-indigo-100">
                    <span class="text-sm font-semibold text-gray-700">Tổng điểm thi đua mới:</span>
                    <span class="text-lg font-bold text-indigo-700"><span id="editSavedClassTotal">0.00</span> / 10.00</span>
                </div>

                <!-- Note text -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Ghi chú</label>
                    <textarea id="editSavedClassNote" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Nhập ghi chú hoặc lý do thay đổi..."></textarea>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-gray-50 border-t flex justify-end gap-2">
            <button id="btnCancelEditSavedClass" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-100 transition shadow-sm">Hủy</button>
            <button id="btnSubmitEditSavedClass" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition shadow-sm">💾 Lưu thay đổi</button>
        </div>
    </div>
</div>

<!-- MODAL 3: XEM CHI TIẾT ĐIỂM SỐ LỚP (GIỮ LẠI TỪ BẢN CŨ) -->
<div id="classDetailModal" class="fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-xl shadow-xl max-w-5xl w-full max-h-[85vh] flex flex-col mx-4 overflow-hidden border">
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
                            <tr id="detailTableHeader"></tr>
                        </thead>
                        <tbody id="detailTableBody" class="divide-y text-gray-700"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-gray-50 border-t flex justify-end">
            <button onclick="closeClassDetail()" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-300 transition shadow-sm">
                Đóng
            </button>
        </div>
    </div>
</div>

<script>
    window.SCORING_BASE_API = "controllers/scoring.php";
</script>

<script src="<?= BASE_URL ?>assets/js/scoring.js?v=<?= time() ?>"></script>