// assets/js/tasks/tasks.admin.js
(function () {
  const app = document.getElementById("tasks-app");
  if (!app || app.dataset.view !== "admin") return;

  const T = window.Tasks;
  if (!T) {
    console.error("Tasks core missing: window.Tasks");
    return;
  }

  if (typeof T.fromDTLocal !== "function") {
    T.fromDTLocal = (v) => (v ? String(v).replace("T", " ") + ":00" : "");
  }

  let META = { projects: [], users: [] };
  let TASK_MAP = new Map(); // cache task rows theo id
  let ASSIGNEE_DETAIL_CACHE = new Map(); // taskId -> [{name, done}]

  function isAssigneeDone(a) {
    const s = String(a?.status || a?.my_status || a?.assignee_status || "").toLowerCase();
    if (s === "done") return true;

    // fallback nếu backend trả finished_at
    if (a?.finished_at || a?.my_finished_at) return true;

    // fallback cờ bool
    if (Number(a?.is_done) === 1) return true;

    return false;
  }

  function normalizeAssigneesDetail(rawArr) {
    const arr = Array.isArray(rawArr) ? rawArr : [];
    return arr.map(x => {
      const uid = Number(x.user_id || x.id || 0);
      const name = (x.fullname || x.username || ("User#" + uid)).trim();
      return {
        id: uid,
        name,
        done: isAssigneeDone(x),
      };
    }).filter(x => x.name);
  }
  function renderSchoolYearOptions(selectedId) {
    const list = Array.isArray(META.school_years) ? META.school_years : [];
    return list.map(y => {
      const id = Number(y.id);
      const label = y.year_label || "";
      return `<option value="${id}" ${id === Number(selectedId) ? "selected" : ""}>${T.escape(label)}</option>`;
    }).join("");
  }

  function renderSemesterOptions(selectedCode) {
    const list = Array.isArray(META.semesters) ? META.semesters : [];
    const sel = String(selectedCode || "").toUpperCase().replace(/\s+/g, "");
    return list.map(s => {
      const code = String(s.code || "").toUpperCase().replace(/\s+/g, "");
      const label = s.label || code;
      return `<option value="${T.escape(code)}" ${code === sel ? "selected" : ""}>${T.escape(label)}</option>`;
    }).join("");
  }
  function normSemesterCode(v = "") {
    return String(v || "").toUpperCase().replace(/\s+/g, "");
  }

  function getDefaultSchoolYearId() {
    const list = Array.isArray(META.school_years) ? META.school_years : [];
    if (!list.length) return 0;
    const active = list.find(x => Number(x.is_active) === 1);
    return Number((active || list[0]).id || 0);
  }

  function getDefaultSemesterCode() {
    const list = Array.isArray(META.semesters) ? META.semesters : [];
    if (!list.length) return "";
    const active = list.find(x => Number(x.is_active) === 1);
    return normSemesterCode((active || list[0]).code || "");
  }

  /**
   * ✅ Setup filter năm học / học kỳ cho LIST (nếu có select trong HTML)
   * - ưu tiên URL params (?school_year_id=&semester_code=)
   * - fallback meta active
   */
  function setupListYearSemesterFilterUI() {
    // lấy từ URL (nếu có)
    const sp = new URLSearchParams(window.location.search);
    const urlSY = Number(sp.get("school_year_id") || 0);
    const urlHK = normSemesterCode(sp.get("semester_code") || sp.get("semester") || "");

    // ✅ chỉ set khi URL thật sự có param
    if (sp.has("school_year_id")) STATE.school_year_id = urlSY || 0;
    if (sp.has("semester_code") || sp.has("semester")) STATE.semester_code = urlHK || "";

    // ✅ default: 0 / "" => Tất cả


    // nếu có select filter trên page thì fill options + bind change
    const selSY = document.getElementById("taskFSchoolYearId");
    const selHK = document.getElementById("taskFSemesterCode");

    if (selSY) {
      // nếu HTML chưa render options thì auto fill
      if (!selSY.options || selSY.options.length <= 1) {
        selSY.innerHTML =
          `<option value="">Tất cả</option>` +
          renderSchoolYearOptions(STATE.school_year_id || 0);
      }
      // set value (ưu tiên state)
      selSY.value = STATE.school_year_id ? String(STATE.school_year_id) : "";
      selSY.addEventListener("change", () => {
        const v = Number(selSY.value || 0);
        STATE.school_year_id = v || 0;
        STATE.page = 1;
        loadList();
      });
    }

    if (selHK) {
      if (!selHK.options || selHK.options.length <= 1) {
        selHK.innerHTML =
          `<option value="">Tất cả</option>` +
          renderSemesterOptions(STATE.semester_code || "");
      }
      selHK.value = STATE.semester_code ? String(STATE.semester_code) : "";
      selHK.addEventListener("change", () => {
        const v = normSemesterCode(selHK.value || "");
        STATE.semester_code = v || "";
        STATE.page = 1;
        loadList();
      });
    }
  }

  function renderAssigneesHTML(r) {
    // ưu tiên detail
    const detail = Array.isArray(r?.assignees_detail) ? r.assignees_detail : null;

    if (detail && detail.length) {
      return `
      <div class="space-y-1">
        ${detail
          .map((a) => {
            const cls = a.done ? "text-emerald-700" : "text-gray-900";
            return `<div class="${cls} leading-snug">${T.escape(a.name)}</div>`;
          })
          .join("")}
      </div>
    `;
    }

    // fallback cũ (chuỗi "A, B, C" -> tách ra mỗi dòng)
    const text = String(r?.assignees || r?.assignee_name || "").trim();
    if (!text) return "-";

    const parts = text
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean);

    if (parts.length <= 1) return T.escape(text);

    return `
    <div class="space-y-1">
      ${parts.map((name) => `<div class="text-gray-900 leading-snug">${T.escape(name)}</div>`).join("")}
    </div>
  `;
  }


  async function enrichRowsAssigneesStatus(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const need = list.filter(r => {
      const id = Number(r?.id || 0);
      return id && !ASSIGNEE_DETAIL_CACHE.has(id);
    });

    if (!need.length) {
      // apply cache
      for (const r of list) {
        const id = Number(r?.id || 0);
        if (!id) continue;
        const cached = ASSIGNEE_DETAIL_CACHE.get(id);
        if (cached) r.assignees_detail = cached;
      }
      return;
    }

    await Promise.allSettled(
      need.map(async (r) => {
        const id = Number(r?.id || 0);
        if (!id) return;

        try {
          const d = await fetchDetail(id);
          const assigneesDetail = normalizeAssigneesDetail(d?.assignees || []);
          ASSIGNEE_DETAIL_CACHE.set(id, assigneesDetail);
          r.assignees_detail = assigneesDetail;
        } catch (_) {
          // ignore
        }
      })
    );
  }

  let STATE = {
    page: 1,
    page_size: 6, // ✅ 1 trang 6 block
    view_task_id: 0,

    project_text: "",
    assignee_id: "",
    status: "",
    q: "",

    // ✅ NEW (Hướng B): filter năm học / học kỳ
    school_year_id: 0,
    semester_code: "",

    total_pages: 1,
    total: 0,
  };


  let ASSIGNEE_TIMER = null;
  let PROJECT_TIMER = null;

  async function loadMeta() {
    const j = await T.api("meta", {}, "POST");
    if (!j?.ok) return T.toast(j?.error || "Lỗi meta");
    META = j.data || META;
  }

  function normVN(str = "") {
    return String(str)
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/đ/g, "d")
      .trim();
  }

  function filterProjects(q) {
    const items = META.projects || [];
    const nq = normVN(q);
    if (!nq) return items.slice(0, 10);

    const scored = [];
    for (const p of items) {
      const name = p.title || "";
      const n = normVN(name);

      let score = 0;
      if (n.startsWith(nq)) score = 2;
      else if (n.includes(nq)) score = 1;

      if (score > 0) scored.push({ p, score, name });
    }

    scored.sort((a, b) => {
      if (b.score !== a.score) return b.score - a.score;
      return String(a.name).localeCompare(String(b.name));
    });

    return scored.slice(0, 10).map(x => x.p);
  }

  function renderProjectSuggest(list) {
    const box = document.getElementById("projectSuggest");
    if (!box) return;

    if (!list.length) {
      box.innerHTML = `<div class="px-3 py-2 text-sm text-gray-500">Không thấy dự án</div>`;
      box.classList.remove("hidden");
      return;
    }

    box.innerHTML = list.map(p => {
      const title = p.title || "";
      return `
      <button type="button"
        class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
        data-pick-project="${T.escape(title)}">
        <div class="font-medium text-gray-800">${T.escape(title)}</div>
      </button>
    `;
    }).join("");

    box.classList.remove("hidden");
  }

  function hideProjectSuggest() {
    const box = document.getElementById("projectSuggest");
    if (!box) return;
    box.classList.add("hidden");
    box.innerHTML = "";
  }

  function filterAssignees(q) {
    const users = META.users || [];
    const nq = normVN(q);
    if (!nq) return users.slice(0, 10);

    const scored = [];
    for (const u of users) {
      const name = u.fullname || u.username || "";
      const n = normVN(name);

      let score = 0;
      if (n.startsWith(nq)) score = 2;
      else if (n.includes(nq)) score = 1;

      if (score > 0) scored.push({ u, score, name });
    }

    scored.sort((a, b) => {
      if (b.score !== a.score) return b.score - a.score;
      return String(a.name).localeCompare(String(b.name));
    });

    return scored.slice(0, 10).map(x => x.u);
  }

  function parseIds(csv = "") {
    return String(csv)
      .split(",")
      .map(s => Number(String(s).trim()))
      .filter(n => Number.isFinite(n) && n > 0);
  }

  function setIdsToHidden(hiddenEl, ids) {
    hiddenEl.value = ids.join(",");
  }

  function getPickedAssignees(hiddenEl) {
    const ids = parseIds(hiddenEl.value);
    const map = new Map();
    for (const u of (META.users || [])) map.set(Number(u.id), u);
    return ids.map(id => map.get(id)).filter(Boolean);
  }

  function renderAssigneeChips() {
    const wrap = document.getElementById("mAssigneeChips");
    const hid = document.getElementById("mTaskAssigneeIds");
    const sum = document.getElementById("mAssigneeSummary");
    if (!wrap || !hid) return;

    const picked = getPickedAssignees(hid);

    if (!picked.length) {
      wrap.innerHTML = "";
      if (sum) sum.textContent = "Chưa chọn ai";
      syncAssigneeSelectFromHidden();
      return;
    }

    wrap.innerHTML = picked.map(u => {
      const name = (u.fullname || u.username || ("User#" + u.id)).trim();
      return `
      <span class="inline-flex items-center gap-2 px-2 py-1 rounded-full bg-gray-100 text-gray-800 text-xs">
        ${T.escape(name)}
        <button type="button" class="text-gray-500 hover:text-red-600" data-chip-del="${u.id}">✕</button>
      </span>
    `;
    }).join("");

    if (sum) {
      sum.textContent = picked.length ? `Đã chọn ${picked.length} người` : "Chưa chọn ai";
    }


    syncAssigneeSelectFromHidden();
  }



  function bindAssigneeChipsEvents() {
    const wrap = document.getElementById("mAssigneeChips");
    const hid = document.getElementById("mTaskAssigneeIds");
    if (!wrap || !hid) return;

    wrap.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-chip-del]");
      if (!btn) return;

      const delId = Number(btn.getAttribute("data-chip-del"));
      const ids = parseIds(hid.value).filter(x => x !== delId);

      setIdsToHidden(hid, ids);
      renderAssigneeChips();
    });
  }

  function hideEl(el) {
    if (!el) return;
    el.classList.add("hidden");
    el.innerHTML = "";
  }

  function renderModalSuggest(list) {
    const box = document.getElementById("mAssigneeSuggest");
    const hid = document.getElementById("mTaskAssigneeIds");
    if (!box) return;

    const pickedIds = parseIds(hid?.value || "");

    if (!list.length) {
      box.innerHTML = `<div class="px-3 py-2 text-sm text-gray-500">Không thấy ai</div>`;
      box.classList.remove("hidden");
      return;
    }

    box.innerHTML = list.map(u => {
      const id = Number(u.id);
      const name = u.fullname || u.username || ("User#" + id);

      const isPicked = pickedIds.includes(id);

      return `
      <button type="button"
        class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm flex items-center justify-between gap-3
          ${isPicked ? "opacity-60 pointer-events-none" : ""}"
        data-pick-id="${id}">
        
        <div class="min-w-0">
          <div class="font-medium text-gray-800 truncate">${T.escape(name)}</div>
          <div class="text-xs text-gray-500">#${id}</div>
        </div>

        ${isPicked
          ? `<span class="text-xs font-semibold px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">✅ Đã chọn</span>`
          : `<span class="text-xs text-gray-400">Chọn</span>`
        }
      </button>
    `;
    }).join("");

    box.classList.remove("hidden");
  }

  async function loadSingleTask(taskId) {
    const pager = document.getElementById("taskPager");
    if (pager) pager.innerHTML = "";

    try {
      const d = await fetchDetail(taskId);
      const t = d?.task || null;
      if (!t) {
        renderCards([]);
        return T.toast("Không tìm thấy công việc", "error");
      }

      const assignees = Array.isArray(d?.assignees)
        ? d.assignees.map(x => x.fullname || x.username || "").filter(Boolean).join(", ")
        : "";

      const assigneesDetail = normalizeAssigneesDetail(d?.assignees || []);

      ASSIGNEE_DETAIL_CACHE.set(Number(t.id), assigneesDetail);

      const row = {
        ...t,
        assignees: assignees || "",
        assignees_detail: assigneesDetail,
        project_title: t.project_text || t.project_title || "",
        project_text: t.project_text || "",

        // ✅ NEW (Hướng B) cho renderCards
        school_year_id: t.school_year_id || null,
        semester_code: t.semester_code || null,
        school_year_label: t.school_year_label || "",
      };



      // stats cho đẹp (1 item)
      renderStats({
        total: 1,
        pending: t.status === "pending" ? 1 : 0,
        doing: t.status === "doing" ? 1 : 0,
        done: t.status === "done" ? 1 : 0,
        overdue: isOverdueTask(row) ? 1 : 0,
      });

      renderCards([row]);
    } catch (e) {
      console.error(e);
      renderCards([]);
      T.toast("Không tải được công việc", "error");
    }
  }

  async function loadList() {
    const payload = {
      page: STATE.page,
      page_size: STATE.page_size,
      project_text: STATE.project_text || "",
      assignee_id: STATE.assignee_id || "",
      status: STATE.status || "",
      q: STATE.q || "",

      // ✅ NEW (Hướng B)
      school_year_id: Number(STATE.school_year_id || 0) || 0,
      semester_code: normSemesterCode(STATE.semester_code || ""),
    };

    const j = await T.api("list", payload, "POST");
    if (!j?.ok) return T.toast(j?.error || "Lỗi list");

    const rows = j.data?.rows || [];
    TASK_MAP = new Map((rows || []).map(r => [Number(r.id), r]));

    const paging = j.data?.paging || {};
    STATE.total_pages = Number(paging.total_pages || 1);
    STATE.total = Number(paging.total || rows.length || 0);

    renderStats(j.data?.stats || {
      total: STATE.total,
      pending: 0,
      doing: 0,
      done: 0,
      overdue: 0
    });

    await enrichRowsAssigneesStatus(rows);

    renderCards(rows);
    renderPager();
  }


  function renderStats(stats) {
    const total = Number(stats?.total || 0);
    const pending = Number(stats?.pending || 0); // chưa bắt đầu
    const doing = Number(stats?.doing || 0);
    const done = Number(stats?.done || 0);
    const overdue = Number(stats?.overdue || 0);

    // ✅ Hiệu suất: done / total
    const eff = total > 0 ? Math.round((done / total) * 100) : 0;

    const elTotal = document.getElementById("statTotal");
    const elPending = document.getElementById("statPending");
    const elDoing = document.getElementById("statDoing");
    const elDone = document.getElementById("statDone");
    const elOverdue = document.getElementById("statOverdue");
    const elEff = document.getElementById("statEff");

    if (elTotal) elTotal.textContent = total;
    if (elPending) elPending.textContent = pending;
    if (elDoing) elDoing.textContent = doing;
    if (elDone) elDone.textContent = done;
    if (elOverdue) elOverdue.textContent = overdue;
    if (elEff) elEff.textContent = eff + "%";
  }


  // ✅ tags pills
  function renderTagPills(tagsStr = "") {
    const raw = String(tagsStr || "").trim();
    if (!raw) return "";

    const tags = raw
      .split(",")
      .map(s => s.trim())
      .filter(Boolean);

    if (!tags.length) return "";

    const maxShow = 3;
    const show = tags.slice(0, maxShow);
    const rest = tags.length - show.length;

    const pills = show.map(t => `
      <span class="px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-medium">
        ${T.escape(t)}
      </span>
    `).join("");

    const more = rest > 0
      ? `<span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700 text-xs font-medium">+${rest}</span>`
      : "";

    return `<div class="flex flex-wrap gap-2">${pills}${more}</div>`;
  }

  function cardBorderByRow(r) {
    const s = String(r?.status || "pending");

    // DONE: ưu tiên theo result_type
    if (s === "done") {
      const rt = String(r?.result_type || "");
      return rt === "late" ? "border-red-200" : "border-emerald-200";
    }

    // quá hạn (pending/doing)
    if (isOverdueTask(r)) return "border-red-200";

    if (s === "doing") return "border-blue-200";

    // pending
    return "border-gray-200";
  }

  function isOverdueTask(r) {
    if (!r) return false;
    if (String(r.status || "") === "done") return false;
    if (!r.deadline) return false;

    // deadline kiểu "YYYY-MM-DD HH:mm:ss"
    const d = new Date(String(r.deadline).replace(" ", "T"));
    if (isNaN(d.getTime())) return false;

    return d.getTime() < Date.now();
  }

  function cardClassByStatus(status, isOverdue) {
    const s = String(status || "pending");

    // ✅ quá hạn ưu tiên đè lên hết (trừ done)
    if (isOverdue && s !== "done") {
      return "border-red-200 bg-red-50";
    }

    if (s === "doing") {
      return "border-blue-200 bg-blue-50";
    }

    if (s === "done") {
      return "border-emerald-200 bg-emerald-50";
    }

    // pending
    return "border-gray-200 bg-gray-50";
  }


  function statusBadgeHTML(status) {
    const s = String(status || "pending");

    if (s === "doing") {
      return `
      <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-blue-600 text-white text-xs font-semibold">
        Đang làm
      </span>
    `;
    }

    if (s === "done") {
      return `
      <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-emerald-600 text-white text-xs font-semibold">
        Hoàn thành
      </span>
    `;
    }

    // pending
    return `
    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-gray-200 text-gray-800 text-xs font-semibold">
      Chưa làm
    </span>
  `;
  }

  function overdueBadgeHTML() {
    return `
    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-red-50 text-red-700 text-xs font-semibold border border-red-100">
      Quá hạn
    </span>
  `;
  }


  function renderCards(rows) {
    const grid = document.getElementById("taskGrid");
    if (!grid) return;

    if (!rows.length) {
      grid.innerHTML = `
        <div class="col-span-full px-3 py-10 text-center text-gray-500">
          Không có công việc
        </div>
      `;
      return;
    }

    grid.innerHTML = rows.map((r) => {
      const title = T.escape(r.title || "");
      const desc = T.escape(r.description || "");
      const project = T.escape(r.project_title || r.project_text || "");
      const deadline = T.escape(T.fmtDT(r.deadline));
      const priorityBadge = T.badgePriority(r.priority || "medium");
      const baseStatus = statusBadgeHTML(r.status);
      const sy = String(r.school_year_label || r.project_school_year || "").trim();
      const hk = String(r.semester_code || r.project_semester || "").trim();

      const schoolSemHTML = `
  <div class="mt-1 text-xs text-gray-600 font-semibold">
    Năm học: <span class="text-gray-900">${T.escape(sy || "-")}</span>
    ${" • "}
    Học kỳ: <span class="text-gray-900">${T.escape(hk || "-")}</span>
  </div>
`;



      const overdue = isOverdueTask(r)
        ? overdueBadgeHTML()
        : "";

      const statusBadge = `
      <div class="flex items-center gap-2 justify-end flex-wrap">
        ${baseStatus}
        ${overdue}
      </div>
    `;



      // ✅ nhiều người: ưu tiên r.assignees
      const assigneesHTML = renderAssigneesHTML(r);

      const supName = String(r?.supervisor_name || "").trim();

      const supervisorHTML = `
  <div class="mb-2 border-l-4 border-amber-300 pl-2">
    <div class="text-[11px] font-extrabold tracking-wide text-amber-700">
      NGƯỜI PHỤ TRÁCH
    </div>
    <div class="mt-0.5 text-sm font-bold text-amber-900">
      ${supName ? T.escape(supName) : "-"}
    </div>
  </div>
`;





      const doneBox = (r.status === "done" && r.finished_at)
        ? (() => {
          const rt = String(r.result_type || "");
          const isLate = rt === "late";

          const boxCls = isLate
            ? "bg-red-50 border border-red-200"
            : "bg-emerald-100 border border-emerald-200";

          const headCls = isLate ? "text-red-700" : "text-emerald-700";
          const mainCls = isLate ? "text-red-900" : "text-emerald-900";
          const subCls = isLate ? "text-red-700" : "text-emerald-700";

          const label =
            rt === "early" ? "Hoàn thành sớm" :
              rt === "ontime" ? "Đúng hạn" :
                rt === "late" ? "Trễ hạn" : "";

          return `
      <div class="rounded-xl px-4 py-3 ${boxCls}">
        <div class="text-xs font-semibold ${headCls}">HOÀN THÀNH LÚC</div>
        <div class="mt-1 text-sm font-bold ${mainCls}">
          ${T.escape(T.fmtDT(r.finished_at))}
        </div>
        ${label ? `<div class="mt-1 text-xs ${subCls}">${label}</div>` : ""}
      </div>
    `;
        })()
        : `
    <div class="rounded-xl bg-gray-50 px-4 py-3">
      <div class="text-xs font-semibold text-gray-600">TIẾN ĐỘ</div>
      <div class="mt-1 text-sm font-bold text-gray-900">${Number(r.progress || 0)}%</div>
    </div>
  `;


      const cardBorder = cardBorderByRow(r);
      return `
<div class="rounded-2xl border-2 ${cardBorder} bg-white shadow-sm p-5 flex flex-col gap-4">
          <!-- header -->
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <div class="text-lg font-bold text-gray-900 truncate">${title}</div>
              ${desc ? `<div class="mt-1 text-sm text-gray-500 line-clamp-2">${desc}</div>` : ""}
            </div>
            <div class="shrink-0">${statusBadge}</div>
          </div>

          <!-- tags -->
          ${renderTagPills(r.tags || "")}

          <!-- info boxes like screenshot -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="rounded-xl bg-gray-50 px-4 py-3">
<div class="mt-1 text-sm font-bold">
  ${supervisorHTML}
  <div>
    <div class="text-xs font-semibold text-gray-600">NGƯỜI THỰC HIỆN</div>
    <div class="mt-1 text-sm font-bold">
      ${assigneesHTML}
    </div>
  </div>
</div>

            </div>

            <div class="rounded-xl bg-gray-50 px-4 py-3">
              <div class="text-xs font-semibold text-gray-600">DỰ ÁN</div>
              <div class="mt-1 text-sm font-bold text-gray-900">${project}</div>
                            ${schoolSemHTML}
            </div>

            <div class="rounded-xl bg-gray-50 px-4 py-3">
              <div class="text-xs font-semibold text-gray-600">ĐỘ ƯU TIÊN</div>
              <div class="mt-1">${priorityBadge}</div>

            </div>

            <div class="rounded-xl bg-gray-50 px-4 py-3">
              <div class="text-xs font-semibold text-gray-600">HẠN HOÀN THÀNH</div>
              <div class="mt-1 text-sm font-bold text-gray-900">${deadline}</div>
            </div>

            <div class="md:col-span-2">
              ${doneBox}
            </div>
          </div>
          <!-- actions -->
          <div class="mt-auto flex gap-3 pt-3">
            <button class="flex-1 px-4 py-3 rounded-xl bg-blue-600 text-white font-semibold"
              data-edit="${r.id}">
              Chỉnh sửa
            </button>

            <button class="flex-1 px-4 py-3 rounded-xl bg-red-600 text-white font-semibold"
              data-del="${r.id}">
              Xóa
            </button>

            <button class="flex-1 px-4 py-3 rounded-xl bg-emerald-600 text-white font-semibold"
              data-share="${r.id}">
              Chia sẻ
            </button>

          </div>

        </div>
      `;
    }).join("");
  }

  function renderPager() {
    const pager = document.getElementById("taskPager");
    if (!pager) return;

    const p = STATE.page;
    const tp = Math.max(1, STATE.total_pages);

    pager.innerHTML = `
      <div class="flex items-center justify-between gap-3">
        <button class="px-3 py-2 rounded-xl border ${p <= 1 ? "opacity-50 pointer-events-none" : ""}" data-page="prev">
          Trước
        </button>

        <div class="text-sm text-gray-600">
          Trang <b>${p}</b> / <b>${tp}</b> • Tổng <b>${STATE.total}</b> công việc
        </div>

        <button class="px-3 py-2 rounded-xl border ${p >= tp ? "opacity-50 pointer-events-none" : ""}" data-page="next">
          Sau
        </button>
      </div>
    `;

    pager.querySelector('button[data-page="prev"]')?.addEventListener("click", () => {
      if (STATE.page > 1) {
        STATE.page--;
        loadList();
      }
    });

    pager.querySelector('button[data-page="next"]')?.addEventListener("click", () => {
      if (STATE.page < tp) {
        STATE.page++;
        loadList();
      }
    });
  }

  async function fetchDetail(id) {
    const j = await T.api("detail", { id }, "POST");
    if (!j?.ok) throw new Error(j?.error || "Lỗi detail");
    return j.data;
  }

  function taskFormHTML(row = null) {
    const isEdit = !!row;
    const t = row?.task || row || {};

    const title = T.escape(t.title || "");
    const desc = T.escape(t.description || "");
    const tags = T.escape(t.tags || "");
    const extra = T.escape(t.extra_note || "");

    const pri = t.priority || "medium";
    const st = t.status || "pending";
    // ✅ init năm học / học kỳ: ưu tiên task_items (Hướng B), fallback project
    const taskSY = Number(t.school_year_id || 0);
    const taskSem = normSemesterCode(t.semester_code || "");

    const projectSchoolYearLabel = String(t.project_school_year || "").trim();
    const projectSemester = normSemesterCode(t.project_semester || "");

    // school_year_id
    let initSchoolYearId = taskSY || 0;

    // fallback map year_label -> id
    if (!initSchoolYearId && projectSchoolYearLabel) {
      const found = (META.school_years || []).find(
        x => String(x.year_label || "").trim() === projectSchoolYearLabel
      );
      if (found) initSchoolYearId = Number(found.id || 0);
    }

    // fallback active/default
    if (!initSchoolYearId) initSchoolYearId = getDefaultSchoolYearId();

    // semester_code
    let initSemesterCode = taskSem || projectSemester || "";
    if (!initSemesterCode) initSemesterCode = getDefaultSemesterCode();


    // ✅ nếu detail có assignees thì map ra ids
    let initAssigneeIds = [];
    if (Array.isArray(row?.assignees) && row.assignees.length) {
      initAssigneeIds = row.assignees.map(x => Number(x.user_id)).filter(Boolean);
    } else if (t.assignee_id) {
      initAssigneeIds = [Number(t.assignee_id)];
    }
    // ✅ init supervisor (1 người)
    let initSupervisorId = Number(t.supervisor_id || 0);
    let initSupervisorName = String(t.supervisor_name || "").trim();

    // fallback: nếu chưa có supervisor thì lấy người thực hiện đầu tiên
    if (!initSupervisorId) {
      const first = (initAssigneeIds && initAssigneeIds.length) ? initAssigneeIds[0] : 0;
      if (first) {
        initSupervisorId = Number(first);
        const u = (META.users || []).find(x => Number(x.id) === initSupervisorId);
        initSupervisorName = u ? (u.fullname || u.username || "").trim() : "";
      }
    }

    return `
      <div class="space-y-4">
        <div>
          <label class="text-sm font-medium">Tiêu đề công việc *</label>
          <input id="mTaskTitle" class="mt-1 w-full px-3 py-2 border rounded-xl" value="${title}">
        </div>

        <div>
          <label class="text-sm font-medium">Mô tả chi tiết</label>
          <textarea id="mTaskDesc" class="mt-1 w-full px-3 py-2 border rounded-xl min-h-[60px]">${desc}</textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">


        <!-- SUPERVISOR (single) -->
            <div class="relative">
              <label class="text-sm font-medium">Người phụ trách *</label>

              <div class="relative mt-1">
                <input id="mTaskSupervisor"
                  class="w-full px-3 py-2 border rounded-xl"
                  placeholder="Gõ tên để tìm..."
                  autocomplete="off"
                  value="${T.escape(initSupervisorName)}">

                <div id="mSupervisorSuggest"
                  class="absolute left-0 top-full mt-1 w-full bg-white border rounded-xl shadow-lg hidden max-h-[260px] overflow-auto z-50">
                </div>
              </div>

              <input type="hidden" id="mTaskSupervisorId" value="${T.escape(String(initSupervisorId || ""))}">
              <p class="text-xs text-gray-500 mt-1">Bấm vào ô để chọn nhanh</p>
            </div>
          <!-- ASSIGNEES (multi) -->
          <div class="relative">
            <label class="text-sm font-medium">Người thực hiện *</label>

            <div class="mt-1 rounded-2xl border border-gray-200 bg-white shadow-sm p-3">
              <div id="mAssigneeChips" class="flex flex-wrap gap-2 mb-2"></div>

              <div class="flex items-center justify-between mb-2">
                <div id="mAssigneeSummary" class="text-xs text-gray-500">Chưa chọn ai</div>
                <button type="button" id="mAssigneeClear"
                  class="text-xs font-semibold text-gray-600 hover:text-red-600 underline underline-offset-2">
                  Xóa chọn
                </button>
              </div>

              <!-- search nhanh -->
              <div class="relative mb-2">
                <input id="mAssigneeSearch"
                  class="w-full px-3 py-2 border border-gray-200 rounded-xl bg-gray-50
                        focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
                  placeholder="Tìm nhanh trong danh sách..."
                  autocomplete="off" />
              </div>

              <!-- ✅ MULTI SELECT -->
              <select id="mTaskAssigneeSelect"
                multiple
                class="w-full h-[230px] rounded-xl border border-gray-200 bg-white px-2 py-2 text-sm
                      focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300">
              </select>

              <p class="text-xs text-gray-500 mt-2">
                Click để chọn/bỏ chọn
              </p>
            </div>

            <input type="hidden" id="mTaskAssigneeIds" value="${T.escape(initAssigneeIds.join(","))}">
          </div>

        </div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
  <div>
    <label class="text-sm font-medium">Năm học *</label>
    <select id="mTaskSchoolYearId" class="mt-1 w-full px-3 py-2 border rounded-xl">
      ${renderSchoolYearOptions(initSchoolYearId)}
    </select>
  </div>

  <div>
    <label class="text-sm font-medium">Học kỳ *</label>
    <select id="mTaskSemesterCode" class="mt-1 w-full px-3 py-2 border rounded-xl">
      ${renderSemesterOptions(initSemesterCode)}
    </select>
  </div>
</div>

                  <!-- PROJECT TEXTBOX + SUGGEST -->
          <div>
            <label class="text-sm font-medium">Dự án *</label>

            <div class="relative mt-1">
              <input id="mTaskProjectText"
                class="w-full px-3 py-2 border rounded-xl"
                placeholder="Nhập tên dự án..."
                autocomplete="off"
                value="${T.escape(t.project_text || t.project_title || "")}">

              <div id="mProjectSuggest"
                class="absolute left-0 top-full mt-1 w-full bg-white border rounded-xl shadow-lg hidden max-h-[260px] overflow-auto z-50">
              </div>
            </div>

            <p class="text-xs text-gray-500 mt-1">Gõ để lọc và chọn nhanh</p>
          </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="text-sm font-medium">Độ ưu tiên</label>
            <select id="mTaskPriority" class="mt-1 w-full px-3 py-2 border rounded-xl">
              <option value="high" ${pri === "high" ? "selected" : ""}>Cao</option>
              <option value="medium" ${pri === "medium" ? "selected" : ""}>Trung bình</option>
              <option value="low" ${pri === "low" ? "selected" : ""}>Thấp</option>
            </select>
          </div>

          <div>
            <label class="text-sm font-medium">Trạng thái</label>
            <select id="mTaskStatus" class="mt-1 w-full px-3 py-2 border rounded-xl">
              <option value="pending" ${st === "pending" ? "selected" : ""}>Chưa làm</option>
              <option value="doing" ${st === "doing" ? "selected" : ""}>Đang làm</option>
              <option value="done" ${st === "done" ? "selected" : ""}>Hoàn thành</option>
            </select>
          </div>
        </div>

        <div>
          <label class="text-sm font-medium">Hạn hoàn thành (Ngày & Giờ) *</label>
          <input id="mTaskDeadline" type="datetime-local" class="mt-1 w-full px-3 py-2 border rounded-xl"
                 value="${T.toDTLocal(t.deadline || "")}">
        </div>

        <div>
          <label class="text-sm font-medium">Tags (phân cách bằng dấu phẩy)</label>
          <input id="mTaskTags" class="mt-1 w-full px-3 py-2 border rounded-xl" value="${tags}">
        </div>

        <div>
          <label class="text-sm font-medium">Ghi chú thêm</label>
          <textarea id="mTaskExtra" class="mt-1 w-full px-3 py-2 border rounded-xl min-h-[80px]">${extra}</textarea>
        </div>

        <div class="flex gap-2 justify-end pt-2">
          <button class="px-4 py-2 rounded-xl border" onclick="closeModal()">Hủy</button>
          <button class="px-4 py-2 rounded-xl bg-purple-600 text-white" data-primary id="mTaskSaveBtn">
            ${isEdit ? "Lưu công việc" : "Tạo công việc"}
          </button>
        </div>
      </div>
    `;
  }

  function renderModalProjectSuggest(list) {
    const box = document.getElementById("mProjectSuggest");
    if (!box) return;

    if (!list.length) {
      box.innerHTML = `<div class="px-3 py-2 text-sm text-gray-500">Không thấy dự án</div>`;
      box.classList.remove("hidden");
      return;
    }

    box.innerHTML = list.map(p => {
      const title = p.title || "";
      return `
      <button type="button"
        class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
        data-pick-project="${T.escape(title)}">
        <div class="font-medium text-gray-800">${T.escape(title)}</div>
      </button>
    `;
    }).join("");

    box.classList.remove("hidden");
  }

  function hideModalProjectSuggest() {
    const box = document.getElementById("mProjectSuggest");
    if (!box) return;
    box.classList.add("hidden");
    box.innerHTML = "";
  }
  function renderSupervisorSuggest(list) {
    const box = document.getElementById("mSupervisorSuggest");
    const hid = document.getElementById("mTaskSupervisorId");
    if (!box) return;

    const pickedId = Number(hid?.value || 0);

    if (!list.length) {
      box.innerHTML = `<div class="px-3 py-2 text-sm text-gray-500">Không thấy ai</div>`;
      box.classList.remove("hidden");
      return;
    }

    box.innerHTML = list.map(u => {
      const id = Number(u.id);
      const name = (u.fullname || u.username || ("User#" + id)).trim();
      const isPicked = pickedId === id;

      return `
      <button type="button"
        class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm flex items-center justify-between gap-3
          ${isPicked ? "opacity-60 pointer-events-none" : ""}"
        data-pick-supervisor-id="${id}"
        data-pick-supervisor-name="${T.escape(name)}">

        <div class="min-w-0">
          <div class="font-medium text-gray-800 truncate">${T.escape(name)}</div>
          <div class="text-xs text-gray-500">#${id}</div>
        </div>

        ${isPicked
          ? `<span class="text-xs font-semibold px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">✅ Đã chọn</span>`
          : `<span class="text-xs text-gray-400">Chọn</span>`
        }
      </button>
    `;
    }).join("");

    box.classList.remove("hidden");
  }

  function hideSupervisorSuggest() {
    const box = document.getElementById("mSupervisorSuggest");
    if (!box) return;
    box.classList.add("hidden");
    box.innerHTML = "";
  }

  function bindModalSupervisorTypeahead() {
    const ip = document.getElementById("mTaskSupervisor");
    const hid = document.getElementById("mTaskSupervisorId");
    const box = document.getElementById("mSupervisorSuggest");
    if (!ip || !hid || !box) return;

    const show = (qOverride = null) => {
      const q = (qOverride !== null) ? String(qOverride) : ip.value.trim();
      const list = filterAssignees(q);
      renderSupervisorSuggest(list);
    };

    // ✅ GÕ thì lọc theo text
    ip.addEventListener("input", () => {
      clearTimeout(ip._tSup);
      ip._tSup = setTimeout(() => {
        if (!ip.value.trim()) hid.value = "";
        show(); // lọc theo text đang gõ
      }, 120);
    });

    // ✅ CLICK / FOCUS: luôn show danh sách (không phụ thuộc text hiện tại)
    const showAll = () => show(""); // empty => META.users.slice(0,10)
    ip.addEventListener("focus", showAll);

    // quan trọng: khi input đã focus rồi, click lại vẫn mở list
    ip.addEventListener("click", showAll);

    // (tuỳ chọn) mousedown để mở “ăn” chắc hơn trên vài trình duyệt
    ip.addEventListener("mousedown", showAll);

    box.addEventListener("mousedown", (e) => {
      const btn = e.target.closest("button[data-pick-supervisor-id]");
      if (!btn) return;

      e.preventDefault();

      const id = Number(btn.getAttribute("data-pick-supervisor-id") || 0);
      const name = btn.getAttribute("data-pick-supervisor-name") || "";

      if (id) {
        hid.value = String(id);
        ip.value = name;
      }

      hideSupervisorSuggest();
      ip.focus();
    });

    ip.addEventListener("blur", () => {
      setTimeout(hideSupervisorSuggest, 150);
    });
  }


  function bindModalProjectTypeahead() {
    const ip = document.getElementById("mTaskProjectText");
    const box = document.getElementById("mProjectSuggest");
    if (!ip || !box) return;

    const show = () => {
      const list = filterProjects(ip.value.trim());
      renderModalProjectSuggest(list);
    };

    ip.addEventListener("input", () => {
      clearTimeout(ip._tProj);
      ip._tProj = setTimeout(show, 120);
    });

    ip.addEventListener("focus", show);

    box.addEventListener("mousedown", (e) => {
      const btn = e.target.closest("button[data-pick-project]");
      if (!btn) return;

      e.preventDefault();

      const title = btn.getAttribute("data-pick-project") || "";
      ip.value = title;

      hideModalProjectSuggest();
      ip.focus();
    });

    ip.addEventListener("blur", () => {
      setTimeout(hideModalProjectSuggest, 150);
    });

    // click ngoài -> đóng
    document.addEventListener("mousedown", (e) => {
      const inside =
        e.target.closest("#mProjectSuggest") || e.target.closest("#mTaskProjectText");
      if (!inside) hideModalProjectSuggest();
    });
  }
  function syncAssigneeSelectFromHidden() {
    const sel = document.getElementById("mTaskAssigneeSelect");
    const hid = document.getElementById("mTaskAssigneeIds");
    if (!sel || !hid) return;

    const ids = parseIds(hid.value);
    for (const opt of sel.options) {
      opt.selected = ids.includes(Number(opt.value));
    }
  }

  function bindModalAssigneeSelect() {
    const sel = document.getElementById("mTaskAssigneeSelect");
    const hid = document.getElementById("mTaskAssigneeIds");
    const ipSearch = document.getElementById("mAssigneeSearch");
    const btnClear = document.getElementById("mAssigneeClear");
    if (!sel || !hid) return;

    function renderOptions(filterText = "") {
      const nq = normVN(filterText || "");
      const pickedIds = parseIds(hid.value);

      let users = Array.isArray(META.users) ? META.users.slice() : [];

      // ✅ ưu tiên người đã chọn lên đầu cho dễ nhìn
      users.sort((a, b) => {
        const aSel = pickedIds.includes(Number(a.id)) ? 1 : 0;
        const bSel = pickedIds.includes(Number(b.id)) ? 1 : 0;
        if (bSel !== aSel) return bSel - aSel;

        const an = String(a.fullname || a.username || "");
        const bn = String(b.fullname || b.username || "");
        return an.localeCompare(bn, "vi");
      });

      sel.innerHTML = "";

      for (const u of users) {
        const id = Number(u.id);
        const name = (u.fullname || u.username || ("User#" + id)).trim();

        // ✅ filter theo search
        if (nq) {
          const n = normVN(name);
          if (!n.includes(nq) && !String(id).includes(nq)) continue;
        }

        const opt = document.createElement("option");
        opt.value = String(id);

        // ✅ BỎ "(#id)" -> chỉ hiện tên
        opt.textContent = name;

        opt.selected = pickedIds.includes(id);
        sel.appendChild(opt);
      }

      if (!sel.options.length) {
        const opt = document.createElement("option");
        opt.value = "";
        opt.textContent = "Không có kết quả";
        opt.disabled = true;
        sel.appendChild(opt);
      }
    }

    function syncFromSelect() {
      const ids = Array.from(sel.selectedOptions)
        .map(o => Number(o.value))
        .filter(n => Number.isFinite(n) && n > 0);

      setIdsToHidden(hid, ids);
      renderAssigneeChips(); // chips + summary auto update
    }

    // ✅ render lần đầu
    renderOptions(ipSearch?.value?.trim() || "");
    renderAssigneeChips();

    // ✅ CLICK 1 cái là toggle (khỏi Ctrl/Cmd)
    sel.addEventListener("mousedown", (e) => {
      const opt = e.target.closest("option");
      if (!opt || opt.disabled) return;

      e.preventDefault();

      const prevScroll = sel.scrollTop;

      // toggle chọn/bỏ chọn
      opt.selected = !opt.selected;

      // giữ scroll không nhảy lung tung
      sel.focus();
      sel.scrollTop = prevScroll;

      syncFromSelect();
    });

    // ✅ vẫn support bàn phím
    sel.addEventListener("change", syncFromSelect);

    // ✅ search lọc option
    if (ipSearch) {
      ipSearch.addEventListener("input", () => {
        renderOptions(ipSearch.value.trim());
        // giữ selection đúng theo hidden
        syncAssigneeSelectFromHidden();
      });
    }

    // ✅ Clear all
    if (btnClear) {
      btnClear.addEventListener("click", () => {
        hid.value = "";
        renderOptions(ipSearch?.value?.trim() || "");
        renderAssigneeChips();
      });
    }
  }



  function openCreateModal() {
    if (typeof window.modal !== "function") return T.toast("Thiếu modal() trong app.js");
    modal(taskFormHTML(null), "Thêm công việc", "large");
    bindAssigneeChipsEvents();
    bindModalAssigneeSelect();
    bindModalProjectTypeahead(); // ✅ THÊM
    bindModalSupervisorTypeahead(); // ✅ THÊM

    bindModalSave(0);
  }

  async function openEditModal(id) {
    try {
      const d = await fetchDetail(id);
      modal(taskFormHTML(d), "Cập nhật công việc", "large");
      bindAssigneeChipsEvents();
      bindModalAssigneeSelect();
      bindModalProjectTypeahead(); // ✅ THÊM
      bindModalSupervisorTypeahead(); // ✅ THÊM

      bindModalSave(id);
    } catch (e) {
      T.toast(e.message || "Lỗi mở modal");
    }
  }

  function bindModalTypeahead() {
    const ipUser = document.getElementById("mTaskAssignee");
    const hid = document.getElementById("mTaskAssigneeIds");
    const box = document.getElementById("mAssigneeSuggest");
    if (!ipUser || !hid || !box) return;

    renderAssigneeChips();

    const show = () => {
      const list = filterAssignees(ipUser.value.trim());
      renderModalSuggest(list);
    };

    ipUser.addEventListener("input", () => {
      clearTimeout(ipUser._t);
      ipUser._t = setTimeout(show, 120);
    });

    ipUser.addEventListener("focus", show);

    box.addEventListener("mousedown", (e) => {
      const btn = e.target.closest("button[data-pick-id]");
      if (!btn) return;

      e.preventDefault();

      const pickId = Number(btn.getAttribute("data-pick-id"));
      if (!pickId) return;

      const ids = parseIds(hid.value);
      if (!ids.includes(pickId)) ids.push(pickId);

      setIdsToHidden(hid, ids);
      renderAssigneeChips();

      ipUser.value = "";
      hideEl(box);
      ipUser.focus();
    });

    ipUser.addEventListener("blur", () => {
      setTimeout(() => hideEl(box), 150);
    });

    document.addEventListener("mousedown", (e) => {
      const ip = document.getElementById("mTaskAssignee");
      const bx = document.getElementById("mAssigneeSuggest");
      if (!ip || !bx) return;
      const inside = e.target.closest("#mAssigneeSuggest") || e.target.closest("#mTaskAssignee");
      if (!inside) hideEl(bx);
    });
  }

  function bindModalSave(editId) {
    const btn = document.getElementById("mTaskSaveBtn");
    if (!btn) return;

    btn.onclick = async () => {
      const title = document.getElementById("mTaskTitle")?.value?.trim() || "";
      const description = document.getElementById("mTaskDesc")?.value?.trim() || "";
      const assignee_ids = parseIds(document.getElementById("mTaskAssigneeIds")?.value || "");
      const project_text = document.getElementById("mTaskProjectText")?.value?.trim() || "";
      const priority = document.getElementById("mTaskPriority")?.value || "medium";
      const status = document.getElementById("mTaskStatus")?.value || "pending";
      const deadlineLocal = document.getElementById("mTaskDeadline")?.value || "";
      const tags = document.getElementById("mTaskTags")?.value?.trim?.() || "";
      const extra_note = document.getElementById("mTaskExtra")?.value?.trim?.() || "";
      const supervisor_id = Number(document.getElementById("mTaskSupervisorId")?.value || 0);
      const school_year_id = Number(document.getElementById("mTaskSchoolYearId")?.value || 0);
      const semester_code = String(document.getElementById("mTaskSemesterCode")?.value || "").trim();
      if (!school_year_id) return T.toast("Thiếu năm học");
      if (!semester_code) return T.toast("Thiếu học kỳ");

      if (!supervisor_id) return T.toast("Thiếu người phụ trách");
      if (!title) return T.toast("Thiếu tiêu đề");
      if (!assignee_ids.length) return T.toast("Thiếu người thực hiện");
      if (!project_text) return T.toast("Thiếu dự án");
      if (!deadlineLocal) return T.toast("Thiếu hạn hoàn thành");

      // ✅ primary assignee = người đầu tiên
      const primary = assignee_ids[0];

      const payload = {
        title,
        description,
        assignee_id: primary,
        assignee_ids: assignee_ids,     // ✅ gửi đầy đủ nhiều người
        supervisor_id,     // ✅ NEW
        project_text,
        school_year_id,
        semester_code,
        priority,
        status,
        deadline: T.fromDTLocal(deadlineLocal),
        tags,
        extra_note,
      };

      const action = editId ? "update" : "create";
      if (editId) payload.id = editId;

      const j = await T.api(action, payload, "POST");
      if (!j?.ok) return T.toast(j?.error || "Lỗi lưu");

      closeModal();
      await loadList();
      T.toast(editId ? "Đã cập nhật" : "Đã tạo");
    };
  }

  async function deleteTask(id) {
    if (!confirm("Xóa công việc này?")) return;
    const j = await T.api("delete", { id }, "POST");
    if (!j?.ok) return T.toast(j?.error || "Lỗi xóa");
    await loadList();
    T.toast("Đã xóa");
  }
  function buildSharePayload(r) {
    const id = Number(r?.id || 0);
    const title = String(r?.title || "").trim();
    const project = String(r?.project_title || r?.project_text || "").trim();
    const assignees = String(r?.assignees || r?.assignee_name || "").trim();
    const deadline = String(T?.fmtDT ? T.fmtDT(r?.deadline) : (r?.deadline || "")).trim();
    const desc = String(r?.description || "").trim();

    const url = `${location.origin}${location.pathname}?p=tasks&task_id=${id}`;

    const lines = [
      title || "Công việc",
      `Dự án: ${project || "-"}`,
      `Người thực hiện: ${assignees || "-"}`,
      `Hạn: ${deadline || "-"}`,
    ];

    if (desc) lines.push(`Mô tả: ${desc}`);
    lines.push(`Link: ${url}`);

    return {
      id,
      title,
      project,
      assignees,
      deadline,
      desc,
      url,
      text: lines.join("\n"),
    };
  }

  function shareModalHTML(p) {
    const canNativeShare = !!navigator.share;

    return `
  <div class="space-y-4">
    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
      <div class="text-xs font-semibold text-gray-500">NỘI DUNG CHIA SẺ</div>

      <div class="mt-2 rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm text-gray-900 whitespace-pre-wrap">
${T.escape(p.text)}
      </div>

      <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
        <button id="btnShareCopyText"
          class="px-4 py-3 rounded-xl bg-indigo-600 text-white font-semibold hover:opacity-95">
          Copy nội dung
        </button>

        <button id="btnShareCopyLink"
          class="px-4 py-3 rounded-xl border border-gray-200 bg-white text-gray-800 font-semibold hover:bg-gray-50">
          Copy link
        </button>

        ${canNativeShare
        ? `<button id="btnShareNative"
                class="md:col-span-2 px-4 py-3 rounded-xl bg-emerald-600 text-white font-semibold hover:opacity-95">
                Chia sẻ hệ thống
              </button>`
        : ""
      }
      </div>

      <div class="mt-3 text-xs text-gray-500">
        Tip: Copy nội dung xong quăng vào nhóm BCH là chiến.
      </div>
    </div>

    <div class="flex justify-end gap-2">
      <button class="px-4 py-2 rounded-xl border" onclick="closeModal()">Đóng</button>
    </div>
  </div>
  `;
  }
  function buildTaskShareUrl(id) {
    const u = new URL(window.location.href);

    // ép về trang tasks
    u.searchParams.set("p", "tasks");

    // chỉ xem 1 task
    u.searchParams.set("task_id", String(id));

    // dọn mấy param filter/paging nếu có
    u.searchParams.delete("page");
    u.searchParams.delete("page_size");
    u.searchParams.delete("status");
    u.searchParams.delete("q");
    u.searchParams.delete("assignee_id");
    u.searchParams.delete("project_text");

    return u.toString();
  }

  function generateQRCode(text, containerId) {
    const box = document.getElementById(containerId);
    if (!box) return;

    // dùng service QR giống mày làm bên campaigns
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(text)}`;

    box.innerHTML = `
    <div class="inline-flex flex-col items-center gap-2">
      <div class="p-3 bg-white rounded-2xl border shadow-sm">
        <img src="${qrUrl}" alt="QR" class="w-[220px] h-[220px]">
      </div>
      <div class="text-xs text-gray-500">Quét QR để mở công việc</div>
    </div>
  `;
  }

  function shareToSocial(platform, url, title = "") {
    const encodedUrl = encodeURIComponent(url);
    const encodedTitle = encodeURIComponent(title);

    let shareUrl = "";

    if (platform === "facebook") {
      shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
    }

    // Zalo share URL (giống mày đang dùng)
    if (platform === "zalo") {
      shareUrl = `https://zalo.me/share?url=${encodedUrl}&title=${encodedTitle}`;
    }

    if (shareUrl) {
      window.open(shareUrl, "_blank", "width=720,height=520");
    }
  }

  async function copyToClipboard(text) {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (_) { }

    // fallback
    try {
      const ta = document.createElement("textarea");
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand("copy");
      document.body.removeChild(ta);
      return true;
    } catch (_) {
      return false;
    }
  }

  async function openShareModal(id) {
    try {
      let r = TASK_MAP?.get(Number(id));

      // nếu cache không có -> gọi detail
      if (!r) {
        const d = await fetchDetail(id);
        const t = d?.task || {};

        const assignees = Array.isArray(d?.assignees)
          ? d.assignees.map(x => x.fullname || x.username || "").filter(Boolean).join(", ")
          : "";

        r = {
          id: t.id || id,
          title: t.title || "",
          project_text: t.project_text || t.project_title || "",
          deadline: t.deadline || "",
          assignees: assignees || "",
          description: t.description || "",
          status: t.status || "",
          priority: t.priority || "",
        };
      }

      const shareUrl = buildTaskShareUrl(Number(id));
      const title = String(r.title || "Công việc").trim();

      const html = `
      <div class="space-y-4">

        <!-- LINK -->
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="text-sm font-semibold text-gray-900 mb-2">Link chia sẻ</div>

          <div class="flex gap-2">
            <input
              id="taskShareUrlInput"
              value="${T.escape(shareUrl)}"
              readonly
              class="flex-1 px-3 py-2 rounded-xl border border-gray-200 bg-gray-50 text-sm
                     focus:outline-none focus:ring-2 focus:ring-indigo-200 focus:border-indigo-300"
            />
            <button
              id="btnTaskCopyLink"
              class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-semibold hover:opacity-95"
              type="button"
            >
              Copy
            </button>
          </div>

          <div id="taskCopyHint" class="mt-2 text-xs text-emerald-600 hidden">
            Đã copy link
          </div>
        </div>

        <!-- QR -->
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm text-center">
          <div class="text-sm font-semibold text-gray-900 mb-3">Mã QR</div>
          <div id="taskQrBox"></div>
        </div>

        <!-- SOCIAL -->
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
          <div class="text-sm font-semibold text-gray-900 mb-3">Chia sẻ nhanh</div>

          <div class="flex flex-wrap gap-2 justify-center">
            <button
              id="btnTaskShareFB"
              type="button"
              class="px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold hover:opacity-95 flex items-center gap-2"
            >
              <span class="inline-block w-4 h-4">
                <svg viewBox="0 0 24 24" fill="currentColor">
                  <path d="M22 12a10 10 0 1 0-11.5 9.9v-7h-2.1V12h2.1V9.8c0-2.1 1.3-3.3 3.2-3.3.9 0 1.8.1 1.8.1v2h-1c-1 0-1.3.6-1.3 1.2V12h2.2l-.3 2.9h-1.9v7A10 10 0 0 0 22 12z"/>
                </svg>
              </span>
              Facebook
            </button>

            <button
              id="btnTaskShareZalo"
              type="button"
              class="px-4 py-2 rounded-xl bg-sky-500 text-white font-semibold hover:opacity-95"
            >
              Zalo
            </button>

            <button
              id="btnTaskCopyLink2"
              type="button"
              class="px-4 py-2 rounded-xl border border-gray-200 bg-white font-semibold hover:bg-gray-50"
            >
              Copy link
            </button>
          </div>
        </div>

        <div class="flex justify-end">
          <button class="px-4 py-2 rounded-xl border" onclick="closeModal()">Đóng</button>
        </div>
      </div>
    `;

      modal(html, `Chia sẻ: ${T.escape(title)}`, "medium");

      // QR render
      generateQRCode(shareUrl, "taskQrBox");

      const hint = document.getElementById("taskCopyHint");

      const doCopy = async () => {
        const ok = await copyToClipboard(shareUrl);
        if (ok) {
          if (hint) {
            hint.classList.remove("hidden");
            setTimeout(() => hint.classList.add("hidden"), 1500);
          }
          T.toast("Đã copy link");
        } else {
          T.toast("Copy thất bại", "error");
        }
      };

      document.getElementById("btnTaskCopyLink")?.addEventListener("click", doCopy);
      document.getElementById("btnTaskCopyLink2")?.addEventListener("click", doCopy);

      document.getElementById("btnTaskShareFB")?.addEventListener("click", () => {
        shareToSocial("facebook", shareUrl, title);
      });

      document.getElementById("btnTaskShareZalo")?.addEventListener("click", () => {
        shareToSocial("zalo", shareUrl, title);
      });

    } catch (e) {
      console.error(e);
      T.toast("Không mở được modal chia sẻ", "error");
    }
  }


  /* =========================
   FILTER HANDLERS
========================= */

  function debounce(fn, ms = 250) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  function renderAssigneeSuggestFilter(list) {
    const box = document.getElementById("assigneeSuggest");
    if (!box) return;

    if (!list.length) {
      box.innerHTML = `<div class="px-3 py-2 text-sm text-gray-500">Không thấy ai</div>`;
      box.classList.remove("hidden");
      return;
    }

    box.innerHTML = list
      .map((u) => {
        const name = u.fullname || u.username || ("User#" + u.id);
        return `
        <button type="button"
          class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
          data-pick-id="${u.id}"
          data-pick-name="${T.escape(name)}">
          <div class="font-medium text-gray-800">${T.escape(name)}</div>
          <div class="text-xs text-gray-500">#${u.id}</div>
        </button>
      `;
      })
      .join("");

    box.classList.remove("hidden");
  }

  function hideAssigneeSuggestFilter() {
    const box = document.getElementById("assigneeSuggest");
    if (!box) return;
    box.classList.add("hidden");
    box.innerHTML = "";
  }

  function applyFilterAndReload() {
    STATE.page = 1;
    loadList();
  }

  function bindFilterEvents() {
    const ipProject = document.getElementById("taskFProject");
    const boxProject = document.getElementById("projectSuggest");
    const ipAssignee = document.getElementById("taskFAssignee");
    const box = document.getElementById("assigneeSuggest");
    const selStatus = document.getElementById("taskFStatus");

    if (ipProject && boxProject) {
      const showSuggest = debounce(() => {
        renderProjectSuggest(filterProjects(ipProject.value.trim()));
      }, 120);

      ipProject.addEventListener("input", debounce(() => {
        const val = ipProject.value.trim();

        // ✅ xóa trống => clear filter + reload liền
        if (!val) {
          STATE.project_text = "";
          hideProjectSuggest();
          applyFilterAndReload();
          return;
        }

        // ✅ vẫn filter theo text đang gõ
        STATE.project_text = val;
        applyFilterAndReload();

        // ✅ hiện gợi ý
        showSuggest();
      }, 180));

      ipProject.addEventListener("focus", () => {
        renderProjectSuggest(filterProjects(ipProject.value.trim()));
      });

      // ✅ chọn dự án bằng mousedown (ăn trước blur)
      boxProject.addEventListener("mousedown", (e) => {
        const btn = e.target.closest("button[data-pick-project]");
        if (!btn) return;

        e.preventDefault();

        const title = btn.getAttribute("data-pick-project") || "";
        ipProject.value = title;

        STATE.project_text = title;   // ✅ set filter
        hideProjectSuggest();
        applyFilterAndReload();
      });

      ipProject.addEventListener("blur", () => {
        setTimeout(hideProjectSuggest, 150);
      });

      // ✅ click ngoài -> đóng suggest
      document.addEventListener("mousedown", (e) => {
        const inside =
          e.target.closest("#projectSuggest") || e.target.closest("#taskFProject");
        if (!inside) hideProjectSuggest();
      });

      // ✅ Enter -> reload luôn
      ipProject.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          applyFilterAndReload();
        }
      });
    }

    // ✅ status: đổi là reload
    if (selStatus) {
      selStatus.addEventListener("change", () => {
        STATE.status = selStatus.value || "";
        applyFilterAndReload();
      });
    }

    // ✅ assignee typeahead:
    // - gõ: show suggest + clear assignee_id
    // - chọn: set assignee_id + reload
    if (ipAssignee && box) {
      const showSuggest = debounce(() => {
        const val = ipAssignee.value.trim();

        // gõ lại thì clear selection cũ
        STATE.assignee_id = "";

        renderAssigneeSuggestFilter(filterAssignees(val));
      }, 120);

      ipAssignee.addEventListener("input", () => {
        const val = ipAssignee.value.trim();

        // ✅ nếu xóa trống => clear filter + reload luôn
        if (!val) {
          STATE.assignee_id = "";
          hideAssigneeSuggestFilter();
          applyFilterAndReload(); // auto reload, khỏi Enter
          return;
        }

        // ✅ còn đang gõ => chỉ show suggest, chưa reload
        showSuggest();
      });


      ipAssignee.addEventListener("focus", () => {
        renderAssigneeSuggestFilter(filterAssignees(ipAssignee.value.trim()));
      });

      // ✅ mousedown để ăn trước blur
      box.addEventListener("mousedown", (e) => {
        const btn = e.target.closest("button[data-pick-id]");
        if (!btn) return;

        e.preventDefault();

        const id = btn.getAttribute("data-pick-id");
        const name = btn.getAttribute("data-pick-name") || "";

        STATE.assignee_id = String(id);
        ipAssignee.value = name;

        hideAssigneeSuggestFilter();
        applyFilterAndReload();
      });

      ipAssignee.addEventListener("blur", () => {
        setTimeout(hideAssigneeSuggestFilter, 150);
      });

      // click ngoài thì đóng suggest
      document.addEventListener("mousedown", (e) => {
        const inside =
          e.target.closest("#assigneeSuggest") || e.target.closest("#taskFAssignee");
        if (!inside) hideAssigneeSuggestFilter();
      });
    }

    // ✅ Enter trong input → reload luôn
    const enterReload = (el) => {
      if (!el) return;
      el.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          applyFilterAndReload();
        }
      });
    };

    enterReload(ipProject);
    enterReload(ipAssignee);
  }



  function bindEvents() {
    document.getElementById("taskBtnNew")?.addEventListener("click", openCreateModal);

    // ✅ FILTER
    bindFilterEvents();

    // ✅ card actions
    document.getElementById("taskGrid")?.addEventListener("click", (e) => {
      const btn = e.target.closest("button");
      if (!btn) return;

      const editId = btn.getAttribute("data-edit");
      const shareId = btn.getAttribute("data-share");
      const delId = btn.getAttribute("data-del");

      if (editId) return openEditModal(Number(editId));
      if (shareId) return openShareModal(Number(shareId));
      if (delId) return deleteTask(Number(delId));


    });
  }


  async function init() {
    await loadMeta();

    // ✅ NEW: set default & bind filter selects nếu có
    setupListYearSemesterFilterUI();

    bindEvents();

    const sp = new URLSearchParams(window.location.search);
    const vid = Number(sp.get("task_id") || sp.get("view") || 0) || 0;
    STATE.view_task_id = vid;

    if (STATE.view_task_id) {
      await loadSingleTask(STATE.view_task_id);
    } else {
      await loadList();
    }
  }


  init();
})();
