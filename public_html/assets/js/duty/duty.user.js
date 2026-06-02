const SHIFT_MAP = {
  // UI register/study
  morning: "morning",
  afternoon: "afternoon",

  // UI my-duty table
  sang: "morning",
  chieu: "afternoon",

  // break
  rachoi_s: "break_morning",
  rachoi_c: "break_afternoon",

  // nếu backend trả sẵn db shift thì giữ nguyên
  break_morning: "break_morning",
  break_afternoon: "break_afternoon",
};


const SHIFT_REVERSE_MAP = {
  break_morning: "rachoi_s",
  break_afternoon: "rachoi_c"
};


function getUserDutyItemClass(isMe, dbShift) {
  const isBreak = (dbShift === "break_morning" || dbShift === "break_afternoon");

  // bạn đang trực -> nổi bật hơn
  if (isMe) {
    return isBreak
      ? "bg-orange-100 border-orange-300 text-orange-800 ring-1 ring-orange-200 font-extrabold"
      : "bg-green-300 border-green-500 text-green-800 ring-1 ring-green-200 font-extrabold";
  }

  // người khác -> vẫn rõ nhưng nhẹ hơn
  return isBreak
    ? "bg-orange-50 border-orange-200 text-orange-700"
    : "bg-green-50 border-green-200 text-green-700";
}

function renderUserDutyCell(list = [], dbShift = "") {
  const safeList = Array.isArray(list) ? list : [];

  if (safeList.length === 0) {
    return `
      <div class="text-center text-gray-400 text-xs py-6">
        Chưa phân công
      </div>
    `;
  }

  return safeList.slice(0, 3).map(u => {
    const isMe = Number(u.user_id) === Number(CURRENT_USER_ID);
    const cls = getUserDutyItemClass(isMe, dbShift);

    const label = isMe ? "✔ Bạn trực" : (u.fullname || u.username || "Không tên");

    return `
      <div class="w-full px-3 py-2 rounded-xl border text-sm ${cls}
                   leading-snug whitespace-normal break-words">
        ${label}
      </div>
    `;
  }).join("");
}

const availabilityGrid = document.getElementById("availabilityGrid");
const root = availabilityGrid || document;

const checkboxes = availabilityGrid
  ? availabilityGrid.querySelectorAll(".duty-checkbox")
  : document.querySelectorAll(".duty-checkbox");

const btnSave = document.getElementById("btnSaveAvailability");
const hint = document.getElementById("availabilityHint");

/* ======================
   COUNT (THEO ĐIỂM)
   - Scope trong register block
   - Dedupe theo day-shift để không bị nhân đôi PC/Mobile
====================== */
function updateCount() {
  let totalScore = 0;
  const seen = new Set();

  root.querySelectorAll(".duty-checkbox:checked").forEach(cb => {
    const key = `${cb.dataset.day}-${cb.dataset.shift}`;
    if (seen.has(key)) return;
    seen.add(key);

    const shift = cb.dataset.shift;
    totalScore += (shift === "rachoi_s" || shift === "rachoi_c") ? 0.5 : 1;
  });

  // Cách 2 – chi tiết hơn
  if (hint) {
    hint.textContent = totalScore === 0
      ? "Bạn có thể không chọn buổi rảnh nào cũng được"
      : `Đã chọn: ${totalScore} điểm`;
  }
}



/* ======================
   LOAD (QUAN TRỌNG)
====================== */
async function loadAvailability() {
  try {
    const res = await fetch(`${DUTY_API}?action=get_my_availability`);
    const json = await res.json();

    if (!json.ok) return;

    const map = {};
    json.data.forEach(i => {
      const uiShift = SHIFT_REVERSE_MAP[i.shift] || i.shift;
      map[`${i.day}-${uiShift}`] = true;
    });

    checkboxes.forEach(cb => {
      const key = `${cb.dataset.day}-${cb.dataset.shift}`;
      cb.checked = !!map[key];
      // sync tất cả checkbox trùng key trong root (PC/Mobile)
      syncSameSlot(cb.dataset.day, cb.dataset.shift, cb.checked);
    });
    updateCount();
  } catch (e) {
    console.error('Load availability error', e);
  }
}

/* ======================
   SAVE
====================== */

function getSelectedAvailability() {
  const items = [];
  const seen = new Set();
  let totalScore = 0;

  root.querySelectorAll(".duty-checkbox:checked").forEach(cb => {
    const key = `${cb.dataset.day}-${cb.dataset.shift}`;
    if (seen.has(key)) return;
    seen.add(key);

    const uiShift = cb.dataset.shift;

    // tính điểm theo UI shift
    totalScore += (uiShift === "rachoi_s" || uiShift === "rachoi_c") ? 0.5 : 1;

    // build payload theo DB shift
    items.push({
      day: Number(cb.dataset.day),
      shift: SHIFT_MAP[uiShift] || uiShift
    });
  });

  return { items, totalScore };
}

if (btnSave) {
  btnSave.addEventListener("click", async () => {
    const { items, totalScore } = getSelectedAvailability();

    btnSave.disabled = true;
    btnSave.textContent = "Đang lưu...";

    try {
      const res = await fetch(`${DUTY_API}?action=save_my_availability`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ items })
      });

      const json = await res.json();

      if (!res.ok || !json.ok) {
        toast(json?.error || "Lỗi lưu dữ liệu", "error", 4000);
        return;
      }

      toast("Đã lưu đăng ký lịch rảnh thành công", "success");

    } catch (e) {
      console.error(e);
      toast("Không thể kết nối đến server", "error", 4000);
    } finally {
      btnSave.disabled = false;
      btnSave.textContent = "Lưu đăng ký";
    }
  });
}


/* ======================
   EVENTS
====================== */

function syncSameSlot(day, shift, checked) {
  root
    .querySelectorAll(`.duty-checkbox[data-day="${day}"][data-shift="${shift}"]`)
    .forEach(cb => {
      cb.checked = checked;
    });
}

root.addEventListener("change", (e) => {
  const t = e.target;
  if (!t || !t.classList || !t.classList.contains("duty-checkbox")) return;

  const day = t.dataset.day;
  const shift = t.dataset.shift;

  // sync PC <-> Mobile
  syncSameSlot(day, shift, t.checked);

  // cập nhật hint
  updateCount();
});





/* =====================================================
   WEEK CHOICE MODAL (Giữ / Sửa) - modal() chung (CÁCH A)
   - Hiện khi confirmed = 0
   - locked = true thì bỏ
===================================================== */

function openDutyWeekChoiceModal() {
  return new Promise((resolve) => {
    const wrap = document.createElement("div");
    wrap.className = "w-full";

    wrap.innerHTML = `
      <div class="p-5 border-b">
        <h3 class="text-base font-semibold text-gray-800">
          Xác nhận lịch tuần mới
        </h3>
        <p class="mt-1 text-sm text-gray-500">
          Tuần mới đã mở. Bạn muốn giữ lịch cũ hay cập nhật lịch mới?
        </p>
      </div>

      <div class="p-5 flex items-center justify-end gap-2">
        <button
          type="button"
          data-action="keep"
          class="px-4 py-2 rounded-xl border bg-white hover:bg-gray-50 text-gray-700"
        >
          Giữ lịch
        </button>

        <button
          type="button"
          data-action="edit"
          data-primary
          class="px-4 py-2 rounded-xl bg-orange-500 hover:bg-orange-600 text-white font-medium"
        >
          Sửa lịch
        </button>
      </div>
    `;

    // ✅ CÁCH A: dùng "medium"
    modal(wrap, "", "medium", { noHeader: true });

    const rootModal = MODAL_STACK.at(-1);

    let done = false;
    const finish = (val) => {
      if (done) return;
      done = true;
      resolve(val);
    };

    // đóng bằng ESC / click backdrop => resolve null để không treo await
    if (rootModal) {
      const oldCleanup = rootModal.cleanup;
      rootModal.cleanup = () => {
        oldCleanup?.();
        finish(null);
      };
    }

    wrap.querySelector('[data-action="keep"]').onclick = () => {
      finish("keep");
      closeModal();
    };

    wrap.querySelector('[data-action="edit"]').onclick = () => {
      finish("edit");
      closeModal();
    };
  });
}

async function initDutyWeekChoiceModal() {
  async function fetchChoice() {
    try {
      const res = await fetch(`${DUTY_API}?action=get_my_week_choice`);
      const json = await res.json();
      if (!json.ok) return null;
      return json.data;
    } catch (e) {
      console.error("[WEEK CHOICE]", e);
      return null;
    }
  }

  async function setChoice(choice) {
    // ... giữ nguyên như cũ
  }

  const data = await fetchChoice();
  if (!data) return;

  // admin đã xếp lịch => khóa, không hiện modal
  if (data.locked) return;

  // ────────────────────────────────────────────── THAY ĐỔI CHÍNH Ở ĐÂY
  // Chỉ hiện modal nếu CHƯA ĐĂNG KÝ LỊCH HỌC (study count = 0)
  // Không quan tâm availability nữa
  const hasStudy = await checkIfHasStudySchedule();

  // Nếu đã có lịch học → không cần hiện modal nữa
  if (hasStudy) return;

  // Nếu chưa có lịch học → hiện modal
  const choice = await openDutyWeekChoiceModal();

  if (!choice) return; // user đóng modal bằng ESC hoặc click ngoài

  const ok = await setChoice(choice);
  if (!ok) return;

  if (choice === "edit") {
    document.querySelector('.tab-btn[data-tab="register"]')?.click(); // hoặc tab lịch học nếu bạn có
    return;
  }

  // chọn keep: reload data
  loadStudySchedule?.();
  loadMyDutySchedule?.();
}

// Helper function mới: kiểm tra có lịch học chưa
async function checkIfHasStudySchedule() {
  try {
    const res = await fetch(`${DUTY_API}?action=get_my_study`);
    const json = await res.json();
    if (!json.ok) return false;
    return Array.isArray(json.data) && json.data.length > 0;
  } catch (e) {
    console.error("Check study schedule error", e);
    return false;
  }
}

// chạy boot
initDutyWeekChoiceModal();




/* =====================================================
   STUDY SCHEDULE (LỊCH HỌC)
===================================================== */

const studyGrid = document.getElementById("studyGrid");
const studyRoot = studyGrid || document;

const studyCheckboxes = studyGrid
  ? studyGrid.querySelectorAll(".study-checkbox")
  : document.querySelectorAll(".study-checkbox");

const btnSaveStudy = document.getElementById("btnSaveStudy");
const studyHint = document.getElementById("studyHint");

/* ======================
   SYNC PC <-> MOBILE (same day + shift)
====================== */
function syncStudySlot(day, shift, checked) {
  studyRoot
    .querySelectorAll(`.study-checkbox[data-day="${day}"][data-shift="${shift}"]`)
    .forEach(cb => {
      cb.checked = checked;
    });
}

/* ======================
   COUNT (DEDUPE)
====================== */
function updateStudyCount() {
  const seen = new Set();
  let count = 0;

  studyRoot.querySelectorAll(".study-checkbox:checked").forEach(cb => {
    const key = `${cb.dataset.day}-${cb.dataset.shift}`;
    if (seen.has(key)) return;
    seen.add(key);
    count += 1;
  });

  if (studyHint) {
    studyHint.textContent = count === 0
      ? "Bắt buộc chọn ít nhất 1 buổi học"
      : `Đã chọn: ${count} buổi học`;
  }
}

/* ======================
   EVENTS (ONE TIME ONLY)
====================== */
studyRoot.addEventListener("change", (e) => {
  const t = e.target;
  if (!t?.classList?.contains("study-checkbox")) return;

  syncStudySlot(t.dataset.day, t.dataset.shift, t.checked);
  updateStudyCount();
});

/* ======================
   LOAD
====================== */
async function loadStudySchedule() {
  if (!studyCheckboxes.length) return;

  try {
    const res = await fetch(`${DUTY_API}?action=get_my_study`);
    const json = await res.json();
    if (!json.ok) return;

    const map = {};
    (json.data || []).forEach(i => {
      map[`${i.day}-${i.shift}`] = true;
    });

    studyCheckboxes.forEach(cb => {
      const key = `${cb.dataset.day}-${cb.dataset.shift}`;
      const checked = !!map[key];
      cb.checked = checked;
      syncStudySlot(cb.dataset.day, cb.dataset.shift, checked);
    });

  } catch (e) {
    console.error("Load study schedule error", e);
  }

  updateStudyCount();
}
/* ======================
   INIT
====================== */
loadAvailability();
loadMyDutySchedule();
loadStudySchedule();
/* ======================
   SAVE (DEDUPE)
====================== */
if (btnSaveStudy) {
  btnSaveStudy.addEventListener("click", async () => {
    const items = [];
    const seen = new Set();

    studyRoot.querySelectorAll(".study-checkbox:checked").forEach(cb => {
      const key = `${cb.dataset.day}-${cb.dataset.shift}`;
      if (seen.has(key)) return;
      seen.add(key);

      items.push({
        day: Number(cb.dataset.day),
        shift: cb.dataset.shift
      });
    });

    if (items.length === 0) {
      toast("Vui lòng chọn ít nhất 1 buổi có lịch học.");
      return;
    }

    btnSaveStudy.disabled = true;
    btnSaveStudy.textContent = "Đang lưu...";

    try {
      const res = await fetch(`${DUTY_API}?action=save_my_study`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ items })
      });
      const json = await res.json();
      if (!res.ok || !json.ok) {
        toast(json?.error || "Lỗi lưu lịch học");
        return;
      }

      toast("Đã lưu lịch học", "success");
    } catch (e) {
      console.error(e);
      toast("Không thể kết nối server", "error");
    } finally {
      btnSaveStudy.disabled = false;
      btnSaveStudy.textContent = "💾 Lưu lịch học";
    }
  });
}


function getMyDutyBgByShift(shift) {
  if (shift === "break_morning" || shift === "break_afternoon") {
    return "bg-orange-100 border-orange-300 text-orange-800";
  }
  return "bg-green-100 border-green-300 text-green-800";
}

async function loadMyDutySchedule() {
  try {
    const res = await fetch(`${DUTY_API}?action=get_week_schedule`);
    const json = await res.json();

    const emptyEl = document.getElementById("myDutyEmpty");
    const gridWrap = document.getElementById("myDutyGridWrap");

    const mobileEmptyEl = document.getElementById("myDutyMobileEmpty");
    const mobileListEl = document.getElementById("myDutyMobileList");

    // ✅ không có lịch
    if (!json.ok || !Array.isArray(json.data) || json.data.length === 0) {
      // PC
      emptyEl?.classList.remove("hidden");
      gridWrap?.classList.add("hidden");

      // MOBILE
      mobileEmptyEl?.classList.remove("hidden");
      mobileListEl?.classList.add("hidden");
      return;
    }

    // ✅ có lịch
    emptyEl?.classList.add("hidden");
    gridWrap?.classList.remove("hidden");

    mobileEmptyEl?.classList.add("hidden");
    mobileListEl?.classList.remove("hidden");

    // ======================
    // MAP: shift -> day -> list
    // ======================
    const map = {};

    json.data.forEach(i => {
      const dbShift = SHIFT_MAP[i.shift] || i.shift;
      const dayKey = String(i.day);

      if (!map[dbShift]) map[dbShift] = {};
      if (!map[dbShift][dayKey]) map[dbShift][dayKey] = [];
      map[dbShift][dayKey].push(i);
    });

    // ======================
    // RENDER ALL CELLS (PC + MOBILE)
    // ======================
    document.querySelectorAll(".my-duty-cell").forEach(cell => {
      const day = String(cell.dataset.day);
      const uiShift = cell.dataset.shift;
      const dbShift = SHIFT_MAP[uiShift] || uiShift;

      const list = map[dbShift]?.[day] || [];

      cell.classList.add("user-duty-cell");
      cell.innerHTML = renderUserDutyCell(list, dbShift);
    });

  } catch (e) {
    console.error("[MY DUTY ERROR]", e);
  }
}



