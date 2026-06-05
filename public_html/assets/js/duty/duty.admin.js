let SELECTED_DUTY_USER_IDS = [];
let DUTY_DRAFT_MODE = false;
let DUTY_DRAFT_SCHEDULE = [];
let DUTY_MEMBERS_CACHE = []; // [{id, fullname, username, free_count, avatar_url}]
let DUTY_AVAILABILITY_MATRIX = {};
let DUTY_CURRENT_SCHEDULE = [];
let FILTER_SELECTED_SHIFTS = [];
let DUTY_MEMBER_PAGE = 1;
let DUTY_MEMBER_LIMIT = 20;
let DUTY_CURRENT_WEEK_START = "";
let DUTY_CURRENT_WEEK_END = "";
// ===== BULK SELECT (DUTY VIEW) =====
let DUTY_BULK_SELECTED = new Map(); // key -> {user_id, day, shift}

function dutyKey(user_id, day, shift) {
  return `${day}|${shift}|${Number(user_id)}`;
}

function ensureDutyBulkBar() {
  if (document.getElementById("dutyBulkBar")) return;

  document.body.insertAdjacentHTML("beforeend", `
    <div id="dutyBulkBar"
         class="hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-[9999]
                bg-white border border-gray-200 shadow-lg rounded-2xl px-4 py-3
                flex items-center gap-3">
      <div class="text-sm text-gray-700">
        Đã chọn <b id="dutyBulkCount">0</b>
      </div>

      <button id="dutyBulkDelete"
              class="px-3 py-2 rounded-xl bg-red-600 text-white text-sm font-semibold hover:bg-red-700">
        Xóa
      </button>

      <button id="dutyBulkClear"
              class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-semibold hover:bg-gray-50">
        Bỏ chọn
      </button>
    </div>
  `);
}

function updateDutyBulkBar() {
  ensureDutyBulkBar();
  const bar = document.getElementById("dutyBulkBar");
  const countEl = document.getElementById("dutyBulkCount");
  if (!bar || !countEl) return;

  const n = DUTY_BULK_SELECTED.size;
  countEl.textContent = String(n);

  if (n > 0) bar.classList.remove("hidden");
  else bar.classList.add("hidden");
}

function markDutyItemSelected(itemEl, selected) {
  if (!itemEl) return;
  if (selected) {
    itemEl.dataset.selected = "1";
    itemEl.classList.add("ring-2", "ring-red-400");
  } else {
    itemEl.removeAttribute("data-selected");   // <-- đổi dòng này
    itemEl.classList.remove("ring-2", "ring-red-400");
  }
}


function clearDutyBulkSelection() {
  DUTY_BULK_SELECTED.clear();
  document.querySelectorAll(".duty-item[data-selected='1']")
    .forEach(el => markDutyItemSelected(el, false));
  updateDutyBulkBar();
}

function toggleDutyBulkSelect(itemEl) {
  if (!itemEl) return;

  const user_id = Number(itemEl.dataset.userId || 0);
  const day = itemEl.dataset.day || "";
  const shift = itemEl.dataset.shift || "";

  if (!user_id || !day || !shift) return;

  const key = dutyKey(user_id, day, shift);

  if (DUTY_BULK_SELECTED.has(key)) {
    DUTY_BULK_SELECTED.delete(key);
    markDutyItemSelected(itemEl, false);
  } else {
    DUTY_BULK_SELECTED.set(key, { user_id, day, shift });
    markDutyItemSelected(itemEl, true);
  }

  updateDutyBulkBar();
}

function normalizeShift(shift) {
  return shift;
}

function getDutyBgByShift(shift) {
  const common =
    "shadow-sm ring-1 ring-black/5 font-semibold tracking-tight " +
    "transition-all duration-150 hover:shadow-md hover:-translate-y-[1px]";

  if (shift === "rachoi_s" || shift === "rachoi_c") {
    return `bg-orange-100 border-orange-300 text-orange-800 ${common}`;
  }
  return `bg-green-100 border-green-300 text-green-800 ${common}`;
}


function getAdminNextWeekQuery() {
  return `offset=${VIEW_WEEK_OFFSET}`;

}

document.addEventListener('DOMContentLoaded', () => {

  /* ==========================
   FORM XÁC NHẬN XẾP LỊCH
========================== */

  /* ==========================
     FORM XÁC NHẬN XẾP LỊCH (modal() chung)
  ========================== */

  let generatingWeek = false;

  const btnGenerate = document.getElementById("btnGenerateWeek");

  if (btnGenerate) {
    btnGenerate.addEventListener("click", () => {
      if (generatingWeek) return;

      SELECTED_DUTY_USER_IDS = Array.from(
        document.querySelectorAll(".duty-member-checkbox:checked")
      )
        .map(cb => Number(cb.value))
        .filter(Boolean);

      if (SELECTED_DUTY_USER_IDS.length === 0) {
        toast("❌ Chưa chọn thành viên để xếp lịch", "error");
        return;
      }

      modal(`
      <div class="text-center space-y-4">
        <p class="text-gray-700">Bạn có chắc muốn chạy gợi ý xếp lịch trực cho tuần này không? Hệ thống sẽ tạo một bản nháp gợi ý để bạn xem trước và chỉnh sửa thoải mái trước khi lưu chính thức.</p>

        <div class="flex justify-center gap-3">
          <button
            type="button"
            class="px-4 py-2 border rounded-lg"
            onclick="closeModal()">
            Hủy
          </button>

          <button
            type="button"
            id="confirmGenerateWeekBtn"
            data-primary
            class="px-4 py-2 rounded-lg text-white bg-blue-600 hover:bg-blue-700">
            Tạo gợi ý nháp
          </button>
        </div>
      </div>
    `, "Gợi ý lịch trực tuần", "small");

      const confirmBtn = document.getElementById("confirmGenerateWeekBtn");
      if (!confirmBtn) return;

      confirmBtn.onclick = async () => {
        if (generatingWeek) return;
        generatingWeek = true;

        const original = confirmBtn.textContent;
        confirmBtn.disabled = true;
        confirmBtn.textContent = "Đang chạy thuật toán...";

        try {
          const q = getAdminNextWeekQuery();

          const res = await fetch(`${DUTY_API}?action=suggest_week&${q}`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ user_ids: SELECTED_DUTY_USER_IDS })
          });

          const json = await res.json();

          if (!json.ok) {
            toast(json.error || "Gợi ý lịch thất bại", "error");
            confirmBtn.disabled = false;
            confirmBtn.textContent = original;
            generatingWeek = false;
            return;
          }

          closeModal();
          toast("✅ Đã tạo lịch trực gợi ý (Nháp) thành công", "success");

          // Kích hoạt chế độ nháp
          DUTY_DRAFT_MODE = true;
          DUTY_DRAFT_SCHEDULE = Array.isArray(json.data.assignments) ? json.data.assignments : [];

          const banner = document.getElementById("dutyDraftBanner");
          if (banner) banner.classList.remove("hidden");

          // Render lịch nháp lên bảng xem
          renderAdminDutyView(DUTY_DRAFT_SCHEDULE);

        } catch (e) {
          console.error(e);
          toast("❌ Lỗi hệ thống", "error");
          confirmBtn.disabled = false;
          confirmBtn.textContent = original;
        } finally {
          generatingWeek = false;
        }
      };
    });
  }

  // Event handlers cho banner nháp
  document.getElementById("btnCancelDraft")?.addEventListener("click", () => {
    DUTY_DRAFT_MODE = false;
    DUTY_DRAFT_SCHEDULE = [];
    document.getElementById("dutyDraftBanner")?.classList.add("hidden");
    toast("Đã hủy bản nháp", "info");
    loadDutyViewSchedule(VIEW_WEEK_OFFSET);
  });

  document.getElementById("btnSaveDraft")?.addEventListener("click", async () => {
    const btnSave = document.getElementById("btnSaveDraft");
    if (!btnSave || btnSave.disabled) return;

    btnSave.disabled = true;
    const originalText = btnSave.textContent;
    btnSave.textContent = "Đang lưu...";

    try {
      const q = getAdminNextWeekQuery();
      const res = await fetch(`${DUTY_API}?action=save_week_schedule&${q}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ items: DUTY_DRAFT_SCHEDULE })
      });
      const json = await res.json();
      if (!json.ok) {
        toast(json.error || "Không thể lưu lịch trực", "error");
        btnSave.disabled = false;
        btnSave.textContent = originalText;
        return;
      }

      toast("✅ Đã lưu lịch trực chính thức thành công!", "success");
      DUTY_DRAFT_MODE = false;
      DUTY_DRAFT_SCHEDULE = [];
      document.getElementById("dutyDraftBanner")?.classList.add("hidden");

      loadAdminOverview();
      loadFreeStats();
      loadDutyViewSchedule(VIEW_WEEK_OFFSET);
    } catch (err) {
      console.error(err);
      toast("❌ Lỗi lưu lịch trực", "error");
      btnSave.disabled = false;
      btnSave.textContent = originalText;
    }
  });


  /* ==========================
     TAB CON TRONG ADMIN
     (overview / assign / view)
  ========================== */

  const adminTabs = document.querySelectorAll('.duty-admin-tab');
  const adminViews = document.querySelectorAll('[data-admin-view]');

  if (adminTabs.length === 0 || adminViews.length === 0) {
    console.warn('[ADMIN] No admin tabs/views found');
    return;
  }

  /* ==========================
     HELPER: SHOW TAB
  ========================== */
  function showAdminTab(tab, pushUrl = true) {

    // style tab
    adminTabs.forEach(b => {
      b.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');
      b.classList.add('text-gray-600');
    });

    const activeBtn = document.querySelector(
      `.duty-admin-tab[data-admin-tab="${tab}"]`
    );
    if (activeBtn) {
      activeBtn.classList.add('text-blue-600', 'border-b-2', 'border-blue-600');
    }

    // toggle view
    adminViews.forEach(v => {
      v.classList.toggle('hidden', v.dataset.adminView !== tab);
    });

    // save URL
    if (pushUrl) {
      const url = new URL(window.location);
      url.searchParams.set('tab', tab);
      history.replaceState(null, '', url);
    }

    // hook load data
    if (tab === 'assign') {
      loadFreeStats();
      loadDutyViewSchedule(VIEW_WEEK_OFFSET);
    }

  }

  /* ==========================
     CLICK TAB
  ========================== */
  adminTabs.forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.adminTab;
      showAdminTab(tab);
    });
  });

  /* ==========================
     INIT TAB FROM URL
  ========================== */
  const params = new URLSearchParams(window.location.search);
  let initTab = params.get('tab') || 'overview';
  if (initTab === 'view') {
    initTab = 'assign';
  }

  showAdminTab(initTab, false);


  /* ==========================
     LOAD OVERVIEW (ADMIN)
  ========================== */

  loadDutyViewSchedule(1);

  document.getElementById("btnSelectAllAssign")?.addEventListener("click", () => {
    document.querySelectorAll("#dutyAssignMemberList .duty-member-checkbox").forEach(cb => cb.checked = true);
    document.querySelectorAll("#dutyMemberListTable .duty-member-table-checkbox").forEach(cb => cb.checked = true);
    updateSelectAllHeaderState();
  });
  document.getElementById("btnUnselectAllAssign")?.addEventListener("click", () => {
    document.querySelectorAll("#dutyAssignMemberList .duty-member-checkbox").forEach(cb => cb.checked = false);
    document.querySelectorAll("#dutyMemberListTable .duty-member-table-checkbox").forEach(cb => cb.checked = false);
    updateSelectAllHeaderState();
  });

  // Lắng nghe sự thay đổi của bộ lọc thành viên
  document.getElementById("filterMemberSearch")?.addEventListener("input", () => {
    DUTY_MEMBER_PAGE = 1;
    renderDutyMemberListTable();
  });
  document.getElementById("filterMemberStatus")?.addEventListener("change", () => {
    DUTY_MEMBER_PAGE = 1;
    renderDutyMemberListTable();
  });
  document.getElementById("btnFilterShiftModal")?.addEventListener("click", openFilterShiftModal);

  // Lọc số lượng hiển thị (Pagination limit)
  document.getElementById("pagLimit")?.addEventListener("change", (e) => {
    DUTY_MEMBER_LIMIT = parseInt(e.target.value) || 20;
    DUTY_MEMBER_PAGE = 1;
    renderDutyMemberListTable();
  });

  // Checkbox chọn tất cả trên bảng (chỉ áp dụng cho trang hiện tại)
  document.getElementById("selectAllMemberTable")?.addEventListener("change", (e) => {
    const isChecked = e.target.checked;
    document.querySelectorAll("#dutyMemberListTable .duty-member-table-checkbox").forEach(cb => {
      cb.checked = isChecked;
      const uid = cb.value;
      const assignCb = document.querySelector(`#dutyAssignMemberList .duty-member-checkbox[value="${uid}"]`);
      if (assignCb) {
        assignCb.checked = isChecked;
      }
    });
  });

  // Delegation cho checkbox trên từng hàng của bảng thành viên
  document.getElementById("dutyMemberListTable")?.addEventListener("change", (e) => {
    if (e.target.classList.contains("duty-member-table-checkbox")) {
      const uid = e.target.value;
      const isChecked = e.target.checked;
      const assignCb = document.querySelector(`#dutyAssignMemberList .duty-member-checkbox[value="${uid}"]`);
      if (assignCb) {
        assignCb.checked = isChecked;
      }
      updateSelectAllHeaderState();
    }
  });

  // Delegation cho checkbox trên checklist xếp lịch (tab Xếp lịch)
  document.getElementById("dutyAssignMemberList")?.addEventListener("change", (e) => {
    if (e.target.classList.contains("duty-member-checkbox")) {
      const uid = e.target.value;
      const isChecked = e.target.checked;
      const tableCb = document.querySelector(`#dutyMemberListTable .duty-member-table-checkbox[value="${uid}"]`);
      if (tableCb) {
        tableCb.checked = isChecked;
      }
      updateSelectAllHeaderState();
    }
  });

});


/* ==========================
   API: LOAD ADMIN OVERVIEW
========================== */
async function loadAdminOverview() {
  try {
    const q = getAdminNextWeekQuery();

    const res = await fetch(
      `${DUTY_API}?action=get_week_overview&${q}`
    );
    const json = await res.json();

    if (!json.ok) {
      console.warn('[ADMIN OVERVIEW] API not ok');
      return;
    }

    const totalEl = document.getElementById('statTotal');
    const regEl = document.getElementById('statRegistered');
    const unregEl = document.getElementById('statUnregistered');

    if (totalEl) totalEl.textContent = json.data.total_users + ' người';
    if (regEl) regEl.textContent = json.data.registered_users + ' người';
    if (unregEl) unregEl.textContent = json.data.unregistered_users + ' người';

    // =======================
    // UPDATE BUTTON GENERATE
    // =======================
    const btnGenerate = document.getElementById("btnGenerateWeek");
    if (btnGenerate) {
      if (json.data.has_schedule) {
        btnGenerate.innerHTML = "🔁 Xếp lại lịch tuần sau";
        btnGenerate.dataset.mode = "regenerate";
      } else {
        btnGenerate.innerHTML = "⚡ Xếp lịch tuần sau";
        btnGenerate.dataset.mode = "generate";
      }
    }
    loadDutyMembers()
  } catch (e) {
    console.error('[ADMIN OVERVIEW ERROR]', e);
  }
}
async function loadDutyMembers() {
  try {
    const q = getAdminNextWeekQuery();

    // 1. Lấy danh sách thành viên + free_count
    const res = await fetch(`${DUTY_API}?action=get_week_members&${q}`);
    const json = await res.json();
    if (!json.ok) return;
    DUTY_MEMBERS_CACHE = Array.isArray(json.data) ? json.data : [];

    // --- RENDER BẢNG CHI TIẾT (TAB: OVERVIEW) ---
    renderDutyMemberListTable();

    // --- RENDER CHECKLIST (TAB: ASSIGN) ---
    const assignBox = document.getElementById("dutyAssignMemberList");
    const assignCountEl = document.getElementById("assignMemberCount");
    if (assignBox) {
      assignBox.innerHTML = "";
      if (assignCountEl) assignCountEl.textContent = `(${DUTY_MEMBERS_CACHE.length} người)`;

      DUTY_MEMBERS_CACHE.forEach(u => {
        const uid = Number(u.id);
        const name = u.fullname || u.username || "Không tên";
        const free = Number(u.free_count || 0);

        assignBox.insertAdjacentHTML("beforeend", `
          <label class="flex items-center gap-2.5 p-2.5 rounded-xl border border-gray-200 hover:border-blue-300 hover:bg-blue-50/50 cursor-pointer select-none transition justify-between">
            <div class="flex items-center gap-2.5 min-w-0">
              <input type="checkbox" class="duty-member-checkbox w-5 h-5 accent-blue-600 rounded-lg shrink-0" value="${uid}" checked>
              <div class="flex flex-col min-w-0">
                <span class="text-sm font-semibold text-gray-700 truncate">${name}</span>
                <span class="text-[11px] text-gray-500 font-normal mt-0.5">Rảnh: ${free} buổi</span>
              </div>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full font-medium ${free > 0 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'} shrink-0">
              ${free} buổi rảnh
            </span>
          </label>
        `);
      });
    }

  } catch (e) {
    console.error("[DUTY MEMBER LIST ERROR]", e);
  }
}

function renderDutyMemberListTable() {
  const tableBody = document.getElementById("dutyMemberListTable");
  const countEl = document.getElementById("memberCount");
  if (!tableBody) return;

  const searchVal = (document.getElementById("filterMemberSearch")?.value || "").trim().toLowerCase();
  const statusVal = document.getElementById("filterMemberStatus")?.value || "all";
  const shiftVal = document.getElementById("filterMemberShift")?.value || "all";

  // Lọc dữ liệu
  const filtered = DUTY_MEMBERS_CACHE.filter(u => {
    // 1. Lọc tìm kiếm tên / username
    const name = (u.fullname || "").toLowerCase();
    const username = (u.username || "").toLowerCase();
    if (searchVal && !name.includes(searchVal) && !username.includes(searchVal)) {
      return false;
    }

    // 2. Lọc theo trạng thái rảnh
    const free = Number(u.free_count || 0);
    if (statusVal === "has_free" && free === 0) return false;
    if (statusVal === "no_free" && free > 0) return false;

    // 3. Lọc theo ca rảnh cụ thể (bất kỳ ca nào trong mảng FILTER_SELECTED_SHIFTS được chọn)
    if (FILTER_SELECTED_SHIFTS.length > 0) {
      const userMatrix = DUTY_AVAILABILITY_MATRIX[u.id] || { availability: [], study: [] };
      const isFreeAny = FILTER_SELECTED_SHIFTS.some(shiftVal => {
        const parts = shiftVal.split("-");
        const dayNum = parseInt(parts[0]);
        const shiftName = parts[1];
        return userMatrix.availability.some(a => a.day === dayNum && a.shift === shiftName);
      });
      if (!isFreeAny) return false;
    }

    return true;
  });

  // Phân trang
  const totalItems = filtered.length;
  const totalPages = Math.ceil(totalItems / DUTY_MEMBER_LIMIT) || 1;
  
  if (DUTY_MEMBER_PAGE > totalPages) {
    DUTY_MEMBER_PAGE = totalPages;
  }
  if (DUTY_MEMBER_PAGE < 1) {
    DUTY_MEMBER_PAGE = 1;
  }

  const startIdx = (DUTY_MEMBER_PAGE - 1) * DUTY_MEMBER_LIMIT;
  const endIdx = Math.min(startIdx + DUTY_MEMBER_LIMIT, totalItems);
  const pageData = filtered.slice(startIdx, endIdx);

  tableBody.innerHTML = "";
  if (countEl) countEl.textContent = `(${totalItems} người)`;

  // Cập nhật thanh phân trang
  const startShow = totalItems === 0 ? 0 : startIdx + 1;
  const endShow = endIdx;

  const pagStartEl = document.getElementById("pagStart");
  const pagEndEl = document.getElementById("pagEnd");
  const pagTotalEl = document.getElementById("pagTotal");
  if (pagStartEl) pagStartEl.textContent = String(startShow);
  if (pagEndEl) pagEndEl.textContent = String(endShow);
  if (pagTotalEl) pagTotalEl.textContent = String(totalItems);

  const prevBtn = document.getElementById("btnPagPrev");
  if (prevBtn) {
    prevBtn.disabled = DUTY_MEMBER_PAGE === 1;
    prevBtn.onclick = () => changeMemberPage(DUTY_MEMBER_PAGE - 1);
  }
  const nextBtn = document.getElementById("btnPagNext");
  if (nextBtn) {
    nextBtn.disabled = DUTY_MEMBER_PAGE === totalPages;
    nextBtn.onclick = () => changeMemberPage(DUTY_MEMBER_PAGE + 1);
  }

  const pagesEl = document.getElementById("pagPages");
  if (pagesEl) {
    pagesEl.innerHTML = "";
    for (let i = 1; i <= totalPages; i++) {
      const isActive = i === DUTY_MEMBER_PAGE;
      const activeClass = isActive 
        ? "bg-blue-600 text-white border-blue-600 shadow-sm" 
        : "bg-white text-gray-700 border-gray-200 hover:bg-gray-50";
      pagesEl.insertAdjacentHTML("beforeend", `
        <button type="button" class="w-7 h-7 flex items-center justify-center text-xs border rounded-lg font-semibold transition duration-150 ${activeClass}" onclick="changeMemberPage(${i})">
          ${i}
        </button>
      `);
    }
  }

  if (totalItems === 0) {
    tableBody.innerHTML = `
      <tr>
        <td colspan="4" class="px-6 py-8 text-center text-gray-500 italic text-sm">
          Không tìm thấy thành viên nào phù hợp bộ lọc.
        </td>
      </tr>
    `;
    return;
  }

  const getWeeklySchedule = (userId) => {
    const schedule = DUTY_DRAFT_MODE ? DUTY_DRAFT_SCHEDULE : DUTY_CURRENT_SCHEDULE;
    return schedule.filter(a => Number(a.user_id) === Number(userId));
  };

  pageData.forEach(u => {
    const uid = Number(u.id);
    const name = u.fullname || u.username || "Không tên";
    const username = u.username ? `@${u.username}` : "";
    const free = Number(u.free_count || 0);
    const schedule = getWeeklySchedule(uid);

    // Kiểm tra xem thành viên này có đang được check ở checklist không
    const assignCb = document.querySelector(`#dutyAssignMemberList .duty-member-checkbox[value="${uid}"]`);
    const isChecked = assignCb ? assignCb.checked : true; // Mặc định là true nếu chưa render checklist

    // RENDER MINI BADGES
    let scheduleHTML = '';
    if (schedule.length > 0) {
      const sorted = [...schedule].sort((a, b) => {
        return parseInt(a.day.replace('T','')) - parseInt(b.day.replace('T',''));
      });

      scheduleHTML = `<div class="flex flex-wrap gap-1.5">`;
      sorted.forEach(s => {
        let label = '';
        let colorClass = 'emerald';
        if (s.shift === "sang") { label = "Sáng"; colorClass = "emerald"; }
        else if (s.shift === "chieu") { label = "Chiều"; colorClass = "violet"; }
        else if (s.shift === "rachoi_s") { label = "RCS"; colorClass = "amber"; }
        else if (s.shift === "rachoi_c") { label = "RCC"; colorClass = "amber"; }

        scheduleHTML += `
          <span class="text-xs px-2 py-0.5 rounded font-semibold bg-${colorClass}-50 text-${colorClass}-700 border border-${colorClass}-200">
            ${s.day} ${label}
          </span>`;
      });
      scheduleHTML += `</div>`;
    } else {
      scheduleHTML = `<span class="text-xs text-gray-400 italic">Chưa có lịch trực</span>`;
    }

    // AVATAR
    const avatarHTML = u.avatar_url ? 
      `<img src="${u.avatar_url}" class="w-10 h-10 object-cover rounded-full border border-gray-200">` :
      `<div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white text-sm font-bold">${name.charAt(0).toUpperCase()}</div>`;

    const rowHTML = `
      <tr class="hover:bg-gray-50 transition duration-150">
        <td class="px-6 py-4 w-12 text-center">
          <input type="checkbox" class="duty-member-table-checkbox w-4 h-4 accent-blue-600 rounded cursor-pointer" value="${uid}" ${isChecked ? 'checked' : ''}>
        </td>
        <td class="px-6 py-4">
          <div class="flex items-center gap-3">
            ${avatarHTML}
            <div>
              <div class="font-semibold text-gray-800">${name}</div>
              <div class="text-xs text-gray-500">${username}</div>
            </div>
          </div>
        </td>
        <td class="px-6 py-4 text-center">
          <span class="px-2.5 py-1 text-xs font-semibold rounded-full ${free > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}">
            ${free} buổi rảnh
          </span>
        </td>
        <td class="px-6 py-4">
          ${scheduleHTML}
        </td>
        <td class="px-6 py-4">
          <div class="flex items-center justify-center gap-2">
            <button type="button" onclick="viewAvailability(${uid})" class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg text-xs font-semibold border border-blue-200 transition">
              Xem
            </button>
            <button type="button" onclick="addShift(${uid})" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-600 rounded-lg text-xs font-semibold border border-emerald-200 transition">
              Thêm ca
            </button>
            <button type="button" onclick="editShifts(${uid})" class="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-600 rounded-lg text-xs font-semibold border border-amber-200 transition">
              Sửa
            </button>
            <button type="button" onclick="clearUserAssignments(${uid}, '${name.replace(/'/g, "\\'")}')" class="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg text-xs font-semibold border border-red-200 transition">
              Xóa
            </button>
          </div>
        </td>
      </tr>
    `;
    tableBody.insertAdjacentHTML("beforeend", rowHTML);
  });
  updateSelectAllHeaderState();
}

// ==========================================
// TÁC VỤ CRUD TRÊN BẢNG TỔNG QUAN (ADMIN)
// ==========================================

window.viewAvailability = async function(userId) {
  try {
    const q = getAdminNextWeekQuery();
    const res = await fetch(`${DUTY_API}?action=get_user_availability&user_id=${userId}&${q}`);
    const json = await res.json();
    if (!json.ok) {
      toast(json.error || "Không thể tải thông tin", "error");
      return;
    }

    const { availability, study } = json.data;
    let assignments = json.data.assignments || [];
    if (DUTY_DRAFT_MODE) {
      assignments = DUTY_DRAFT_SCHEDULE.filter(a => Number(a.user_id) === Number(userId));
    }

    const member = DUTY_MEMBERS_CACHE.find(m => m.id === userId) || {};
    const name = member.fullname || member.username || "Không tên";

    const days = [2, 3, 4, 5, 6];
    const dayLabels = { 2: "Thứ 2", 3: "Thứ 3", 4: "Thứ 4", 5: "Thứ 5", 6: "Thứ 6" };

    // Map avail & study & assigns
    const availMap = {};
    availability.forEach(a => { availMap[`${a.day}-${a.shift}`] = true; });

    const studyMap = {};
    study.forEach(s => { studyMap[`${s.day}-${s.shift}`] = true; });

    const assignMap = {};
    assignments.forEach(asg => {
      const dayNum = parseInt(asg.day.replace('T', ''));
      const key = `${dayNum}-${asg.shift}`;
      if (!assignMap[key]) assignMap[key] = [];
      assignMap[key].push(asg);
    });

    let html = `
      <div class="space-y-6 text-sm text-gray-700">
        <div>
          <h4 class="font-bold text-gray-800 text-base mb-1">${name}</h4>
          <p class="text-xs text-gray-500">Chi tiết đăng ký lịch rảnh, lịch học và lịch trực tuần này</p>
        </div>

        <div class="border rounded-xl overflow-hidden bg-white">
          <table class="w-full text-xs text-center border-collapse">
            <thead class="bg-gray-50 border-b">
              <tr class="font-semibold text-gray-600">
                <th class="px-3 py-2 text-left">Buổi / Thứ</th>
                ${days.map(d => `<th class="px-3 py-2">${dayLabels[d]}</th>`).join('')}
              </tr>
            </thead>
            <tbody class="divide-y">
              <!-- SÁNG -->
              <tr>
                <td class="px-3 py-2 text-left font-semibold bg-gray-50">Sáng</td>
                ${days.map(d => {
                  const hasStudy = studyMap[`${d}-morning`];
                  const hasAvail = availMap[`${d}-morning`];
                  const isAssigned = assignMap[`${d}-sang`];
                  let badge = '';
                  if (hasStudy) badge += `<span class="block px-1.5 py-0.5 rounded bg-red-100 text-red-700 mb-1">Lịch học</span>`;
                  if (hasAvail) badge += `<span class="block px-1.5 py-0.5 rounded bg-green-100 text-green-700 mb-1">Rảnh</span>`;
                  if (isAssigned) badge += `<span class="block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 font-bold">Đã trực</span>`;
                  return `<td class="px-2 py-2">${badge || '-'}</td>`;
                }).join('')}
              </tr>
              <!-- CHIỀU -->
              <tr>
                <td class="px-3 py-2 text-left font-semibold bg-gray-50">Chiều</td>
                ${days.map(d => {
                  const hasStudy = studyMap[`${d}-afternoon`];
                  const hasAvail = availMap[`${d}-afternoon`];
                  const isAssigned = assignMap[`${d}-chieu`];
                  let badge = '';
                  if (hasStudy) badge += `<span class="block px-1.5 py-0.5 rounded bg-red-100 text-red-700 mb-1">Lịch học</span>`;
                  if (hasAvail) badge += `<span class="block px-1.5 py-0.5 rounded bg-green-100 text-green-700 mb-1">Rảnh</span>`;
                  if (isAssigned) badge += `<span class="block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 font-bold">Đã trực</span>`;
                  return `<td class="px-2 py-2">${badge || '-'}</td>`;
                }).join('')}
              </tr>
              <!-- RA CHƠI S -->
              <tr>
                <td class="px-3 py-2 text-left font-semibold bg-gray-50">Ra chơi S</td>
                ${days.map(d => {
                  const hasAvail = availMap[`${d}-break_morning`];
                  const isAssigned = assignMap[`${d}-rachoi_s`];
                  let badge = '';
                  if (hasAvail) badge += `<span class="block px-1.5 py-0.5 rounded bg-orange-100 text-orange-700 mb-1">Rảnh</span>`;
                  if (isAssigned) badge += `<span class="block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 font-bold">Đã trực</span>`;
                  return `<td class="px-2 py-2">${badge || '-'}</td>`;
                }).join('')}
              </tr>
              <!-- RA CHƠI C -->
              <tr>
                <td class="px-3 py-2 text-left font-semibold bg-gray-50">Ra chơi C</td>
                ${days.map(d => {
                  const hasAvail = availMap[`${d}-break_afternoon`];
                  const isAssigned = assignMap[`${d}-rachoi_c`];
                  let badge = '';
                  if (hasAvail) badge += `<span class="block px-1.5 py-0.5 rounded bg-orange-100 text-orange-700 mb-1">Rảnh</span>`;
                  if (isAssigned) badge += `<span class="block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 font-bold">Đã trực</span>`;
                  return `<td class="px-2 py-2">${badge || '-'}</td>`;
                }).join('')}
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex justify-end pt-3">
          <button type="button" class="px-4 py-2 border rounded-lg hover:bg-gray-50" onclick="closeModal()">Đóng</button>
        </div>
      </div>
    `;

    modal(html, "Thông tin lịch đăng ký", "medium");
  } catch (e) {
    console.error(e);
    toast("Lỗi hệ thống", "error");
  }
}

window.addShift = function(userId) {
  const member = DUTY_MEMBERS_CACHE.find(m => m.id === userId) || {};
  const name = member.fullname || member.username || "Không tên";

  const html = `
    <div class="space-y-4 text-sm text-gray-700">
      <div>
        <h4 class="font-bold text-gray-800 text-base mb-1">Thêm ca trực: ${name}</h4>
        <p class="text-xs text-gray-500">Gán ca trực thủ công cho thành viên này</p>
      </div>

      <div class="space-y-3">
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Chọn ngày</label>
          <select id="addShiftDay" class="w-full px-3 py-2 border rounded-lg">
            <option value="T2">Thứ 2</option>
            <option value="T3">Thứ 3</option>
            <option value="T4">Thứ 4</option>
            <option value="T5">Thứ 5</option>
            <option value="T6">Thứ 6</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Chọn ca trực</label>
          <select id="addShiftType" class="w-full px-3 py-2 border rounded-lg">
            <option value="sang">Sáng</option>
            <option value="chieu">Chiều</option>
            <option value="rachoi_s">Ra chơi Sáng</option>
            <option value="rachoi_c">Ra chơi Chiều</option>
          </select>
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-3 border-t">
        <button type="button" class="px-4 py-2 border rounded-lg hover:bg-gray-50" onclick="closeModal()">Hủy</button>
        <button type="button" id="confirmAddShiftBtn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Lưu lại</button>
      </div>
    </div>
  `;

  modal(html, "Gán ca trực thủ công", "small");

  document.getElementById("confirmAddShiftBtn").onclick = async () => {
    const day = document.getElementById("addShiftDay").value;
    const shift = document.getElementById("addShiftType").value;

    if (DUTY_DRAFT_MODE) {
      const cntInTarget = DUTY_DRAFT_SCHEDULE.filter(a => a.day === day && a.shift === shift).length;
      if (cntInTarget >= 3) {
        toast("Ca này đã đủ 3 người", "warning");
        return;
      }

      const isAlreadyInTarget = DUTY_DRAFT_SCHEDULE.some(a => a.user_id === userId && a.day === day && a.shift === shift);
      if (isAlreadyInTarget) {
        toast("Người này đã có trong ca đích", "warning");
        return;
      }

      let toType = "thuong";
      let toScore = 1.0;
      if (shift === "rachoi_s" || shift === "rachoi_c") {
        toType = "rachoi";
        toScore = 0.5;
      }

      let currentScore = DUTY_DRAFT_SCHEDULE.filter(a => a.user_id === userId).reduce((sum, a) => sum + Number(a.score || 0), 0);
      if (currentScore + toScore > 5.0 + 1e-9) {
        toast("Người này sẽ vượt quá 5 điểm/tuần", "warning");
        return;
      }

      DUTY_DRAFT_SCHEDULE.push({
        user_id: userId,
        fullname: name,
        day: day,
        shift: shift,
        type: toType,
        score: toScore
      });

      toast("✅ Đã thêm ca trực (Nháp) thành công", "success");
      closeModal();
      renderAdminDutyView(DUTY_DRAFT_SCHEDULE);
      return;
    }

    const q = getAdminNextWeekQuery();

    try {
      const res = await fetch(`${DUTY_API}?action=add_assignment&${q}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ user_id: userId, day, shift })
      });
      const json = await res.json();
      if (!json.ok) {
        toast(json.error || "Lỗi thêm ca trực", "error");
        return;
      }

      toast("✅ Đã thêm ca trực thành công", "success");
      closeModal();
      loadDutyMembers();
      loadDutyViewSchedule(VIEW_WEEK_OFFSET);
    } catch (e) {
      console.error(e);
      toast("❌ Lỗi hệ thống", "error");
    }
  };
}

window.editShifts = async function(userId) {
  try {
    const member = DUTY_MEMBERS_CACHE.find(m => m.id === userId) || {};
    const name = member.fullname || member.username || "Không tên";

    let assignments = [];
    if (DUTY_DRAFT_MODE) {
      assignments = DUTY_DRAFT_SCHEDULE.filter(a => a.user_id === userId);
    } else {
      const q = getAdminNextWeekQuery();
      const res = await fetch(`${DUTY_API}?action=get_user_availability&user_id=${userId}&${q}`);
      const json = await res.json();
      if (!json.ok) {
        toast(json.error || "Không thể tải thông tin", "error");
        return;
      }
      assignments = json.data.assignments;
    }

    const renderShiftsList = () => {
      if (assignments.length === 0) {
        return `<div class="text-gray-400 italic text-center py-4 text-xs">Chưa có ca trực nào được phân công.</div>`;
      }

      return `
        <div class="space-y-2 max-h-[200px] overflow-y-auto border rounded-xl p-3 bg-gray-50">
          ${assignments.map(asg => {
            const shiftLbl = shiftLabel(asg.shift);
            return `
              <div class="flex items-center justify-between p-2 bg-white rounded-lg border shadow-sm text-xs">
                <span class="font-semibold text-gray-700">${asg.day} - ${shiftLbl} (${asg.type === 'rachoi' ? '0.5' : '1.0'} điểm)</span>
                <button type="button" class="text-red-500 hover:text-red-700 font-bold px-2 py-1" onclick="deleteSingleAssignment(${userId}, '${asg.day}', '${asg.shift}')">
                  Xóa
                </button>
              </div>
            `;
          }).join('')}
        </div>
      `;
    };

    const html = `
      <div class="space-y-4 text-sm text-gray-700">
        <div>
          <h4 class="font-bold text-gray-800 text-base mb-1">Chỉnh sửa ca trực: ${name}</h4>
          <p class="text-xs text-gray-500">Quản lý và cập nhật ca trực hiện tại</p>
        </div>

        <div class="space-y-2">
          <label class="block text-xs font-semibold text-gray-500 uppercase">Danh sách ca trực hiện tại</label>
          <div id="editShiftsListContainer">
            ${renderShiftsList()}
          </div>
        </div>

        <div class="border-t pt-3 space-y-3">
          <label class="block text-xs font-semibold text-gray-500 uppercase">Thêm nhanh ca trực mới</label>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <select id="editAddShiftDay" class="w-full px-3 py-1.5 border rounded-lg text-xs">
                <option value="T2">Thứ 2</option>
                <option value="T3">Thứ 3</option>
                <option value="T4">Thứ 4</option>
                <option value="T5">Thứ 5</option>
                <option value="T6">Thứ 6</option>
              </select>
            </div>
            <div>
              <select id="editAddShiftType" class="w-full px-3 py-1.5 border rounded-lg text-xs">
                <option value="sang">Sáng</option>
                <option value="chieu">Chiều</option>
                <option value="rachoi_s">Ra chơi Sáng</option>
                <option value="rachoi_c">Ra chơi Chiều</option>
              </select>
            </div>
          </div>
          <button type="button" id="editAddShiftBtn" class="w-full py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-semibold">
            Thêm ca trực
          </button>
        </div>

        <div class="flex justify-end pt-3 border-t">
          <button type="button" class="px-4 py-2 border rounded-lg hover:bg-gray-50 text-xs" onclick="closeModal()">Đóng</button>
        </div>
      </div>
    `;

    modal(html, "Chỉnh sửa ca trực", "medium");

    // Click handler for Add
    document.getElementById("editAddShiftBtn").onclick = async () => {
      const day = document.getElementById("editAddShiftDay").value;
      const shift = document.getElementById("editAddShiftType").value;

      if (DUTY_DRAFT_MODE) {
        const cntInTarget = DUTY_DRAFT_SCHEDULE.filter(a => a.day === day && a.shift === shift).length;
        if (cntInTarget >= 3) {
          toast("Ca này đã đủ 3 người", "warning");
          return;
        }

        const isAlreadyInTarget = DUTY_DRAFT_SCHEDULE.some(a => a.user_id === userId && a.day === day && a.shift === shift);
        if (isAlreadyInTarget) {
          toast("Người này đã có trong ca đích", "warning");
          return;
        }

        let toType = "thuong";
        let toScore = 1.0;
        if (shift === "rachoi_s" || shift === "rachoi_c") {
          toType = "rachoi";
          toScore = 0.5;
        }

        let currentScore = DUTY_DRAFT_SCHEDULE.filter(a => a.user_id === userId).reduce((sum, a) => sum + Number(a.score || 0), 0);
        if (currentScore + toScore > 5.0 + 1e-9) {
          toast("Người này sẽ vượt quá 5 điểm/tuần", "warning");
          return;
        }

        DUTY_DRAFT_SCHEDULE.push({
          user_id: userId,
          fullname: name,
          day: day,
          shift: shift,
          type: toType,
          score: toScore
        });

        toast("✅ Đã thêm ca trực (Nháp) thành công", "success");
        renderAdminDutyView(DUTY_DRAFT_SCHEDULE);
        closeModal();
        setTimeout(() => editShifts(userId), 200);
        return;
      }

      const q = getAdminNextWeekQuery();

      try {
        const res = await fetch(`${DUTY_API}?action=add_assignment&${q}`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ user_id: userId, day, shift })
        });
        const jsonAdd = await res.json();
        if (!jsonAdd.ok) {
          toast(jsonAdd.error || "Lỗi thêm ca trực", "error");
          return;
        }

        toast("✅ Đã thêm ca trực thành công", "success");
        loadDutyMembers();
        loadDutyViewSchedule(VIEW_WEEK_OFFSET);
        closeModal();
        // Mở lại modal để xem thay đổi mới
        setTimeout(() => editShifts(userId), 200);
      } catch (e) {
        console.error(e);
        toast("❌ Lỗi hệ thống", "error");
      }
    };

    // Helper window delete function to call inside modal
    window.deleteSingleAssignment = async function(uid, day, shift) {
      if (DUTY_DRAFT_MODE) {
        const idx = DUTY_DRAFT_SCHEDULE.findIndex(a => a.user_id === uid && a.day === day && a.shift === shift);
        if (idx !== -1) {
          DUTY_DRAFT_SCHEDULE.splice(idx, 1);
          toast("Đã xóa ca trực (Nháp)", "success");
        }
        renderAdminDutyView(DUTY_DRAFT_SCHEDULE);
        closeModal();
        setTimeout(() => editShifts(uid), 200);
        return;
      }

      const q = getAdminNextWeekQuery();
      try {
        const res = await fetch(`${DUTY_API}?action=delete_assignment&${q}`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ user_id: uid, day, shift })
        });
        const jsonDel = await res.json();
        if (!jsonDel.ok) {
          toast(jsonDel.error || "Lỗi xóa ca trực", "error");
          return;
        }

        toast("Đã xóa ca trực", "success");
        loadDutyMembers();
        loadDutyViewSchedule(VIEW_WEEK_OFFSET);
        closeModal();
        setTimeout(() => editShifts(uid), 200);
      } catch (e) {
        console.error(e);
        toast("❌ Lỗi hệ thống", "error");
      }
    };

  } catch (e) {
    console.error(e);
    toast("Lỗi hệ thống", "error");
  }
}

window.clearUserAssignments = function(userId, name) {
  modal(`
    <div class="text-center space-y-4">
      <p class="text-gray-700">
        Bạn có chắc chắn muốn <b>xóa toàn bộ</b> phân công lịch trực của thành viên <b>${name}</b> trong tuần này không?
      </p>

      <div class="flex justify-center gap-3">
        <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
        <button id="confirmClearUserBtn" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">
          Xác nhận xóa
        </button>
      </div>
    </div>
  `, "Xác nhận xóa lịch trực", "small");

  document.getElementById("confirmClearUserBtn").onclick = async () => {
    try {
      const q = getAdminNextWeekQuery();
      const res = await fetch(`${DUTY_API}?action=delete_user_assignments&user_id=${userId}&${q}`);
      const json = await res.json();
      if (!json.ok) {
        toast(json.error || "Không thể xóa lịch trực", "error");
        return;
      }

      toast(`✅ Đã xóa toàn bộ lịch trực của ${name}`, "success");
      closeModal();
      loadDutyMembers();
      loadDutyViewSchedule(VIEW_WEEK_OFFSET);
    } catch (e) {
      console.error(e);
      toast("❌ Lỗi hệ thống", "error");
    }
  };
}


/* ==========================
   LOAD FREE STATS (ASSIGN)
========================== */

async function loadFreeStats() {
  try {
    const q = getAdminNextWeekQuery();
    const res = await fetch(
      `${DUTY_API}?action=get_free_stats&${q}`
    );
    const json = await res.json();
    if (!json.ok) return;

    const box = document.getElementById("dutyFreeStats");
    if (!box) return;

    // map stats[day][shift]
    const stats = {};
    json.data.forEach(i => {
      if (!stats[i.day]) stats[i.day] = {};
      stats[i.day][i.shift] = Number(i.free || 0);
    });

    const days = [
      { d: 2, label: "T2" },
      { d: 3, label: "T3" },
      { d: 4, label: "T4" },
      { d: 5, label: "T5" },
      { d: 6, label: "T6" }
    ];

    box.innerHTML = "";

    const renderCard = (title, free, isBreak = false) => {
      let bg = "bg-red-50 border-red-200 text-red-700";
      if (free >= 2) bg = "bg-green-50 border-green-200 text-green-700";
      else if (free === 1) bg = "bg-yellow-50 border-yellow-200 text-yellow-700";

      const subText = isBreak
        ? `${free} người có thể phân công`
        : `${free} rảnh`;

      return `
    <div class="p-4 rounded-xl border ${bg}">
      <div class="font-semibold">${title}</div>
      <div class="text-sm mt-1">${subText}</div>
    </div>
  `;
    };


    /* ========= CỘT 1: SÁNG ========= */
    days.forEach(x => {
      const free = stats[x.d]?.morning ?? 0;
      box.insertAdjacentHTML("beforeend",
        renderCard(`${x.label} Sáng`, free)
      );
    });

    /* ========= CỘT 2: CHIỀU ========= */
    days.forEach(x => {
      const free = stats[x.d]?.afternoon ?? 0;
      box.insertAdjacentHTML("beforeend",
        renderCard(`${x.label} Chiều`, free)
      );
    });

    /* ========= CỘT 3: RA CHƠI ========= */
    days.forEach(x => {
      const free = stats[x.d]?.break ?? 0;
      box.insertAdjacentHTML(
        "beforeend",
        renderCard(`${x.label} Ra chơi`, free, true)
      );
    });


  } catch (e) {
    console.error("[FREE STATS ERROR]", e);
  }
}

async function loadViewSchedule() {
  try {
    const q = getAdminNextWeekQuery();
    const res = await fetch(
      `${DUTY_API}?action=get_week_schedule&${q}`
    );
    fetch(`${DUTY_API}?action=get_week_schedule&${q}`);
    const json = await res.json();
    if (!json.ok) return;

    const tbody = document.getElementById("dutyViewTable");
    if (!tbody) return;

    /* =========================
       SET DATE HEADER (T2–T6)
    ========================= */
    const now = new Date();
    const monday = new Date(now);
    const diff = now.getDay() === 0 ? -6 : 1 - now.getDay();
    monday.setDate(now.getDate() + diff + 7);


    for (let d = 2; d <= 6; d++) {
      const date = new Date(monday);
      date.setDate(monday.getDate() + (d - 2));
      const dd = String(date.getDate()).padStart(2, "0");
      const mm = String(date.getMonth() + 1).padStart(2, "0");

      const el = document.getElementById(`date-${d}`);
      if (el) el.textContent = `${dd}/${mm}`;
    }

    const shifts = [
      { key: "sang", label: "Sáng", badge: "text-blue-700 bg-blue-100/70 border-blue-200" },
      { key: "chieu", label: "Chiều", badge: "text-purple-700 bg-purple-100/70 border-purple-200" },
      { key: "rachoi_s", label: "Ra chơi S", badge: "text-orange-700 bg-orange-100/70 border-orange-200" },
      { key: "rachoi_c", label: "Ra chơi C", badge: "text-orange-700 bg-orange-100/70 border-orange-200" }
    ];


    // map[shift][day] = [{name,type}]
    const map = {};

    json.data.forEach(i => {
      const shiftKey = i.shift;   // sang | chieu | rachoi_s | rachoi_c
      const dayKey = i.day;       // T2 | T3 | T4 | T5 | T6

      if (!map[shiftKey]) map[shiftKey] = {};
      if (!map[shiftKey][dayKey]) map[shiftKey][dayKey] = [];

      map[shiftKey][dayKey].push({
        user_id: i.user_id,
        name: i.fullname,
        type: i.type
      });
    });


    tbody.innerHTML = "";

    shifts.forEach(s => {
      let row = `
    <tr>
<td class="px-3 py-3 text-center border border-gray-200 bg-white align-middle">
  <div class="inline-flex items-center justify-center w-full">
    <div class="px-4 py-2 rounded-xl border shadow-sm ring-1 ring-black/5 font-extrabold tracking-wide ${s.badge}">
      ${s.label}
    </div>
  </div>
</td>

  `;

      ["T2", "T3", "T4", "T5", "T6"].forEach(d => {
        const list = map[s.key]?.[d] || [];

        row += `
      <td class="px-2 py-2 border border-gray-200 align-top">
        <div
class="duty-cell min-h-[84px] rounded-xl border border-gray-200 bg-gray-50/70 p-2 shadow-sm ring-1 ring-black/5 space-y-1.5"
          data-day="${d}"
          data-shift="${s.key}"
        >
    `;

        if (list.length === 0) {
          row += `
        <div class="duty-empty text-center text-gray-400 text-xs py-6">
          Chưa phân công
        </div>
      `;
        } else {
          list.forEach(u => {
            const bg = getDutyBgByShift(s.key);
            row += `
          <div
class="duty-item px-2.5 py-1.5 rounded-lg border text-[12.5px] cursor-move ${bg}"
            draggable="true"
            data-user-id="${u.user_id}"
            data-day="${d}"
            data-shift="${s.key}"
          >
            <div class="min-w-0">
<span class="duty-select-name truncate block cursor-pointer select-none">
  ${u.name}
</span>
            </div>

          </div>
        `;
          });
        }

        row += `
        </div>
      </td>
    `;
      });

      row += `</tr>`;
      tbody.insertAdjacentHTML("beforeend", row);
    });


  } catch (e) {
    console.error("[VIEW SCHEDULE ERROR]", e);
  }
}

let draggedItem = null;

document.addEventListener("dragstart", (e) => {
  showDutyTrash();

  const item = e.target.closest(".duty-item");
  if (!item) return;

  draggedItem = item;
  e.dataTransfer.effectAllowed = "copyMove";

  // luôn set text/plain để Firefox đỡ kén
  try {
    e.dataTransfer.setData("text/plain", item.dataset.userId || "");
  } catch { }

  // payload chuẩn
  e.dataTransfer.setData("user_id", String(item.dataset.userId || ""));
  e.dataTransfer.setData("from_day", String(item.dataset.day || ""));
  e.dataTransfer.setData("from_shift", String(item.dataset.shift || ""));

  // giữ Shift để copy
  e.dataTransfer.setData("copy", e.shiftKey ? "1" : "0");

  setTimeout(() => item.classList.add("opacity-50"), 0);
});


document.addEventListener("dragend", () => {
  if (draggedItem) {
    draggedItem.classList.remove("opacity-50");
    draggedItem = null;
  }
  document.querySelectorAll(".duty-cell.drag-over")
    .forEach(el => el.classList.remove("drag-over"));

  hideDutyTrash();

});

document.addEventListener("dragenter", (e) => {
  const cell = e.target.closest(".duty-cell");
  if (cell) cell.classList.add("drag-over");
});

document.addEventListener("dragleave", (e) => {
  const cell = e.target.closest(".duty-cell");
  if (cell) cell.classList.remove("drag-over");
});

// =====================
// DROP ZONE
// =====================
document.addEventListener("dragover", (e) => {
  const cell = e.target.closest(".duty-cell");
  if (cell) e.preventDefault();
});

document.addEventListener("drop", (e) => {
  const cell = e.target.closest(".duty-cell");
  if (!cell || !draggedItem) return;

  e.preventDefault();
  handleDrop(draggedItem, cell, e);   // ✅ thêm e

});

async function handleDrop(item, cell, evt) {
  const userId = Number(item.dataset.userId || 0);

  const fromCell = item.closest(".duty-cell");

  const fromDay = item.dataset.day || fromCell?.dataset.day;
  const fromShift = item.dataset.shift || fromCell?.dataset.shift;

  const toDay = cell.dataset.day;
  const toShift = cell.dataset.shift;

  if (!userId || !fromDay || !fromShift || !toDay || !toShift) {
    toast("Thiếu dữ liệu kéo thả (from/to)", "error");
    console.log({ userId, fromDay, fromShift, toDay, toShift, item, fromCell, cell });
    return;
  }

  // đọc copy flag (đã set trong dragstart)
  let isCopy = false;
  try {
    isCopy = (evt?.dataTransfer?.getData("copy") === "1");
  } catch {
    isCopy = false;
  }

  // nếu thả vào đúng ô cũ, bỏ qua
  if (!isCopy && fromDay === toDay && fromShift === toShift) return;

  // limit client-side (backend vẫn check lại)
  const maxPerCell = 3;
  if (cell.querySelectorAll(".duty-item").length >= maxPerCell) {
    toast("Ca này đã đủ 3 người", "warning");
    return;
  }

  if (DUTY_DRAFT_MODE) {
    const member = DUTY_MEMBERS_CACHE.find(m => m.id === userId) || {};
    const name = member.fullname || member.username || "Không tên";

    const cntInTarget = DUTY_DRAFT_SCHEDULE.filter(a => a.day === toDay && a.shift === toShift).length;
    if (cntInTarget >= 3) {
      toast("Ca này đã đủ 3 người", "warning");
      return;
    }

    const isAlreadyInTarget = DUTY_DRAFT_SCHEDULE.some(a => a.user_id === userId && a.day === toDay && a.shift === toShift);
    if (isAlreadyInTarget) {
      toast("Người này đã có trong ca đích", "warning");
      return;
    }

    let toType = "thuong";
    let toScore = 1.0;
    if (toShift === "rachoi_s" || toShift === "rachoi_c") {
      toType = "rachoi";
      toScore = 0.5;
    }

    let currentScore = DUTY_DRAFT_SCHEDULE.filter(a => a.user_id === userId).reduce((sum, a) => sum + Number(a.score || 0), 0);

    let fromScore = 0;
    const fromIndex = DUTY_DRAFT_SCHEDULE.findIndex(a => a.user_id === userId && a.day === fromDay && a.shift === fromShift);
    if (fromIndex !== -1) {
      fromScore = Number(DUTY_DRAFT_SCHEDULE[fromIndex].score || 0);
    }

    const afterScore = isCopy ? (currentScore + toScore) : (currentScore - fromScore + toScore);
    if (afterScore > 5.0 + 1e-9) {
      toast("Người này sẽ vượt quá 5 điểm/tuần", "warning");
      return;
    }

    if (isCopy) {
      DUTY_DRAFT_SCHEDULE.push({
        user_id: userId,
        fullname: name,
        day: toDay,
        shift: toShift,
        type: toType,
        score: toScore
      });
      toast("Đã nhân đôi ca trực (Nháp)", "success");
    } else {
      if (fromIndex !== -1) {
        DUTY_DRAFT_SCHEDULE[fromIndex].day = toDay;
        DUTY_DRAFT_SCHEDULE[fromIndex].shift = toShift;
        DUTY_DRAFT_SCHEDULE[fromIndex].type = toType;
        DUTY_DRAFT_SCHEDULE[fromIndex].score = toScore;
        toast("Đã cập nhật lịch trực (Nháp)", "success");
      }
    }

    renderAdminDutyView(DUTY_DRAFT_SCHEDULE);
    return;
  }

  const q = getAdminNextWeekQuery();

  const res = await fetch(`${DUTY_API}?action=move_assignment&${q}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      user_id: userId,
      from_day: fromDay,
      from_shift: fromShift,
      to_day: toDay,
      to_shift: toShift,
      copy: isCopy
    })
  });

  const json = await res.json();

  if (!json.ok) {
    toast(json.error || "Không thể đổi ca", "error");
    return;
  }

  // ✅ SUCCESS: nếu item đang selected và bạn MOVE thì cập nhật key sang vị trí mới
  if (!isCopy) {
    const oldKey = dutyKey(userId, fromDay, fromShift);
    if (DUTY_BULK_SELECTED.has(oldKey)) {
      DUTY_BULK_SELECTED.delete(oldKey);

      const newKey = dutyKey(userId, toDay, toShift);
      DUTY_BULK_SELECTED.set(newKey, { user_id: userId, day: toDay, shift: toShift });

      // giữ trạng thái highlight
      markDutyItemSelected(item, true);
      updateDutyBulkBar();
    }
  }

  // UI update
  cell.querySelector(".duty-empty")?.remove();

  if (isCopy) {
    // COPY: clone node, giữ node cũ
    const clone = item.cloneNode(true);

    // update style theo shift mới
    clone.classList.remove(
      "bg-green-100", "border-green-300", "text-green-800",
      "bg-orange-100", "border-orange-300", "text-orange-800",
      "opacity-50"
    );
    clone.classList.add(...getDutyBgByShift(toShift).split(" "));

    // update dataset để kéo tiếp cho đúng
    clone.dataset.day = toDay;
    clone.dataset.shift = toShift;

    cell.appendChild(clone);
  } else {
    // MOVE: append node thật
    cell.appendChild(item);

    item.classList.remove(
      "bg-green-100", "border-green-300", "text-green-800",
      "bg-orange-100", "border-orange-300", "text-orange-800",
      "opacity-50"
    );
    item.classList.add(...getDutyBgByShift(toShift).split(" "));

    item.dataset.day = toDay;
    item.dataset.shift = toShift;

    // nếu ô cũ trống → trả placeholder
    if (fromCell && fromCell.querySelectorAll(".duty-item").length === 0) {
      fromCell.insertAdjacentHTML("beforeend", `
        <div class="duty-empty text-center text-gray-400 text-xs py-4">
          Chưa phân công
        </div>
      `);
    }
  }


  toast(isCopy ? "Đã nhân đôi ca trực" : "Đã cập nhật lịch trực", "success");

}


function fmtDDMM(dateStr) {
  // dateStr: YYYY-MM-DD
  if (!dateStr) return "--/--";
  const d = new Date(dateStr + "T00:00:00");
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  return `${dd}/${mm}`;
}
function fmtDDMMYYYY(dateStr) {
  if (!dateStr) return "--/--/----";
  const d = new Date(dateStr + "T00:00:00");
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = d.getFullYear();
  return `${dd}/${mm}/${yyyy}`;
}

function addDays(yyyy_mm_dd, days) {
  const d = new Date(yyyy_mm_dd + "T00:00:00");
  d.setDate(d.getDate() + days);
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return `${yyyy}-${mm}-${dd}`;
}

function updateWeekHeader(week) {
  const viewWrap = document.querySelector('[data-admin-view="view"]');
  const rangeEl = viewWrap?.querySelector("#dutyWeekRangeAdmin"); // <-- đổi id ở đây
  const headerRangeEl = document.getElementById("dutyWeekRange");

  if (!week || !week.week_start) return;

  const start = week.week_start;
  const end = week.week_end;

  DUTY_CURRENT_WEEK_START = start;
  DUTY_CURRENT_WEEK_END = end;

  if (rangeEl) {
    rangeEl.textContent = `${fmtDDMMYYYY(start)} → ${fmtDDMMYYYY(end)}`;
    rangeEl.dataset.weekStart = start;
    rangeEl.dataset.weekEnd = end;
  }
  
  if (headerRangeEl) {
    headerRangeEl.textContent = `${fmtDDMM(start)} - ${fmtDDMM(end)}`;
    headerRangeEl.dataset.weekStart = start;
    headerRangeEl.dataset.weekEnd = end;
  }

  updateFilterShiftButtonLabel();

  for (let d = 2; d <= 6; d++) {
    const el = document.getElementById(`date-${d}`);
    if (!el) continue;
    if (week.dates && week.dates[d]) el.textContent = week.dates[d];
  }
}


let VIEW_WEEK_OFFSET = 1;

async function loadDutyViewSchedule(offset = VIEW_WEEK_OFFSET) {
  VIEW_WEEK_OFFSET = offset;

  // Tải ma trận đăng ký rảnh/học
  try {
    const q = `offset=${offset}`;
    const matrixRes = await fetch(`${DUTY_API}?action=get_week_availability_matrix&${q}`);
    const matrixJson = await matrixRes.json();
    if (matrixJson.ok) {
      DUTY_AVAILABILITY_MATRIX = matrixJson.data || {};
    }
  } catch (err) {
    console.error("[LOAD AVAILABILITY MATRIX ERROR]", err);
  }

  // meta tuần
  const metaRes = await fetch(`${DUTY_API}?action=get_week_meta&offset=${offset}`, {
    headers: { "Accept": "application/json" }
  });
  const metaJson = await metaRes.json();
  if (!metaRes.ok || !metaJson.ok) return;

  const week = metaJson.data;
  updateWeekHeader(week);

  // schedule tuần
  const res = await fetch(`${DUTY_API}?action=get_week_schedule&offset=${offset}`, {
    headers: { "Accept": "application/json" }
  });
  const json = await res.json();
  if (!res.ok || !json.ok) return;

  const rows = Array.isArray(json.data) ? json.data : (json.data?.rows || []);
  renderAdminDutyView(rows);

  // Đồng bộ hóa thống kê và danh sách thành viên theo tuần được chọn
  loadAdminOverview();
}

// events
document.getElementById("btnWeekPrev")?.addEventListener("click", () => loadDutyViewSchedule(VIEW_WEEK_OFFSET - 1));
document.getElementById("btnWeekThis")?.addEventListener("click", () => loadDutyViewSchedule(0));
document.getElementById("btnWeekNext")?.addEventListener("click", () => loadDutyViewSchedule(VIEW_WEEK_OFFSET + 1));

document.getElementById("btnHeaderWeekPrev")?.addEventListener("click", () => loadDutyViewSchedule(VIEW_WEEK_OFFSET - 1));
document.getElementById("btnHeaderWeekThis")?.addEventListener("click", () => loadDutyViewSchedule(0));
document.getElementById("btnHeaderWeekNext")?.addEventListener("click", () => loadDutyViewSchedule(VIEW_WEEK_OFFSET + 1));

function renderAdminDutyView(rows = []) {
  DUTY_CURRENT_SCHEDULE = rows;
  const tbody = document.getElementById("dutyViewTable");
  if (!tbody) return;

  const shifts = [
    { key: "sang", label: "Sáng", badge: "text-blue-700 bg-blue-100/70 border-blue-200" },
    { key: "chieu", label: "Chiều", badge: "text-purple-700 bg-purple-100/70 border-purple-200" },
    { key: "rachoi_s", label: "Ra chơi S", badge: "text-orange-700 bg-orange-100/70 border-orange-200" },
    { key: "rachoi_c", label: "Ra chơi C", badge: "text-orange-700 bg-orange-100/70 border-orange-200" }
  ];


  const map = {};
  (Array.isArray(rows) ? rows : []).forEach(i => {
    const shiftKey = i.shift;
    const dayKey = i.day;
    if (!map[shiftKey]) map[shiftKey] = {};
    if (!map[shiftKey][dayKey]) map[shiftKey][dayKey] = [];
    map[shiftKey][dayKey].push({
      id: Number(i.id || 0),
      user_id: i.user_id,
      name: i.fullname
    });

  });

  tbody.innerHTML = "";

  shifts.forEach(s => {
    let row = `
<tr class="align-center">
  <td class="px-2 py-3 font-medium text-center align-middle border border-gray-200 bg-gray-50">
    <div class="inline-flex items-center justify-center w-full">
      <div class="duty-shift-badge px-4 py-2 rounded-xl ${s.badge} font-extrabold whitespace-nowrap border border-gray-200 shadow-sm">
        ${s.label}
      </div>
    </div>
  </td>
`;


    ['T2', 'T3', 'T4', 'T5', 'T6'].forEach(d => {
      const list = map[s.key]?.[d] || [];

      row += `
  <td class="px-2 py-3 align-center border border-gray-200 bg-gray-50">
    <div class="duty-cell min-h-[84px] rounded-xl border border-gray-200 bg-gray-50/70 p-2 shadow-sm ring-1 ring-black/5 relative z-0"
         data-day="${d}" data-shift="${s.key}">
      <button
        type="button"
        class="duty-add absolute top-1 right-1 z-30 w-7 h-7 rounded-lg border border-gray-200 bg-white shadow-sm text-gray-700 hover:bg-white hover:shadow transition opacity-100"
        data-day="${d}"
        data-shift="${s.key}"
        title="Thêm người"
      >+</button>
`;


      if (list.length === 0) {
        row += `
          <div class="duty-empty text-center text-gray-400 text-xs py-4">
            Chưa phân công
          </div>
        `;
      } else {
        list.forEach(u => {
          const bg = getDutyBgByShift(s.key);
          row += `
            <div
              class="duty-item px-2.5 py-1.5 rounded-lg border text-[14.5px] cursor-move ${bg}"
              draggable="true"
              data-user-id="${u.user_id}"
              data-day="${d}"
              data-shift="${s.key}"
            >
<div class="min-w-0">
<span class="duty-select-name truncate block cursor-pointer select-none">${u.name}</span>
</div>


            </div>

          `;
        });
      }

      // Tìm gợi ý mờ
      let suggestHTML = '';
      if (list.length < 3) {
        const dayMap = { "T2": 2, "T3": 3, "T4": 4, "T5": 5, "T6": 6 };
        const dayNum = dayMap[d];

        const shiftMap = {
          "sang": "morning",
          "chieu": "afternoon",
          "rachoi_s": "break_morning",
          "rachoi_c": "break_afternoon"
        };
        const availShift = shiftMap[s.key];
        let studyShift = availShift;
        if (availShift === "break_morning") studyShift = "morning";
        if (availShift === "break_afternoon") studyShift = "afternoon";

        const getWeeklyScore = (userId) => {
          const schedule = DUTY_DRAFT_MODE ? DUTY_DRAFT_SCHEDULE : DUTY_CURRENT_SCHEDULE;
          return schedule
            .filter(a => Number(a.user_id) === userId)
            .reduce((sum, a) => sum + Number(a.score || (a.type === 'rachoi' ? 0.5 : 1.0)), 0);
        };

        const activeUserIdsInCell = list.map(u => Number(u.user_id));
        const suggestions = [];

        if (Array.isArray(DUTY_MEMBERS_CACHE)) {
          DUTY_MEMBERS_CACHE.forEach(u => {
            const userId = Number(u.id);
            if (activeUserIdsInCell.includes(userId)) return;

            const userMatrix = DUTY_AVAILABILITY_MATRIX[userId] || { availability: [], study: [] };
            const isFree = userMatrix.availability.some(a => a.day === dayNum && a.shift === availShift);
            const isStudying = userMatrix.study.some(s => s.day === dayNum && s.shift === studyShift);
            const score = getWeeklyScore(userId);

            if (isFree && !isStudying && score < 5.0) {
              suggestions.push({
                id: userId,
                name: u.fullname || u.username || "Không tên",
                score: score
              });
            }
          });
        }

        // Sắp xếp theo điểm tăng dần để ưu tiên người trực ít hơn
        suggestions.sort((a, b) => a.score - b.score);

        // Lấy tối đa số lượng gợi ý có thể gán (3 - list.length) hoặc tối đa 2 người
        const maxSuggestionsToShow = Math.min(3 - list.length, 2);
        const topSuggestions = suggestions.slice(0, maxSuggestionsToShow);

        if (topSuggestions.length > 0) {
          suggestHTML += `<div class="duty-suggestions-wrapper mt-2 pt-2 border-t border-gray-200/50 space-y-1">`;
          topSuggestions.forEach(u => {
            suggestHTML += `
              <div class="duty-item-suggest px-2 py-1 rounded-lg border border-dashed border-blue-300 bg-blue-50/50 text-blue-800 text-[11px] opacity-60 flex items-center justify-between gap-1 select-none transition duration-150 hover:opacity-100 hover:bg-blue-100/50 hover:border-blue-400"
                   data-user-id="${u.id}" data-day="${d}" data-shift="${s.key}" data-name="${u.name}">
                <span class="truncate block">💡 ${u.name} (${u.score})</span>
                <button type="button" class="duty-approve-btn text-emerald-600 hover:text-emerald-800 font-extrabold text-[12px] px-1 py-0.2 rounded hover:bg-emerald-50 transition" title="Duyệt ca trực này">
                  ✓
                </button>
              </div>
            `;
          });
          suggestHTML += `</div>`;
        }
      }

      row += suggestHTML;

      row += `
          </div>
        </td>
      `;
    });

    row += `</tr>`;
    tbody.insertAdjacentHTML("beforeend", row);
  });
  function reapplyDutyBulkSelectionToDom() {
    document.querySelectorAll(".duty-item").forEach(item => {
      const user_id = Number(item.dataset.userId || 0);
      const day = item.dataset.day || "";
      const shift = item.dataset.shift || "";
      const key = dutyKey(user_id, day, shift);
      markDutyItemSelected(item, DUTY_BULK_SELECTED.has(key));
    });
    updateDutyBulkBar();
  }
  reapplyDutyBulkSelectionToDom();
  renderDutyMemberListTable();
}
document.addEventListener("click", async (e) => {
  const btnApprove = e.target.closest(".duty-approve-btn");
  if (btnApprove) {
    e.stopPropagation();
    const suggestEl = btnApprove.closest(".duty-item-suggest");
    if (!suggestEl) return;

    const userId = Number(suggestEl.dataset.userId || 0);
    const day = suggestEl.dataset.day;
    const shift = suggestEl.dataset.shift;
    const name = suggestEl.dataset.name;

    if (!userId || !day || !shift) return;

    let toType = "thuong";
    let toScore = 1.0;
    if (shift === "rachoi_s" || shift === "rachoi_c") {
      toType = "rachoi";
      toScore = 0.5;
    }

    if (DUTY_DRAFT_MODE) {
      const cntInTarget = DUTY_DRAFT_SCHEDULE.filter(a => a.day === day && a.shift === shift).length;
      if (cntInTarget >= 3) {
        toast("Ca này đã đủ 3 người", "warning");
        return;
      }

      const isAlreadyInTarget = DUTY_DRAFT_SCHEDULE.some(a => a.user_id === userId && a.day === day && a.shift === shift);
      if (isAlreadyInTarget) {
        toast("Người này đã có trong ca đích", "warning");
        return;
      }

      let currentScore = DUTY_DRAFT_SCHEDULE.filter(a => a.user_id === userId).reduce((sum, a) => sum + Number(a.score || 0), 0);
      if (currentScore + toScore > 5.0 + 1e-9) {
        toast("Người này sẽ vượt quá 5 điểm/tuần", "warning");
        return;
      }

      DUTY_DRAFT_SCHEDULE.push({
        user_id: userId,
        fullname: name,
        day: day,
        shift: shift,
        type: toType,
        score: toScore
      });

      toast(`✅ Đã duyệt ${name} vào ca trực (Nháp)`, "success");
      renderAdminDutyView(DUTY_DRAFT_SCHEDULE);
      return;
    }

    try {
      const q = getAdminNextWeekQuery();
      const res = await fetch(`${DUTY_API}?action=add_assignment&${q}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ user_id: userId, day, shift }),
      });

      const json = await res.json();
      if (!json.ok) {
        toast(json.error || "Không thể duyệt ca trực", "error");
        return;
      }

      toast(`✅ Đã duyệt ${name} vào ca trực thành công`, "success");
      loadDutyViewSchedule(VIEW_WEEK_OFFSET);
    } catch (err) {
      console.error(err);
      toast("Lỗi khi duyệt ca trực", "error");
    }
    return;
  }

  const btnClear = e.target.closest("#dutyBulkClear");
  const btnDel = e.target.closest("#dutyBulkDelete");

  if (btnClear) {
    clearDutyBulkSelection();
    return;
  }

  if (btnDel) {
    const items = Array.from(DUTY_BULK_SELECTED.values());
    if (items.length === 0) return;

    // confirm đơn giản (hoặc bạn thay bằng modal đẹp)
    if (btnDel) {
      const count = DUTY_BULK_SELECTED.size;
      if (count === 0) return;

      modal(`
    <div class="text-center space-y-4">
      <p class="text-gray-700">
        Bạn có chắc chắn muốn <b>xóa</b> ${count} ca đã chọn khỏi lịch trực?
      </p>

      <div class="flex justify-center gap-3">
        <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
        <button id="confirmDutyBulkDelete"
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">
          Xóa
        </button>
      </div>
    </div>
  `, "Xác nhận xóa", "small");

      document.getElementById("confirmDutyBulkDelete").onclick = async () => {
        // giữ style giống campaigns: click confirm rồi mới xử lý
        // (ở đây bạn có thể close trước hoặc close sau – mình close trước cho giống)
        closeModal();

        const items = Array.from(DUTY_BULK_SELECTED.values());
        if (items.length === 0) return;

        try {
          const q = getAdminNextWeekQuery();
          const res = await fetch(`${DUTY_API}?action=bulk_delete_assignments&${q}`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ items })
          });

          const json = await res.json();

          if (!json.ok) {
            toast(json.error || "Không thể xóa hàng loạt", "error");
            return;
          }

          toast(`✅ Đã xóa ${json.deleted || items.length} ca`, "success");

          clearDutyBulkSelection();
          loadDutyViewSchedule(VIEW_WEEK_OFFSET);

        } catch (err) {
          console.error(err);
          toast("Lỗi khi xóa hàng loạt", "error");
        }
      };

      return;
    }

    try {
      const q = getAdminNextWeekQuery();
      const res = await fetch(`${DUTY_API}?action=bulk_delete_assignments&${q}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ items })
      });

      const json = await res.json();

      if (!json.ok) {
        toast(json.error || "Không thể xóa hàng loạt", "error");
        return;
      }

      toast(`✅ Đã xóa ${json.deleted || items.length} ca`, "success");

      clearDutyBulkSelection();
      loadDutyViewSchedule(VIEW_WEEK_OFFSET); // reload lại bảng

    } catch (err) {
      console.error(err);
      toast("Lỗi khi xóa hàng loạt", "error");
    }
  }
});

function initDutyTrashZone() {
  const trash = document.getElementById("dutyTrash");
  if (!trash) return;

  trash.addEventListener("dragover", (e) => {
    e.preventDefault();
    trash.classList.add("ring-2", "ring-red-400");
  });

  trash.addEventListener("dragleave", () => {
    trash.classList.remove("ring-2", "ring-red-400");
  });

  trash.addEventListener("drop", async (e) => {
    e.preventDefault();
    trash.classList.remove("ring-2", "ring-red-400");

    // ✅ CAPTURE ngay lúc drop (trước await)
    const item = draggedItem;
    if (!item) return;

    const userId = Number(item.dataset.userId || 0);
    const day = item.dataset.day;
    const shift = item.dataset.shift;

    if (!userId || !day || !shift) {
      toast("Thiếu dữ liệu để xoá", "error");
      return;
    }

    if (DUTY_DRAFT_MODE) {
      const idx = DUTY_DRAFT_SCHEDULE.findIndex(a => a.user_id === userId && a.day === day && a.shift === shift);
      if (idx !== -1) {
        DUTY_DRAFT_SCHEDULE.splice(idx, 1);
        toast("Đã xoá ca trực (Nháp)", "success");
      }
      renderAdminDutyView(DUTY_DRAFT_SCHEDULE);
      hideDutyTrash();

      const key = dutyKey(userId, day, shift);
      if (DUTY_BULK_SELECTED.delete(key)) {
        updateDutyBulkBar();
      }
      return;
    }

    try {
      const q = getAdminNextWeekQuery();
      const res = await fetch(`${DUTY_API}?action=delete_assignment&${q}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ user_id: userId, day, shift })
      });

      const json = await res.json();

      if (!json.ok) {
        toast(json.error || "Không thể xoá ca", "error");
        return;
      }

      // ✅ UI update bằng item đã capture
      const parent = item.closest(".duty-cell");
      item.remove();

      if (parent && parent.querySelectorAll(".duty-item").length === 0) {
        parent.insertAdjacentHTML("beforeend", `
          <div class="duty-empty text-center text-gray-400 text-xs py-4">
            Chưa phân công
          </div>
        `);
      }

      toast("Đã xoá ca trực", "success");

      hideDutyTrash();

      const key = dutyKey(userId, day, shift);
      if (DUTY_BULK_SELECTED.delete(key)) {
        updateDutyBulkBar();
      }
      // ✅ Option: reload lại bảng cho chắc (nếu bạn muốn)
      // loadDutyViewSchedule(VIEW_WEEK_OFFSET);

    } catch (err) {
      console.error(err);
      toast("Lỗi khi xoá ca", "error");
    }
  });
}

document.addEventListener("DOMContentLoaded", initDutyTrashZone);
function showDutyTrash() {
  const t = document.getElementById("dutyTrash");
  if (!t) return;
  t.classList.remove("hidden");
  t.classList.remove("pointer-events-none");
}

function hideDutyTrash() {
  const t = document.getElementById("dutyTrash");
  if (!t) return;
  t.classList.add("hidden");
  t.classList.add("pointer-events-none");
  t.classList.remove("ring-2", "ring-red-400");
}
let DUTY_ADD_TARGET = { day: null, shift: null };

function shiftLabel(shift) {
  if (shift === "sang") return "Sáng";
  if (shift === "chieu") return "Chiều";
  if (shift === "rachoi_s") return "Ra chơi S";
  if (shift === "rachoi_c") return "Ra chơi C";
  return shift;
}

function renderDutyAddModalContent(day, shift) {
  return `
    <div class="space-y-3">

      <div class="text-sm text-gray-600">
        Ca: <span class="font-semibold text-gray-800">
          ${day} - ${shiftLabel(shift)}
        </span>
      </div>

      <input
        id="dutyAddSearch"
        class="w-full px-3 py-2 border rounded-lg"
        placeholder="Tìm theo tên / username..."
      />

      <div
        id="dutyAddList"
        class="max-h-[320px] overflow-auto border rounded-lg"
      ></div>

    </div>
  `;
}

function openDutyAddModal(day, shift) {
  DUTY_ADD_TARGET = { day, shift };

  // tạo node content (để modal() append vào body)
  const root = document.createElement("div");
  root.innerHTML = renderDutyAddModalContent(day, shift);

  // mở modal system
  modal(root, "Thêm người vào ca", "medium");

  const search = root.querySelector("#dutyAddSearch");
  const listBox = root.querySelector("#dutyAddList");

  if (!search || !listBox) return;

  const dayMap = { "T2": 2, "T3": 3, "T4": 4, "T5": 5, "T6": 6 };
  const dayNum = dayMap[day];

  const shiftMap = {
    "sang": "morning",
    "chieu": "afternoon",
    "rachoi_s": "break_morning",
    "rachoi_c": "break_afternoon"
  };
  const availShift = shiftMap[shift];
  let studyShift = availShift;
  if (availShift === "break_morning") studyShift = "morning";
  if (availShift === "break_afternoon") studyShift = "afternoon";

  const getWeeklyScore = (userId) => {
    const schedule = DUTY_DRAFT_MODE ? DUTY_DRAFT_SCHEDULE : DUTY_CURRENT_SCHEDULE;
    return schedule
      .filter(a => Number(a.user_id) === userId)
      .reduce((sum, a) => sum + Number(a.score || (a.type === 'rachoi' ? 0.5 : 1.0)), 0);
  };

  const renderList = (keyword = "") => {
    const k = (keyword || "").trim().toLowerCase();
    const items = Array.isArray(DUTY_MEMBERS_CACHE) ? DUTY_MEMBERS_CACHE : [];

    const filtered = !k
      ? items
      : items.filter((u) => {
        const name = (u.fullname || "").toLowerCase();
        const user = (u.username || "").toLowerCase();
        return name.includes(k) || user.includes(k);
      });

    const topSuggestions = [];
    const backupSuggestions = [];
    const otherMembers = [];

    filtered.forEach(u => {
      const userMatrix = DUTY_AVAILABILITY_MATRIX[u.id] || { availability: [], study: [] };
      const isFree = userMatrix.availability.some(a => a.day === dayNum && a.shift === availShift);
      const isStudying = userMatrix.study.some(s => s.day === dayNum && s.shift === studyShift);
      const score = getWeeklyScore(u.id);

      const memberData = {
        ...u,
        score,
        isFree,
        isStudying,
        userMatrix
      };

      if (isFree && !isStudying && score < 3.0) {
        topSuggestions.push(memberData);
      } else if (isFree && !isStudying && score >= 3.0 && score < 5.0) {
        backupSuggestions.push(memberData);
      } else {
        memberData.reason = score >= 5.0 
          ? "Đạt tối đa 5 ca" 
          : (isStudying ? "Trùng lịch học" : "Không đăng ký rảnh");
        otherMembers.push(memberData);
      }
    });

    const sortByScoreAndName = (a, b) => {
      if (a.score !== b.score) return a.score - b.score;
      return (a.fullname || "").localeCompare(b.fullname || "");
    };

    topSuggestions.sort(sortByScoreAndName);
    backupSuggestions.sort(sortByScoreAndName);
    otherMembers.sort((a, b) => (a.fullname || "").localeCompare(b.fullname || ""));

    const renderMemberItem = (u, groupClass = "") => {
      const name = u.fullname || u.username || "Không tên";
      const sub = u.username ? `@${u.username}` : "";
      const free = Number(u.free_count || 0);
      const score = u.score;

      // AVATAR
      const avatarHTML = u.avatar_url ? 
        `<img src="${u.avatar_url}" class="w-8 h-8 object-cover rounded-full border border-gray-200">` :
        `<div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white text-xs font-bold">${name.charAt(0).toUpperCase()}</div>`;

      // Badge điểm
      let scoreBadge = '';
      if (score >= 3.0) {
        scoreBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">${score} ca</span>`;
      } else {
        scoreBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">${score} ca</span>`;
      }

      // Nhãn phụ: lý do nếu thuộc nhóm "Thành viên khác"
      let extraLabel = '';
      if (u.reason) {
        let reasonColor = 'bg-gray-100 text-gray-600 border-gray-200';
        if (u.reason === 'Trùng lịch học') reasonColor = 'bg-red-50 text-red-700 border-red-200';
        else if (u.reason === 'Đạt tối đa 5 ca') reasonColor = 'bg-rose-50 text-rose-700 border-rose-200';
        extraLabel = `<span class="text-[10px] px-1.5 py-0.5 rounded border ${reasonColor}">${u.reason}</span>`;
      } else {
        extraLabel = `<span class="text-[10px] text-gray-500">${free} buổi rảnh</span>`;
      }

      return `
        <button
          type="button"
          class="w-full text-left px-4 py-3 border-b hover:bg-gray-50/80 duty-add-pick flex items-center gap-3 transition ${groupClass}"
          data-user-id="${u.id}"
        >
          ${avatarHTML}
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="font-semibold text-gray-800 text-sm truncate">${name}</span>
              ${scoreBadge}
            </div>
            <div class="text-xs text-gray-500 mt-0.5 flex items-center justify-between">
              <span>${sub}</span>
              ${extraLabel}
            </div>
          </div>
        </button>
      `;
    };

    let htmlContent = "";

    if (topSuggestions.length > 0) {
      htmlContent += `
        <div class="bg-emerald-50/20 p-2 border-b border-emerald-100">
          <div class="text-emerald-800 bg-emerald-100/60 px-2.5 py-1 rounded-md font-bold text-[11px] uppercase tracking-wide flex items-center gap-1.5 mb-1 select-none">
            <span>💡 Gợi ý hàng đầu</span>
            <span class="text-[10px] lowercase font-normal text-emerald-600">(${topSuggestions.length} đang rảnh & chưa đủ ca)</span>
          </div>
          <div class="divide-y divide-gray-100 bg-white rounded-lg border border-gray-100 overflow-hidden">
            ${topSuggestions.map(u => renderMemberItem(u, "bg-white")).join("")}
          </div>
        </div>
      `;
    }

    if (backupSuggestions.length > 0) {
      htmlContent += `
        <div class="bg-amber-50/10 p-2 border-b border-amber-100">
          <div class="text-amber-800 bg-amber-100/50 px-2.5 py-1 rounded-md font-bold text-[11px] uppercase tracking-wide flex items-center gap-1.5 mb-1 select-none">
            <span>⚡ Gợi ý dự phòng</span>
            <span class="text-[10px] lowercase font-normal text-amber-600">(${backupSuggestions.length} đã đủ ca nhưng rảnh)</span>
          </div>
          <div class="divide-y divide-gray-100 bg-white rounded-lg border border-gray-100 overflow-hidden">
            ${backupSuggestions.map(u => renderMemberItem(u, "bg-white")).join("")}
          </div>
        </div>
      `;
    }

    if (otherMembers.length > 0) {
      htmlContent += `
        <div class="bg-gray-50/30 p-2">
          <div class="text-gray-600 bg-gray-200/50 px-2.5 py-1 rounded-md font-bold text-[11px] uppercase tracking-wide flex items-center gap-1.5 mb-1 select-none">
            <span>Thành viên khác</span>
            <span class="text-[10px] lowercase font-normal text-gray-500">(${otherMembers.length} bận học hoặc không đăng ký rảnh)</span>
          </div>
          <div class="divide-y divide-gray-100 bg-white rounded-lg border border-gray-100 overflow-hidden">
            ${otherMembers.map(u => renderMemberItem(u, "bg-white")).join("")}
          </div>
        </div>
      `;
    }

    listBox.innerHTML = htmlContent || `<div class="p-4 text-sm text-gray-500 text-center">Không có kết quả</div>`;
  };

  // init list
  renderList("");
  setTimeout(() => search.focus(), 0);

  // search input
  search.addEventListener("input", () => {
    renderList(search.value);
  });

  // pick user
  listBox.addEventListener("click", async (e) => {
    const btn = e.target.closest(".duty-add-pick");
    if (!btn) return;

    const userId = Number(btn.dataset.userId || 0);
    if (!userId) return;

    const { day: td, shift: ts } = DUTY_ADD_TARGET;

    // client-side limit check
    const cell = document.querySelector(
      `.duty-cell[data-day="${td}"][data-shift="${ts}"]`
    );
    if (cell && cell.querySelectorAll(".duty-item").length >= 3) {
      toast("Ca này đã đủ 3 người", "warning");
      return;
    }

    let toType = "thuong";
    let toScore = 1.0;
    if (ts === "rachoi_s" || ts === "rachoi_c") {
      toType = "rachoi";
      toScore = 0.5;
    }

    if (DUTY_DRAFT_MODE) {
      const cntInTarget = DUTY_DRAFT_SCHEDULE.filter(a => a.day === td && a.shift === ts).length;
      if (cntInTarget >= 3) {
        toast("Ca này đã đủ 3 người", "warning");
        return;
      }

      const isAlreadyInTarget = DUTY_DRAFT_SCHEDULE.some(a => a.user_id === userId && a.day === td && a.shift === ts);
      if (isAlreadyInTarget) {
        toast("Người này đã có trong ca đích", "warning");
        return;
      }

      let currentScore = DUTY_DRAFT_SCHEDULE.filter(a => a.user_id === userId).reduce((sum, a) => sum + Number(a.score || 0), 0);
      if (currentScore + toScore > 5.0 + 1e-9) {
        toast("Người này sẽ vượt quá 5 điểm/tuần", "warning");
        return;
      }

      const member = DUTY_MEMBERS_CACHE.find(m => m.id === userId) || {};
      const name = member.fullname || member.username || "Không tên";

      DUTY_DRAFT_SCHEDULE.push({
        user_id: userId,
        fullname: name,
        day: td,
        shift: ts,
        type: toType,
        score: toScore
      });

      toast("✅ Đã thêm người vào ca (Nháp)", "success");
      if (typeof closeModal === "function") closeModal();
      renderAdminDutyView(DUTY_DRAFT_SCHEDULE);
      return;
    }

    try {
      const q = getAdminNextWeekQuery();

      const res = await fetch(`${DUTY_API}?action=add_assignment&${q}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ user_id: userId, day: td, shift: ts }),
      });

      const json = await res.json();
      if (!json.ok) {
        toast(json.error || "Không thể thêm người", "error");
        return;
      }

      toast("Đã thêm người vào ca", "success");

      // đóng modal system (đúng stack)
      if (typeof closeModal === "function") closeModal();

      // reload đúng tuần đang xem
      loadDutyViewSchedule(VIEW_WEEK_OFFSET);
    } catch (err) {
      console.error(err);
      toast("Lỗi khi thêm người", "error");
    }
  });
}

// click nút +
document.addEventListener("click", (e) => {
  const btn = e.target.closest(".duty-add");
  if (!btn) return;

  const day = btn.dataset.day;
  const shift = btn.dataset.shift;
  if (!day || !shift) return;

  // ✅ PHẢI GỌI OPEN MODAL, KHÔNG PHẢI render string
  openDutyAddModal(day, shift);
});

function renderDutyAddModalContent(day, shift) {
  return `
    <div class="space-y-3">

      <div class="text-sm text-gray-600">
        Ca: <span class="font-semibold text-gray-800">
          ${day} - ${shiftLabel(shift)}
        </span>
      </div>

      <input
        id="dutyAddSearch"
        class="w-full px-3 py-2 border rounded-lg"
        placeholder="Tìm theo tên / username..."
      />

      <div
        id="dutyAddList"
        class="max-h-[320px] overflow-auto border rounded-lg"
      ></div>

    </div>
  `;
}
document.addEventListener("click", (e) => {
  const nameEl = e.target.closest(".duty-select-name");
  if (!nameEl) return;

  const item = nameEl.closest(".duty-item");
  if (!item) return;

  toggleDutyBulkSelect(item);
});

function openFilterShiftModal() {
  const date2 = document.getElementById("date-2")?.textContent || "";
  const date3 = document.getElementById("date-3")?.textContent || "";
  const date4 = document.getElementById("date-4")?.textContent || "";
  const date5 = document.getElementById("date-5")?.textContent || "";
  const date6 = document.getElementById("date-6")?.textContent || "";
  const weekRangeText = document.getElementById("dutyWeekRangeAdmin")?.textContent || document.getElementById("dutyWeekRange")?.textContent || "";

  const shifts = [
    { label: "Sáng", availKey: "morning" },
    { label: "Chiều", availKey: "afternoon" },
    { label: "Ra chơi S", availKey: "break_morning" },
    { label: "Ra chơi C", availKey: "break_afternoon" }
  ];
  const days = [2, 3, 4, 5, 6];

  const root = document.createElement("div");
  
  root.innerHTML = `
    <div class="space-y-4 text-sm text-gray-700">
      <div>
        <h4 class="font-bold text-gray-800 text-base mb-1">Chọn thời gian rảnh cụ thể</h4>
        <p class="text-xs text-gray-500">Lọc ra những thành viên rảnh trong các ca được tích chọn dưới đây</p>
        <p class="text-xs text-orange-600 font-semibold mt-1">Tuần: ${weekRangeText}</p>
      </div>

      <div class="overflow-x-auto border rounded-xl bg-white shadow-sm">
        <table class="w-full text-xs text-center border-collapse">
          <thead class="bg-gray-50 border-b">
            <tr class="font-semibold text-gray-600">
              <th class="px-3 py-3 border-r text-left">Buổi / Thứ</th>
              <th class="px-3 py-3 border-r">Thứ 2<br><span class="text-[10px] text-gray-400 font-normal">${date2}</span></th>
              <th class="px-3 py-3 border-r">Thứ 3<br><span class="text-[10px] text-gray-400 font-normal">${date3}</span></th>
              <th class="px-3 py-3 border-r">Thứ 4<br><span class="text-[10px] text-gray-400 font-normal">${date4}</span></th>
              <th class="px-3 py-3 border-r">Thứ 5<br><span class="text-[10px] text-gray-400 font-normal">${date5}</span></th>
              <th class="px-3 py-3">Thứ 6<br><span class="text-[10px] text-gray-400 font-normal">${date6}</span></th>
            </tr>
          </thead>
          <tbody class="divide-y">
            ${shifts.map(s => `
              <tr>
                <td class="px-3 py-3 text-left font-semibold bg-gray-50 border-r">${s.label}</td>
                ${days.map(d => {
                  const key = `${d}-${s.availKey}`;
                  const isChecked = FILTER_SELECTED_SHIFTS.includes(key);
                  return `
                    <td class="px-2 py-3 border-r align-middle cursor-pointer hover:bg-blue-50/30 transition-all select-none suggest-filter-cell" data-key="${key}">
                      <div class="flex items-center justify-center">
                        <input type="checkbox" class="w-5 h-5 accent-blue-600 rounded cursor-pointer suggest-filter-checkbox" data-key="${key}" ${isChecked ? 'checked' : ''}>
                      </div>
                    </td>
                  `;
                }).join('')}
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>

      <div class="flex justify-between items-center pt-3 border-t">
        <button type="button" class="px-3 py-1.5 text-xs text-red-600 hover:text-red-800 font-semibold hover:bg-red-50 rounded-lg transition" id="btnClearFilterShifts">
          Xóa tất cả bộ lọc ca
        </button>
        <div class="flex gap-2">
          <button type="button" class="px-4 py-2 border rounded-lg hover:bg-gray-50 text-xs" onclick="closeModal()">Hủy</button>
          <button type="button" id="btnConfirmFilterShifts" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold shadow-sm">
            Xác nhận
          </button>
        </div>
      </div>
    </div>
  `;

  modal(root, "Bộ lọc thời gian rảnh", "medium");

  // click toggle checkbox khi click vào cell
  root.addEventListener("click", (e) => {
    const cell = e.target.closest(".suggest-filter-cell");
    if (cell && !e.target.classList.contains("suggest-filter-checkbox")) {
      const checkbox = cell.querySelector(".suggest-filter-checkbox");
      if (checkbox) {
        checkbox.checked = !checkbox.checked;
      }
    }
  });

  // Nút xóa tất cả
  root.querySelector("#btnClearFilterShifts").onclick = () => {
    root.querySelectorAll(".suggest-filter-checkbox").forEach(cb => cb.checked = false);
  };

  // Nút xác nhận
  root.querySelector("#btnConfirmFilterShifts").onclick = () => {
    const selected = [];
    root.querySelectorAll(".suggest-filter-checkbox:checked").forEach(cb => {
      selected.push(cb.dataset.key);
    });

    FILTER_SELECTED_SHIFTS = selected;

    updateFilterShiftButtonLabel();

    DUTY_MEMBER_PAGE = 1; // reset về trang 1 khi thay đổi filter ca
    closeModal();
    renderDutyMemberListTable();
  };
}

window.changeMemberPage = function(p) {
  DUTY_MEMBER_PAGE = p;
  renderDutyMemberListTable();
};

function updateFilterShiftButtonLabel() {
  const labelEl = document.getElementById("filterShiftLabel");
  if (!labelEl) return;

  let weekStr = "";
  if (DUTY_CURRENT_WEEK_START && DUTY_CURRENT_WEEK_END) {
    weekStr = ` (Tuần: ${fmtDDMM(DUTY_CURRENT_WEEK_START)} - ${fmtDDMM(DUTY_CURRENT_WEEK_END)})`;
  }

  if (FILTER_SELECTED_SHIFTS.length === 0) {
    labelEl.textContent = `Chọn ca rảnh...${weekStr}`;
    labelEl.className = "text-gray-500 text-xs sm:text-sm";
  } else {
    labelEl.textContent = `Đã chọn ${FILTER_SELECTED_SHIFTS.length} ca rảnh${weekStr}`;
    labelEl.className = "text-blue-700 font-semibold text-xs sm:text-sm";
  }
}

function updateSelectAllHeaderState() {
  const headerCb = document.getElementById("selectAllMemberTable");
  if (!headerCb) return;

  const tableCbs = document.querySelectorAll("#dutyMemberListTable .duty-member-table-checkbox");
  if (tableCbs.length === 0) {
    headerCb.checked = false;
    return;
  }

  const allChecked = Array.from(tableCbs).every(cb => cb.checked);
  headerCb.checked = allChecked;
}
