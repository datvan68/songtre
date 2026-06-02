// assets/js/tasks/tasks.user.js
(function () {
  const app = document.getElementById("tasks-app");
  if (!app || app.dataset.view !== "user") return;

  const T = window.Tasks;
  if (!T || typeof T.api !== "function") {
    console.error("Tasks core missing: window.Tasks.api");
    return;
  }

  /* =========================
     FALLBACK HELPERS
  ========================= */
  if (typeof T.escape !== "function") {
    T.escape = (s) =>
      String(s ?? "").replace(/[&<>"']/g, (m) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      }[m]));
  }

  if (typeof T.toast !== "function") {
    T.toast = (m) => alert(m);
  }

  if (typeof T.fmtDT !== "function") {
    T.fmtDT = (v) => (v ? String(v).replace("T", " ").slice(0, 16) : "");
  }

  if (typeof T.badgePriority !== "function") {
    T.badgePriority = (p) => {
      const x = String(p || "medium");
      if (x === "high") return `<span class="px-2 py-1 rounded-full bg-red-50 text-red-700 text-xs font-semibold">Cao</span>`;
      if (x === "low") return `<span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700 text-xs font-semibold">Thấp</span>`;
      return `<span class="px-2 py-1 rounded-full bg-yellow-50 text-yellow-800 text-xs font-semibold">Trung bình</span>`;
    };
  }

  /* =========================
     STATE
  ========================= */
  let META = { projects: [] };

  let STATE = {
    page: 1,
    page_size: 6,
    project_text: "",
    status: "",
    q: "",
    sort: "deadline_asc",

    // ✅ NEW: năm học / học kỳ (mặc định Tất cả)
    school_year_id: 0,
    semester_code: "",

    total_pages: 1,
    total: 0,
  };


  /* =========================
     UTILS
  ========================= */
  function normVN(str = "") {
    return String(str)
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/đ/g, "d")
      .trim();
  }
  function normSemesterCode(v = "") {
    return String(v || "").toUpperCase().replace(/\s+/g, "");
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
    const sel = normSemesterCode(selectedCode || "");
    return list.map(s => {
      const code = normSemesterCode(s.code || "");
      const label = s.label || code;
      return `<option value="${T.escape(code)}" ${code === sel ? "selected" : ""}>${T.escape(label)}</option>`;
    }).join("");
  }

  /**
   * ✅ nếu user page có 2 select:
   *   #taskFSchoolYearId, #taskFSemesterCode
   * thì fill options + bind change.
   * Default luôn là "Tất cả" (0 / "")
   */
  function setupListYearSemesterFilterUI() {
    const sp = new URLSearchParams(window.location.search);

    // ✅ chỉ set theo URL nếu URL thật sự có param
    if (sp.has("school_year_id")) STATE.school_year_id = Number(sp.get("school_year_id") || 0) || 0;
    if (sp.has("semester_code") || sp.has("semester")) {
      STATE.semester_code = normSemesterCode(sp.get("semester_code") || sp.get("semester") || "");
    }

    const selSY = document.getElementById("taskFSchoolYearId");
    const selHK = document.getElementById("taskFSemesterCode");

    if (selSY) {
      selSY.innerHTML =
        `<option value="">Tất cả</option>` +
        renderSchoolYearOptions(STATE.school_year_id || 0);

      selSY.value = STATE.school_year_id ? String(STATE.school_year_id) : "";

      selSY.addEventListener("change", () => {
        const v = Number(selSY.value || 0);
        STATE.school_year_id = v || 0;
        STATE.page = 1;
        loadList();
      });
    }

    if (selHK) {
      selHK.innerHTML =
        `<option value="">Tất cả</option>` +
        renderSemesterOptions(STATE.semester_code || "");

      selHK.value = STATE.semester_code ? String(STATE.semester_code) : "";

      selHK.addEventListener("change", () => {
        const v = normSemesterCode(selHK.value || "");
        STATE.semester_code = v || "";
        STATE.page = 1;
        loadList();
      });
    }
  }

  function debounce(fn, ms = 250) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  function isOverdueTask(r) {
    if (!r) return false;
    if (String(r.status || "") === "done") return false;
    if (!r.deadline) return false;
    const d = new Date(String(r.deadline).replace(" ", "T"));
    if (isNaN(d.getTime())) return false;
    return d.getTime() < Date.now();
  }

  function computeEfficiency(total, done) {
    total = Number(total || 0);
    done = Number(done || 0);
    if (total <= 0) return 0;
    return Math.round((done / total) * 100);
  }

  /* =========================
     BADGES / COLORS
  ========================= */
  function statusBadgeHTML(status) {
    const s = String(status || "pending");

    if (s === "doing") {
      return `<span class="inline-flex items-center px-2.5 py-1 rounded-full bg-blue-600 text-white text-xs font-semibold">Đang làm</span>`;
    }
    if (s === "done") {
      return `<span class="inline-flex items-center px-2.5 py-1 rounded-full bg-emerald-600 text-white text-xs font-semibold">Hoàn thành</span>`;
    }
    return `<span class="inline-flex items-center px-2.5 py-1 rounded-full bg-gray-200 text-gray-800 text-xs font-semibold">Chưa bắt đầu</span>`;
  }

  function overdueBadgeHTML() {
    return `<span class="inline-flex items-center px-2.5 py-1 rounded-full bg-red-50 text-red-700 text-xs font-semibold border border-red-100">Quá hạn</span>`;
  }

  function cardClassByStatus(status, overdue) {
    const s = String(status || "pending");

    if (overdue && s !== "done") return "border-red-200 bg-white";
    if (s === "doing") return "border-blue-200 bg-white";
    if (s === "done") return "border-emerald-200 bg-white";

    return "border-gray-200 bg-white";
  }

  /* =========================
     PROJECT SUGGEST
  ========================= */
  function filterProjects(q) {
    const list = META.projects || [];
    const nq = normVN(q);

    if (!nq) return list.slice(0, 12);

    const scored = [];
    for (const p of list) {
      const title = String(p.title || "");
      const nt = normVN(title);

      let score = 0;
      if (nt.startsWith(nq)) score = 2;
      else if (nt.includes(nq)) score = 1;

      if (score > 0) scored.push({ p, score, title });
    }

    scored.sort((a, b) => {
      if (b.score !== a.score) return b.score - a.score;
      return String(a.title).localeCompare(String(b.title));
    });

    return scored.slice(0, 12).map(x => x.p);
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
      const title = T.escape(p.title || "");
      return `
        <button type="button"
          class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
          data-pick-title="${title}">
          <div class="font-medium text-gray-800">${title}</div>
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

  /* =========================
     LOAD META
  ========================= */
  async function loadMeta() {
    const j = await T.api("meta", {}, "POST");
    if (!j?.ok) {
      T.toast(j?.error || "Lỗi meta");
      return;
    }
    META = j.data || META;
  }

  /* =========================
     LOAD LIST
  ========================= */
  async function loadList() {
    const payload = {
      page: STATE.page,
      page_size: STATE.page_size,
      project_text: STATE.project_text || "",
      assignee_id: "",
      status: STATE.status || "",
      q: STATE.q || "",
      sort: STATE.sort || "deadline_asc",

      // ✅ NEW
      school_year_id: Number(STATE.school_year_id || 0) || 0,
      semester_code: normSemesterCode(STATE.semester_code || ""),
    };


    const j = await T.api("list", payload, "POST");
    if (!j?.ok) {
      T.toast(j?.error || "Lỗi load list");
      return;
    }

    const rows = j.data?.rows || [];
    const paging = j.data?.paging || {};
    STATE.total_pages = Number(paging.total_pages || 1);
    STATE.total = Number(paging.total || rows.length || 0);

    // stats
    const stats = j.data?.stats || { total: STATE.total, pending: 0, doing: 0, done: 0, overdue: 0 };
    renderStats(stats);

    // ✅ nếu backend chưa sort, ta sort client luôn cho chắc
    const sorted = sortClient(rows, STATE.sort);

    renderGrid(sorted);
    renderPager();
  }

  function sortClient(rows, sortKey) {
    const arr = Array.isArray(rows) ? [...rows] : [];
    const key = String(sortKey || "deadline_asc");

    const priRank = (p) => {
      const x = String(p || "medium");
      if (x === "high") return 3;
      if (x === "medium") return 2;
      return 1;
    };

    const dt = (s) => {
      if (!s) return 0;
      const d = new Date(String(s).replace(" ", "T"));
      return isNaN(d.getTime()) ? 0 : d.getTime();
    };

    arr.sort((a, b) => {
      if (key === "deadline_desc") return dt(b.deadline) - dt(a.deadline);
      if (key === "priority_desc") return priRank(b.priority) - priRank(a.priority);
      if (key === "priority_asc") return priRank(a.priority) - priRank(b.priority);

      if (key === "created_asc") return dt(a.created_at) - dt(b.created_at);
      if (key === "created_desc") return dt(b.created_at) - dt(a.created_at);

      // default deadline_asc
      return dt(a.deadline) - dt(b.deadline);
    });

    return arr;
  }

  /* =========================
     STATS RENDER
  ========================= */
  function renderStats(stats) {
    const total = Number(stats?.total || 0);
    const pending = Number(stats?.pending || 0);
    const doing = Number(stats?.doing || 0);
    const done = Number(stats?.done || 0);
    const overdue = Number(stats?.overdue || 0);
    const eff = computeEfficiency(total, done);

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

  function calcResultType(finishedAt, deadline) {
    if (!finishedAt || !deadline) return "ontime";
    const f = new Date(String(finishedAt).replace(" ", "T"));
    const d = new Date(String(deadline).replace(" ", "T"));
    if (isNaN(f.getTime()) || isNaN(d.getTime())) return "ontime";

    if (f.getTime() < d.getTime()) return "early";
    if (f.getTime() > d.getTime()) return "late";
    return "ontime";
  }

  /* =========================
     GRID RENDER (3 cột / 6 card)
  ========================= */
  function renderGrid(rows) {
    const box = document.getElementById("taskListBox");
    if (!box) return;

    if (!rows.length) {
      box.innerHTML = `
      <div class="col-span-full py-12 text-center text-gray-500">
        Không có công việc phù hợp
      </div>
    `;
      return;
    }

    box.innerHTML = rows.map((r) => {
      const title = T.escape(r.title || "");
      const desc = T.escape(r.description || "");
      const project = T.escape(r.project_title || r.project_text || "");
      const deadline = T.escape(T.fmtDT(r.deadline));
      const priorityBadge = T.badgePriority(r.priority || "medium");

      const overdue = isOverdueTask(r);
      const baseStatus = statusBadgeHTML(r.status);
      const overdueBadge = overdue ? overdueBadgeHTML() : "";

      const cardCls = cardClassByStatus(r.status, overdue);

      const totalA = Number(r.assignee_total || 0);
      const doneA = Number(r.assignee_done || 0);

      // nếu là multi assignees => progress theo X/Y
      const progress = totalA > 0 ? computeEfficiency(totalA, doneA) : Number(r.progress || 0);

      const progressText = totalA > 0
        ? `${doneA}/${totalA} (${progress}%)`
        : `${progress}%`;
      const assigner = T.escape(r.assigner_name || "");

      // ✅ DONE BOX
      const isMulti = totalA > 0;

      const taskDone = String(r.status || "") === "done";   // tổng task (10/10)
      const myDone = String(r.my_status || "") === "done";  // phần của mình

      // mình đã done hay chưa (multi -> theo myDone, single -> theo taskDone)
      const iAmDone = isMulti ? myDone : taskDone;

      // ✅ FIX: biến isDone bị thiếu
      const isDone = taskDone || iAmDone;

      // ✅ finished_at hiển thị hợp lý:
      // - nếu taskDone => ưu tiên finished_at tổng
      // - nếu chưa taskDone mà multi => dùng my_finished_at
      const finishedAtText = T.escape(
        T.fmtDT(
          isMulti
            ? (taskDone ? (r.finished_at || "") : (r.my_finished_at || ""))
            : (r.finished_at || "")
        )
      );

      // ✅ result_type: multi thì dùng my_result_type, single dùng result_type
      let rt = String(r.result_type || "").trim();

      // done theo người đang login (multi) hoặc done tổng (single)
      const doneForCalc = iAmDone;

      // finished_at đúng theo multi/single
      const finishedAtRaw = isMulti ? (r.my_finished_at || "") : (r.finished_at || "");

      if (doneForCalc && finishedAtRaw && !["early", "ontime", "late"].includes(rt)) {
        rt = calcResultType(finishedAtRaw, r.deadline);
      }

      const sy = String(r.school_year_label || r.project_school_year || "").trim();
      const hk = normSemesterCode(r.semester_code || r.project_semester || "");

      const doneLabel =
        rt === "late" ? "Trễ hạn"
          : rt === "early" ? "Hoàn thành sớm"
            : "Đúng hạn";

      const doneBox = taskDone || iAmDone
        ? `
    <div class="rounded-xl bg-emerald-100 px-4 py-3 border border-black/5">
      <div class="text-xs font-semibold text-emerald-700 ">
        ${taskDone ? "CÔNG VIỆC ĐÃ HOÀN THÀNH" : "BẠN ĐÃ HOÀN THÀNH PHẦN CỦA BẠN"}
      </div>
      <div class="mt-1 text-sm font-bold text-emerald-900">
        ${finishedAtText || "-"}
      </div>
    </div>
  `
        : `
    <div class="rounded-xl bg-white/70 px-4 py-3 border border-black/5">
      <div class="text-xs font-semibold text-gray-600">TIẾN ĐỘ</div>
      <div class="mt-1 text-sm font-bold text-gray-900">${progressText}</div>
    </div>
  `;


      // ✅ Button state (done -> disable)
      // 🔥 đổi "Cập nhật" -> "Xác nhận hoàn thành"
      const actionBtn = taskDone
        ? `
    <button class="w-full px-4 py-3 rounded-xl bg-green-200 text-gray-600 font-semibold cursor-not-allowed" disabled>
      ✅ Công việc đã hoàn thành
    </button>
  `
        : iAmDone
          ? `
      <button class="w-full px-4 py-3 rounded-xl bg-gray-200 text-gray-600 font-semibold cursor-not-allowed" disabled>
        ✅ Bạn đã hoàn thành phần của bạn
      </button>
    `
          : `
      <button class="w-full px-4 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold"
        data-done="${r.id}">
        Xác nhận hoàn thành
      </button>
    `;


      return `
      <div class="rounded-2xl border shadow-sm p-5 flex flex-col gap-4 ${cardCls}">
        <!-- header -->
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="text-lg font-bold text-gray-900 truncate">${title}</div>
            ${desc ? `<div class="mt-1 text-sm text-gray-600 line-clamp-2">${desc}</div>` : ""}
          </div>

          <div class="shrink-0">
            <div class="flex items-center gap-2 justify-end flex-wrap">
              ${baseStatus}
              ${overdueBadge}
            </div>
          </div>
        </div>

        <!-- info -->
        <div class="grid grid-cols-1 gap-3">
          <div class="rounded-xl bg-gray-50 px-4 py-3 border border-black/5">
            <div class="text-xs font-semibold text-gray-600">DỰ ÁN</div>
            <div class="mt-1 text-sm font-bold text-gray-900 truncate">${project || "-"}</div>
            <div class="mt-1 text-xs text-gray-600 font-semibold">
  Năm học: <span class="text-gray-900">${T.escape(sy || "-")}</span>
  •
  Học kỳ: <span class="text-gray-900">${T.escape(hk || "-")}</span>
</div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl bg-gray-50 px-4 py-3 border border-black/5">
              <div class="text-xs font-semibold text-gray-600">ƯU TIÊN</div>
              <div class="mt-1">${priorityBadge}</div>
            </div>

            <div class="rounded-xl bg-gray-50 px-4 py-3 border border-black/5">
              <div class="text-xs font-semibold text-gray-600">HẠN</div>
              <div class="mt-1 text-sm font-bold text-gray-900">${deadline || "-"}</div>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            ${doneBox}

            <div class="rounded-xl bg-gray-50 px-4 py-3 border border-black/5">
              <div class="text-xs font-semibold text-gray-600">NGƯỜI GIAO</div>
              <div class="mt-1 text-sm font-bold text-gray-900 truncate">${assigner || "-"}</div>
            </div>
          </div>
        </div>

        <!-- actions -->
        <div class="mt-auto pt-2">
          ${actionBtn}
        </div>
      </div>
    `;
    }).join("");
  }

  /* =========================
     PAGER
  ========================= */
  function renderPager() {
    const pager = document.getElementById("taskPager");
    if (!pager) return;

    const p = STATE.page;
    const tp = Math.max(1, STATE.total_pages);

    pager.innerHTML = `
      <div class="flex items-center justify-between gap-3">
        <button class="px-3 py-2 rounded-xl border bg-white ${p <= 1 ? "opacity-50 pointer-events-none" : ""}" data-page="prev">
          Trước
        </button>

        <div class="text-sm text-gray-600">
          Trang <b>${p}</b> / <b>${tp}</b> • Tổng <b>${STATE.total}</b> công việc
        </div>

        <button class="px-3 py-2 rounded-xl border bg-white ${p >= tp ? "opacity-50 pointer-events-none" : ""}" data-page="next">
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

  /* =========================
     CONFIRM DONE MODAL
  ========================= */
  function confirmDoneModal(task) {
    const id = Number(task?.id || 0);
    if (!id) return;

    const title = T.escape(task?.title || "");
    const project = T.escape(task?.project_title || task?.project_text || "");
    const deadline = T.escape(T.fmtDT(task?.deadline || ""));

    // fallback nếu thiếu modal() trong app.js
    if (typeof window.modal !== "function") {
      const ok = confirm("Bạn có chắc là đã hoàn thành chưa ?");
      if (!ok) return;

      return doMarkDone(id);
    }

    const html = `
      <div class="space-y-4">
        <div class="rounded-xl bg-gray-50 p-4 border border-black/5">
          <div class="text-sm font-bold text-gray-900">${title || "Công việc"}</div>
          <div class="text-sm text-gray-600 mt-1"><b>Dự án:</b> ${project || "-"}</div>
          <div class="text-sm text-gray-600 mt-1"><b>Deadline:</b> ${deadline || "-"}</div>
        </div>

        <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3">
          <div class="text-sm font-semibold text-amber-900">
            Bạn có chắc là đã hoàn thành chưa ?
          </div>
          <div class="text-xs text-amber-800 mt-1">
          Nếu xác nhận, hệ thống sẽ ghi nhận <b>bạn</b> đã hoàn thành.
          Tiến độ tổng sẽ tăng theo số người đã hoàn thành, và chỉ lên <b>100%</b> khi tất cả thành viên hoàn tất.
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button class="px-4 py-2 rounded-xl border" onclick="closeModal()">Hủy</button>
          <button id="btnConfirmDone" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">
            Xác nhận hoàn thành
          </button>
        </div>
      </div>
    `;

    modal(html, "Xác nhận hoàn thành", "large");

    document.getElementById("btnConfirmDone").onclick = async () => {
      await doMarkDone(id);
    };
  }

  async function doMarkDone(id) {
    const j = await T.api("update", { id, my_status: "done" }, "POST");
    if (!j?.ok) {
      T.toast(j?.error || "Lỗi xác nhận hoàn thành");
      return;
    }

    if (typeof window.closeModal === "function") closeModal();
    await loadList();
    T.toast("✅ Đã xác nhận hoàn thành");
  }

  async function openConfirmDone(id) {
    const j = await T.api("detail", { id }, "POST");
    if (!j?.ok) return T.toast(j?.error || "Lỗi detail");

    const t = j.data?.task || {};
    if (String(t.status || "") === "done") {
      T.toast("Công việc này đã hoàn thành rồi.");
      return;
    }

    confirmDoneModal(t);
  }

  /* =========================
     FILTER EVENTS
  ========================= */
  function bindFilterEvents() {
    const ipProject = document.getElementById("taskFProject");
    const boxSuggest = document.getElementById("projectSuggest");

    const selStatus = document.getElementById("taskFStatus");
    const selSort = document.getElementById("taskFSort");
    const ipQ = document.getElementById("taskFQ");
    const btnClear = document.getElementById("taskBtnClear");

    const applyReload = () => {
      STATE.page = 1;
      loadList();
    };

    // ✅ PROJECT INPUT + SUGGEST
    if (ipProject && boxSuggest) {
      const doSuggest = debounce(() => {
        renderProjectSuggest(filterProjects(ipProject.value.trim()));
      }, 80);

      const doReload = debounce(() => {
        STATE.project_text = ipProject.value.trim();
        applyReload();
      }, 220);

      ipProject.addEventListener("input", () => {
        const val = ipProject.value.trim();

        // nếu clear trống -> clear filter + reload luôn
        if (!val) {
          STATE.project_text = "";
          hideProjectSuggest();
          applyReload();
          return;
        }

        // show suggest + auto reload nhẹ
        doSuggest();
        doReload();
      });

      ipProject.addEventListener("focus", () => {
        renderProjectSuggest(filterProjects(ipProject.value.trim()));
      });

      boxSuggest.addEventListener("mousedown", (e) => {
        const btn = e.target.closest("button[data-pick-title]");
        if (!btn) return;

        e.preventDefault();
        const title = btn.getAttribute("data-pick-title") || "";
        ipProject.value = title;

        STATE.project_text = title;
        hideProjectSuggest();
        applyReload();
      });

      ipProject.addEventListener("blur", () => {
        setTimeout(hideProjectSuggest, 150);
      });

      document.addEventListener("mousedown", (e) => {
        const inside = e.target.closest("#projectSuggest") || e.target.closest("#taskFProject");
        if (!inside) hideProjectSuggest();
      });

      // Enter -> reload
      ipProject.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          STATE.project_text = ipProject.value.trim();
          hideProjectSuggest();
          applyReload();
        }
      });
    }

    // ✅ STATUS
    if (selStatus) {
      selStatus.addEventListener("change", () => {
        STATE.status = selStatus.value || "";
        applyReload();
      });
    }

    // ✅ SORT
    if (selSort) {
      selSort.addEventListener("change", () => {
        STATE.sort = selSort.value || "deadline_asc";
        applyReload();
      });
    }

    // ✅ SEARCH Q
    if (ipQ) {
      ipQ.addEventListener("input", debounce(() => {
        STATE.q = ipQ.value.trim();
        applyReload();
      }, 250));

      ipQ.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          STATE.q = ipQ.value.trim();
          applyReload();
        }
      });
    }

    // ✅ RESET
    if (btnClear) {
      btnClear.addEventListener("click", () => {
        STATE.project_text = "";
        STATE.status = "";
        STATE.q = "";
        STATE.sort = "deadline_asc";
        STATE.page = 1;
        STATE.school_year_id = 0;
        STATE.semester_code = "";

        if (ipProject) ipProject.value = "";
        if (selStatus) selStatus.value = "";
        if (selSort) selSort.value = "deadline_asc";
        if (ipQ) ipQ.value = "";
        const selSY = document.getElementById("taskFSchoolYearId");
        const selHK = document.getElementById("taskFSemesterCode");
        if (selSY) selSY.value = "";
        if (selHK) selHK.value = "";

        hideProjectSuggest();
        loadList();
      });
    }
  }

  function bindEvents() {
    bindFilterEvents();

    // ✅ click "Xác nhận hoàn thành"
    document.getElementById("taskListBox")?.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-done]");
      if (!btn) return;

      const id = Number(btn.getAttribute("data-done") || 0);
      if (!id) return;

      openConfirmDone(id);
    });
  }

  async function init() {
    await loadMeta();

    // ✅ nếu page có select filter năm/hk thì tự bind
    setupListYearSemesterFilterUI();
    
    bindEvents();
    await loadList();
  }

  init();
})();
