<section id="tasks-app" data-view="admin">
  <div class="bg-white rounded-2xl shadow-sm border">

    <!-- HEADER -->
    <div class="p-6 border-b flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">QUẢN LÝ CÔNG VIỆC</h1>
        <p class="text-sm text-gray-500 mt-1">Admin giao việc và theo dõi tiến độ</p>
      </div>

      <button id="taskBtnNew" class="px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-semibold">
        Thêm công việc
      </button>
    </div>


    <!-- STATS (6 cards) -->
    <div class="p-6 border-b">
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">

        <!-- Tổng CV -->
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold text-gray-500 uppercase">TỔNG CV</div>
          <div id="statTotal" class="mt-2 text-3xl font-extrabold text-violet-600">0</div>
        </div>

        <!-- Chưa bắt đầu -->
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold text-gray-500 uppercase">CHƯA BẮT ĐẦU</div>
          <div id="statPending" class="mt-2 text-3xl font-extrabold text-gray-900">0</div>
        </div>

        <!-- Đang làm -->
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold text-gray-500 uppercase">ĐANG LÀM</div>
          <div id="statDoing" class="mt-2 text-3xl font-extrabold text-blue-600">0</div>
        </div>

        <!-- Hoàn thành -->
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold text-gray-500 uppercase">HOÀN THÀNH</div>
          <div id="statDone" class="mt-2 text-3xl font-extrabold text-emerald-600">0</div>
        </div>

        <!-- Quá hạn -->
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold text-gray-500 uppercase">QUÁ HẠN</div>
          <div id="statOverdue" class="mt-2 text-3xl font-extrabold text-red-600">0</div>
        </div>

        <!-- Hiệu suất -->
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
          <div class="text-xs font-semibold text-gray-500 uppercase">HIỆU SUẤT</div>
          <div id="statEff" class="mt-2 text-3xl font-extrabold text-emerald-600">0%</div>
        </div>

      </div>
    </div>

    <!-- FILTER -->
    <div class="p-6 flex flex-wrap gap-3 items-center border-b">
      <!-- Dự án: nhập tự do + gợi ý -->
      <div class="relative w-[220px]">
        <input id="taskFProject" class="px-3 py-2 border rounded-lg text-sm w-full" placeholder="Dự án (nhập tự do)..."
          autocomplete="off" />
        <div id="projectSuggest"
          class="absolute left-0 top-full mt-1 w-full bg-white border rounded-xl shadow-lg hidden max-h-[280px] overflow-auto z-50">
        </div>
      </div>

      <!-- Người thực hiện: typeahead -->
      <div class="relative w-[220px]">
        <input id="taskFAssignee" class="px-3 py-2 border rounded-lg text-sm w-full"
          placeholder="Người thực hiện (gõ để tìm)..." autocomplete="off" />
        <div id="assigneeSuggest"
          class="absolute left-0 top-full mt-1 w-full bg-white border rounded-xl shadow-lg hidden max-h-[280px] overflow-auto z-50">
        </div>
      </div>

      <!-- ✅ Năm học -->
      <select id="taskFSchoolYearId" class="px-3 py-2 border rounded-lg text-sm w-[180px]">
        <option value="">-- Năm học --</option>
        <!-- JS sẽ tự fill options từ META.school_years -->
      </select>

      <!-- ✅ Học kỳ -->
      <select id="taskFSemesterCode" class="px-3 py-2 border rounded-lg text-sm w-[160px]">
        <option value="">-- Học kỳ --</option>
        <!-- JS sẽ tự fill options từ META.semesters -->
      </select>

      <select id="taskFStatus" class="px-3 py-2 border rounded-lg text-sm w-[180px]">
        <option value="">-- Trạng thái --</option>
        <option value="pending">Chưa làm</option>
        <option value="doing">Đang làm</option>
        <option value="done">Hoàn thành</option>
        <option value="overdue">Quá hạn</option>
      </select>
    </div>




    <!-- GRID CARDS -->
    <div class="p-6">
      <!-- ✅ 1 hàng 3 block (desktop), 1 trang 6 block (JS page_size=6) -->
      <div id="taskGrid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6"></div>

      <!-- PAGER -->
      <div id="taskPager" class="mt-6"></div>
    </div>

  </div>
</section>