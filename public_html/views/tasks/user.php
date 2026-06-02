<section id="tasks-app" data-view="user">
  <div class="w-full">
    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">

      <!-- HEADER -->
      <div class="p-6 border-b">
        <h1 class="text-2xl font-bold text-gray-800">CÔNG VIỆC CỦA TÔI</h1>
        <p class="text-sm text-gray-500 mt-1">Theo dõi và cập nhật tiến độ</p>
      </div>

      <!-- STATS (6 cards như hình bạn gửi) -->
      <div class="p-6 border-b">
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">

          <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-[11px] font-bold text-gray-500 tracking-wide uppercase">Tổng CV</div>
            <div id="statTotal" class="mt-1 text-2xl font-extrabold text-violet-700">0</div>
          </div>

          <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-[11px] font-bold text-gray-500 tracking-wide uppercase">Chưa bắt đầu</div>
            <div id="statPending" class="mt-1 text-2xl font-extrabold text-orange-600">0</div>
          </div>

          <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-[11px] font-bold text-gray-500 tracking-wide uppercase">Đang làm</div>
            <div id="statDoing" class="mt-1 text-2xl font-extrabold text-blue-600">0</div>
          </div>

          <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-[11px] font-bold text-gray-500 tracking-wide uppercase">Hoàn thành</div>
            <div id="statDone" class="mt-1 text-2xl font-extrabold text-emerald-600">0</div>
          </div>

          <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-[11px] font-bold text-gray-500 tracking-wide uppercase">Quá hạn</div>
            <div id="statOverdue" class="mt-1 text-2xl font-extrabold text-red-600">0</div>
          </div>

          <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-[11px] font-bold text-gray-500 tracking-wide uppercase">Hiệu suất</div>
            <div id="statEff" class="mt-1 text-2xl font-extrabold text-emerald-700">0%</div>
          </div>

        </div>
      </div>

      <!-- FILTER -->
      <div class="p-4 border-b bg-gray-50 flex flex-wrap gap-3 items-center">
        <div class="relative w-[240px]">
          <input id="taskFProject" class="px-3 py-2 border rounded-xl text-sm w-full bg-white"
            placeholder="Dự án (gõ để tìm)..." autocomplete="off" />

          <div id="projectSuggest"
            class="absolute left-0 top-full mt-1 w-full bg-white border rounded-xl shadow-lg hidden max-h-[280px] overflow-auto z-50">
          </div>
        </div>


        <select id="taskFStatus" class="px-3 py-2 border rounded-xl text-sm w-[200px] bg-white">
          <option value="">-- Trạng thái --</option>
          <option value="pending">Chưa bắt đầu</option>
          <option value="doing">Đang làm</option>
          <option value="done">Hoàn thành</option>
          <option value="overdue">Quá hạn</option>
        </select>

        <select id="taskFSort" class="px-3 py-2 border rounded-xl text-sm w-[240px] bg-white">
          <option value="deadline_asc">Hạn hoàn thành (gần nhất)</option>
          <option value="deadline_desc">Hạn hoàn thành (xa nhất)</option>
          <option value="priority_desc">Ưu tiên (cao → thấp)</option>
          <option value="priority_asc">Ưu tiên (thấp → cao)</option>
          <option value="created_desc">Ngày tạo (mới nhất)</option>
          <option value="created_asc">Ngày tạo (cũ nhất)</option>
        </select>

        <input id="taskFQ" class="px-3 py-2 border rounded-xl text-sm flex-1 min-w-[220px] bg-white"
          placeholder="Tìm theo tiêu đề / mô tả / tags..." />

        <button id="taskBtnClear" class="px-4 py-2 rounded-xl border bg-white text-sm font-semibold">
          Reset
        </button>
      </div>

      <!-- LIST -->
      <div class="p-6 bg-gray-50">
        <div id="taskListBox" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6"></div>
        <div id="taskPager" class="mt-6"></div>
      </div>

    </div>
  </div>
</section>