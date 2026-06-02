const STORAGE_KEY = "schedule_open_months";
let OPEN_MONTH_IDS = new Set();
let CURRENT_WEEK_DATE = new Date();

function isExpired(ev) {
  const now = new Date();

  // ưu tiên end_date, nếu không có thì dùng start_date
  const end = ev.end
    ? new Date(ev.end)
    : new Date(ev.start);

  return end < now;
}

(() => {
  const saved = localStorage.getItem(STORAGE_KEY);
  if (saved) {
    try {
      OPEN_MONTH_IDS = new Set(JSON.parse(saved));
    } catch {
      OPEN_MONTH_IDS = new Set();
    }
  }
})();

document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("btnPrevWeek")?.addEventListener("click", () => {
    CURRENT_WEEK_DATE.setDate(CURRENT_WEEK_DATE.getDate() - 7);
    loadScheduleByWeek(new Date(CURRENT_WEEK_DATE));
  });

  document.getElementById("btnNextWeek")?.addEventListener("click", () => {
    CURRENT_WEEK_DATE.setDate(CURRENT_WEEK_DATE.getDate() + 7);
    loadScheduleByWeek(new Date(CURRENT_WEEK_DATE));
  });

  const params = new URLSearchParams(window.location.search);
  let view = params.get("view");

  if (!view) {
    view = "list";
    const url = new URL(window.location.href);
    url.searchParams.set("view", "list");
    history.replaceState({ view: "list" }, "", url);
  }

  activateView(view, false);


  document.querySelectorAll(".view-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      activateView(btn.dataset.view);
    });
  });
  updateApproveBadge();
  loadScheduleByMonth();
  updateMyPendingBadge();

});

function activateView(view, push = true) {
  const listBox = document.getElementById("listBox");
  const calendarBox = document.getElementById("calendarBox");
  const weekBox = document.getElementById("weekListBox");
  const approveBox = document.getElementById("approveBox");
  const myPendingBox = document.getElementById("myPendingBox");

  // reset active
  document.querySelectorAll(".view-btn")
    .forEach(b => b.classList.remove("active"));

  document
    .querySelector(`.view-btn[data-view="${view}"]`)
    ?.classList.add("active");

  // Ẩn tất cả
  [listBox, calendarBox, weekBox, approveBox, myPendingBox]
    .forEach(el => el?.classList.add("hidden"));

  // Hiện đúng tab
  if (view === "list") {
    listBox?.classList.remove("hidden");
    loadScheduleByMonth();
  }

  if (view === "week") {
    weekBox?.classList.remove("hidden");
    loadScheduleByWeek(new Date());
  }

  if (view === "month") {
    calendarBox?.classList.remove("hidden");
    initMonthCalendar(); // ✅ chỉ init, không then refresh nữa
  }




  if (view === "approve") {
    approveBox?.classList.remove("hidden");
    loadPendingSchedules();
  }

  if (view === "my_pending") {
    myPendingBox?.classList.remove("hidden");
    loadMyPendingSchedules();
  }

  if (push) {
    const url = new URL(window.location.href);
    url.searchParams.set("view", view);
    history.pushState({ view }, "", url);
  }
}

let FCALENDAR = null;
let CALENDAR_READY = false;

function fmtDTLocal(d) {
  // yyyy-mm-dd HH:ii (cho flatpickr / input)
  const pad = (n) => String(n).padStart(2, "0");
  const y = d.getFullYear();
  const m = pad(d.getMonth() + 1);
  const day = pad(d.getDate());
  const hh = pad(d.getHours());
  const mm = pad(d.getMinutes());
  return `${y}-${m}-${day} ${hh}:${mm}`;
}

function toIsoLocal(v) {
  if (!v) return null;
  let s = String(v).trim();

  // đã ISO có T rồi thì giữ nguyên
  if (/^\d{4}-\d{2}-\d{2}T/.test(s)) return s;

  // dạng "YYYY-MM-DD HH:mm" hoặc "YYYY-MM-DD HH:mm:ss"
  if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) {
    if (s.length === 16) s += ":00";       // thêm seconds
    return s.replace(" ", "T");            // đổi sang ISO local
  }

  // date-only "YYYY-MM-DD"
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
    return s + "T00:00:00";
  }

  return s;
}

function parseDT(v) {
  const iso = toIsoLocal(v);
  if (!iso) return null;
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d;
}

function sameDateOnly(a, b) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function toIsoLocal(v) {
  if (!v) return null;
  let s = String(v).trim();

  // đã ISO có T rồi thì giữ nguyên
  if (/^\d{4}-\d{2}-\d{2}T/.test(s)) return s;

  // dạng "YYYY-MM-DD HH:mm" hoặc "YYYY-MM-DD HH:mm:ss"
  if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) {
    if (s.length === 16) s += ":00";       // thêm seconds
    return s.replace(" ", "T");            // đổi sang ISO local
  }

  // date-only "YYYY-MM-DD"
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
    return s + "T00:00:00";
  }

  return s;
}

function parseDT(v) {
  const iso = toIsoLocal(v);
  if (!iso) return null;
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d;
}

function sameDateOnly(a, b) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function fixIso(v) {
  if (!v) return null;
  v = String(v).trim();

  // "YYYY-MM-DD HH:mm" => "YYYY-MM-DDTHH:mm:00"
  if (v.includes(" ") && !v.includes("T")) v = v.replace(" ", "T");
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(v)) v += ":00";

  return v;
}

function sameDateOnly(a, b) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function mapEventsForCalendar(events = [], opts = {}) {
  const mode = opts.mode || "full"; // "month" | "full"

  return events.map(ev => {
    const startRaw = ev.start || ev.start_date;
    const endRaw = ev.end || ev.end_date || null;

    const startStr = fixIso(startRaw);
    const endStr = fixIso(endRaw);

    const start = startStr ? new Date(startStr) : null;
    let end = endStr ? new Date(endStr) : null;

    // nếu end <= start thì bỏ end cho chắc
    if (start && end && end <= start) end = null;

    // ✅ Month: nếu span qua ngày khác => bỏ end (để giống event 1 ngày)
    if (mode === "month" && start && end && !sameDateOnly(start, end)) {
      end = null;
    }

    const department =
      ev.department ||
      ev.extendedProps?.department ||
      ev.extendedProps?.room ||
      "";

    const location =
      ev.location ||
      ev.extendedProps?.location ||
      "";

    const participants =
      ev.participants ||
      ev.extendedProps?.participants ||
      "";

    return {
      id: String(ev.id),
      title: ev.title || "(Không tiêu đề)",
      start: start || startRaw,
      end: end || null,
      allDay: false,

      extendedProps: {
        department,
        location,
        participants,
        status: ev.status || ev.extendedProps?.status || "approved",
        created_by: ev.created_by || ev.extendedProps?.created_by || 0
      }
    };
  });
}




async function fetchScheduleEvents() {
  const res = await api("controllers/schedule.php?action=list");
  const events = await res.json();
  return Array.isArray(events) ? events : [];
}

function getStatusClass(status) {
  // tùy hệ thống bạn đang dùng status nào
  // gợi ý: approved / pending / update_pending / delete_pending / rejected
  if (!status) return "approved";
  return status;
}

async function initMonthCalendar() {
  const el = document.getElementById("calendar");
  if (!el || CALENDAR_READY) return;

  CALENDAR_READY = true;



  FCALENDAR = new FullCalendar.Calendar(el, {
    initialView: "dayGridMonth",
    locale: "vi",
    height: "auto",
    firstDay: 1,

    headerToolbar: {
      left: "prev,next today",
      center: "title",
      right: "dayGridMonth,timeGridWeek,timeGridDay"
    },

    // ✅ Month sẽ là dạng list-item (gọn, không block ngang)
    views: {
      dayGridMonth: {
        eventDisplay: "list-item",
        dayMaxEventRows: 4
      }
    },

    eventTimeFormat: { hour: "2-digit", minute: "2-digit", hour12: false },
    displayEventEnd: false,

    dayMaxEvents: true,
    navLinks: true,
    nowIndicator: true,
    events: async function (fetchInfo, successCallback, failureCallback) {
      try {
        const raw = await fetchScheduleEvents();

        const mode = (fetchInfo.view.type === "dayGridMonth") ? "month" : "full";
        const fcEvents = mapEventsForCalendar(raw, { mode });

        successCallback(fcEvents);
      } catch (err) {
        console.error("fetch events failed", err);
        failureCallback(err);
      }
    },


    datesSet: function (arg) {
      refreshMonthCalendar(arg.view.type);
    },
    eventDidMount: function (info) {
      const status = info.event.extendedProps?.status || "approved";
      info.el.classList.add(status);

      const end = info.event.end || info.event.start;
      if (end && new Date(end) < new Date()) {
        info.el.classList.add("expired");
      }

      const dept = info.event.extendedProps?.department || "";
      const loc = info.event.extendedProps?.location || "";
      info.el.title = `${info.event.title}${dept ? " | " + dept : ""}${loc ? " | " + loc : ""}`;
    },

    eventClick: function (info) {
      openInfoFromList(info.event.id);
    },

    dateClick: function (info) {
      if (!window.SCHEDULE_CAN?.create) return;

      const d = new Date(info.date);
      d.setHours(8, 0, 0, 0);

      openScheduleForm(null, {
        start_date: fmtDTLocal(d),
        end_date: ""
      });
    }
  });

  FCALENDAR.render();
}

async function refreshMonthCalendar(viewType = null) {
  if (!FCALENDAR) return;

  const type = viewType || FCALENDAR.view?.type || "dayGridMonth";

  const rawEvents = await fetchScheduleEvents();

  // Month => clamp multi-day thành 1-day
  const mode = (type === "dayGridMonth") ? "month" : "full";
  const fcEvents = mapEventsForCalendar(rawEvents, { mode });

  FCALENDAR.removeAllEvents();
  FCALENDAR.addEventSource(fcEvents);

  FCALENDAR.updateSize();
}


function escapeHtml(str = "") {
  return String(str).replace(/[&<>"']/g, s => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;"
  }[s]));
}

async function loadScheduleByMonth() {
  const box = document.getElementById("listBox");
  if (!box) return;

  box.innerHTML = `<div class="text-gray-500 text-center py-6">Đang tải...</div>`;

  const res = await api("controllers/schedule.php?action=list");
  const events = await res.json();

  if (!events.length) {
    box.innerHTML = `<div class="text-gray-500 text-center py-6">Không có lịch công tác</div>`;
    return;
  }

  // ===== GROUP THEO THÁNG =====
  const groups = {};
  events.forEach(ev => {
    const d = new Date(ev.start);
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
    (groups[key] ||= []).push(ev);
  });

  box.innerHTML = "";

  Object.keys(groups)
    .sort((a, b) => b.localeCompare(a)) // tháng mới lên trước
    .forEach((key, i) => {
      const [y, m] = key.split("-");
      const list = groups[key].slice().sort((a, b) => {
        const aExpired = isExpired(a);
        const bExpired = isExpired(b);

        // chưa hết hạn → lên trên
        if (aExpired !== bExpired) {
          return aExpired ? 1 : -1;
        }

        // cùng trạng thái → sort theo thời gian
        return new Date(a.start) - new Date(b.start);
      });
      const bodyId = `month-body-${i}`;

      box.insertAdjacentHTML("beforeend", `
        <div class="border rounded-xl overflow-hidden">
          
          <!-- HEADER THÁNG -->
          <button
            class="w-full flex items-center justify-between px-4 py-3
                   bg-gradient-to-r from-indigo-500 to-purple-600
                   text-white font-semibold"
            data-toggle="${bodyId}">
            
            <span class="flex items-center gap-2">
              <span class="transition-transform rotate-0" data-arrow>▶</span>
              Tháng ${m} ${y}
              <span class="text-sm opacity-90">(${list.length} sự kiện)</span>
            </span>
          </button>

          <!-- BODY -->
          <div id="${bodyId}" class="hidden bg-white">
            <table class="w-full text-sm">
              <tbody>
                ${list.map(ev => {
        const expired = isExpired(ev);

        return `
    <tr
      class="border-t cursor-pointer
        ${expired ? 'opacity-55 grayscale bg-gray-50' : 'hover:bg-gray-50'}"
      onclick="openInfoFromList(${ev.id})">

      <td class="px-4 py-3">

        <!-- TITLE + STATUS -->
        <div class="flex items-center justify-between mb-2">
          <div class="font-semibold text-gray-900">
            ${escapeHtml(ev.title)}
          </div>

          ${expired ? `
            <span class="text-xs italic text-gray-500">
              Đã kết thúc
            </span>
          ` : ``}
        </div>

        <!-- META INFO -->
        <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">

          <span class="flex items-center gap-1">
            <i data-lucide="calendar" class="w-4 h-4 text-blue-600"></i>
            ${new Date(ev.start).toLocaleDateString("vi-VN")}
          </span>

          <span class="flex items-center gap-1">
            <i data-lucide="clock" class="w-4 h-4 text-orange-500"></i>
            ${(() => {
            const s = new Date(ev.start);
            const e = ev.end ? new Date(ev.end) : null;
            return e
              ? `${s.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })} – 
                   ${e.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })}`
              : s.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });
          })()}
          </span>

          <span class="flex items-center gap-1">
            <i data-lucide="school" class="w-4 h-4 text-green-600"></i>
            ${escapeHtml(ev.extendedProps?.department || "—")}
          </span>

          <span class="flex items-center gap-1">
            <i data-lucide="map-pin" class="w-4 h-4 text-red-600"></i>
            ${escapeHtml(ev.extendedProps?.location || "—")}
          </span>

        </div>
      </td>
    </tr>
  `;
      }).join("")}
              </tbody>
            </table>
          </div>
        </div>
      `);
    });

  // ===== ACCORDION HANDLER (MỞ ĐỘC LẬP) =====
  box.querySelectorAll("[data-toggle]").forEach(btn => {
    btn.addEventListener("click", () => {
      const id = btn.dataset.toggle;
      const body = document.getElementById(id);
      const arrow = btn.querySelector("[data-arrow]");
      if (!body) return;

      const isOpen = !body.classList.contains("hidden");

      if (isOpen) {
        body.classList.add("hidden");
        arrow.style.transform = "rotate(0deg)";
        OPEN_MONTH_IDS.delete(id);
      } else {
        body.classList.remove("hidden");
        arrow.style.transform = "rotate(90deg)";
        OPEN_MONTH_IDS.add(id);
      }

      saveOpenMonths();
    });
  });

  function saveOpenMonths() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify([...OPEN_MONTH_IDS])
    );
  }
  // ===== RESTORE OPEN MONTHS =====
  OPEN_MONTH_IDS.forEach(id => {
    const body = document.getElementById(id);
    if (!body) return;

    body.classList.remove("hidden");
    const btn = document.querySelector(`[data-toggle="${id}"]`);
    const arrow = btn?.querySelector("[data-arrow]");
    if (arrow) arrow.style.transform = "rotate(90deg)";
  });
  if (window.lucide) lucide.createIcons();

}

// ===============================
// TAB 2 – DANH SÁCH TUẦN
// ===============================
async function loadScheduleByWeek(refDate = new Date()) {
  const titleEl = document.getElementById("weekTitle");
  const rangeEl = document.getElementById("weekRange");
  const tbody = document.getElementById("weekTableBody");

  if (!tbody) return;

  tbody.innerHTML = `
    <tr>
      <td colspan="4" class="text-center text-gray-400 py-6">
        Đang tải lịch tuần...
      </td>
    </tr>
  `;

  const res = await api("controllers/schedule.php?action=list");
  const events = await res.json();

  // ===== TÍNH THỨ 2 – CHỦ NHẬT =====
  const monday = getMonday(refDate);
  const sunday = new Date(monday);
  sunday.setDate(monday.getDate() + 6);

  // header
  titleEl.textContent = `TUẦN ${getWeekNumber(refDate)}`;
  rangeEl.textContent =
    `Từ ngày ${fmtDate(monday)} đến ngày ${fmtDate(sunday)}`;

  tbody.innerHTML = "";

  for (let i = 0; i < 7; i++) {
    const day = new Date(monday);
    day.setDate(monday.getDate() + i);

    const dayEvents = events.filter(ev =>
      sameDay(new Date(ev.start), day)
    );

    const slots = {
      morning: [],
      afternoon: [],
      evening: []
    };

    dayEvents.forEach(ev => {
      const h = new Date(ev.start).getHours();
      if (h >= 5 && h < 13) slots.morning.push(ev);
      else if (h >= 13 && h < 18) slots.afternoon.push(ev);
      else slots.evening.push(ev);
    });

    tbody.insertAdjacentHTML("beforeend", `
      <tr class="border-t align-top">
        <td class="border px-3 py-2 font-medium">
          ${fmtDay(day)}
        </td>

        ${["morning", "afternoon", "evening"].map(k => `
          <td class="border px-3 py-2">
            ${slots[k].length
        ? slots[k].map(ev => renderWeekItem(ev)).join("")
        : `<div class="text-gray-400 text-center">—</div>`
      }
          </td>
        `).join("")}
      </tr>
    `);
  }
  // sau khi render xong toàn bộ
  if (window.lucide) {
    lucide.createIcons();
  }
}

function renderWeekItem(ev) {
  const s = new Date(ev.start);
  const e = ev.end ? new Date(ev.end) : null;

  const time = e
    ? `${s.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })} – 
       ${e.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })}`
    : s.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });

  return `
  <div
    class="mb-2 p-2 rounded-lg bg-yellow-50 border-l-4 border-orange-500
           cursor-pointer hover:bg-yellow-100"
    onclick="openInfoFromList(${ev.id})">

    <!-- TIME -->
    <div class="flex items-center gap-1 text-sm font-semibold text-orange-600 mb-1">
      <i data-lucide="clock" class="w-4 h-4 text-orange-500"></i>
      <span>${time}</span>
    </div>

    <!-- TITLE -->
    <div class="font-medium text-gray-900 mb-1">
      ${escapeHtml(ev.title)}
    </div>

    <!-- DEPARTMENT -->
    <div class="flex items-center gap-1 text-xs text-gray-700 mb-2">
      <i data-lucide="school" class="w-4 h-4 text-green-600"></i>
      <span>${escapeHtml(ev.extendedProps?.department || "—")}</span>
    </div>

    <!-- LOCATION -->
    <div class="flex items-center gap-1 text-xs text-gray-700 mb-2">
      <i data-lucide="map-pin" class="w-4 h-4 text-red-600"></i>
      <span>${escapeHtml(ev.extendedProps?.location || "—")}</span>
    </div>

    <hr class="my-1 border-gray-300 mb-1">

    <!-- PARTICIPANTS -->
    <div class="flex items-center gap-1 text-xs text-gray-700">
      <i data-lucide="users" class="w-4 h-4 text-purple-600"></i>
      <span>${escapeHtml(ev.extendedProps?.participants || "—")}</span>
    </div>
  </div>
`;
}


function getMonday(d) {
  d = new Date(d);
  const day = d.getDay() || 7;
  if (day !== 1) d.setDate(d.getDate() - day + 1);
  return d;
}

function sameDay(a, b) {
  return a.toDateString() === b.toDateString();
}

function fmtDate(d) {
  return d.toLocaleDateString("vi-VN");
}

function fmtDay(d) {
  return d.toLocaleDateString("vi-VN", {
    weekday: "short",
    day: "2-digit",
    month: "2-digit"
  });
}

function getWeekNumber(d) {
  d = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
  return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
}

// ===============================
// ADD EVENT MODAL
// ===============================
const btnAdd = document.getElementById("btnAddEvent");

if (btnAdd && window.SCHEDULE_CAN?.create) {
  btnAdd.addEventListener("click", () => {
    openScheduleForm();
  });
}

async function openScheduleForm(id = null, prefill = null) {
  let data = {
    title: "",
    department: "",
    location: "",
    participants: "",
    start_date: "",
    end_date: "",
    description: ""
  };
  // prefill (khi click ngày trong lịch tháng)
  if (!id && prefill && typeof prefill === "object") {
    data = { ...data, ...prefill };
  }


  // ===== EDIT MODE =====
  if (id) {
    const res = await api(`controllers/schedule.php?action=get&id=${id}`);
    const j = await res.json();
    if (j.error) {
      notify(j.error, "error");
      return;
    }
    data = j;
  }

  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <form id="scheduleForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">

      ${id ? `<input type="hidden" name="id" value="${id}">` : ""}

      <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Tiêu đề</label>
        <input name="title" required
          value="${escapeHtml(data.title || "")}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Phòng</label>
        <input name="department"
          value="${escapeHtml(data.department || "")}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Địa điểm</label>
        <input name="location"
          value="${escapeHtml(data.location || "")}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Thành phần</label>
        <input name="participants"
          value="${escapeHtml(data.participants || "")}"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Thời gian bắt đầu</label>
        <input
          type="text"
          name="start_date"
          value="${data.start_date || ""}"
          class="w-full px-3 py-2 border rounded-lg js-datetime"
          placeholder="Chọn ngày & giờ">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Thời gian kết thúc</label>
        <input
          type="text"
          name="end_date"
          value="${data.end_date || ""}"
          class="w-full px-3 py-2 border rounded-lg js-datetime"
          placeholder="Chọn ngày & giờ">
      </div>


      <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Nội dung</label>
        <textarea name="description" rows="4"
          class="w-full px-3 py-2 border rounded-lg">${escapeHtml(data.description || "")}</textarea>
      </div>

      <div class="md:col-span-2 flex justify-end gap-3 pt-3">
        <button type="button"
          class="px-4 py-2 border rounded-lg"
          onclick="closeModal()">Hủy</button>

        <button type="submit"
          class="px-5 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          ${id ? "Cập nhật" : "Thêm lịch"}
        </button>
      </div>
    </form>
  `;

  modal(
    wrap,
    `<span class="text-xl font-bold">${id ? "Sửa lịch công tác" : "Thêm lịch công tác"}</span>`,
    "large"
  );

  if (window.flatpickr) {
    flatpickr(".js-datetime", {
      enableTime: true,
      time_24hr: true,
      dateFormat: "Y-m-d H:i",

      monthSelectorType: "dropdown", // dropdown tháng
      locale: "en",                  // 🔴 mấu chốt

      allowInput: true
    });

  }

  // ===== SUBMIT =====
  wrap.querySelector("#scheduleForm").addEventListener("submit", async (e) => {
    e.preventDefault();

    const fd = new FormData(e.target);
    const action = id ? "update" : "create";

    const res = await api(`controllers/schedule.php?action=${action}`, {
      method: "POST",
      body: fd
    });

    const j = await res.json();
    if (j.error) {
      notify(j.error, "error");
      return;
    }

    if (window.SCHEDULE_CAN?.review) {
      notify(id ? "Đã cập nhật & duyệt lịch" : "Đã thêm & duyệt lịch");
    } else {
      notify(id ? "Đã cập nhật, chờ duyệt" : "Đã gửi lịch, chờ duyệt");
    }
    closeModal();
    if (window.SCHEDULE_CAN?.review) {
      loadScheduleByMonth();
      loadScheduleByWeek();
      refreshMonthCalendar(); // ✅ thêm

    }
    updateMyPendingBadge();

  });
}


async function openInfoFromList(id) {
  const res = await api(`controllers/schedule.php?action=get&id=${id}`);
  const data = await res.json();
  const canDelete =
    window.SCHEDULE_CAN?.delete &&
    data.status !== 'delete_pending' &&
    (window.SCHEDULE_CAN?.review || data.created_by == window.AUTH_USER_ID);
  const canEdit =
    window.SCHEDULE_CAN?.update &&
    (
      window.SCHEDULE_CAN?.review ||
      data.created_by == window.AUTH_USER_ID
    ) &&
    data.status !== 'delete_pending';

  if (data.error) {
    notify(data.error, "error");
    return;
  }

  const wrap = document.createElement("div");

  const s = new Date(data.start_date);
  const e = data.end_date ? new Date(data.end_date) : null;

  const timeStr = e
    ? `${s.toLocaleDateString("vi-VN")} | ${s.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })} – ${e.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })}`
    : `${s.toLocaleString("vi-VN")}`;

  wrap.innerHTML = `
    <div class="space-y-8 mt-10">


      <!-- META INFO -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-800">

        <div class="flex gap-3">
          <i data-lucide="calendar" class="w-6 h-6 text-blue-600 mt-1"></i>
          <div>
            <div class="font-semibold text-base">Thời gian</div>
            <div>${timeStr}</div>
          </div>
        </div>

        <div class="flex gap-3">
          <i data-lucide="school" class="w-6 h-6 text-indigo-600 mt-1"></i>
          <div>
            <div class="font-semibold text-base">Phòng</div>
            <div>${escapeHtml(data.department || "—")}</div>
          </div>
        </div>

        <div class="flex gap-3">
          <i data-lucide="map-pin" class="w-6 h-6 text-red-600 mt-1"></i>
          <div>
            <div class="font-semibold text-base">Địa điểm</div>
            <div>${escapeHtml(data.location || "—")}</div>
          </div>
        </div>

        <div class="flex gap-3">
          <i data-lucide="users" class="w-6 h-6 text-purple-600 mt-1"></i>
          <div>
            <div class="font-semibold text-base">Thành phần</div>
            <div>${escapeHtml(data.participants || "—")}</div>
          </div>
        </div>

      </div>

      <!-- CONTENT -->
      <div>
        <div class="flex items-center gap-3 mb-3">
          <i data-lucide="file-text" class="w-7 h-7 text-gray-700"></i>
          <span class="font-bold text-xl text-gray-900">
            Nội dung
          </span>
        </div>

      <div
        class="bg-gray-50 border border-gray-300 rounded-lg
              px-3 pb-4 
              text-sx text-gray-900
              leading-tight
              whitespace-pre-line
              break-all
              overflow-y-auto
              max-h-[200px]
              cursor-default
              select-text">
        ${escapeHtml((data.description || "—").trim())}
      </div>

      </div>

      <!-- ACTIONS -->
      <div class="flex justify-end gap-3 pt-5 border-t">

        ${canEdit ? `
          <button
            class="flex items-center gap-2 px-4 py-2
                   border rounded-xl text-sm hover:bg-gray-50"
              onclick="openScheduleForm(${data.id})">
            <i data-lucide="pencil" class="w-5 h-5"></i>
            Sửa
          </button>
        ` : ""}

        ${canDelete ? `
          <button
            class="flex items-center gap-2 px-4 py-2
                   bg-red-600 text-white rounded-xl text-sm
                   hover:bg-red-700 "data-primary
            onclick="confirmDeleteSchedule(${data.id}, '${escapeHtml(data.title)}')">
            <i data-lucide="trash-2" class="w-5 h-5"></i>
            Xóa
          </button>
        ` : ""}

      </div>
    </div>
  `;

  modal(
    wrap,
    `<span class="text-2xl font-bold">${escapeHtml(data.title)}</span>`,
    "large"
  );

  // render lucide icons
  if (window.lucide) {
    lucide.createIcons();
  }
}


function confirmDeleteSchedule(id, title = "") {
  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <div class="space-y-4">

      <p class="text-sx text-gray-700 mt-5">
        Bạn có chắc chắn muốn xóa lịch
        <span class="font-semibold text-gray-900">
          "${escapeHtml(title)}"
        </span>
        không?
      </p>

      <div class="flex justify-end gap-3 pt-4 border-t">
        <button
          class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50"
          onclick="closeModal()">
          Hủy
        </button>

        <button
          class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700 " data-primary
          id="btnConfirmDelete">
          Xóa
        </button>
      </div>
    </div>
  `;

  modal(
    wrap,
    `
    <div class="flex items-center gap-3">
      <i data-lucide="alert-triangle" class="w-6 h-6 text-red-600"></i>
      <h3 class="text-lg font-semibold text-gray-900">
        Xác nhận xóa lịch
      </h3>
    </div>
  `,
    "small"
  );

  if (window.lucide) lucide.createIcons();

  wrap.querySelector("#btnConfirmDelete").addEventListener("click", async () => {
    const res = await api(`controllers/schedule.php?action=delete&id=${id}`);
    const j = await res.json();

    if (j.error) {
      notify(j.error, "error");
      return;
    }

    const pendingDelete = j.data?.pending_delete === true;

    if (pendingDelete) {
      notify("Đã gửi yêu cầu xóa, chờ admin duyệt");
      updateMyPendingBadge();
      loadMyPendingSchedules();
    } else {
      notify("Đã xóa lịch");
      loadScheduleByMonth();
      loadScheduleByWeek();
      refreshMonthCalendar(); // ✅ thêm

    }


    closeModal();

  });
}

async function loadPendingSchedules() {
  if (!window.SCHEDULE_CAN?.review) return;
  const box = document.getElementById("approveBox");
  if (!box) return;

  box.innerHTML = `
    <div class="text-gray-500 text-center py-6">
      Đang tải lịch chờ duyệt...
    </div>
  `;

  const res = await api("controllers/schedule.php?action=pending");
  const j = await res.json();
  const rows = Array.isArray(j.data)
    ? j.data
    : (j.rows || []);


  if (!j.ok || rows.length === 0) {
    box.innerHTML = `
      <div class="text-gray-500 text-center py-6">
        Không có lịch chờ duyệt
      </div>
    `;
    return;
  }

  box.innerHTML = `
    <div class="overflow-x-auto">
      <table class="w-full text-sm border border-gray-200">
        <thead class="bg-gray-100">
          <tr>
            <th class="px-3 py-2 text-left">Người tạo</th>
            <th class="px-3 py-2 text-left">Tiêu đề</th>
            <th class="px-3 py-2 text-center">Thời gian</th>
            <th class="px-3 py-2 text-left">Nội dung</th>
            <th class="px-3 py-2 text-center">Thao tác</th>
          </tr>
        </thead>
        <tbody>
${rows.map(r => `
          <tr class="border-t align-top">

  <!-- NGƯỜI TẠO -->
  <td class="px-3 py-2 font-medium">
    ${escapeHtml(r.creator_name || "—")}
  </td>

  <!-- TIÊU ĐỀ -->
  <td class="px-3 py-2">
    ${escapeHtml(r.title)}
  </td>

  <!-- THỜI GIAN -->
  <td class="px-3 py-2 text-center whitespace-nowrap">
    ${(() => {
      const s = new Date(r.start_date);
      const e = r.end_date ? new Date(r.end_date) : null;

      const date = s.toLocaleDateString("vi-VN");
      const startTime = s.toLocaleTimeString("vi-VN", {
        hour: "2-digit",
        minute: "2-digit"
      });

      const endTime = e
        ? e.toLocaleTimeString("vi-VN", {
          hour: "2-digit",
          minute: "2-digit"
        })
        : "";

      return endTime
        ? `${date} | ${startTime} – ${endTime}`
        : `${date} | ${startTime}`;
    })()}
  </td>

  <!-- NỘI DUNG -->
<td class="px-3 py-2 max-w-[300px] text-sm text-gray-700">
  <div class="line-clamp-3 break-words ">
    ${escapeHtml(r.description || "—")}
  </div>
</td>

  <!-- THAO TÁC -->
  <td class="px-3 py-2 text-center space-x-2 whitespace-nowrap">

  ${r.status === 'delete_pending' ? `
    <button
      class="px-3 py-1 bg-red-700 text-white rounded"
      onclick="approveDelete(${r.id})">
      Duyệt xóa
    </button>

    <button
      class="px-3 py-1 bg-gray-600 text-white rounded"
      onclick="rejectDeleteSchedule(${r.id})">
      Từ chối xóa
    </button>
  ` : r.status === 'update_pending' ? `
    <button
      class="px-3 py-1 bg-blue-600 text-white rounded"
      onclick="approveSchedule(${r.id})">
      Duyệt sửa
    </button>

    <button
      class="px-3 py-1 bg-gray-600 text-white rounded"
      onclick="rejectSchedule(${r.id})">
      Từ chối sửa
    </button>
  ` : `
    <button
      class="px-3 py-1 bg-green-600 text-white rounded"
      onclick="approveSchedule(${r.id})">
      Duyệt
    </button>

    <button
      class="px-3 py-1 bg-red-600 text-white rounded"
      onclick="rejectSchedule(${r.id})">
      Từ chối
    </button>
  `}
</td>
</tr>

        `).join("")}

        </tbody>
      </table>
    </div>
  `;
}
async function approveSchedule(id) {
  if (!confirm("Duyệt lịch công tác này?")) return;

  const fd = new FormData();
  fd.append("id", id);

  const res = await api("controllers/schedule.php?action=approve", {
    method: "POST",
    body: fd
  });

  const j = await res.json();
  if (j.error) {
    notify(j.error, "error");
    return;
  }

  notify("Đã duyệt lịch");
  loadPendingSchedules();
  updateApproveBadge(); // 👈 thêm

  loadScheduleByMonth();
  loadScheduleByWeek();
  refreshMonthCalendar(); // ✅ thêm

}
function rejectSchedule(id) {
  const reason = prompt("Nhập lý do từ chối (có thể bỏ trống):");
  if (reason === null) return;

  submitRejectSchedule(id, reason);
}

async function submitRejectSchedule(id, note = "") {
  const fd = new FormData();
  fd.append("id", id);
  fd.append("note", note);

  const res = await api("controllers/schedule.php?action=reject", {
    method: "POST",
    body: fd
  });

  const j = await res.json();
  if (j.error) {
    notify(j.error, "error");
    return;
  }

  notify("Đã từ chối lịch");
  loadPendingSchedules();
  updateApproveBadge(); // 👈 thêm

}
async function updateApproveBadge() {
  if (!window.SCHEDULE_CAN?.review) return;

  const badge = document.getElementById("approveBadge");
  if (!badge) return;

  try {
    const res = await api("controllers/schedule.php?action=pending_count");
    const j = await res.json();

    if (!j.ok || j.count <= 0) {
      badge.classList.add("hidden");
      return;
    }

    badge.textContent = j.count;
    badge.classList.remove("hidden");

  } catch (e) {
    console.error("Load pending count failed", e);
  }
}

async function loadMyPendingSchedules() {
  const box = document.getElementById("myPendingBox");
  if (!box) return;

  box.innerHTML = `
    <div class="text-gray-500 text-center py-6">
      Đang tải lịch chờ duyệt...
    </div>
  `;

  const res = await api("controllers/schedule.php?action=my_pending");
  const j = await res.json();
  const rows = j.data?.rows || [];

  if (!j.ok || rows.length === 0) {

    box.innerHTML = `
      <div class="text-gray-500 text-center py-6">
        Không có lịch chờ duyệt
      </div>
    `;
    return;
  }

  box.innerHTML = `
  <div class="overflow-x-auto">
    <table class="w-full text-sm border border-gray-200">
      <thead class="bg-gray-100">
        <tr>
          <th class="px-3 py-2 text-left">Tiêu đề</th>
          <th class="px-3 py-2 text-left">Thời gian</th>
          <th class="px-3 py-2 text-left">Nội dung</th>
          <th class="px-3 py-2 text-center">Trạng thái</th>
          <th class="px-3 py-2 text-left">Ghi chú</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map(r => {
    const s = new Date(r.start_date);
    const e = r.end_date ? new Date(r.end_date) : null;

    const date = s.toLocaleDateString("vi-VN");
    const startTime = s.toLocaleTimeString("vi-VN", {
      hour: "2-digit",
      minute: "2-digit"
    });
    const endTime = e
      ? e.toLocaleTimeString("vi-VN", {
        hour: "2-digit",
        minute: "2-digit"
      })
      : "";

    const timeStr = endTime
      ? `${date} | ${startTime} – ${endTime}`
      : `${date} | ${startTime}`;

    return `
          <tr class="border-t align-top">

            <!-- TIÊU ĐỀ -->
            <td class="px-3 py-2 font-medium">
              ${escapeHtml(r.title)}
            </td>

            <!-- THỜI GIAN -->
            <td class="px-3 py-2 text-left whitespace-nowrap">
              ${timeStr}
            </td>

            <!-- NỘI DUNG -->
            <td class="px-3 py-2 max-w-[320px] text-sm text-gray-700">
              <div class="line-clamp-3 break-words">
                ${escapeHtml(r.description || "—")}
              </div>
            </td>

            <!-- TRẠNG THÁI -->
            <td class="px-3 py-2 text-center whitespace-nowrap">
              ${r.status === 'rejected'
        ? `<span class="text-red-600 font-semibold">Bị từ chối</span>`
        : r.status === 'delete_pending'
          ? `<span class="text-orange-600 font-semibold">Chờ duyệt xóa</span>`
          : r.status === 'update_pending'
            ? `<span class="text-blue-600 font-semibold">Chờ duyệt sửa</span>`
            : `<span class="text-gray-600">Chờ duyệt</span>`
      }
            </td>

            <!-- GHI CHÚ -->
            <td class="px-3 py-2 text-sm text-gray-700 max-w-[240px]">
              ${r.status === 'rejected'
        ? escapeHtml(r.reject_note || '—')
        : '—'
      }
            </td>

          </tr>
          `;
  }).join("")}
      </tbody>
    </table>
  </div>
`;

}


async function updateMyPendingBadge() {
  const badge = document.getElementById("myPendingBadge");
  if (!badge) return;

  try {
    const res = await api("controllers/schedule.php?action=my_pending_count");
    const j = await res.json();

    if (!j.ok || j.count <= 0) {
      badge.classList.add("hidden");
      return;
    }

    badge.textContent = j.count;
    badge.classList.remove("hidden");
  } catch {
    badge.classList.add("hidden");
  }
}
async function approveDelete(id) {
  if (!confirm("Duyệt xóa lịch này?")) return;

  const fd = new FormData();
  fd.append("id", id);

  const res = await api("controllers/schedule.php?action=approve_delete", {
    method: "POST",
    body: fd
  });

  const j = await res.json();
  if (j.error) {
    notify(j.error, "error");
    return;
  }

  notify("Đã duyệt xóa lịch");
  loadPendingSchedules();
  updateApproveBadge();
  loadScheduleByMonth();
  loadScheduleByWeek();
  refreshMonthCalendar(); // ✅ thêm

}
async function rejectDeleteSchedule(id) {
  const reason = prompt("Nhập lý do từ chối xóa (có thể bỏ trống):");
  if (reason === null) return;

  const fd = new FormData();
  fd.append("id", id);
  fd.append("note", reason);

  const res = await api("controllers/schedule.php?action=reject_delete", {
    method: "POST",
    body: fd
  });

  const j = await res.json();
  if (j.error) {
    notify(j.error, "error");
    return;
  }

  notify("Đã từ chối yêu cầu xóa");
  loadPendingSchedules();
  updateApproveBadge();
}
