let SELECTED_DUTY_USER_IDS = [];
let DUTY_MEMBERS_CACHE = []; // [{id, fullname, username, free_count, avatar_url}]
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

      const isRegen = btnGenerate.dataset.mode === "regenerate";

      const title = isRegen ? "Xác nhận xếp lại lịch" : "Xác nhận xếp lịch";
      const message = isRegen
        ? "⚠️ Tuần này đã có lịch. Xếp lại sẽ ghi đè toàn bộ lịch cũ. Bạn có chắc chắn không?"
        : "Bạn có chắc muốn xếp lịch trực cho tuần này không?";

      const confirmText = isRegen ? "Xếp lại lịch" : "Xếp lịch";
      const confirmClass = isRegen
        ? "bg-red-600 hover:bg-red-700"
        : "bg-blue-600 hover:bg-blue-700";

      modal(`
      <div class="text-center space-y-4">
        <p class="text-gray-700">${message}</p>

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
            class="px-4 py-2 rounded-lg text-white ${confirmClass}">
            ${confirmText}
          </button>
        </div>
      </div>
    `, title, "small");

      const confirmBtn = document.getElementById("confirmGenerateWeekBtn");
      if (!confirmBtn) return;

      confirmBtn.onclick = async () => {
        if (generatingWeek) return;
        generatingWeek = true;

        const original = confirmBtn.textContent;
        confirmBtn.disabled = true;
        confirmBtn.textContent = "Đang xếp lịch...";

        try {
          const q = getAdminNextWeekQuery();

          const res = await fetch(`${DUTY_API}?action=generate_week&${q}`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ user_ids: SELECTED_DUTY_USER_IDS })
          });

          const json = await res.json();

          if (!json.ok) {
            toast(json.error || "Xếp lịch thất bại", "error");
            confirmBtn.disabled = false;
            confirmBtn.textContent = original;
            generatingWeek = false;
            return;
          }

          closeModal();
          toast("✅ Đã xếp lịch tuần sau thành công", "success");

          loadAdminOverview();
          loadFreeStats();
          // ✅ reload đúng tuần đang xem (đừng gọi loadViewSchedule cũ)
          loadDutyViewSchedule(VIEW_WEEK_OFFSET);

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
    }
    if (tab === 'view') {
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
  const initTab = params.get('tab') || 'overview';

  showAdminTab(initTab, false);


  /* ==========================
     LOAD OVERVIEW (ADMIN)
  ========================== */

  loadAdminOverview();
  loadDutyMembers();
  loadDutyViewSchedule(1);

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

    // 2. Lấy toàn bộ lịch trực tuần này (để hiển thị mini schedule)
    const resSchedule = await fetch(`${DUTY_API}?action=get_week_schedule&${q}`);
    const jsonSchedule = await resSchedule.json();

    // Tạo map: user_id → mảng lịch trực
    const userSchedule = {};
    if (jsonSchedule.ok && Array.isArray(jsonSchedule.data)) {
      jsonSchedule.data.forEach(item => {
        const uid = Number(item.user_id);
        if (!userSchedule[uid]) userSchedule[uid] = [];
        userSchedule[uid].push({
          day: item.day,      // T2, T3...
          shift: item.shift   // sang, chieu, rachoi_s, rachoi_c
        });
      });
    }

    const box = document.getElementById("dutyMemberList");
    const countEl = document.getElementById("memberCount");
    if (!box) return;

    box.innerHTML = "";
    countEl.textContent = `(${json.data.length} người)`;

    json.data.forEach(u => {
      const uid = Number(u.id);
      const name = u.fullname || u.username || "Không tên";
      const username = u.username ? `@${u.username}` : "";
      const free = Number(u.free_count || 0);
      const schedule = userSchedule[uid] || [];

                  // === MINI LỊCH TRỰC - FONT TO HƠN (T2-S, T3-C, T4-RS) ===
            let scheduleHTML = '';
            
            if (schedule.length > 0) {
                // Sắp xếp theo thứ tự T2 → T6
                const sorted = [...schedule].sort((a, b) => {
                    return parseInt(a.day.replace('T','')) - parseInt(b.day.replace('T',''));
                });

                scheduleHTML = `<div class="flex flex-wrap gap-1.5 mt-3">`;
                
                sorted.forEach(s => {
                    let label = '';
                    let colorClass = 'emerald';

                    if (s.shift === "sang") {
                        label = "S";
                        colorClass = "emerald";     // Xanh
                    } else if (s.shift === "chieu") {
                        label = "C";
                        colorClass = "violet";      // Tím
                    } else if (s.shift === "rachoi_s") {
                        label = "RS";
                        colorClass = "amber";       // Cam
                    } else if (s.shift === "rachoi_c") {
                        label = "RC";
                        colorClass = "amber";
                    }

                    scheduleHTML += `
                        <span class="text-xs px-3 py-1 rounded font-semibold 
                                     bg-${colorClass}-100 text-${colorClass}-700 border border-${colorClass}-200">
                            ${s.day}-${label}
                        </span>`;
                });
                
                scheduleHTML += `</div>`;
            } else {
                scheduleHTML = `<div class="text-xs text-gray-400 mt-3 italic">Chưa có lịch trực</div>`;
            }

      const cardHTML = `
                <label class="group relative flex flex-col p-5 rounded-2xl border-2 transition-all duration-200 cursor-pointer
                              ${free === 0 ? 'border-red-200 bg-red-50' : 'border-gray-200 hover:border-blue-300 hover:bg-blue-50/50'}">

                    <!-- Checkbox lớn -->
                    <input type="checkbox" 
                           class="duty-member-checkbox absolute top-4 right-4 w-6 h-6 accent-blue-600 rounded-xl"
                           value="${uid}">

                    <!-- Avatar + Info -->
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 flex-shrink-0 rounded-2xl overflow-hidden border-2 border-white shadow">
                            ${u.avatar_url ?
          `<img src="${u.avatar_url}" class="w-full h-full object-cover">` :
          `<div class="w-full h-full bg-gradient-to-br from-purple-500 to-indigo-600 flex items-center justify-center text-white text-3xl font-bold">
                                    ${name.charAt(0).toUpperCase()}
                                 </div>`
        }
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-lg text-gray-800 truncate">${name}</div>
                            <div class="text-sm text-gray-500">${username}</div>
                            
                            <div class="mt-2 flex items-center gap-2">
                                <span class="text-xs font-medium px-3 py-1 rounded-full 
                                    ${free > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}">
                                    ${free} buổi rảnh
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Mini lịch trực -->
                    ${scheduleHTML}
                </label>
            `;

      box.insertAdjacentHTML("beforeend", cardHTML);
    });

    // Giữ nguyên chức năng checkbox cho việc generate week
    // (không cần thêm code vì class .duty-member-checkbox vẫn như cũ)

  } catch (e) {
    console.error("[DUTY MEMBER LIST ERROR]", e);
  }
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

  if (!week || !week.week_start) return;

  const start = week.week_start;
  const end = week.week_end;

  if (rangeEl) {
    rangeEl.textContent = `${fmtDDMMYYYY(start)} → ${fmtDDMMYYYY(end)}`;
    rangeEl.dataset.weekStart = start;
    rangeEl.dataset.weekEnd = end;
  }

  for (let d = 2; d <= 6; d++) {
    const el = document.getElementById(`date-${d}`);
    if (!el) continue;
    if (week.dates && week.dates[d]) el.textContent = week.dates[d];
  }
}


let VIEW_WEEK_OFFSET = 1;

async function loadDutyViewSchedule(offset = VIEW_WEEK_OFFSET) {
  VIEW_WEEK_OFFSET = offset;

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
}

// events
document.getElementById("btnWeekPrev")?.addEventListener("click", () => loadDutyViewSchedule(VIEW_WEEK_OFFSET - 1));
document.getElementById("btnWeekThis")?.addEventListener("click", () => loadDutyViewSchedule(0));
document.getElementById("btnWeekNext")?.addEventListener("click", () => loadDutyViewSchedule(VIEW_WEEK_OFFSET + 1));

function renderAdminDutyView(rows = []) {
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

}
document.addEventListener("click", async (e) => {
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

    if (filtered.length === 0) {
      listBox.innerHTML = `<div class="p-4 text-sm text-gray-500">Không có kết quả</div>`;
      return;
    }

    listBox.innerHTML = filtered
      .map((u) => {
        const name = u.fullname || u.username || "Không tên";
        const sub = u.username ? `@${u.username}` : "";
        const free = Number(u.free_count || 0);

        return `
          <button
            type="button"
            class="w-full text-left px-4 py-3 border-b hover:bg-gray-50 duty-add-pick"
            data-user-id="${u.id}"
          >
            <div class="font-medium text-gray-800">${name}</div>
            <div class="text-xs text-gray-500 flex items-center justify-between">
              <span>${sub}</span>
              <span>${free} buổi rảnh</span>
            </div>
          </button>
        `;
      })
      .join("");
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
