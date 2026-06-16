<section class="p-6">
  <div class="w-full">

    <!-- ================= HEADER ================= -->
    <header class="mb-6">
      <div class="flex items-center gap-4 mb-2">
        <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
          <svg class="w-7 h-7 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
        </div>
        <div>
          <h1 class="text-2xl font-bold text-gray-800">
            QUẢN LÝ TRANG THIẾT BỊ & ĐỒ DÙNG
          </h1>
          <p class="text-sm text-gray-500">
            Quản lý, mượn – trả và theo dõi lịch sử trang thiết bị, đồ dùng Đoàn – Hội
          </p>
        </div>
      </div>
    </header>

    <!-- ================= STAT CARDS ================= -->
    <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
      <div class="bg-white p-4 rounded-lg border shadow-sm">
        <p id="stat-total" class="text-2xl font-bold text-gray-800">0</p>
        <p class="text-xs text-gray-500">Tổng số</p>
      </div>
      <div class="bg-white p-4 rounded-lg border shadow-sm">
        <p id="stat-using" class="text-2xl font-bold text-green-600">0</p>
        <p class="text-xs text-gray-500">Đang dùng</p>
      </div>
      <div class="bg-white p-4 rounded-lg border shadow-sm">
        <p id="stat-borrowing" class="text-2xl font-bold text-amber-600">0</p>
        <p class="text-xs text-gray-500">Đang mượn</p>
      </div>
      <div class="bg-white p-4 rounded-lg border shadow-sm">
        <p id="stat-stock" class="text-2xl font-bold text-gray-600">0</p>
        <p class="text-xs text-gray-500">Tồn kho</p>
      </div>
      <div class="bg-white p-4 rounded-lg border shadow-sm">
        <p id="stat-broken" class="text-2xl font-bold text-red-600">0</p>
        <p class="text-xs text-gray-500">Hỏng/Bảo trì</p>
      </div>
      <div class="bg-white p-4 rounded-lg border shadow-sm">
        <p id="stat-month" class="text-2xl font-bold text-purple-600">0</p>
        <p class="text-xs text-gray-500">Mượn/tháng</p>
      </div>
    </section>



    <!-- ================= TABS ================= -->
    <div class="bg-white border rounded-t-lg">
      <div class="flex border-b">
        <button id="tab-inventory" class="tab-btn px-6 py-3 text-sm font-medium">
          Danh sách thiết bị & đồ dùng
        </button>

        <button id="tab-history" class="tab-btn px-6 py-3 text-sm font-medium">
          Lịch sử mượn – trả
        </button>

        <button id="tab-category" class="tab-btn px-6 py-3 text-sm font-medium">
          Danh mục
        </button>


      </div>
    </div>

    <!-- ================= INVENTORY TABLE ================= -->
    <section id="inventory-section" class="bg-white rounded-b-lg shadow-sm border border-gray-100 mb-6">

      <!-- FILTER (sticky, KHÔNG nằm trong overflow) -->
      <div class="sticky top-0 z-30 bg-gray-50 border-b">
        <div class="p-4">
          <div class="flex flex-col lg:flex-row gap-3">

            <input id="search-input" type="text" placeholder="Tìm theo tên / mã thiết bị / đồ dùng..."
              class="flex-1 px-4 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />

            <select id="filter-type" class="px-3 py-2 border rounded-lg text-sm"></select>

            <select id="filter-category" class="px-3 py-2 border rounded-lg text-sm"></select>

            <button id="btn-export-inventory"
              class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              Xuất Excel
            </button>

            <button onclick="openAddInventory()"
              class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">
              + Thêm mới
            </button>

          </div>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[1400px]">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">STT</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Mã</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Tên</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Loại</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Danh mục</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase ">Ghi chú</th>
              <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Tổng SL</th>
              <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Đang mượn</th>
              <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Trạng thái</th>
              <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase sticky right-0 bg-gray-50">
                Thao tác
              </th>
            </tr>
          </thead>
          <tbody id="inventory-tbody"></tbody>
        </table>
      </div>

      <!-- PAGINATION -->
      <div class="px-4 py-3 border-t flex flex-col sm:flex-row
            sm:justify-between sm:items-center gap-3">

        <!-- INFO -->
        <p class="text-sm text-gray-500">
          Hiển thị
          <span id="page-from">0</span> –
          <span id="page-to">0</span> /
          <span id="page-total">0</span> bản ghi
        </p>

        <!-- CONTROLS -->
        <div class="flex items-center gap-1" id="pagination">
          <!-- JS render -->
        </div>
      </div>

    </section>

    <section id="history-section" class="bg-white rounded-b-lg shadow-sm border border-gray-100 mb-6 hidden">

      <!-- FILTER TAB 2 (STICKY) -->
      <div class="sticky top-0 z-30 bg-gray-50 border-b">
        <div class="p-4">
          <div class="flex flex-col lg:flex-row gap-3">

            <input id="history-search" type="text" placeholder="Tìm theo mã / tên / người mượn..."
              class="flex-1 px-4 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />

            <select id="history-status" class="px-3 py-2 border rounded-lg text-sm">
              <option value="">Tất cả trạng thái</option>
              <option value="borrowing">Chưa trả</option>
              <option value="returned">Đã trả</option>
              <option value="overdue">Quá hạn</option>
            </select>

            <button id="btn-export-history"
              class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              Xuất Excel
            </button>

          </div>
        </div>
      </div>

      <!-- TABLE SCROLL -->
      <div class="overflow-x-auto">
        <table class="w-full min-w-[1700px]">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">STT</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Mã</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Tên</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Người mượn</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Lớp</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase text-center">Uy tín</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase text-center">SL</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase text-center">Ngày mượn</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase text-center">Hạn trả</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase text-center">Ngày trả</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase text-center">Trạng thái</th>
              <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase
         sticky top-0 right-0 z-40 bg-gray-50 border-l">
                Thao tác
              </th>
            </tr>
          </thead>

          <tbody id="history-tbody" class="divide-y divide-gray-100">
            <!-- HISTORY DATA -->
          </tbody>
        </table>
      </div>

      <!-- PAGINATION HISTORY -->
      <div class="px-4 py-3 border-t flex flex-col sm:flex-row
            sm:justify-between sm:items-center gap-3">

        <!-- INFO -->
        <p class="text-sm text-gray-500">
          Hiển thị
          <span id="history-page-from">0</span> –
          <span id="history-page-to">0</span> /
          <span id="history-page-total">0</span> bản ghi
        </p>

        <!-- CONTROLS -->
        <div class="flex items-center gap-1" id="pagination-history">
          <!-- JS render -->
        </div>
      </div>

    </section>


    <section id="category-section" class="bg-white rounded-b-lg shadow-sm border hidden p-4">
      <div class="flex justify-between items-center mb-3">
        <h3 class="font-semibold text-gray-800">Danh mục thiết bị</h3>
        <div class="flex gap-2">
          <button id="btn-export-category"
            class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm flex items-center gap-1.5 font-medium">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Xuất Excel
          </button>
          <button onclick="openAddCategory()" class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium">
            + Thêm danh mục
          </button>
        </div>
      </div>

      <table class="w-full text-sm">
        <tbody id="category-tbody"></tbody>
      </table>
    </section>
  </div>

</section>

<script src="<?= BASE_URL ?>assets/js/inventory.js?v=<?= time() ?>"></script>