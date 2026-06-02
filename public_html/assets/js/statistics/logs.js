// assets/js/statistics/logs.js
(function () {
  // Guard chống load 2 lần
  if (window.__STATS_LOGS_READY__) return;
  window.__STATS_LOGS_READY__ = true;

  window.StatsModules = window.StatsModules || {};
  const STATS = window.STATS || {};

  // =========================================================
  // CONFIG ENDPOINT
  // - Ưu tiên: window.LOGS_API nếu bạn set từ PHP view
  // - Fallback: tự đoán app base theo segment đầu của pathname (vd /doanthanhnien)
  // =========================================================
  const APP_BASE = (() => {
    const seg = (window.location.pathname || "/")
      .split("/")
      .filter(Boolean)[0];
    return seg ? `/${seg}` : "";
  })();

  // ĐỔI TẠI ĐÂY nếu bạn muốn cố định tuyệt đối:
  // const BASE_API = "/doanthanhnien/controllers/statistics/logs.php";
  const BASE_API = "controllers/statistics/logs.php";

  // Chuẩn hoá thành URL tuyệt đối (đảm bảo new URL không bị sai)
  const API = new URL(BASE_API, window.location.origin).toString();

  // ===== Utils =====
  function fmtNum(n) {
    return Number(n || 0).toLocaleString("vi-VN");
  }
  function esc(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }
  function icon(name) {
    return `<i data-lucide="${esc(name)}" class="w-5 h-5"></i>`;
  }
  function ensureLucide(root) {
    try {
      window.lucide?.createIcons?.({ root: root || document });
    } catch (e) { }
  }

  function debounce(fn, ms) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  function toYmd(d) {
    if (!(d instanceof Date)) d = new Date(d);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function addDaysYmd(ymd, days) {
    const [y, m, d] = String(ymd || "").split("-").map((x) => Number(x || 0));
    if (!y || !m || !d) return "";
    const dt = new Date(y, m - 1, d);
    dt.setDate(dt.getDate() + Number(days || 0));
    return toYmd(dt);
  }

  function fmtDateTime(s) {
    // Input thường là "YYYY-MM-DD HH:mm:ss"
    const str = String(s || "").trim();
    if (!str) return "";
    const [d, t] = str.split(" ");
    if (!d) return str;
    const [y, m, dd] = d.split("-");
    if (!y || !m || !dd) return str;
    return `${dd}/${m}/${y}${t ? " " + t.slice(0, 5) : ""}`;
  }

  function pill(text, tone = "gray") {
    const map = {
      gray: "bg-gray-100 text-gray-700 border-gray-200",
      indigo: "bg-indigo-50 text-indigo-700 border-indigo-200",
      green: "bg-emerald-50 text-emerald-700 border-emerald-200",
      red: "bg-rose-50 text-rose-700 border-rose-200",
      amber: "bg-amber-50 text-amber-800 border-amber-200",
      blue: "bg-sky-50 text-sky-700 border-sky-200",
    };
    const cls = map[tone] || map.gray;
    return `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-xs ${cls}">${esc(
      text
    )}</span>`;
  }

  function toneByAction(action) {
    const a = String(action || "").toLowerCase();
    if (["create", "import", "add"].includes(a)) return "green";
    if (["update", "edit", "save", "approve"].includes(a)) return "blue";
    if (["delete", "remove"].includes(a)) return "red";
    if (["lock", "unlock"].includes(a)) return "amber";
    return "indigo";
  }

  // ===== VI MAP (module + action + target_type) =====
  const MODULE_VI = {
    members: "Đoàn viên",
    campaigns: "Phong trào",
    attendance: "Điểm danh",
    finance: "Tài chính",
    inventory: "Thiết bị / Đồ dùng",
    schedule: "Lịch công tác",
    nominations: "Thi đua / Đề cử",
    awards: "Thi đua / Đề cử",
    notifications: "Thông báo",
    tasks: "Công việc",
    projects: "Dự án",
    duty: "Trực",
    users: "Tài khoản",
    roles: "Vai trò",
    permissions: "Phân quyền",
    settings: "Cài đặt",
    statistics: "Thống kê",
    logs: "Nhật ký hoạt động",
    auth: "Xác thực",
    system: "Hệ thống",
  };

  const ACTION_VI = {
    create: "Thêm mới",
    add: "Thêm mới",
    update: "Cập nhật",
    edit: "Cập nhật",
    save: "Lưu",
    delete: "Xoá",
    remove: "Xoá",
    import: "Nhập dữ liệu",
    export: "Xuất dữ liệu",
    approve: "Duyệt",
    reject: "Từ chối",
    lock: "Khoá",
    unlock: "Mở khoá",
    login: "Đăng nhập",
    logout: "Đăng xuất",
    reset_password: "Đặt lại mật khẩu",
    change_password: "Đổi mật khẩu",
    assign: "Phân công",
    unassign: "Huỷ phân công",
    print: "In",
    view: "Xem",
    send: "Gửi",
    notify: "Thông báo",
    sync: "Đồng bộ",
  };

  const TARGET_VI = {
    member: "Đoàn viên",
    members: "Đoàn viên",
    user: "Tài khoản",
    users: "Tài khoản",
    campaign: "Phong trào",
    campaigns: "Phong trào",
    transaction: "Phiếu thu/chi",
    finance: "Tài chính",
    inventory_item: "Thiết bị",
    inventory_borrow: "Phiếu mượn",
    schedule: "Lịch công tác",
    nomination: "Hồ sơ đề cử",
    notification: "Thông báo",
  };

  function normKey(s) {
    return String(s || "")
      .trim()
      .toLowerCase()
      .replaceAll("-", "_")
      .replace(/\s+/g, "_");
  }

  function humanizeKey(s) {
    const k = normKey(s);
    if (!k) return "";
    return k
      .split("_")
      .filter(Boolean)
      .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
      .join(" ");
  }

  function labelModule(m) {
    const k = normKey(m);
    return MODULE_VI[k] || humanizeKey(k) || "Không xác định";
  }

  function labelAction(a) {
    const k = normKey(a);
    return ACTION_VI[k] || humanizeKey(k) || "Không xác định";
  }

  function labelTargetType(t) {
    const k = normKey(t);
    return TARGET_VI[k] || humanizeKey(k) || "";
  }

  function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename || "export.xlsx";
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function fetchJSON(url) {
    const res = await fetch(url, { credentials: "same-origin" });
    const ct = res.headers.get("content-type") || "";
    if (!res.ok) {
      let msg = `HTTP ${res.status}`;
      try {
        if (ct.includes("application/json")) {
          const j = await res.json();
          msg = j?.message || msg;
        } else {
          const t = await res.text();
          msg = t || msg;
        }
      } catch (e) { }
      throw new Error(msg);
    }
    if (ct.includes("application/json")) return res.json();
    const txt = await res.text();
    try {
      return JSON.parse(txt);
    } catch (e) {
      throw new Error(txt || "API không trả JSON");
    }
  }

  // ===== State =====
  const state = {
    page: 1,
    pageSize: 10,
    q: "",
    module: "",
    action: "",
    dateFrom: "",
    dateTo: "",
    sort: "newest", // newest|oldest
    rows: [],
    summary: null,
    pageInfo: { page: 1, totalPages: 1, totalRows: 0 },
    options: { modules: [], actions: [] },
    loading: false,
    err: "",
  };

  function defaultRangeLast30Days() {
    const now = new Date();
    const to = toYmd(now);
    const from = addDaysYmd(to, -29);
    return { from, to };
  }

  function buildUrl(action, params = {}) {
    const u = new URL(API);
    u.searchParams.set("action", action);

    Object.entries(params).forEach(([k, v]) => {
      if (v == null) return;
      const val = String(v).trim();
      if (val === "") return;
      u.searchParams.set(k, val);
    });

    return u.toString();
  }

  function renderSkeleton(panelEl) {
    panelEl.innerHTML = `
      <div class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="h-4 w-40 bg-gray-100 rounded"></div>
            <div class="h-8 w-28 bg-gray-100 rounded mt-3"></div>
            <div class="h-3 w-56 bg-gray-100 rounded mt-3"></div>
          </div>
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="h-4 w-40 bg-gray-100 rounded"></div>
            <div class="h-8 w-28 bg-gray-100 rounded mt-3"></div>
            <div class="h-3 w-56 bg-gray-100 rounded mt-3"></div>
          </div>
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="h-4 w-40 bg-gray-100 rounded"></div>
            <div class="h-8 w-28 bg-gray-100 rounded mt-3"></div>
            <div class="h-3 w-56 bg-gray-100 rounded mt-3"></div>
          </div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="h-10 w-full bg-gray-100 rounded"></div>
          <div class="h-64 w-full bg-gray-100 rounded mt-4"></div>
        </div>
      </div>
    `;
  }

  function render(panelEl) {
    const totalLogs = Number(STATS.total_logs || 0);

    const summary = state.summary || {};
    const total = Number(summary.total || totalLogs || 0);
    const uniqUsers = Number(summary.unique_users || 0);
    const uniqModules = Number(summary.unique_modules || 0);

    const topActions = Array.isArray(summary.top_actions) ? summary.top_actions : [];
    const topModules = Array.isArray(summary.top_modules) ? summary.top_modules : [];
    const topUsers = Array.isArray(summary.top_users) ? summary.top_users : [];

    const rows = Array.isArray(state.rows) ? state.rows : [];
    const pi = state.pageInfo || { page: 1, totalPages: 1, totalRows: 0 };

    const rangeHint =
      state.dateFrom || state.dateTo
        ? `${esc(state.dateFrom || "---")} → ${esc(state.dateTo || "---")}`
        : "30 ngày gần nhất";

    panelEl.innerHTML = `
      <div class="space-y-4">

        <!-- KPI -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-start justify-between">
              <div>
                <div class="text-sm text-gray-500">Tổng nhật ký hoạt động</div>
                <div class="text-2xl font-bold text-gray-900 mt-1">${fmtNum(total)}</div>
                <div class="text-xs text-gray-500 mt-2">${esc(rangeHint)}</div>
              </div>
              <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                ${icon("activity")}
              </div>
            </div>
          </div>

          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-start justify-between">
              <div>
                <div class="text-sm text-gray-500">Người dùng thao tác</div>
                <div class="text-2xl font-bold text-gray-900 mt-1">${fmtNum(uniqUsers)}</div>
                <div class="text-xs text-gray-500 mt-2">COUNT DISTINCT(user_id)</div>
              </div>
              <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                ${icon("users")}
              </div>
            </div>
          </div>

          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-start justify-between">
              <div>
                <div class="text-sm text-gray-500">Phân hệ ghi log</div>
                <div class="text-2xl font-bold text-gray-900 mt-1">${fmtNum(uniqModules)}</div>
                <div class="text-xs text-gray-500 mt-2">COUNT DISTINCT(module)</div>
              </div>
              <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                ${icon("layers")}
              </div>
            </div>
          </div>
        </div>

        <!-- Insights -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between">
              <div class="font-semibold text-gray-900">Top hành động</div>
              <div class="text-xs text-gray-500">${icon("sparkles")}</div>
            </div>
            <div class="mt-3 space-y-2">
              ${topActions.length
        ? topActions
          .slice(0, 6)
          .map(
            (x) => `
                        <div class="flex items-center justify-between">
                          <div class="text-sm text-gray-800">${pill(labelAction(x.action || "unknown"), toneByAction(x.action))
              }</div>
                          <div class="text-sm font-semibold text-gray-900">${fmtNum(
                x.total || 0
              )}</div>
                        </div>`
          )
          .join("")
        : `<div class="text-sm text-gray-500">Chưa có dữ liệu top hành động.</div>`
      }
            </div>
          </div>

          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between">
              <div class="font-semibold text-gray-900">Top phân hệ</div>
              <div class="text-xs text-gray-500">${icon("boxes")}</div>
            </div>
            <div class="mt-3 space-y-2">
              ${topModules.length
        ? topModules
          .slice(0, 6)
          .map(
            (x) => `
                        <div class="flex items-center justify-between">
                          <div class="text-sm text-gray-800">${pill(labelModule(x.module || "unknown"), "gray")
              }</div>
                          <div class="text-sm font-semibold text-gray-900">${fmtNum(
                x.total || 0
              )}</div>
                        </div>`
          )
          .join("")
        : `<div class="text-sm text-gray-500">Chưa có dữ liệu top phân hệ.</div>`
      }
            </div>
          </div>

          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between">
              <div class="font-semibold text-gray-900">Top người thao tác</div>
              <div class="text-xs text-gray-500">${icon("user-round")}</div>
            </div>
            <div class="mt-3 space-y-2">
              ${topUsers.length
        ? topUsers
          .slice(0, 6)
          .map(
            (x) => `
                        <div class="flex items-center justify-between gap-3">
                          <div class="min-w-0">
                            <div class="text-sm text-gray-900 font-medium truncate">${esc(
              x.fullname || x.username || "Không xác định"
            )}</div>
                            <div class="text-xs text-gray-500 truncate">${esc(
              x.username || ""
            )}</div>
                          </div>
                          <div class="text-sm font-semibold text-gray-900">${fmtNum(
              x.total || 0
            )}</div>
                        </div>`
          )
          .join("")
        : `<div class="text-sm text-gray-500">Chưa có dữ liệu top người thao tác.</div>`
      }
            </div>
          </div>
        </div>

        <!-- Filters + Table -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
  <div class="flex flex-col gap-3">
    <div class="flex items-center justify-between gap-3">
      <div class="text-sm font-semibold text-gray-900">Bộ lọc</div>

      <div class="flex gap-2 shrink-0">
        <button id="logsBtnReset"
          class="h-10 px-3 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 inline-flex items-center">
          ${icon("rotate-ccw")} <span class="ml-1">Reset</span>
        </button>
        <button id="logsBtnExport"
          class="h-10 px-3 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 inline-flex items-center">
          ${icon("download")} <span class="ml-1">Xuất Excel</span>
        </button>
      </div>
    </div>

    <!-- Filter grid: tự xuống dòng, không tràn -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-2">
      <div class="lg:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Từ ngày</label>
        <input id="logsDateFrom" type="date" value="${esc(state.dateFrom)}"
          class="h-10 w-full px-3 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
      </div>

      <div class="lg:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Đến ngày</label>
        <input id="logsDateTo" type="date" value="${esc(state.dateTo)}"
          class="h-10 w-full px-3 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
      </div>

      <div class="lg:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Phân hệ</label>
        <select id="logsModule"
          class="h-10 w-full px-3 rounded-xl border border-gray-200 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-200">
          <option value="">Tất cả</option>
          ${(state.options.modules || [])
        .map(
          (m) =>
            `<option value="${esc(m)}"${state.module === m ? " selected" : ""
            }>${esc(labelModule(m))}</option>`
        )
        .join("")}
        </select>
      </div>

      <div class="lg:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Hành động</label>
        <select id="logsAction"
          class="h-10 w-full px-3 rounded-xl border border-gray-200 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-200">
          <option value="">Tất cả</option>
          ${(state.options.actions || [])
        .map(
          (a) =>
            `<option value="${esc(a)}"${state.action === a ? " selected" : ""
            }>${esc(labelAction(a))}</option>`
        )
        .join("")}
        </select>
      </div>

      <div class="lg:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Sắp xếp</label>
        <select id="logsSort"
          class="h-10 w-full px-3 rounded-xl border border-gray-200 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-200">
          <option value="newest"${state.sort === "newest" ? " selected" : ""
      }>Mới nhất</option>
          <option value="oldest"${state.sort === "oldest" ? " selected" : ""
      }>Cũ nhất</option>
        </select>
      </div>

      <div class="lg:col-span-2 sm:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Tìm kiếm</label>
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
            ${icon("search")}
          </span>
          <input id="logsQ" value="${esc(state.q)}"
            placeholder="VD: members, delete, 192.168..."
            class="h-10 w-full min-w-0 pl-10 pr-3 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
        </div>
      </div>
    </div>
  </div>


          ${state.err
        ? `<div class="mt-3 p-3 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 text-sm">
                  <div class="font-semibold">Không thể tải dữ liệu</div>
                  <div class="mt-1 whitespace-pre-wrap">${esc(state.err)}</div>
                </div>`
        : ""
      }

          <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="bg-gray-50 text-gray-700">
                  <th class="text-left font-semibold px-3 py-2 rounded-l-xl">Thời gian</th>
                  <th class="text-left font-semibold px-3 py-2">Người thao tác</th>
                  <th class="text-left font-semibold px-3 py-2">Phân hệ</th>
                  <th class="text-left font-semibold px-3 py-2">Hành động</th>
                  <th class="text-left font-semibold px-3 py-2">Đối tượng</th>
                  <th class="text-left font-semibold px-3 py-2">Mô tả</th>
                </tr>
              </thead>
              <tbody id="logsTbody">
                ${state.loading
        ? `<tr><td colspan="7" class="px-3 py-10 text-center text-gray-500">Đang tải dữ liệu...</td></tr>`
        : rows.length
          ? rows
            .map((x) => {
              const who = x.fullname || x.username || "Không xác định";
              const when = fmtDateTime(x.created_at || "");
              const mod = x.module || "";
              const act = x.action || "";
              const modLabel = labelModule(mod);
              const actLabel = labelAction(act);

              const targetType = x.target_type ? labelTargetType(x.target_type) : "";
              const target = [
                targetType,
                x.target_id != null && String(x.target_id) !== "" ? `#${x.target_id}` : "",
              ].filter(Boolean).join(" ");
              const desc = x.description || "";
              const ip = x.ip_address || "";
              return `
                            <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                              <td class="px-3 py-2 whitespace-nowrap text-gray-700">${esc(when)}</td>
                              <td class="px-3 py-2">
                                <div class="font-medium text-gray-900">${esc(who)}</div>
                                <div class="text-xs text-gray-500">${esc(x.username || "")}</div>
                              </td>
                              <td class="px-3 py-2 text-gray-700">${esc(modLabel)}</td>
                              <td class="px-3 py-2">${pill(actLabel || "Không xác định", toneByAction(act))}</td>
                              <td class="px-3 py-2 text-gray-700">${esc(target)}</td>
                              <td class="px-3 py-2 text-gray-700">
                                <div class="max-w-[520px] truncate" title="${esc(desc)}">${esc(desc)}</div>
                              </td>
                            </tr>
                          `;
            })
            .join("")
          : `<tr><td colspan="7" class="px-3 py-10 text-center text-gray-500">Không có dữ liệu phù hợp bộ lọc.</td></tr>`
      }
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div class="mt-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="text-sm text-gray-600">
              Trang <span class="font-semibold text-gray-900">${fmtNum(pi.page || 1)}</span> /
              <span class="font-semibold text-gray-900">${fmtNum(pi.totalPages || 1)}</span>
              · Tổng <span class="font-semibold text-gray-900">${fmtNum(pi.totalRows || 0)}</span> dòng
            </div>

            <div class="flex items-center gap-2">
              <select id="logsPageSize" class="h-10 px-3 rounded-xl border border-gray-200 bg-white">
                ${[10, 20, 50, 100]
        .map(
          (n) =>
            `<option value="${n}"${state.pageSize === n ? " selected" : ""
            }>${n}/trang</option>`
        )
        .join("")}
              </select>

              <button id="logsPrev" class="h-10 px-3 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50" ${(pi.page || 1) <= 1 ? "disabled" : ""
      }>
                ${icon("chevron-left")} <span class="ml-1">Trước</span>
              </button>
              <button id="logsNext" class="h-10 px-3 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50" ${(pi.page || 1) >= (pi.totalPages || 1) ? "disabled" : ""
      }>
                <span class="mr-1">Sau</span> ${icon("chevron-right")}
              </button>
            </div>
          </div>

          <div class="mt-4 text-xs text-gray-500">
            Gợi ý: dùng bộ lọc ngày để giảm tải; xuất Excel sẽ xuất theo bộ lọc hiện tại.
          </div>
        </div>
      </div>
    `;

    ensureLucide(panelEl);
    bindUI(panelEl);
  }

  function bindUI(panelEl) {
    const $ = (id) => panelEl.querySelector("#" + id);

    const qEl = $("logsQ");
    const fromEl = $("logsDateFrom");
    const toEl = $("logsDateTo");
    const modEl = $("logsModule");
    const actEl = $("logsAction");
    const sortEl = $("logsSort");
    const sizeEl = $("logsPageSize");
    const prevEl = $("logsPrev");
    const nextEl = $("logsNext");
    const resetEl = $("logsBtnReset");
    const exportEl = $("logsBtnExport");

    const apply = () => {
      state.page = 1;
      reload(panelEl);
    };
    const applyDebounced = debounce(apply, 250);

    qEl?.addEventListener("input", () => {
      state.q = qEl.value || "";
      applyDebounced();
    });

    fromEl?.addEventListener("change", () => {
      state.dateFrom = fromEl.value || "";
      apply();
    });
    toEl?.addEventListener("change", () => {
      state.dateTo = toEl.value || "";
      apply();
    });

    modEl?.addEventListener("change", () => {
      state.module = modEl.value || "";
      apply();
    });
    actEl?.addEventListener("change", () => {
      state.action = actEl.value || "";
      apply();
    });

    sortEl?.addEventListener("change", () => {
      state.sort = sortEl.value || "newest";
      apply();
    });

    sizeEl?.addEventListener("change", () => {
      state.pageSize = Number(sizeEl.value || 10) || 10;
      state.page = 1;
      reload(panelEl);
    });

    prevEl?.addEventListener("click", () => {
      if ((state.pageInfo.page || 1) <= 1) return;
      state.page = (state.pageInfo.page || 1) - 1;
      reload(panelEl);
    });

    nextEl?.addEventListener("click", () => {
      if ((state.pageInfo.page || 1) >= (state.pageInfo.totalPages || 1)) return;
      state.page = (state.pageInfo.page || 1) + 1;
      reload(panelEl);
    });

    resetEl?.addEventListener("click", () => {
      const { from, to } = defaultRangeLast30Days();
      state.page = 1;
      state.pageSize = 10;
      state.q = "";
      state.module = "";
      state.action = "";
      state.sort = "newest";
      state.dateFrom = from;
      state.dateTo = to;
      reload(panelEl);
    });

    exportEl?.addEventListener("click", async () => {
      try {
        exportEl.disabled = true;
        exportEl.classList.add("opacity-70");

        const url = buildUrl("export_logs", buildQueryParams({ forExport: true }));
        const res = await fetch(url, { credentials: "same-origin" });
        if (!res.ok) {
          const txt = await res.text();
          throw new Error(txt || `HTTP ${res.status}`);
        }
        const blob = await res.blob();
        const ts = new Date();
        const name = `nhatkihoatdong_logs_${ts
          .toISOString()
          .replaceAll(":", "")
          .slice(0, 15)}.xlsx`;
        downloadBlob(blob, name);
      } catch (e) {
        alert("Không thể export: " + (e?.message || e));
      } finally {
        exportEl.disabled = false;
        exportEl.classList.remove("opacity-70");
      }
    });
  }

  function buildQueryParams(opts = {}) {
    // nếu user chưa chọn range, mặc định 30 ngày
    let df = state.dateFrom;
    let dt = state.dateTo;
    if (!df && !dt) {
      const r = defaultRangeLast30Days();
      df = r.from;
      dt = r.to;
    }

    const base = {
      page: state.page,
      page_size: state.pageSize,
      q: state.q,
      module: state.module,
      action: state.action,
      sort: state.sort,
      date_from: df,
      date_to: dt,
    };

    // export thường muốn full theo filter, không nhất thiết theo page
    if (opts.forExport) {
      delete base.page;
      delete base.page_size;
    }

    return base;
  }

  async function loadOptions() {
    try {
      const j = await fetchJSON(buildUrl("log_options", {}));
      if (j?.ok) {
        const mods = Array.isArray(j.modules)
          ? j.modules
          : Array.isArray(j.data?.modules)
            ? j.data.modules
            : [];
        const acts = Array.isArray(j.actions)
          ? j.actions
          : Array.isArray(j.data?.actions)
            ? j.data.actions
            : [];
        state.options.modules = mods.filter(Boolean);
        state.options.actions = acts.filter(Boolean);
      }
    } catch (e) {
      // ignore
    }
  }

  async function loadReport() {
    const params = buildQueryParams();
    const j = await fetchJSON(buildUrl("logs_report", params));
    if (!j?.ok) throw new Error(j?.message || "API error");

    const rows = Array.isArray(j.rows) ? j.rows : [];
    const summary = j.summary || null;
    const page = j.page || j.pageInfo || null;

    state.rows = rows;
    state.summary = summary;

    if (page && typeof page === "object") {
      state.pageInfo = {
        page: Number(page.page || 1) || 1,
        totalPages: Number(page.totalPages || page.total_pages || 1) || 1,
        totalRows: Number(page.totalRows || page.total_rows || rows.length || 0) || 0,
      };
    } else {
      state.pageInfo = { page: 1, totalPages: 1, totalRows: rows.length || 0 };
    }
  }

  async function reload(panelEl) {
    state.loading = true;
    state.err = "";
    render(panelEl);

    try {
      // set default range nếu rỗng
      if (!state.dateFrom && !state.dateTo) {
        const r = defaultRangeLast30Days();
        state.dateFrom = r.from;
        state.dateTo = r.to;
      }

      await loadOptions();
      await loadReport();

      state.loading = false;
      state.err = "";
      render(panelEl);
    } catch (e) {
      state.loading = false;
      state.err = e?.message || String(e);
      render(panelEl);
    }
  }

  // ===== Entry =====
  window.StatsModules.logs = (panelEl) => {
    if (!panelEl) return;

    // default 30 ngày
    if (!state.dateFrom && !state.dateTo) {
      const r = defaultRangeLast30Days();
      state.dateFrom = r.from;
      state.dateTo = r.to;
    }

    renderSkeleton(panelEl);
    reload(panelEl);
  };
})();
