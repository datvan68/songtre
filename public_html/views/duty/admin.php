<section>

    <div data-view="admin">

        <div class="w-full p-6">

            <!-- CARD CHÍNH -->

            <div class="w-full">
                <div class="">
                    <!-- ================= HEADER ================= -->
                    <header class="mb-6">
                        <div class="flex items-center justify-between gap-4">
                            <!-- LEFT: TITLE -->
                            <div class="flex items-center gap-3">
                                <div>
                                    <h1 class="text-2xl font-bold text-gray-800">
                                        QUẢN LÝ LỊCH TRỰC BAN CHẤP HÀNH
                                    </h1>
                                    <p class="text-sm text-gray-500 mt-1">
                                        Quản lý, xếp lịch trực theo tuần
                                    </p>
                                </div>
                                <a href="index.php?p=duty&view=user" class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-600 text-xs font-semibold rounded-xl border border-blue-200 shadow-sm transition-all duration-200">
                                    Giao diện cá nhân
                                </a>
                            </div>

                        </div>
                    </header>


                    <!-- ================= TABS ================= -->
                    <div class="mb-6 border-b flex items-center justify-between">
                        <nav class="flex gap-6 text-sm font-medium">
                            <button class="duty-admin-tab text-blue-600 border-b-2 border-blue-600 pb-2"
                                data-admin-tab="overview">
                                Tổng quan
                            </button>

                            <button class="duty-admin-tab text-gray-600 hover:text-blue-600 pb-2"
                                data-admin-tab="assign">
                                Xếp lịch
                            </button>
                        </nav>
                    </div>


                    <!-- ================= TAB CONTENT ================= -->

                    <!-- ===== TAB: OVERVIEW ===== -->
                    <div data-admin-view="overview">

                        <!-- DANH SÁCH THÀNH VIÊN MỚI - DẠNG BẢNG CHI TIẾT -->
                        <div class="mt-8">
                            <div class="flex items-center justify-between mb-4 gap-4">
                                <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                                    👥 Bảng quản lý chi tiết thành viên
                                    <span id="memberCount" class="text-sm font-normal text-gray-500">(-- người)</span>
                                </h3>

                                <!-- BỘ CHỌN TUẦN TƯƠNG TÁC -->
                                <div class="flex items-center gap-2 select-none shrink-0">
                                    <button type="button" id="btnHeaderWeekPrev" class="px-3 py-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-xs font-semibold rounded-xl shadow-sm transition">
                                        ◀ Tuần trước
                                    </button>
                                    <button type="button" id="btnHeaderWeekThis" class="px-3 py-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-xs font-semibold rounded-xl shadow-sm transition">
                                        Tuần này
                                    </button>
                                    <button type="button" id="btnHeaderWeekNext" class="px-3 py-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 text-xs font-semibold rounded-xl shadow-sm transition">
                                        Tuần sau ▶
                                    </button>
                                    <div id="dutyWeekRange" class="shrink-0 text-xs sm:text-sm px-4 py-1.5 rounded-xl bg-orange-100 text-orange-700 font-bold border border-orange-200 shadow-sm min-w-[140px] text-center" data-week-start="" data-week-end="">
                                        --/-- - --/--
                                    </div>
                                </div>
                            </div>

                            <!-- BỘ LỌC THÀNH VIÊN -->
                            <div class="mb-4 grid grid-cols-1 md:grid-cols-3 gap-3 p-4 bg-gray-50 rounded-2xl border border-gray-200">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Tìm kiếm</label>
                                    <input type="text" id="filterMemberSearch" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white" placeholder="Tìm tên / username...">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Trạng thái rảnh</label>
                                    <select id="filterMemberStatus" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                                        <option value="all">Tất cả thành viên</option>
                                        <option value="has_free">Có đăng ký rảnh (> 0 buổi)</option>
                                        <option value="no_free">Chưa đăng ký rảnh (0 buổi)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Thời gian rảnh cụ thể</label>
                                    <button type="button" id="btnFilterShiftModal" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm bg-white hover:bg-gray-50 flex items-center justify-between text-gray-700 transition">
                                        <span id="filterShiftLabel" class="text-gray-500">Chọn ca rảnh...</span>
                                        <span class="text-xs text-gray-400">📅</span>
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto bg-white rounded-2xl border border-gray-200 shadow-sm">
                                <table class="w-full text-sm border-collapse text-left">
                                    <thead class="bg-gray-50 border-b border-gray-200">
                                        <tr class="text-gray-700 font-semibold">
                                            <th class="px-6 py-4 w-12 text-center">
                                                <input type="checkbox" id="selectAllMemberTable" class="w-4 h-4 accent-blue-600 rounded cursor-pointer">
                                            </th>
                                            <th class="px-6 py-4">Thành viên</th>
                                            <th class="px-6 py-4 text-center">Số buổi rảnh</th>
                                            <th class="px-6 py-4">Lịch trực đã xếp</th>
                                            <th class="px-6 py-4 text-center">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dutyMemberListTable" class="divide-y divide-gray-200">
                                        <!-- JS sẽ render hàng thành viên ở đây -->
                                    </tbody>
                                </table>

                                <!-- PHÂN TRANG CHO BẢNG THÀNH VIÊN -->
                                <div class="px-6 py-4 flex flex-col sm:flex-row items-center justify-between border-t border-gray-200 bg-gray-50 gap-3" id="dutyMemberPagination">
                                    <div class="flex items-center gap-4">
                                        <div class="text-xs text-gray-500 font-medium">
                                            Hiển thị <span id="pagStart" class="font-bold text-gray-700">0</span>-<span id="pagEnd" class="font-bold text-gray-700">0</span> của <span id="pagTotal" class="font-bold text-gray-700">0</span>
                                        </div>
                                        <div class="flex items-center gap-1.5 text-xs text-gray-500 font-medium border-l pl-4">
                                            <span>Mỗi trang:</span>
                                            <select id="pagLimit" class="px-2 py-1 text-xs border border-gray-200 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-blue-500 font-bold text-gray-700 cursor-pointer">
                                                <option value="10">10</option>
                                                <option value="20" selected>20</option>
                                                <option value="50">50</option>
                                                <option value="100">100</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" id="btnPagPrev" class="px-3 py-1 text-xs border rounded-lg bg-white hover:bg-gray-50 font-semibold disabled:opacity-50 transition">Trước</button>
                                        <div id="pagPages" class="flex gap-1">
                                            <!-- Các nút số trang hiển thị tại đây -->
                                        </div>
                                        <button type="button" id="btnPagNext" class="px-3 py-1 text-xs border rounded-lg bg-white hover:bg-gray-50 font-semibold disabled:opacity-50 transition">Sau</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- THỐNG KÊ (DỜI XUỐNG DƯỚI BẢNG) -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                            <div class="p-4 rounded-xl bg-blue-50 border border-blue-100 shadow-sm">
                                <p class="text-sm text-gray-500">Tổng BCH</p>
                                <p class="text-2xl font-bold text-blue-700 mt-1" id="statTotal">
                                    -- người
                                </p>
                            </div>

                            <div class="p-4 rounded-xl bg-green-50 border border-green-100 shadow-sm">
                                <p class="text-sm text-gray-500">Đã đăng ký lịch rảnh</p>
                                <p class="text-2xl font-bold text-green-700 mt-1" id="statRegistered">
                                    -- người
                                </p>
                            </div>

                            <div class="p-4 rounded-xl bg-amber-50 border border-amber-100 shadow-sm">
                                <p class="text-sm text-gray-500">Chưa đăng ký</p>
                                <p class="text-2xl font-bold text-amber-700 mt-1" id="statUnregistered">
                                    -- người
                                </p>
                            </div>
                        </div>

                    </div>

                    <!-- ===== TAB: ASSIGN ===== -->
                    <div data-admin-view="assign" class="hidden space-y-6">

                        <!-- CHỌN THÀNH VIÊN XẾP LỊCH -->
                        <div class="p-6 rounded-2xl border border-gray-200 bg-white shadow-sm space-y-4">
                            <div class="flex items-center justify-between border-b pb-3">
                                <h4 class="text-md font-bold text-gray-800 flex items-center gap-2">
                                    👥 Chọn thành viên tham gia trực tuần
                                    <span id="assignMemberCount" class="text-sm font-normal text-gray-500">(-- người)</span>
                                </h4>
                                <div class="flex items-center gap-2">
                                    <button type="button" id="btnSelectAllAssign" class="text-xs px-2.5 py-1.5 rounded-lg border bg-gray-50 hover:bg-gray-100 font-medium">Chọn tất cả</button>
                                    <button type="button" id="btnUnselectAllAssign" class="text-xs px-2.5 py-1.5 rounded-lg border bg-gray-50 hover:bg-gray-100 font-medium">Bỏ chọn</button>
                                </div>
                            </div>
                            
                            <div id="dutyAssignMemberList" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 max-h-[300px] overflow-y-auto p-1">
                                <!-- JS sẽ render checklist thành viên ở đây -->
                            </div>
                        </div>

                        <!-- BẢNG XEM LỊCH TRỰC VÀ ĐIỀU CHỈNH KÉO THẢ (LỒNG VÀO TAB XẾP LỊCH) -->
                        <div class="mt-8 border-t pt-6 space-y-4">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-lg font-semibold text-gray-800">Lịch trực tuần</h3>

                                    <div class="block items-center gap-2">
                                        <button id="btnWeekPrev"
                                            class="px-3 py-2 rounded-lg border bg-white text-sm hover:bg-gray-50">
                                            Tuần trước
                                        </button>

                                        <button id="btnWeekThis"
                                            class="px-3 py-2 rounded-lg border bg-white text-sm hover:bg-gray-50">
                                            Tuần này
                                        </button>

                                        <button id="btnWeekNext"
                                            class="px-3 py-2 rounded-lg border bg-white text-sm hover:bg-gray-50">
                                            Tuần sau
                                        </button>

                                        <div id="dutyWeekRangeAdmin"
                                            class="ml-2 px-3 py-2 rounded-lg border text-sm font-medium bg-white text-center">
                                            --/-- → --/--
                                        </div>
                                        <div id="dutyTrash"
                                            class="hidden fixed bottom-4 right-4 z-50 w-48 h-14 rounded-xl border-2 border-dashed border-red-500 bg-white
             flex items-center justify-center text-sm font-semibold text-red-600 shadow-sm select-none pointer-events-none">
                                            Kéo vào đây để xoá
                                        </div>
                                    </div>
                                </div>

                                <div class="flex gap-2.5 export-hide items-center">
                                    <button id="btnExportImg"
                                        class="px-5 py-2.5 rounded-xl bg-gray-200 border text-sm font-bold text-gray-700 hover:bg-gray-100 transition shadow-sm">
                                        Xuất ảnh
                                    </button>
                                    <button id="btnExportPdf"
                                        class="px-5 py-2.5 rounded-xl bg-red-600 text-white text-sm font-bold hover:bg-red-700 transition shadow-sm">
                                        Xuất PDF
                                    </button>
                                    <button id="btnGenerateWeek" class="px-5 py-2.5 rounded-xl font-bold text-white text-sm
                                            bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600
                                            hover:from-blue-700 hover:via-indigo-700 hover:to-purple-700
                                            shadow-md hover:shadow-lg
                                            active:scale-95
                                            transition-all duration-200 flex items-center gap-2">
                                        ⚡ Xếp lịch tuần này
                                    </button>
                                </div>
                            </div>

                            <!-- BANNER CHẾ ĐỘ NHÁP (MẶC ĐỊNH ẨN) -->
                            <div id="dutyDraftBanner" class="hidden p-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-900 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-sm transition-all duration-200">
                                <div class="flex items-center gap-2">
                                    <span class="text-lg">⚠️</span>
                                    <div>
                                        <div class="font-bold text-sm">Chế độ Xem trước Lịch gợi ý (Nháp)</div>
                                        <div class="text-xs text-amber-700">Mọi chỉnh sửa kéo thả/thêm/xóa hiện tại chỉ lưu trên RAM. Nhấn <b>Lưu chính thức</b> để áp dụng vào hệ thống.</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <button type="button" id="btnCancelDraft" class="px-3 py-1.5 rounded-lg border border-amber-300 bg-white hover:bg-amber-50 text-xs font-semibold text-amber-800 transition">
                                        Hủy nháp
                                    </button>
                                    <button type="button" id="btnSaveDraft" class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold shadow transition">
                                        💾 Lưu chính thức
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="duty-export-target bg-white rounded-2xl border-2 border-gray-300 shadow-sm overflow-hidden">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-[900px] w-full text-sm border-collapse">
                                            <thead class="bg-gray-50 border-b border-gray-200">
                                                <tr class="text-gray-700 font-semibold">
                                                    <th class="w-[160px] text-center px-3 py-3 border border-gray-200 bg-gray-50 font-bold">
                                                        Ca
                                                    </th>
                                                    <th class="text-center px-3 py-3 border border-gray-200 bg-gray-50" data-day="2">
                                                        <div class="font-bold">T2</div>
                                                        <div class="text-xs text-gray-500" id="date-2">--/--</div>
                                                    </th>
                                                    <th class="text-center px-3 py-3 border border-gray-200 bg-gray-50" data-day="3">
                                                        <div class="font-bold">T3</div>
                                                        <div class="text-xs text-gray-500" id="date-3">--/--</div>
                                                    </th>
                                                    <th class="text-center px-3 py-3 border border-gray-200 bg-gray-50" data-day="4">
                                                        <div class="font-bold">T4</div>
                                                        <div class="text-xs text-gray-500" id="date-4">--/--</div>
                                                    </th>
                                                    <th class="text-center px-3 py-3 border border-gray-200 bg-gray-50" data-day="5">
                                                        <div class="font-bold">T5</div>
                                                        <div class="text-xs text-gray-500" id="date-5">--/--</div>
                                                    </th>
                                                    <th class="text-center px-3 py-3 border border-gray-200 bg-gray-50" data-day="6">
                                                        <div class="font-bold">T6</div>
                                                        <div class="text-xs text-gray-500" id="date-6">--/--</div>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody id="dutyViewTable">
                                                <!-- JS render -->
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="px-4 py-3 border-t border-gray-200 bg-gray-50 flex flex-wrap gap-6 text-sm text-gray-700">
                                        <div class="flex items-center gap-2">
                                            <span class="w-3 h-3 rounded bg-green-200 border border-green-400"></span>
                                            Trực thường
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="w-3 h-3 rounded bg-orange-200 border border-orange-400"></span>
                                            Trực giờ ra chơi
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- HEADER -->
                        <div class="border-t pt-6 mt-8">
                            <h3 class="text-lg font-semibold text-gray-800">
                                📊 Thống kê thời gian rảnh
                            </h3>
                        </div>

                        <!-- GRID STATS -->
                        <div id="dutyFreeStats"
                            class="grid grid-cols-1 md:grid-cols-3 md:grid-rows-5 md:grid-flow-col gap-3">
                            <!-- CARD MẪU – JS render -->
                            <div class="p-4 rounded-xl border bg-gray-100 text-gray-400">
                                <div class="font-semibold">T2 Sáng</div>
                                <div class="text-sm mt-1">-- rảnh</div>
                            </div>

                            <div class="p-4 rounded-xl border bg-gray-100 text-gray-400">
                                <div class="font-semibold">T2 Chiều</div>
                                <div class="text-sm mt-1">-- rảnh</div>
                            </div>

                            <div class="p-4 rounded-xl border bg-gray-100 text-gray-400">
                                <div class="font-semibold">T2 Ra chơi</div>
                                <div class="text-sm mt-1">-- rảnh</div>
                            </div>
                        </div>

                        <!-- QUY TẮC XẾP LỊCH -->
                        <div class="p-4 rounded-lg border bg-amber-50 text-sm text-amber-900 space-y-1">
                            <div class="font-semibold flex items-center gap-1">
                                ⚡ Quy tắc xếp lịch
                            </div>

                            <p>• Mỗi ca cần <strong>2 người trực</strong>, mỗi ngày có <strong>2 ca (sáng &
                                    chiều)</strong></p>
                            <p>• Mỗi người trực <strong>tối đa 3 buổi / tuần</strong> (đã tính quy đổi giờ ra chơi)
                            </p>
                            <p>• <strong>2 ca ra chơi = 1 buổi trực thường</strong></p>
                            <p>• Ưu tiên người <strong>rảnh và không có lịch học</strong></p>
                            <p>• Nếu thiếu người trực, hệ thống sẽ <strong>bù bằng ca ra chơi</strong></p>
                            <p>• <strong>Không xếp ca sáng / chiều</strong> cho người đang có lịch học (chỉ có thể
                                trực
                                ra chơi)</p>
                        </div>

                    </div>

                    <div id="confirmGenerateModal"
                        class="fixed inset-0 hidden items-center justify-center bg-black/40 z-50">
                        <div class="bg-white rounded-xl p-6 w-[360px]">
                            <h3 class="font-semibold text-lg mb-2">Xác nhận xếp lịch</h3>
                            <p id="confirmGenerateText" class="text-sm text-gray-600 mb-4">
                                Bạn có chắc muốn xếp lịch trực cho tuần này không?
                            </p>

                            <div class="flex justify-end gap-2">
                                <button id="btnCancelGenerate" class="px-4 py-2 rounded-lg border">
                                    Hủy
                                </button>
                                <button id="btnConfirmGenerate" class="px-4 py-2 rounded-lg bg-blue-600 text-white">
                                    Xếp lịch
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
    </div>


</section>
<script>
    function formatDate(d) {
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        return `${day}/${month}`;
    }

    function getNextWeekRange() {
        const now = new Date();

        // Thứ 2 tuần hiện tại
        const currentMonday = new Date(now);
        const day = now.getDay(); // 0 = CN
        const diffToMonday = day === 0 ? -6 : 1 - day;
        currentMonday.setDate(now.getDate() + diffToMonday);

        // Thứ 2 tuần sau
        const nextMonday = new Date(currentMonday);
        nextMonday.setDate(currentMonday.getDate() + 7);

        // Thứ 6 tuần sau
        const nextFriday = new Date(nextMonday);
        nextFriday.setDate(nextMonday.getDate() + 4);

        return {
            start: nextMonday,
            end: nextFriday,
            label: `${formatDate(nextMonday)} - ${formatDate(nextFriday)}`
        };
    }

    document.addEventListener('DOMContentLoaded', () => {
        const weekEl = document.getElementById('dutyWeekRange');
        if (!weekEl) return;

        const week = getNextWeekRange();
        weekEl.textContent = week.label;

        // 🔥 RẤT QUAN TRỌNG: lưu để JS admin dùng gọi API
        weekEl.dataset.weekStart = week.start.toISOString().slice(0, 10);
        weekEl.dataset.weekEnd = week.end.toISOString().slice(0, 10);
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>

<script>

    (function () {
        const exportTarget = document.querySelector(".duty-export-target");
        const exportEls = document.querySelectorAll(".export-hide");

        if (!exportTarget) return;

        function hideExportButtons() {
            document.body.classList.add("exporting");
            exportEls.forEach(el => el.classList.add("hidden"));
        }

        function showExportButtons() {
            document.body.classList.remove("exporting");
            exportEls.forEach(el => el.classList.remove("hidden"));
        }

        const exportOptions = {
            pixelRatio: 2,
            backgroundColor: "#ffffff",
            cacheBust: true,
            skipFonts: true, // ✅ tránh spam cssRules
            filter: (node) => {
                if (node?.classList?.contains("export-hide")) return false;
                if (node?.classList?.contains("duty-add")) return false;
                return true;
            }
        };

        // ✅ EXPORT IMG
        document.getElementById("btnExportImg")?.addEventListener("click", async () => {
            hideExportButtons();

            try {
                await new Promise(r => setTimeout(r, 120));
                if (document.fonts?.ready) await document.fonts.ready;

                const dataUrl = await htmlToImage.toPng(exportTarget, exportOptions);

                const link = document.createElement("a");
                link.download = "lich-truc-bch.png";
                link.href = dataUrl;
                link.click();

                toast("✅ Đã xuất ảnh", "success");
            } catch (err) {
                console.error(err);
                toast("❌ Export ảnh lỗi", "error");
            } finally {
                showExportButtons();
            }
        });

        // ✅ EXPORT PDF (A4 landscape, fit gọn vào trang)
        document.getElementById("btnExportPdf")?.addEventListener("click", async () => {
            hideExportButtons();

            try {
                await new Promise(r => setTimeout(r, 120));
                if (document.fonts?.ready) await document.fonts.ready;

                const dataUrl = await htmlToImage.toPng(exportTarget, exportOptions);

                const img = new Image();
                img.src = dataUrl;

                await new Promise((resolve, reject) => {
                    img.onload = resolve;
                    img.onerror = reject;
                });

                const { jsPDF } = window.jspdf;

                // ✅ A4 landscape (in đẹp + không bị PDF khổng lồ)
                const pdf = new jsPDF({
                    orientation: "landscape",
                    unit: "mm",
                    format: "a4"
                });

                const pageW = pdf.internal.pageSize.getWidth();
                const pageH = pdf.internal.pageSize.getHeight();

                // Fit image vào A4 với margin
                const margin = 6; // mm
                const maxW = pageW - margin * 2;
                const maxH = pageH - margin * 2;

                const imgRatio = img.width / img.height;

                let drawW = maxW;
                let drawH = drawW / imgRatio;

                if (drawH > maxH) {
                    drawH = maxH;
                    drawW = drawH * imgRatio;
                }

                const x = (pageW - drawW) / 2;
                const y = (pageH - drawH) / 2;

                pdf.addImage(dataUrl, "PNG", x, y, drawW, drawH, undefined, "FAST");
                pdf.save("lich-truc-bch.pdf");

                toast("✅ Đã xuất PDF", "success");
            } catch (err) {
                console.error(err);
                toast("❌ Export PDF lỗi", "error");
            } finally {
                showExportButtons();
            }
        });
    })();

</script>


<style>
    .duty-export-target {
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.06);
    }



    body.exporting .duty-add {
        display: none !important;
    }

    .export-hide.exporting {
        display: none !important;
    }

    .duty-cell.drag-over {
        outline: 2px dashed #3b82f6;
        background: #eff6ff;
    }

    .inner-panel {
        background: #f9fafb;
        /* gray-50 */
        border: 1px solid #e5e7eb;
        /* gray-200 */
        border-radius: 1rem;
        padding: 1.5rem;
        position: relative;
        z-index: 0;
        isolation: isolate;
    }

    .duty-cell.drag-over {
        background: #eff6ff;
        border-color: #93c5fd;
        /* blue-300 */
        box-shadow: 0 6px 16px rgba(59, 130, 246, .12);
        outline: 2px dashed #3b82f6;
        outline-offset: -6px;
    }

    /* ===========================
   ✅ EXPORT FIX: CHỐNG CẮT CHỮ
=========================== */

    /* khi export: tuyệt đối không cho thằng nào crop chữ */
    body.exporting .duty-export-target,
    body.exporting .duty-export-target * {
        transform: none !important;
        transition: none !important;
        animation: none !important;
    }

    body.exporting .duty-export-target {
        overflow: visible !important;
        /* quan trọng */
    }

    body.exporting table,
    body.exporting thead,
    body.exporting tbody,
    body.exporting tr,
    body.exporting th,
    body.exporting td {
        overflow: visible !important;
        /* quan trọng */
    }

    /* duty-cell không được cắt nội dung */
    body.exporting .duty-cell {
        overflow: visible !important;
    }

    /* duty-item: render chữ kiểu block, line-height đủ lớn cho dấu tiếng Việt */
    body.exporting .duty-item {
        display: block !important;
        box-sizing: border-box !important;

        font-size: 14.5px !important;
        line-height: 1.6 !important;
        /* ✅ tăng line-height cho dấu */

        padding: 8px 10px !important;
        /* ✅ padding đều */
        margin-bottom: 12px !important;

        height: auto !important;
        min-height: 0 !important;

        overflow: visible !important;
    }

    /* nếu tên nằm trong span/truncate */
    body.exporting .duty-item span,
    body.exporting .duty-item .truncate {
        display: block !important;
        position: static !important;
        /* ✅ bỏ top:-1px */
        line-height: 1.6 !important;

        /* tuỳ chọn: muốn xuống dòng thì bật normal */
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: clip !important;
    }

    /* ✅ Không cho chữ trong badge cột Ca xuống dòng */
    .duty-export-target .ca-badge,
    .duty-export-target .duty-shift-badge {
        white-space: nowrap !important;
    }
</style>