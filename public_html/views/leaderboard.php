


<div class="flex flex-col md:flex-row">

    <!-- MAIN -->
    <main class="flex-1 bg-bg min-h-screen p-6 min-w-0">

        <!-- CONTAINER GIỐNG DASHBOARD -->
        <div class="w-full">

            <!-- HEADER -->
            <div id="lbHeader" class="mb-6 text-center md:text-left">
                <h1 class="font-heading text-4xl font-bold ">
                    Bảng Xếp Hạng
                </h1>
                <p class="text-subtext mt-1">
                    Thống kê & phân tích theo khoa và sinh viên
                </p>
            </div>

            <!-- TABS + SEARCH -->
            <div id="lbControls"
                class="border-b border-gray-200 flex flex-col md:flex-row md:items-center md:justify-between gap-3 pb-3 min-w-0">

                <!-- TABS -->
                <div class="flex gap-6 flex-wrap w-full md:w-auto">
                    <button id="tabDept" class="tab-btn inline-flex items-center gap-2">
                        <i data-lucide="school" class="w-5 h-5"></i>
                        <span>Top Khoa</span>
                    </button>

                    <button id="tabUser" class="tab-btn inline-flex items-center gap-2">
                        <i data-lucide="user" class="w-5 h-5"></i>
                        <span>Top Cá Nhân</span>
                    </button>
                </div>


                <!-- SEARCH + FILTER -->
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 min-w-0 w-full md:w-auto">

                    <input id="searchBox" type="text" placeholder="Tìm tên hoặc lớp..."
                        class="px-4 py-2 rounded-xl border border-gray-300 shadow-sm focus:ring-2 focus:ring-primary outline-none text-[15px] w-full sm:w-64" />

                    <select id="filterSelect"
                        class="px-3 py-2 rounded-xl border border-gray-300 bg-white shadow-sm text-[14px] cursor-pointer w-full sm:w-auto">
                        <option value="">Lọc danh sách</option>
                        <option value="score_desc">Điểm cao → thấp</option>
                        <option value="score_asc">Điểm thấp → cao</option>
                        <option value="top10">Top 10</option>
                        <option value="top20">Top 20</option>
                    </select>
                </div>
            </div>


            <!-- CONTENT -->
            <div id="lbContent" class="space-y-6 mt-6">
                <div class="bg-card rounded-2xl shadow-card p-6 animate-fade" id="contentDept"></div>
                <div class="bg-card rounded-2xl shadow-card p-6 animate-fade hidden" id="contentUser"></div>
            </div>

        </div>

    </main>
</div>





<style>
    .tab-btn {
        padding-bottom: 10px;
        font-weight: 500;
        font-size: 1.1rem;
        color: #6b7280;
        transition: .2s;
        letter-spacing: 0.2px;
    }

    .tab-btn:hover {
        color: #1e40af;
    }

    .tab-active {
        color: #1e40af;
        border-bottom: 3px solid #1e40af;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(6px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-fade {
        animation: fadeIn .3s ease;
    }
</style>

<script>
    window.LEADERBOARD_CAN_VIEW = <?= can('leaderboard', 'view') ? 'true' : 'false' ?>;
</script>
<!-- JS -->
<script src="<?= BASE_URL ?>assets/js/leaderboard.js?v=<?= time() ?>"></script>