// assets/js/user_lookup.js
(function () {
  const app = document.getElementById("user-lookup-app");
  if (!app) return;

  const ENDPOINT = app.dataset.endpoint;
  const ME_ID = Number(app.dataset.meId || 0);
  const SELF_ONLY = String(app.dataset.selfOnly || "0") === "1";
  const ME_MSSV = String(app.dataset.meMssv || "").trim();
  const $q = document.getElementById("ul-q");
  const $dropdown = document.getElementById("ul-dropdown");
  const $detail = document.getElementById("ul-detail");
  const $summary = document.getElementById("ul-summary");
  const $tabs = document.getElementById("ul-tabs");

  const $btnRefresh = document.getElementById("ul-btn-refresh");
  const $btnClear = document.getElementById("ul-btn-clear");

  const $paid = document.getElementById("ul-paid");

  let SEARCH_TIMER = null;
  let CURRENT_USER_ID = null;
  let CURRENT_DATA = null;

  const TAB_KEYS = [
    { key: "personal", label: "Cá nhân" },
    { key: "reviews", label: "Đánh giá" },
    { key: "campaigns", label: "Phong trào" },
    { key: "attendance", label: "QR / Điểm danh" },
    { key: "tasks", label: "Nhiệm vụ" },
    { key: "duty", label: "Trực BCH" },
    { key: "finance", label: "Thu–Chi" },
    { key: "inventory", label: "Thiết bị" },
    { key: "nominations", label: "Khen thưởng" },
    { key: "achievements", label: "Thành tích" },
    { key: "violations", label: "Kỷ luật - Vi phạm" },
  ];

  let ACTIVE_TAB = "personal";
  function isBCHUser() {
    const role = String(CURRENT_DATA?.user?.role_name || "").toLowerCase();
    return role === "banchaphanh";
  }

  /* =========================
       Helpers
    ========================= */
  function esc(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function fmtMoney(n) {
    const v = Number(n || 0);
    return v.toLocaleString("vi-VN");
  }

  function fmtDate(s) {
    if (!s) return "-";

    const raw = String(s).trim().replace("T", " ");

    // Nếu đã là dd/mm/yyyy
    if (/^\d{1,2}\/\d{1,2}\/\d{4}/.test(raw)) return raw.slice(0, 10);

    // Nếu là yyyy-mm-dd hoặc yyyy-mm-dd hh:mm:ss
    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return `${m[3]}/${m[2]}/${m[1]}`;

    // Fallback parse Date
    const dt = new Date(raw);
    if (!isNaN(dt.getTime())) {
      const dd = String(dt.getDate()).padStart(2, "0");
      const mm = String(dt.getMonth() + 1).padStart(2, "0");
      const yyyy = dt.getFullYear();
      return `${dd}/${mm}/${yyyy}`;
    }

    return raw.slice(0, 10);
  }

  function badge(text, tone = "gray") {
    const toneCls =
      {
        gray: "bg-gray-100 text-gray-700",
        blue: "bg-blue-100 text-blue-700",
        green: "bg-green-100 text-green-700",
        red: "bg-red-100 text-red-700",
        amber: "bg-amber-100 text-amber-700",
        purple: "bg-purple-100 text-purple-700",
      }[tone] || "bg-gray-100 text-gray-700";

    return `<span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium ${toneCls}">${esc(text)}</span>`;
  }

  /* =========================
       VI MAP (EN -> VI)
    ========================= */
  const VI = {
    reg_status: {
      approved: { label: "Đã duyệt", tone: "green" },
      pending: { label: "Chờ duyệt", tone: "amber" },
      rejected: { label: "Từ chối", tone: "red" },

      excellent: { label: "Xuất sắc", tone: "green" },
      good: { label: "Tốt", tone: "blue" },
      incomplete: { label: "Chưa đủ", tone: "amber" },
      cancelled: { label: "Đã hủy", tone: "red" },
    },

    achievement_status: {
      draft: { label: "Nháp", tone: "gray" },
      submitted: { label: "Chờ duyệt", tone: "amber" },
      approved: { label: "Đã duyệt", tone: "green" },
      rejected: { label: "Từ chối", tone: "red" },
    },

    nomination_type_text: {
      self: "Tự đề cử",
      // bạn có thể bổ sung thêm nếu hệ thống có:
      // class: "Lớp đề cử",
      // dept: "Khoa/Phòng đề cử",
      // bch: "BCH đề cử",
    },

    review_rating: {
      excellent: { label: "Xuất sắc", tone: "green" },
      good: { label: "Tốt", tone: "blue" },
      completed: { label: "Hoàn thành", tone: "purple" },
      incomplete: { label: "Chưa hoàn thành", tone: "amber" },
    },

    task_status: {
      pending: { label: "Chờ xử lý", tone: "amber" },
      doing: { label: "Đang làm", tone: "blue" },
      done: { label: "Hoàn thành", tone: "green" },
    },

    nomination_status: {
      approved: { label: "Đã duyệt", tone: "green" },
      rejected: { label: "Từ chối", tone: "red" },
      pending: { label: "Chờ duyệt", tone: "amber" },
    },

    attendance_result: {
      ok: { label: "OK", tone: "green" },
      fail: { label: "Thất bại", tone: "red" },
    },

    finance_type: {
      income: { label: "Thu", tone: "green" },
      expense: { label: "Chi", tone: "red" },
    },

    finance_method_text: {
      cash: "Tiền mặt",
      transfer: "Chuyển khoản",
      bank: "Chuyển khoản",
      momo: "MoMo",
      zalopay: "ZaloPay",
    },

    finance_status_text: {
      paid: "Đã thanh toán",
      unpaid: "Chưa thanh toán",
      pending: "Chờ duyệt",
      approved: "Đã duyệt",
      rejected: "Từ chối",
      cancelled: "Đã hủy",
      draft: "Nháp",
    },

    borrow_status: {
      borrowing: { label: "Đang mượn", tone: "amber" },
      returned: { label: "Đã trả", tone: "green" },
      overdue: { label: "Quá hạn", tone: "red" },
    },

    duty_shift_text: {
      morning: "Sáng",
      afternoon: "Chiều",
      break_morning: "Ra chơi (Sáng)",
      break_afternoon: "Ra chơi (Chiều)",
    },

    duty_type_text: {
      normal: "Trực thường",
      support: "Hỗ trợ",
    },

    priority_text: {
      high: "Cao",
      medium: "Vừa",
      low: "Thấp",
      normal: "Bình thường",
    },
    member_type_text: {
      member: "Đoàn viên",
      youth: "Thanh niên",
    },
  };

  function keyLower(v) {
    return String(v ?? "")
      .trim()
      .toLowerCase();
  }

  function viBadgeFromMap(mapObj, key, fallback = "-") {
    const k = keyLower(key);
    const it = mapObj?.[k];
    if (it?.label) return badge(it.label, it.tone || "gray");
    return badge(k || fallback, "gray");
  }

  function viText(mapObj, key, fallback = "-") {
    const k = keyLower(key);
    return mapObj?.[k] || k || fallback;
  }

  async function api(action, payload = {}) {
    const fd = new FormData();
    fd.append("action", action);
    Object.keys(payload).forEach((k) => fd.append(k, payload[k]));

    const res = await fetch(ENDPOINT, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
    });

    const j = await res.json().catch(() => null);
    if (!j || !j.ok) throw new Error(j?.error || "Lỗi API");
    return j.data;
  }

  function showDropdown(html) {
    if (!$dropdown) return;
    $dropdown.innerHTML = html;
    $dropdown.classList.remove("hidden");
  }
  function hideDropdown() {
    if (!$dropdown) return;
    $dropdown.classList.add("hidden");
    $dropdown.innerHTML = "";
  }


  function renderTabs() {
    const visibleTabs = TAB_KEYS.filter((t) => t.key !== "duty" || isBCHUser());

    $tabs.innerHTML = visibleTabs
      .map((t) => {
        const active = t.key === ACTIVE_TAB;
        return `
        <button data-tab="${esc(t.key)}"
          class="px-3 py-2 rounded-xl border text-sm ${active
            ? "bg-gray-900 text-white border-gray-900"
            : "bg-white text-gray-700 border-gray-200 hover:bg-gray-50"
          }">
          ${esc(t.label)}
        </button>
      `;
      })
      .join("");

    $tabs.querySelectorAll("button[data-tab]").forEach((btn) => {
      btn.addEventListener("click", () => {
        ACTIVE_TAB = btn.dataset.tab;
        renderTabs();
        renderActiveTab();
      });
    });
  }

  function renderPaid(data) {
    if (!$paid) return;

    const st = data.finance_paid_stats || {};
    const rows = data.finance_paid_rows || [];

    if (st && st.forbidden) {
      $paid.innerHTML = `<div class="text-gray-500">Không có quyền xem Thu–Chi.</div>`;
      return;
    }

    if (!CURRENT_USER_ID) {
      $paid.innerHTML = "Chưa chọn user nào.";
      return;
    }

    if (!rows.length) {
      $paid.innerHTML = `<div class="text-gray-500">Chưa có dữ liệu đóng phí.</div>`;
      return;
    }

    // list theo transaction_id -> item_name
    $paid.innerHTML = `
    <div class="space-y-2">
      <div class="font-semibold mb-2">Đã đóng</div>

      ${rows.map((r) => {
      const tid = r.transaction_id || r.id || "-";
      const item = r.item_name || r.item_text || ("Phiếu #" + tid);
      const date = fmtDate(r.trans_date || r.created_at);
      const method = r.method ? viText(VI.finance_method_text, r.method, r.method) : "";
      const status = r.status ? viText(VI.finance_status_text, r.status, r.status) : "";

      const meta = [date, method, status].filter(Boolean).join(" • ");

      return `
          <div class="rounded-xl border border-gray-100 bg-white p-3">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="font-medium text-gray-900 truncate">
                  ${esc(item)}
                </div>
                <div class="text-xs text-gray-500 mt-0.5">
                  ID: ${esc(tid)}${meta ? " • " + esc(meta) : ""}
                </div>
              </div>
            </div>
          </div>
        `;
    }).join("")}
    </div>
  `;
  }

  function renderReviews(data) {
    // Backend nên trả:
    // data.reviews = [{school_year, rating, note, lock_applied, reviewed_at, lock_applied_at}]
    // data.review_years = ["2023-2024", "2024-2025", ...] (nếu muốn show cả năm chưa đánh giá)
    const rows = data.reviews || [];
    const years = data.review_years || [];

    if (data.reviews_forbidden) {
      return `<div class="text-gray-500">Không có quyền xem đánh giá.</div>`;
    }

    // map theo năm
    const map = {};
    rows.forEach((r) => {
      const y = String(r.school_year || "").trim();
      if (y) map[y] = r;
    });

    // nếu có years active => show full list; nếu không có => show theo rows
    const listYears = years.length ? years : Object.keys(map).sort();

    if (!listYears.length) {
      return `<div class="text-gray-500">Chưa có dữ liệu đánh giá.</div>`;
    }

    return `
    <div class="rounded-2xl border border-gray-100 p-4">
      <div class="font-semibold mb-3">Đánh giá theo năm học</div>

      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3">Năm học</th>
              <th class="text-left px-4 py-3">Mức</th>
              <th class="text-left px-4 py-3">Trạng thái</th>
              <th class="text-left px-4 py-3">Ghi chú</th>
              <th class="text-left px-4 py-3">Cập nhật</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${listYears.map((y) => {
      const r = map[y] || null;

      const ratingKey = keyLower(r?.rating || "");
      const ratingCell = ratingKey
        ? viBadgeFromMap(VI.review_rating, ratingKey, "—")
        : badge("Chưa đánh giá", "gray");

      const locked = Number(r?.lock_applied || 0) === 1;
      const lockBadge = locked ? badge("Đã khóa", "amber") : badge("Đang mở", "gray");

      return `
                <tr>
                  <td class="px-4 py-3 font-medium text-gray-900">${esc(y)}</td>
                  <td class="px-4 py-3">${ratingCell}</td>
                  <td class="px-4 py-3">${lockBadge}</td>
                  <td class="px-4 py-3 text-gray-700">${esc(r?.note || "—")}</td>
                  <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r?.reviewed_at))}</td>
                </tr>
              `;
    }).join("")}
          </tbody>
        </table>
      </div>
    </div>
  `;
  }


  function renderSummary(data) {
    const u = data.user || {};
    const stCamp = data.campaign_stats || {};
    const stAtt = data.attendance_stats || {};
    const stTask = data.task_stats || {};
    const stDuty = data.duty_stats || {};
    const isBCH = String(u.role_name || "").toLowerCase() === "banchaphanh";
    const rv = data.reviews || [];
    const reviewedYears = rv.length;
    const latest = rv[rv.length - 1]; // nếu backend đã ORDER BY school_year

    const latestText = latest?.school_year
      ? `${latest.school_year} (${viText({ excellent: "Xuất sắc", good: "Tốt", completed: "Hoàn thành", incomplete: "Chưa hoàn thành" }, latest.rating, "—")})`
      : "Chưa có";
    const name = u.display_name || u.username || "-";

    $summary.innerHTML = `
    <div class="text-xs text-gray-500 pt-2">
  Đánh giá: ${esc(reviewedYears)} năm • Mới nhất: ${esc(latestText)}
</div>
      <div class="space-y-2">
        <div class="font-semibold text-gray-900">${esc(name)}</div>
        <div class="text-xs text-gray-500">${esc(u.role_name || "Không rõ role")} • ID: ${esc(u.id)}</div>

        <div class="grid grid-cols-2 gap-2 pt-2">
          <div class="rounded-xl border border-gray-100 bg-white p-3">
            <div class="text-xs text-gray-500">Phong trào</div>
            <div class="font-semibold">${esc(stCamp.total_joined || 0)} lần</div>
          </div>
          <div class="rounded-xl border border-gray-100 bg-white p-3">
            <div class="text-xs text-gray-500">Tổng điểm</div>
            <div class="font-semibold">${esc(stCamp.total_reg_score || 0)}</div>
          </div>

          <div class="rounded-xl border border-gray-100 bg-white p-3">
            <div class="text-xs text-gray-500">QR OK</div>
            <div class="font-semibold">${esc(stAtt.ok_count || 0)}</div>
          </div>
${isBCH
        ? `
  <div class="rounded-xl border border-gray-100 bg-white p-3">
    <div class="text-xs text-gray-500">Điểm trực BCH</div>
    <div class="font-semibold">${esc(stDuty.total_score || 0)}</div>
  </div>
`
        : ``
      }

        </div>

        <div class="text-xs text-gray-500 pt-2">
          Tasks: ${esc(stTask.pending_count || 0)} pending • ${esc(stTask.doing_count || 0)} doing • ${esc(stTask.done_count || 0)} done
        </div>
      </div>
    `;
  }

  function kv(label, value) {
    return `
      <div class="flex items-start justify-between gap-4 py-2 border-b border-gray-100 last:border-b-0">
        <div class="text-gray-500">${esc(label)}</div>
        <div class="text-gray-900 text-right max-w-[70%] break-words">${value ?? "-"}</div>
      </div>
    `;
  }

  /* =========================
       Render sections by tab
    ========================= */
  function renderPersonal(data) {
    const u = data.user || {};
    const p = data.profile || {};

    const phone = p.phone || u.member_phone || "-";
    const email = p.email || u.member_email || "-";

    const stopFollow =
      Number(u.stop_follow || 0) === 1
        ? badge("Ngừng theo dõi", "red")
        : badge("Bình thường", "green");

    const avatar = u.avatar_url
      ? `<img src="${esc(u.avatar_url)}" class="w-14 h-14 rounded-2xl object-cover border border-gray-200" />`
      : `<div class="w-14 h-14 rounded-2xl bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-500 font-semibold">U</div>`;

    return `
      <div class="flex items-center gap-4 mb-5">
        ${avatar}
        <div class="min-w-0">
          <div class="text-lg font-semibold text-gray-900 truncate">${esc(u.display_name || u.username || "-")}</div>
          <div class="text-sm text-gray-500">
            ${esc(u.role_name || "Không rõ role")} • ${esc(u.permissions_mode || "role")} • ID: ${esc(u.id)}
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="font-semibold mb-2">Thông tin cơ bản</div>
          ${kv("Username", esc(u.username))}
          ${kv("Họ tên (users)", esc(u.user_fullname || "-"))}
          ${kv("Họ tên (members)", esc(u.member_fullname || "-"))}
          ${kv("MSSV", esc(u.mssv || "-"))}
          ${kv("Loại", esc(viText(VI.member_type_text, u.member_type, "-")))}
          ${kv("Trạng thái", stopFollow)}
        </div>

        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="font-semibold mb-2">Khoa / Khóa / Lớp</div>
          ${kv("Khoa/Phòng", esc(u.department_name || "-"))}
          ${kv("Loại đơn vị", esc(u.department_type || "-"))}
          ${kv("Khóa", esc(u.course_name || "-"))}
          ${kv("Lớp", esc(u.class_name || u.class_text || "-"))}
          ${kv("Ngày vào Đoàn", esc(fmtDate(u.join_date)))}
          ${kv("Ngày sinh", esc(fmtDate(u.birth || p.birth)))}
        </div>

        <div class="rounded-2xl border border-gray-100 p-4 md:col-span-2">
          <div class="font-semibold mb-2">Liên hệ</div>
          ${kv("SĐT", esc(phone))}
          ${kv("Email", esc(email))}
          ${kv("Địa chỉ", esc(u.current_address || p.address || "-"))}
          ${kv("Ghi chú", esc(u.member_note || u.note || p.note || "-"))}
        </div>
      </div>
    `;
  }

  function renderCampaigns(data) {
    const st = data.campaign_stats || {};
    const rows = data.campaigns || [];

    const header = `
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Tổng tham gia</div>
          <div class="text-lg font-semibold">${esc(st.total_joined || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Tổng điểm</div>
          <div class="text-lg font-semibold">${esc(st.total_reg_score || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Điểm phong trào</div>
          <div class="text-lg font-semibold">${esc(st.total_campaign_score || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Tổng bản ghi</div>
          <div class="text-lg font-semibold">${esc(st.total_regs || 0)}</div>
        </div>
      </div>
    `;

    if (!rows.length) {
      return (
        header +
        `<div class="text-gray-500">User này chưa có đăng ký phong trào.</div>`
      );
    }

    const table = `
      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3">Phong trào</th>
              <th class="text-left px-4 py-3">Năm học</th>
              <th class="text-left px-4 py-3">Trạng thái</th>
              <th class="text-right px-4 py-3">Điểm</th>
              <th class="text-right px-4 py-3">Điểm CT</th>
              <th class="text-left px-4 py-3">Ngày</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${rows
        .map((r) => {
          const stKey = keyLower(r.reg_status);
          const stBadge = viBadgeFromMap(VI.reg_status, stKey, "-");


          return `
                <tr>
                  <td class="px-4 py-3">
                    <div class="font-medium text-gray-900">${esc(r.campaign_title || "-")}</div>
                    <div class="text-xs text-gray-500">${esc(fmtDate(r.start_date))} → ${esc(fmtDate(r.end_date))}</div>
                  </td>
                  <td class="px-4 py-3">${esc(r.school_year || "-")} ${esc(r.semester || "")}</td>
                  <td class="px-4 py-3">${stBadge}</td>
                  <td class="px-4 py-3 text-right font-semibold">${esc(r.reg_score ?? 0)}</td>
                  <td class="px-4 py-3 text-right">${esc(r.campaign_score ?? 0)}</td>
                  <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.registered_at))}</td>
                </tr>
              `;
        })
        .join("")}
          </tbody>
        </table>
      </div>
    `;

    return header + table;
  }

  function renderAttendance(data) {
    const st = data.attendance_stats || {};
    const rows = data.attendance_logs || [];

    const header = `
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Quét OK</div>
          <div class="text-lg font-semibold">${esc(st.ok_count || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Quét FAIL</div>
          <div class="text-lg font-semibold">${esc(st.fail_count || 0)}</div>
        </div>
      </div>
    `;

    if (!rows.length)
      return header + `<div class="text-gray-500">Chưa có lịch sử QR.</div>`;

    const table = `
      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3">Phong trào</th>
              <th class="text-left px-4 py-3">Kết quả</th>
              <th class="text-left px-4 py-3">Session</th>
              <th class="text-left px-4 py-3">Thời gian</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${rows
        .map((r) => {
          const rsKey = keyLower(r.result);
          const rsBadge = VI.attendance_result[rsKey]
            ? viBadgeFromMap(VI.attendance_result, rsKey)
            : badge(rsKey || "-", "gray");
          return `
                <tr>
                  <td class="px-4 py-3 font-medium text-gray-900">${esc(r.campaign_title || "-")}</td>
                  <td class="px-4 py-3">${rsBadge}</td>
                  <td class="px-4 py-3 text-gray-700">${esc(r.log_session || "-")}</td>
                  <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.time))}</td>
                </tr>
              `;
        })
        .join("")}
          </tbody>
        </table>
      </div>
    `;
    return header + table;
  }

  function renderTasks(data) {
    const st = data.task_stats || {};
    const rows = data.tasks || [];

    const header = `
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Chờ xử lý</div>
          <div class="text-lg font-semibold">${esc(st.pending_count || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Doing</div>
          <div class="text-lg font-semibold">${esc(st.doing_count || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Done</div>
          <div class="text-lg font-semibold">${esc(st.done_count || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Tiến độ TB</div>
          <div class="text-lg font-semibold">${Math.round(Number(st.avg_progress || 0))}%</div>
        </div>
      </div>
    `;

    if (!rows.length)
      return header + `<div class="text-gray-500">User này chưa có task.</div>`;

    return (
      header +
      `
      <div class="space-y-3">
        ${rows
        .map((r) => {
          const stKey = keyLower(r.status || "pending");
          let stBadge = VI.task_status[stKey]
            ? viBadgeFromMap(VI.task_status, stKey)
            : badge(stKey || "-", "gray");

          const prog = Number(r.progress || 0);

          return `
            <div class="rounded-2xl border border-gray-100 p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="font-semibold text-gray-900 truncate">${esc(r.task_title || "-")}</div>
                  <div class="text-xs text-gray-500 mt-1">
                    Dự án: ${esc(r.project_title || "—")} • Hạn: ${esc(fmtDate(r.deadline))}
                  </div>
                </div>
                <div class="shrink-0 text-right">
                  <div>${stBadge}</div>
                    <div class="text-xs text-gray-500 mt-1">${esc(viText(VI.priority_text, r.priority, "-"))}</div>
                </div>
              </div>

              <div class="mt-3">
                <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                  <div class="h-2 bg-gray-900 rounded-full" style="width:${prog}%;"></div>
                </div>
                <div class="text-xs text-gray-500 mt-1">${prog}%</div>
              </div>
            </div>
          `;
        })
        .join("")}
      </div>
    `
    );
  }

  function renderDuty(data) {
    const role = String((data.user || {}).role_name || "").toLowerCase();
    if (role !== "banchaphanh") {
      return `<div class="text-gray-500">User này không thuộc BCH nên không có dữ liệu trực.</div>`;
    }

    const st = data.duty_stats || {};
    const rows = data.duty || [];

    const header = `
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Tổng ca</div>
          <div class="text-lg font-semibold">${esc(st.total_shifts || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Tổng điểm trực</div>
          <div class="text-lg font-semibold">${esc(st.total_score || 0)}</div>
        </div>
      </div>
    `;

    if (!rows.length)
      return (
        header + `<div class="text-gray-500">Chưa có dữ liệu trực BCH.</div>`
      );

    const table = `
      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3">Tuần</th>
              <th class="text-left px-4 py-3">Ngày</th>
              <th class="text-left px-4 py-3">Ca</th>
              <th class="text-left px-4 py-3">Loại</th>
              <th class="text-right px-4 py-3">Điểm</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${rows
        .map((r) => {
          return `
                <tr>
                  <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.week_start))} → ${esc(fmtDate(r.week_end))}</td>
                  <td class="px-4 py-3 font-medium text-gray-900">${esc(r.day)}</td>
                <td class="px-4 py-3 text-gray-700">${esc(viText(VI.duty_shift_text, r.shift, r.shift || "-"))}</td>
                <td class="px-4 py-3">${esc(viText(VI.duty_type_text, r.type, r.type || "-"))}</td>
                  <td class="px-4 py-3 text-right font-semibold">${esc(r.score)}</td>
                </tr>
              `;
        })
        .join("")}
          </tbody>
        </table>
      </div>
    `;
    return header + table;
  }

  function renderFinance(data) {
    const paid = data.finance_user_paid || [];
    const unpaid = data.finance_user_unpaid || [];

    let paidHtml = "";
    if (!paid.length) {
      paidHtml = `<div class="text-gray-500 py-2">Chưa có khoản phí nào đã đóng.</div>`;
    } else {
      paidHtml = `
        <div class="overflow-auto rounded-2xl border border-gray-100 mb-6">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr>
                <th class="text-left px-4 py-3">Khoản thu</th>
                <th class="text-right px-4 py-3">Số tiền</th>
                <th class="text-left px-4 py-3">Ngày đóng</th>
                <th class="text-left px-4 py-3">Hình thức</th>
                <th class="text-left px-4 py-3">Phạm vi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
              ${paid.map((r) => {
                const scopeBadge = r.scope === "Cả lớp" ? badge("Cả lớp", "blue") : badge("Cá nhân", "gray");
                return `
                  <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">${esc(r.item_name || "-")}</td>
                    <td class="px-4 py-3 text-right font-semibold text-green-600">${fmtMoney(r.amount)}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.trans_date))}</td>
                    <td class="px-4 py-3">${esc(viText(VI.finance_method_text, r.method, r.method || "-"))}</td>
                    <td class="px-4 py-3">${scopeBadge}</td>
                  </tr>
                `;
              }).join("")}
            </tbody>
          </table>
        </div>
      `;
    }

    let unpaidHtml = "";
    if (!unpaid.length) {
      unpaidHtml = `<div class="text-gray-500 py-2">Không có khoản phí chưa đóng nào.</div>`;
    } else {
      unpaidHtml = `
        <div class="overflow-auto rounded-2xl border border-gray-100">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr>
                <th class="text-left px-4 py-3">Khoản thu</th>
                <th class="text-left px-4 py-3">Đối tượng nộp</th>
                <th class="text-left px-4 py-3">Trạng thái</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
              ${unpaid.map((r) => {
                let targetText = "Tất cả";
                if (r.target_type === "doan_vien") targetText = "Đoàn viên";
                else if (r.target_type === "thanh_nien") targetText = "Thanh niên";
                
                return `
                  <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">${esc(r.item_name || "-")}</td>
                    <td class="px-4 py-3 text-gray-700">${esc(targetText)}</td>
                    <td class="px-4 py-3">${badge("Chưa đóng", "red")}</td>
                  </tr>
                `;
              }).join("")}
            </tbody>
          </table>
        </div>
      `;
    }

    return `
      <div class="space-y-6">
        <div>
          <div class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
            Các khoản đã đóng
          </div>
          ${paidHtml}
        </div>
        <div>
          <div class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
            Các khoản chưa đóng
          </div>
          ${unpaidHtml}
        </div>
      </div>
    `;
  }

  function renderViolations(data) {
    if (data.violations_forbidden) {
      return `<div class="text-gray-500">Không có quyền xem Kỷ luật - Vi phạm.</div>`;
    }
    const rows = data.violations || [];
    if (!rows.length) {
      return `<div class="text-gray-500">Không có lịch sử vi phạm.</div>`;
    }
    return `
      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3 w-12">STT</th>
              <th class="text-left px-4 py-3">Lý do vi phạm</th>
              <th class="text-left px-4 py-3">Hình thức xử lý</th>
              <th class="text-left px-4 py-3">Ngày ghi nhận</th>
              <th class="text-left px-4 py-3">Người lập</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${rows.map((r, idx) => {
              return `
                <tr>
                  <td class="px-4 py-3 font-medium text-gray-500">${idx + 1}</td>
                  <td class="px-4 py-3 font-medium text-gray-900">${esc(r.reason || "-")}</td>
                  <td class="px-4 py-3">${esc(r.treatment || "-")}</td>
                  <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.created_at))}</td>
                  <td class="px-4 py-3 text-gray-700">${esc(r.creator_name || "-")}</td>
                </tr>
              `;
            }).join("")}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderInventory(data) {
    const rows = data.borrows || [];
    if (!rows.length)
      return `<div class="text-gray-500">User này chưa tạo phiếu mượn thiết bị.</div>`;

    return `
      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3">Thiết bị</th>
              <th class="text-left px-4 py-3">Người mượn</th>
              <th class="text-right px-4 py-3">SL</th>
              <th class="text-left px-4 py-3">Mượn</th>
              <th class="text-left px-4 py-3">Hạn</th>
              <th class="text-left px-4 py-3">TT</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${rows
        .map((r) => {
          const stKey = keyLower(r.status);
          let st = VI.borrow_status[stKey]
            ? viBadgeFromMap(VI.borrow_status, stKey)
            : badge(stKey || "-", "gray");

          return `
                <tr>
                  <td class="px-4 py-3 font-medium text-gray-900">${esc(r.item_name || "-")}</td>
                  <td class="px-4 py-3">${esc(r.borrower_name || "-")}</td>
                  <td class="px-4 py-3 text-right font-semibold">${esc(r.quantity || 0)}</td>
<td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.borrow_date))}</td>
<td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.return_deadline))}</td>
                  <td class="px-4 py-3">${st}</td>
                </tr>
              `;
        })
        .join("")}
          </tbody>
        </table>
      </div>
    `;
  }
  function renderAchievements(data) {
    if (data.achievements_forbidden) {
      return `<div class="text-gray-500">Không có quyền xem thành tích cá nhân.</div>`;
    }

    const st = data.achievement_stats || {};
    const rows = data.achievements || [];

    const header = `
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Tổng</div>
          <div class="text-lg font-semibold">${esc(st.total || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Nháp</div>
          <div class="text-lg font-semibold">${esc(st.draft || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Chờ duyệt</div>
          <div class="text-lg font-semibold">${esc(st.submitted || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Đã duyệt</div>
          <div class="text-lg font-semibold">${esc(st.approved || 0)}</div>
        </div>
        <div class="rounded-2xl border border-gray-100 p-4">
          <div class="text-xs text-gray-500">Từ chối</div>
          <div class="text-lg font-semibold">${esc(st.rejected || 0)}</div>
        </div>
      </div>
    `;

    if (!rows.length) {
      return header + `<div class="text-gray-500">Chưa có thành tích.</div>`;
    }

    return (
      header +
      `
      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3">Tiêu đề</th>
              <th class="text-left px-4 py-3">Ngày đạt</th>
              <th class="text-left px-4 py-3">Trạng thái</th>
              <th class="text-right px-4 py-3">Minh chứng</th>
              <th class="text-left px-4 py-3">Gửi duyệt</th>
              <th class="text-left px-4 py-3">Duyệt</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${rows
        .map((r) => {
          const stKey = keyLower(r.status);
          const stBadge = VI.achievement_status[stKey]
            ? viBadgeFromMap(VI.achievement_status, stKey)
            : badge(stKey || "-", "gray");

          return `
                  <tr>
                    <td class="px-4 py-3">
                      <div class="font-medium text-gray-900">${esc(r.title || "-")}</div>
                      ${r.review_note
              ? `<div class="text-xs text-gray-500 mt-1">Ghi chú: ${esc(r.review_note)}</div>`
              : `<div class="text-xs text-gray-500 mt-1">—</div>`
            }
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.achieved_at))}</td>
                    <td class="px-4 py-3">${stBadge}</td>
                    <td class="px-4 py-3 text-right font-semibold">${esc(r.files_count || 0)}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.submitted_at))}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.reviewed_at))}</td>
                  </tr>
                `;
        })
        .join("")}
          </tbody>
        </table>
      </div>
    `
    );
  }

  function renderNominations(data) {
    const rows = data.nominations || [];
    if (!rows.length)
      return `<div class="text-gray-500">User này chưa có hồ sơ khen thưởng/đề cử.</div>`;

    return `
      <div class="overflow-auto rounded-2xl border border-gray-100">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600">
            <tr>
              <th class="text-left px-4 py-3">Danh hiệu</th>
              <th class="text-left px-4 py-3">Năm học</th>
              <th class="text-left px-4 py-3">Loại</th>
              <th class="text-left px-4 py-3">Trạng thái</th>
              <th class="text-left px-4 py-3">Ngày tạo</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            ${rows
        .map((r) => {
          const stKey = keyLower(r.status);
          let st = VI.nomination_status[stKey]
            ? viBadgeFromMap(VI.nomination_status, stKey)
            : badge(stKey || "-", "gray");

          return `
                <tr>
                  <td class="px-4 py-3 font-medium text-gray-900">${esc(r.title_name || "(chưa chọn)")}</td>
                  <td class="px-4 py-3">${esc(r.school_year || "-")}</td>
                  <td class="px-4 py-3">${esc(viText(VI.nomination_type_text, r.type, r.type || "-"))}</td>
                  <td class="px-4 py-3">${st}</td>
                  <td class="px-4 py-3 text-xs text-gray-500">${esc(fmtDate(r.created_at))}</td>
                </tr>
              `;
        })
        .join("")}
          </tbody>
        </table>
      </div>
    `;
  }


  function renderActiveTab() {
    if (!CURRENT_DATA) {
      $detail.innerHTML = `<div class="text-gray-500">Chưa chọn user nào.</div>`;
      return;
    }

    const d = CURRENT_DATA;

    if (ACTIVE_TAB === "personal") $detail.innerHTML = renderPersonal(d);
    else if (ACTIVE_TAB === "reviews") $detail.innerHTML = renderReviews(d);
    else if (ACTIVE_TAB === "campaigns") $detail.innerHTML = renderCampaigns(d);
    else if (ACTIVE_TAB === "attendance")
      $detail.innerHTML = renderAttendance(d);
    else if (ACTIVE_TAB === "tasks") $detail.innerHTML = renderTasks(d);
    else if (ACTIVE_TAB === "duty") $detail.innerHTML = renderDuty(d);
    else if (ACTIVE_TAB === "finance") $detail.innerHTML = renderFinance(d);
    else if (ACTIVE_TAB === "inventory") $detail.innerHTML = renderInventory(d);
    else if (ACTIVE_TAB === "nominations")
      $detail.innerHTML = renderNominations(d);
    else if (ACTIVE_TAB === "achievements")
      $detail.innerHTML = renderAchievements(d);
    else if (ACTIVE_TAB === "violations")
      $detail.innerHTML = renderViolations(d);

    else
      $detail.innerHTML = `<div class="text-gray-500">Tab không hợp lệ.</div>`;
  }

  /* =========================
       Search flow
    ========================= */
  async function runSearch() {
    if (SELF_ONLY) return;
    if (!$dropdown) return;
    const q = String($q.value || "").trim();

    try {
      const data = await api("search_users", { q, limit: 12 });
      const items = data.items || [];

      if (!items.length) {
        showDropdown(
          `<div class="p-4 text-sm text-gray-500">Không tìm thấy user nào.</div>`,
        );
        return;
      }

      showDropdown(
        items
          .map((it) => {
            const sub = [
              it.mssv ? `MSSV: ${esc(it.mssv)}` : null,
              it.department_name ? esc(it.department_name) : null,
              it.class_name ? esc(it.class_name) : null,
            ]
              .filter(Boolean)
              .join(" • ");

            return `
          <button
            class="w-full text-left px-4 py-3 hover:bg-gray-50"
            data-user-id="${esc(it.id)}"
            data-user-name="${esc(it.display_name || it.username)}"
          >
            <div class="font-medium text-gray-900">${esc(it.display_name || it.username)}</div>
            <div class="text-xs text-gray-500 mt-0.5">${sub || "—"} • ${esc(it.role_name || "role?")}</div>
          </button>
        `;
          })
          .join(""),
      );

      $dropdown.querySelectorAll("button[data-user-id]").forEach((btn) => {
        btn.addEventListener("click", async () => {
          const id = Number(btn.dataset.userId || 0);
          hideDropdown();
          if (id > 0) {
            await loadUserDetail(id);
          }
        });
      });
    } catch (e) {
      showDropdown(
        `<div class="p-4 text-sm text-red-600">${esc(e.message || "Lỗi tìm kiếm")}</div>`,
      );
    }
  }

  async function loadUserDetail(userId) {
    CURRENT_USER_ID = userId;
    ACTIVE_TAB = "personal";
    renderTabs();

    $detail.innerHTML = `
      <div class="rounded-2xl border border-gray-100 p-4 bg-gray-50 text-gray-600">
        Đang load dữ liệu user #${esc(userId)}...
      </div>
    `;

    try {
      const data = await api("get_user_detail", { user_id: userId });
      CURRENT_DATA = data;

      // ✅ render lại tab sau khi có CURRENT_DATA (để duty hiện đúng role)
      renderTabs();

      renderSummary(data);
      renderPaid(data);
      renderActiveTab();

    } catch (e) {
      CURRENT_DATA = null;
      $summary.innerHTML = `Load lỗi: ${esc(e.message || "Unknown")}`;
      $detail.innerHTML = `<div class="text-red-600">${esc(e.message || "Không load được user")}</div>`;
    }
  }

  function clearAll() {
    if (SELF_ONLY) {
      // self-only: reset về chính mình
      if ($q) {
        if (ME_MSSV && !$q.value) $q.value = ME_MSSV;
        $q.disabled = true;
      }
      hideDropdown();
      if (ME_ID > 0) loadUserDetail(ME_ID);
      return;
    }

    CURRENT_USER_ID = null;
    CURRENT_DATA = null;
    ACTIVE_TAB = "personal";
    renderTabs();
    hideDropdown();
    if ($q) $q.value = "";
    if ($paid) $paid.innerHTML = "Chưa chọn user nào.";
    $summary.innerHTML = "Chưa chọn user nào.";
    $detail.innerHTML = `Chọn 1 user ở bên trái để hiển thị thông tin.`;
  }


  /* =========================
       Events + init
  ========================= */

  // Self-only: khóa search UI ngay từ đầu
  if (SELF_ONLY) {
    if ($q) {
      if (ME_MSSV) $q.value = ME_MSSV;
      $q.disabled = true;
      $q.classList.add("opacity-70", "cursor-not-allowed");
    }
    hideDropdown();
  } else {
    // ✅ bấm/focus vào ô input => hiện gợi ý ngay (kể cả đang rỗng)
    const openSuggest = () => {
      if (SEARCH_TIMER) clearTimeout(SEARCH_TIMER);
      SEARCH_TIMER = setTimeout(runSearch, 0);
    };

    $q?.addEventListener("focus", openSuggest);
    $q?.addEventListener("click", openSuggest);

    // gõ chữ vẫn debounce như cũ
    $q?.addEventListener("input", () => {
      if (SEARCH_TIMER) clearTimeout(SEARCH_TIMER);
      SEARCH_TIMER = setTimeout(runSearch, 250);
    });

    document.addEventListener("click", (e) => {
      if (!$dropdown) return;
      const inside = $dropdown.contains(e.target) || ($q && $q.contains(e.target));
      if (!inside) hideDropdown();
    });
  }


  $btnRefresh?.addEventListener("click", async () => {
    if (SELF_ONLY) {
      if (ME_ID > 0) await loadUserDetail(ME_ID);
      return;
    }
    if (!CURRENT_USER_ID) return;
    await loadUserDetail(CURRENT_USER_ID);
  });

  $btnClear?.addEventListener("click", clearAll);

  // init
  renderTabs();

  if (ME_ID > 0) {
    // ✅ luôn ưu tiên load hồ sơ của chính mình khi có me-id
    loadUserDetail(ME_ID);
  } else {
    // fallback: chỉ search nếu không self-only
    if (!SELF_ONLY) runSearch();
  }

})();

