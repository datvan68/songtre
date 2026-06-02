(function () {
  const app = document.getElementById("finance-app");
  if (!app) return;

  const API = window.FINANCE_API;
  if (!API) {
    console.error("Missing FINANCE_API");
    return;
  }

  const $ = (id) => document.getElementById(id);

  const els = {
    btnIncome: $("btnCreateIncome"),
    btnExpense: $("btnCreateExpense"),
    mobileList: $("financeMobileList"),

    statIncome: $("statIncome"),
    statExpense: $("statExpense"),
    statBalance: $("statBalance"),

    filterType: $("filterType"),
    filterDept: $("filterDept"),
    filterClass: $("filterClass"),
    filterFrom: $("filterFrom"),
    filterTo: $("filterTo"),
    filterQ: $("filterQ"),
    btnRefresh: $("btnRefresh"),

    tbody: $("financeTbody"),
    pagingInfo: $("pagingInfo"),
    pagingPage: $("pagingPage"),
    btnPrev: $("btnPrev"),
    btnNext: $("btnNext"),

    btnVoucherSettings: $("btnVoucherSettings"),
    btnExportUnpaidSummary: $("btnExportUnpaidSummary"),

  };

  let META = { departments: [], courses: [], school_years: [], semesters: [], me: null };
  let FINANCE_CACHE = [];

  function isAdminUser() {
    const me = META?.me || {};

    // boolean flags
    if (me.is_admin === 1 || me.is_admin === true) return true;
    if (me.isAdmin === 1 || me.isAdmin === true) return true;

    // role name / code
    const roleName = String(me.role_name || me.role || me.roleCode || "").toLowerCase();
    if (roleName === "admin") return true;

    // role_id fallback (nhiều hệ thống admin = 1)
    const rid = Number(me.role_id || 0);
    if (rid === 1) return true;

    return false;
  }


  let STATE = {
    page: 1,
    page_size: 10,
    type: "all",
    department_id: "",
    class_text: "",
    from: "",
    to: "",
    q: "",
    total: 0,
    total_pages: 1,
  };

  const toast =
    window.toast ||
    function (msg) {
      alert(msg);
    };

  async function api(action, data = {}, method = "POST") {
    const url = API + "?action=" + encodeURIComponent(action);
    const opt = {
      method,
      headers: { "Content-Type": "application/json" },
      body: method === "GET" ? undefined : JSON.stringify(data),
    };

    const res = await fetch(url, opt);
    const j = await res.json().catch(() => null);
    if (!j || !j.ok) {
      throw new Error(j?.error || "Lỗi server");
    }
    return j.data;
  }
  async function apiGet(action, params = {}) {
    const url = new URL(API, window.location.origin);
    url.searchParams.set("action", action);
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      url.searchParams.set(k, String(v));
    });

    const res = await fetch(url.toString(), { method: "GET" });
    const j = await res.json().catch(() => null);
    if (!j || !j.ok) throw new Error(j?.error || "Lỗi server");
    return j.data;
  }

  async function apiForm(action, fields = {}) {
    const url = API + "?action=" + encodeURIComponent(action);
    const fd = new FormData();
    Object.entries(fields || {}).forEach(([k, v]) => fd.append(k, v ?? ""));
    const res = await fetch(url, { method: "POST", body: fd });
    const j = await res.json().catch(() => null);
    if (!j || !j.ok) throw new Error(j?.error || "Lỗi server");
    return j.data;
  }

  function escapeHtml(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function dash(v) {
    const s = String(v ?? "").trim();
    return s ? escapeHtml(s) : "--";
  }

  function normText(s) {
    return String(s ?? "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/đ/g, "d")
      .replace(/[^a-z0-9]/g, "");
  }

  function fmtMoney(n) {
    try {
      return new Intl.NumberFormat("vi-VN").format(Number(n || 0));
    } catch {
      return String(n || 0);
    }
  }

  function parseMoney(v) {
    // "100.000" => 100000
    const s = String(v ?? "").replace(/\D/g, "");
    return s ? Number(s) : 0;
  }

  function getParticipantIds(root) {
    const hid = root.querySelector("#fParticipantIds");
    try {
      const arr = JSON.parse(hid?.value || "[]");
      if (!Array.isArray(arr)) return [];
      return arr.map((x) => Number(x)).filter((n) => n > 0);
    } catch {
      return [];
    }
  }

  function updateParticipantsCount(root) {
    const outCount = root.querySelector("#participantsCount");
    if (!outCount) return;
    outCount.textContent = String(getParticipantIds(root).length);
  }

  function bindMoneyMask(input) {
    if (!input) return;
    input.addEventListener("input", () => {
      const digits = String(input.value ?? "").replace(/\D/g, "");
      input.value = digits ? fmtMoney(digits) : "";
    });
  }

  function fmtInt(v) {
    const n = Number(v || 0);
    if (!isFinite(n) || n <= 0) return "";
    return String(Math.round(n));
  }

  function fmtDate(d) {
    if (!d) return "";
    const parts = String(d).split("-");
    if (parts.length === 3) return parts[2] + "/" + parts[1] + "/" + parts[0];
    return d;
  }

  function badgeType(t) {
    if (t === "income") {
      return `<span class="px-2 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">Thu</span>`;
    }
    return `<span class="px-2 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">Chi</span>`;
  }

  function deptLabel(t) {
    const x = String(t || "").toLowerCase();
    if (x === "khoa") return "Khoa";
    if (x === "phong") return "Phòng";
    return "Đơn vị";
  }

  function iconEdit() {
    return `
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
        <path d="M12 20h9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4 11.5-11.5z"
          stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    `;
  }

  function iconTrash() {
    return `
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
        <path d="M3 6h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        <path d="M8 6V4h8v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        <path d="M19 6l-1 14H6L5 6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
        <path d="M10 11v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        <path d="M14 11v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      </svg>
    `;
  }

  function iconDownload() {
    return `
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M12 3v12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      <path d="M8 11l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M5 21h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
    </svg>
  `;
  }

  function iconPrinter() {
    // ✅ icon máy in mới (khác icon cũ)
    return `
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
      <path d="M8 7V3h8v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M6 17h12v4H6z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
      <path d="M6 10H5a3 3 0 0 0-3 3v2a2 2 0 0 0 2 2h2"
        stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      <path d="M18 10h1a3 3 0 0 1 3 3v2a2 2 0 0 1-2 2h-2"
        stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      <path d="M8 13h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      <path d="M8 16h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
    </svg>
  `;
  }


  function bindOutsideClose(inputEl, boxEl, hideFn) {
    if (!inputEl || !boxEl) return;

    const handler = (e) => {
      const t = e.target;
      if (boxEl.contains(t) || inputEl === t) return;
      hideFn();
    };

    document.addEventListener("pointerdown", handler, true);
    return () => document.removeEventListener("pointerdown", handler, true);
  }

  function renderDeptOptionsGrouped(depts = [], mode = "filter") {
    const head =
      mode === "filter"
        ? `<option value="">Tất cả</option>`
        : `<option value="">-- Chọn khoa --</option>`;

    const khoa = [];
    const phong = [];
    const other = [];

    for (const d of depts) {
      const t = String(d.type || "").toLowerCase();
      const opt = `<option value="${d.id}">${escapeHtml(d.name)}</option>`;
      if (t === "khoa") khoa.push(opt);
      else if (t === "phong") phong.push(opt);
      else other.push(opt);
    }

    let html = head;
    if (khoa.length) html += `<optgroup label="Khoa">${khoa.join("")}</optgroup>`;
    if (phong.length) html += `<optgroup label="Phòng">${phong.join("")}</optgroup>`;
    if (other.length) html += `<optgroup label="Khác">${other.join("")}</optgroup>`;
    return html;
  }

  function renderCourseOptions(courses = [], mode = "form") {
    const head = mode === "form"
      ? `<option value="">-- Chọn khóa --</option>`
      : `<option value="">Tất cả</option>`;

    const opts = (courses || [])
      .map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
      .join("");

    return head + opts;
  }
  function renderSchoolYearOptions(years = []) {
    const head = `<option value="">-- Chọn năm học --</option>`;
    const opts = (years || [])
      .map((y) => {
        const label = y.year_label || y.name || y.title || y.school_year || y.id;
        // ✅ value vẫn dùng id để edit/update đúng (an toàn)
        return `<option value="${escapeHtml(y.id)}">${escapeHtml(label)}</option>`;
      })
      .join("");
    return head + opts;
  }
  function renderSemesterOptions(semesters = []) {
    const head = `<option value="">-- Chọn học kỳ --</option>`;
    const opts = (semesters || [])
      .map((s) => {
        const code = s.code ?? s.value ?? "";
        const label = s.label ?? s.name ?? code;
        if (!code) return "";
        return `<option value="${escapeHtml(code)}">${escapeHtml(label)}</option>`;
      })
      .join("");
    return head + opts;
  }

  /* =========================================================
   ✅ VOUCHER SETTINGS (Header lines)
========================================================= */
  function normalizeVoucherSign(resp) {
    let src = resp;

    if (src && typeof src === "object") {
      if (src.data && typeof src.data === "object") src = src.data;
      if (src.row && typeof src.row === "object") src = src.row;
    }

    return String(
      src?.sign_line3 ??
      src?.signName ??
      src?.sign_name ??
      ""
    ).trim();
  }

  async function voucherSignGet() {
    const data = await api("voucher_sign_get", {});
    return normalizeVoucherSign(data);
  }

  async function voucherSignSave(signLine3) {
    return api("voucher_sign_save", {
      sign_line3: String(signLine3 || "").trim(), // cho phép rỗng (fallback server)
    });
  }

  function normalizeVoucherSettings(resp) {
    // resp ở đây là j.data (vì api() trả về j.data)
    // có thể controller trả về nhiều kiểu: {row:{}}, {rows:[{}]}, {settings:{}}, hoặc trả thẳng object
    let src = resp;

    // unwrap các lớp phổ biến
    if (src && typeof src === "object") {
      if (src.settings && typeof src.settings === "object") src = src.settings;
      else if (src.row && typeof src.row === "object") src = src.row;
      else if (Array.isArray(src.rows) && src.rows.length) src = src.rows[0];

      // phòng trường hợp controller lỡ trả nested
      else if (src.data && typeof src.data === "object") {
        let d = src.data;
        if (d.settings && typeof d.settings === "object") src = d.settings;
        else if (d.row && typeof d.row === "object") src = d.row;
        else if (Array.isArray(d.rows) && d.rows.length) src = d.rows[0];
        else src = d;
      }
    }

    // map đúng theo tên cột trong DB + fallback các tên khác
    const line1 = String(
      src?.org_line1 ??
      src?.line1 ??
      src?.office_line1 ??
      src?.header_line1 ??
      ""
    ).trim();

    const line2 = String(
      src?.org_line2 ??
      src?.line2 ??
      src?.office_line2 ??
      src?.header_line2 ??
      ""
    ).trim();

    const line3 = String(
      src?.org_line3 ??
      src?.line3 ??
      src?.office_line3 ??
      src?.header_line3 ??
      ""
    ).trim();

    return { line1, line2, line3 };
  }

  async function voucherSettingsGet() {
    const data = await api("voucher_settings_get", {});
    return normalizeVoucherSettings(data);
  }

  async function voucherSettingsSave(line1, line2, line3) {
    return api("voucher_settings_save", {
      org_line1: String(line1 || "").trim(),
      org_line2: String(line2 || "").trim(),
      org_line3: String(line3 || "").trim(),
    });
  }


  function renderVoucherHeaderPreview(s) {
    const l1 = s.line1 || "(dòng 1)";
    const l2 = s.line2 || "(dòng 2)";
    const l3 = s.line3 || "(dòng 3)";
    return `
    <div class="rounded-xl border bg-gray-50 p-3">
      <div class="text-xs text-gray-500 mb-2">Preview (in trên phiếu)</div>
      <div class="font-bold text-[13pt] leading-[1.1]">${escapeHtml(l1)}</div>
      <div class="font-bold text-[13pt] leading-[1.1]">${escapeHtml(l2)}</div>
      <div class="font-bold text-[13pt] leading-[1.1]">${escapeHtml(l3)}</div>
    </div>
  `;
  }

  async function openVoucherSettingsModal() {
    // chỉ admin mới mở (an toàn thêm 1 lớp)
    if (!isAdminUser()) {
      toast("Bạn không có quyền thao tác");
      return;
    }
    let curSign = "";
    try {
      curSign = await voucherSignGet();
    } catch (e) {
      console.warn("voucher_sign_get error:", e);
    }

    let cur = { line1: "", line2: "", line3: "" };
    try {
      cur = await voucherSettingsGet();
    } catch (e) {
      // nếu server chưa có action thì sẽ báo lỗi — vẫn cho mở modal rỗng
      console.warn("voucher_settings_get error:", e);
    }

    const html = `
    <div id="voucherSettingsRoot" class="space-y-4">

      <div class="grid grid-cols-1 gap-3">
        <div>
          <div class="text-sm font-semibold mb-1">Dòng 1</div>
          <input id="vLine1" class="w-full border rounded-xl px-3 py-2"
            placeholder="VD: Văn phòng Đoàn trường ..."
            value="${escapeHtml(cur.line1)}">
        </div>

        <div>
          <div class="text-sm font-semibold mb-1">Dòng 2</div>
          <input id="vLine2" class="w-full border rounded-xl px-3 py-2"
            placeholder="VD: Lầu 1 - khu A - phòng ..."
            value="${escapeHtml(cur.line2)}">
        </div>

        <div>
          <div class="text-sm font-semibold mb-1">Dòng 3</div>
          <input id="vLine3" class="w-full border rounded-xl px-3 py-2"
            placeholder="VD: Bí thư đoàn trường: ...."
            value="${escapeHtml(cur.line3)}">
        </div>
        <div>
  <div class="text-sm font-semibold mb-1">Tên người ký (Bí thư đoàn trường)</div>
  <input id="vSignLine3" class="w-full border rounded-xl px-3 py-2"
    placeholder="VD: Nguyễn Văn A"
    value="${escapeHtml(curSign)}">
  <div class="text-xs text-gray-500 mt-1">
    (Có thể để trống: hệ thống sẽ tự dùng “Người lập phiếu”)
  </div>
</div>

      </div>

      <div id="voucherPreview">
        ${renderVoucherHeaderPreview(cur)}
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button id="btnVCancel" class="px-4 py-2 rounded-xl border hover:bg-gray-50">
          Hủy
        </button>
        <button id="btnVSave" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700">
          Lưu
        </button>
      </div>

    </div>
  `;

    modal(html, "Cấu hình phiếu", "large");

    const root = document.getElementById("voucherSettingsRoot");
    if (!root) return;

    const line1 = root.querySelector("#vLine1");
    const line2 = root.querySelector("#vLine2");
    const line3 = root.querySelector("#vLine3");
    const sign3 = root.querySelector("#vSignLine3");

    const preview = root.querySelector("#voucherPreview");

    const refreshPreview = () => {
      const s = {
        line1: line1?.value.trim() || "",
        line2: line2?.value.trim() || "",
        line3: line3?.value.trim() || "",
      };
      if (preview) preview.innerHTML = renderVoucherHeaderPreview(s);
    };

    line1?.addEventListener("input", refreshPreview);
    line2?.addEventListener("input", refreshPreview);
    line3?.addEventListener("input", refreshPreview);

    root.querySelector("#btnVCancel").onclick = () => closeModal();

    root.querySelector("#btnVSave").onclick = async () => {
      try {
        const payload = {
          line1: line1?.value.trim() || "",
          line2: line2?.value.trim() || "",
          line3: line3?.value.trim() || "",
        };
        if (!payload.line1 || !payload.line2 || !payload.line3) {
          return toast("Vui lòng nhập đủ 3 dòng thông tin phiếu");
        }
        await voucherSettingsSave(payload.line1, payload.line2, payload.line3);
        await voucherSignSave(sign3?.value.trim() || "");

        toast("Đã lưu cấu hình phiếu");
        closeModal();
      } catch (e) {
        toast(e.message);
      }
    };

    // focus
    setTimeout(() => line1?.focus(), 10);
  }

  function bindVoucherSettingsButton() {
    const btn = els.btnVoucherSettings;
    if (!btn) return;

    // nếu view render nhầm cho non-admin thì JS tự ẩn
    if (!isAdminUser()) {
      btn.remove();
      return;
    }

    btn.addEventListener("click", () => {
      openVoucherSettingsModal().catch((e) => toast(e.message));
    });
  }

  function iconUnpaidStat() {
    return `
      <svg class="w-4 h-4 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
        <path d="M9 12l2 2 4-4"/>
      </svg>
    `;
  }

  function iconExcelDownload() {
    return `
      <svg class="w-4 h-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
      </svg>
    `;
  }

  /* =========================================================
     ✅ TABLE RENDER
  ========================================================= */
  function renderRow(r) {
    const isIncome = r.type === "income";
    const moneyClass = isIncome ? "text-emerald-700" : "text-rose-700";

    const amountText =
      r.amount !== null && r.amount !== undefined && String(r.amount) !== ""
        ? fmtMoney(r.amount)
        : "--";

    const dateText = r.trans_date ? fmtDate(r.trans_date) : "--";

    // ✅ Thu: giữ như cũ
    // ✅ Chi: đổi hiển thị đúng nghiệp vụ
    let colDeptHtml = "";
    let colClassHtml = "";
    let colPayerHtml = "";
    let colReceiverHtml = "";

    if (isIncome) {
      colDeptHtml = `
        <div class="text-xs text-gray-500">${deptLabel(r.department_type)}</div>
        <div class="font-medium">${dash(r.department_name)}</div>
      `;
      colClassHtml = `${dash(r.class_text)}`;

      colPayerHtml = `
        <div class="text-xs text-gray-500">Người nộp</div>
        <div class="font-medium">${dash(r.payer_name)}</div>
      `;

      colReceiverHtml = `
        <div class="text-xs text-gray-500">Người nhận</div>
        <div class="font-medium">${dash(r.receiver_name)}</div>
      `;
    } else {
      // expense
      colDeptHtml = `
        <div class="text-xs text-gray-500">Người được chi</div>
        <div class="font-medium">${dash(r.payee_name)}</div>
      `;

      colClassHtml = `
        <div class="text-xs text-gray-500">Chức vụ</div>
        <div class="font-medium">${dash(r.class_text)}</div>
      `;

      colPayerHtml = `
        <div class="text-xs text-gray-500">Người duyệt chi</div>
        <div class="font-medium">${dash(r.payer_name)}</div>
      `;

      colReceiverHtml = `
        <div class="text-xs text-gray-500">Chức vụ duyệt</div>
        <div class="font-medium">${dash(r.receiver_name)}</div>
      `;
    }
    const semText = dash(r.semester_label || r.semester);   // ✅ ưu tiên label
    const yearText = dash(r.school_year_label || r.year_label);

    const hkYearText =
      (semText !== "--" || yearText !== "--")
        ? `${semText} • ${yearText}`
        : "--";

    return `
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 whitespace-nowrap text-center">
          ${badgeType(r.type)}
        </td>

        <td class="px-4 py-3 whitespace-nowrap">
          <span class="text-gray-800">${dateText}</span>
        </td>
<!-- ✅ NEW COLUMN -->
<td class="px-4 py-3 whitespace-nowrap">
  <span class="text-gray-800 font-medium">${hkYearText}</span>
</td>
<td class="px-4 py-3 align-top !whitespace-normal break-all [overflow-wrap:anywhere]">
  <div class="font-semibold text-gray-900 !whitespace-normal break-all [overflow-wrap:anywhere] leading-snug">
    ${dash(r.item_name)}
  </div>
  <div class="text-xs text-gray-500 mt-0.5 !whitespace-normal break-all [overflow-wrap:anywhere] leading-snug">
    ${dash(r.description)}
  </div>
</td>


        <td class="px-4 py-3 text-right font-bold ${moneyClass} whitespace-nowrap">
          ${amountText}
        </td>

        <td class="px-4 py-3">${colDeptHtml}</td>
        <td class="px-4 py-3">${colClassHtml}</td>

        <td class="px-4 py-3">${colPayerHtml}</td>
        <td class="px-4 py-3">${colReceiverHtml}</td>

<td class="px-4 py-3 align-top !whitespace-normal break-all [overflow-wrap:anywhere] leading-snug">
  ${dash(r.note)}
</td>

        <td class="px-4 py-3 text-right sticky right-0 bg-gray-50 whitespace-nowrap">
          <div class="flex justify-end gap-1">
            ${isIncome ? `
              <button class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-white"
                title="Thống kê đóng tiền lớp" data-act="unpaid_stat" data-id="${r.id}">
                ${iconUnpaidStat()}
              </button>
            ` : ""}

            <button class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-white"
              title="Sửa" data-act="edit" data-id="${r.id}">
              ${iconEdit()}
            </button>

            <button class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-white text-rose-700"
              title="Xóa" data-act="del" data-id="${r.id}">
              ${iconTrash()}
            </button>

            <button class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-white"
            title="Tải về" data-act="download" data-id="${r.id}">
            ${iconDownload()}
            </button>

            <button class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-white"
            title="In" data-act="print_now" data-id="${r.id}">
            ${iconPrinter()}
            </button>

          </div>
        </td>
      </tr>
    `;
  }

  function renderHK(r) {
    const hk = (r.semester_label || r.semester || "").trim();
    const year = (r.year_label || r.school_year_label || r.school_year_name || "").trim();
    const hkText = hk ? hk : "--";
    const yearText = year ? year : "--";
    return `${escapeHtml(hkText)} • ${escapeHtml(yearText)}`;
  }

  function renderCard(r) {
    const isIncome = r.type === "income";
    const moneyClass = isIncome ? "text-emerald-700" : "text-rose-700";

    const amountText =
      r.amount !== null && r.amount !== undefined && String(r.amount) !== ""
        ? fmtMoney(r.amount)
        : "--";

    const dateText = r.trans_date ? fmtDate(r.trans_date) : "--";
    const hkText = renderHK(r);

    // ✅ nội dung theo nghiệp vụ Thu/Chi
    let leftInfo = "";
    let rightInfo = "";

    if (isIncome) {
      leftInfo = `
      <div class="text-xs text-gray-500">${deptLabel(r.department_type)} / Lớp</div>
      <div class="font-medium text-gray-900 !whitespace-normal break-all [overflow-wrap:anywhere]">
        ${dash(r.department_name)} • ${dash(r.class_text)}
      </div>
    `;
      rightInfo = `
      <div class="text-xs text-gray-500">Người nộp</div>
      <div class="font-medium text-gray-900 !whitespace-normal break-all [overflow-wrap:anywhere]">
        ${dash(r.payer_name)}
      </div>
    `;
    } else {
      leftInfo = `
      <div class="text-xs text-gray-500">Người được chi</div>
      <div class="font-medium text-gray-900 !whitespace-normal break-all [overflow-wrap:anywhere]">
        ${dash(r.payee_name)}
      </div>
    `;
      rightInfo = `
      <div class="text-xs text-gray-500">Duyệt chi</div>
      <div class="font-medium text-gray-900 !whitespace-normal break-all [overflow-wrap:anywhere]">
        ${dash(r.payer_name)} • ${dash(r.receiver_name)}
      </div>
    `;
    }

    return `
    <div class="bg-white border rounded-2xl p-4 shadow-sm">
      
      <div class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <div class="flex items-center gap-2">
            ${badgeType(r.type)}
            <span class="text-xs text-gray-500">${dateText}</span>
          </div>
          <div class="text-xs text-gray-500">${hkText}</div>
        </div>

        <div class="text-right">
          <div class="text-xs text-gray-500">Số tiền</div>
          <div class="text-lg font-extrabold ${moneyClass}">${amountText}</div>
        </div>
      </div>

      <div class="mt-3">
        <div class="font-semibold text-gray-900 !whitespace-normal break-all [overflow-wrap:anywhere] leading-snug">
          ${dash(r.item_name)}
        </div>
        <div class="text-sm text-gray-600 mt-1 !whitespace-normal break-all [overflow-wrap:anywhere] leading-snug">
          ${dash(r.description)}
        </div>
      </div>

      <div class="mt-3 grid grid-cols-2 gap-3">
        <div class="bg-gray-50 rounded-xl p-3">
          ${leftInfo}
        </div>
        <div class="bg-gray-50 rounded-xl p-3">
          ${rightInfo}
        </div>
      </div>

      <div class="mt-3">
        <div class="text-xs text-gray-500">Ghi chú</div>
        <div class="text-sm text-gray-700 mt-0.5 !whitespace-normal break-all [overflow-wrap:anywhere] leading-snug">
          ${dash(r.note)}
        </div>
      </div>

      <div class="mt-4 grid ${isIncome ? 'grid-cols-5' : 'grid-cols-4'} gap-2">
        ${isIncome ? `
          <button class="h-11 rounded-xl border bg-white hover:bg-gray-50 flex items-center justify-center gap-2"
            title="Thống kê" data-act="unpaid_stat" data-id="${r.id}">
            ${iconUnpaidStat()}
          </button>
        ` : ""}

        <button class="h-11 rounded-xl border bg-white hover:bg-gray-50 flex items-center justify-center gap-2"
          data-act="edit" data-id="${r.id}">
          ${iconEdit()} <span class="text-sm font-semibold">Sửa</span>
        </button>

        <button class="h-11 rounded-xl border bg-white hover:bg-gray-50 text-rose-700 flex items-center justify-center gap-2"
          data-act="del" data-id="${r.id}">
          ${iconTrash()} <span class="text-sm font-semibold">Xóa</span>
        </button>

        <button class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-white"
        title="Tải về" data-act="download" data-id="${r.id}">
        ${iconDownload()}
        </button>

        <button class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-white"
        title="In" data-act="print_now" data-id="${r.id}">
        ${iconPrinter()}
        </button>

      </div>

    </div>
  `;
  }


  async function loadMeta() {
    const data = await api("meta", {});
    META = data || { departments: [], courses: [], school_years: [], semesters: [], me: null };

    if (els.filterDept) {
      els.filterDept.innerHTML = renderDeptOptionsGrouped(META.departments || [], "filter");
    }

    // (Tuỳ bạn có filter semester/year ngoài trang hay không)
    // Nếu có element filterSemester/filterSchoolYear thì set ở đây.
  }


  async function loadList() {
    const data = await api("list", {
      page: STATE.page,
      page_size: STATE.page_size,
      type: STATE.type,
      department_id: STATE.department_id,
      class_text: STATE.class_text,
      from: STATE.from,
      to: STATE.to,
      q: STATE.q,
    });

    const rows = data.rows || [];
    FINANCE_CACHE = rows;
    els.tbody.innerHTML = rows.map(renderRow).join("");

    if (els.mobileList) {
      els.mobileList.innerHTML = rows.length
        ? rows.map(renderCard).join("")
        : `<div class="text-sm text-gray-500 p-4 text-center">Không có dữ liệu</div>`;
    }

    const p = data.paging || {};
    STATE.page = p.page || 1;
    STATE.total = p.total || 0;
    STATE.total_pages = p.total_pages || 1;

    els.pagingInfo.textContent = `Tổng: ${STATE.total} khoản`;
    els.pagingPage.textContent = `${STATE.page}/${STATE.total_pages}`;

    const st = data.stats || {};
    els.statIncome.textContent = fmtMoney(st.income || 0);
    els.statExpense.textContent = fmtMoney(st.expense || 0);
    els.statBalance.textContent = fmtMoney(st.balance || 0);
  }

  function bindFilters() {
    const apply = () => {
      STATE.type = els.filterType.value;
      STATE.department_id = els.filterDept.value;
      STATE.class_text = els.filterClass.value.trim();
      STATE.from = els.filterFrom.value;
      STATE.to = els.filterTo.value;
      STATE.q = els.filterQ.value.trim();
      STATE.page = 1;
      loadList().catch((e) => toast(e.message));
    };

    let tmr = null;
    const debounce = () => {
      clearTimeout(tmr);
      tmr = setTimeout(apply, 250);
    };

    els.filterType.onchange = apply;
    els.filterDept.onchange = debounce;
    els.filterClass.oninput = debounce;
    els.filterFrom.onchange = debounce;
    els.filterTo.onchange = debounce;
    els.filterQ.oninput = debounce;

    els.btnRefresh.onclick = () => loadList().catch((e) => toast(e.message));

    if (els.btnExportUnpaidSummary) {
      els.btnExportUnpaidSummary.onclick = () => {
        const params = new URLSearchParams({
          action: "export_unpaid_classes_summary",
          department_id: els.filterDept ? els.filterDept.value : ""
        });
        window.open(API + "?" + params.toString(), "_blank");
      };
    }
  }

  function bindPaging() {
    els.btnPrev.onclick = () => {
      if (STATE.page <= 1) return;
      STATE.page--;
      loadList().catch((e) => toast(e.message));
    };

    els.btnNext.onclick = () => {
      if (STATE.page >= STATE.total_pages) return;
      STATE.page++;
      loadList().catch((e) => toast(e.message));
    };
  }



  /* =========================================================
     ✅ POSITIONS LIST (Chức vụ)
  ========================================================= */
  async function loadPositionsSelect(root, selectId) {
    const data = await api("positions_list", {});
    const rows = data?.rows || [];
    const sel = root.querySelector("#" + selectId);
    if (!sel) return;

    sel.innerHTML =
      `<option value="">-- Chọn chức vụ --</option>` +
      rows.map((x) => `<option value="${escapeHtml(x.name)}">${escapeHtml(x.name)}</option>`).join("");
  }

  /* =========================================================
     ✅ CLASSES BY KHOA + KHÓA
  ========================================================= */
  async function fetchClassesByDeptCourse(deptId, courseId) {
    if (!deptId) return [];
    const data = await api("classes_by_dept_course", {
      department_id: deptId || "",
      course_id: courseId || "",
    });

    const rows = data?.rows || [];
    return rows
      .map((x) => (typeof x === "string" ? x : x?.name || ""))
      .filter(Boolean);
  }

  function setupClassAutocomplete(root, deptSelect, courseSelect) {
    const input = root.querySelector("#fClass");
    const box = root.querySelector("#classSug");
    if (!input || !box || !deptSelect || !courseSelect) return;

    let LIST = [];
    let timer = null;

    const hide = () => {
      box.classList.add("hidden");
      box.innerHTML = "";
    };

    const show = () => box.classList.remove("hidden");

    const render = (items) => {
      if (!items.length) return hide();

      box.innerHTML = items
        .slice(0, 30)
        .map((x) => {
          return `
            <button type="button"
              class="w-full text-left px-3 py-2 hover:bg-gray-50"
              data-name="${escapeHtml(x.name)}">
              <div class="font-medium text-gray-900">${escapeHtml(x.name)}</div>
            </button>
          `;
        })
        .join("");

      show();
    };

    const filter = () => {
      const q = input.value.trim();
      if (!q) return render(LIST);

      const qn = normText(q);
      const items = LIST.filter((x) => x.norm.includes(qn));
      render(items);
    };

    const reload = async (clearInput = false) => {
      const deptId = deptSelect.value;
      const courseId = courseSelect.value;

      if (clearInput) input.value = "";

      const names = await fetchClassesByDeptCourse(deptId, courseId);
      LIST = names.map((name) => ({ name, norm: normText(name) }));

      hide();
    };

    // load lần đầu (giữ lớp khi edit)
    reload(false).catch(() => { });

    // đổi khoa/khóa => reset lớp
    const onChange = () => reload(true).catch(() => { });
    deptSelect.addEventListener("change", onChange);
    courseSelect.addEventListener("change", onChange);

    input.addEventListener("focus", () => {
      if (!LIST.length) return;
      render(LIST);
    });

    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(filter, 120);
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hide();
    });

    box.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-name]");
      if (!btn) return;
      input.value = btn.dataset.name || "";
      hide();
    });

    bindOutsideClose(input, box, hide);
  }

  function setupFilterClassAutocomplete() {
    const input = els.filterClass;
    const deptSel = els.filterDept;
    const box = document.getElementById("filterClassSug");
    if (!input || !deptSel || !box) return;

    let LIST = [];
    let timer = null;

    const hide = () => {
      box.classList.add("hidden");
      box.innerHTML = "";
    };

    const show = () => box.classList.remove("hidden");

    const render = (items, hintText = "") => {
      if (hintText) {
        box.innerHTML = `
                <div class="px-3 py-2 text-sm text-gray-500">
                    ${escapeHtml(hintText)}
                </div>
            `;
        show();
        return;
      }

      if (!items.length) return hide();

      box.innerHTML = items
        .slice(0, 40)
        .map((x) => {
          return `
                <button type="button"
                    class="w-full text-left px-3 py-2 hover:bg-gray-50"
                    data-name="${escapeHtml(x.name)}">
                    <div class="font-medium text-gray-900">${escapeHtml(x.name)}</div>
                </button>
            `;
        })
        .join("");

      show();
    };

    const filter = () => {
      const deptId = deptSel.value;
      if (!deptId) {
        return render([], "Chọn khoa trước để xem danh sách lớp");
      }

      const q = input.value.trim();
      if (!q) return render(LIST);

      const qn = normText(q);
      const items = LIST.filter((x) => x.norm.includes(qn));
      render(items);
    };

    const reload = async (clearInput = true) => {
      const deptId = deptSel.value;

      if (!deptId) {
        LIST = [];
        if (clearInput) input.value = "";
        hide();
        return;
      }

      try {
        // ✅ lấy lớp theo khoa (không cần khóa)
        const names = await fetchClassesByDeptCourse(deptId, "");
        LIST = names.map((name) => ({ name, norm: normText(name) }));

        if (clearInput) {
          input.value = "";
          // trigger lọc lại bảng khi đổi khoa
          input.dispatchEvent(new Event("input", { bubbles: true }));
        }

        hide();
      } catch (e) {
        console.warn("filterClass reload error:", e);
        LIST = [];
        hide();
      }
    };

    // ✅ đổi khoa => reload list lớp theo khoa, clear lớp filter luôn
    deptSel.addEventListener("change", () => reload(true));

    // ✅ click vào input => xổ list (nếu chưa chọn khoa thì hiện hint)
    input.addEventListener("focus", () => {
      if (!deptSel.value) return render([], "Chọn khoa trước để xem danh sách lớp");
      if (!LIST.length) return;
      render(LIST);
    });

    // ✅ gõ => lọc trong list
    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(filter, 120);
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hide();
    });

    box.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-name]");
      if (!btn) return;

      input.value = btn.dataset.name || "";
      hide();

      // ✅ apply filter ngay lập tức
      input.dispatchEvent(new Event("input", { bubbles: true }));
    });

    bindOutsideClose(input, box, hide);

    // load lần đầu (nếu khoa đang có value)
    reload(false).catch(() => { });
  }

  /* =========================================================
     ✅ MEMBERS AUTOCOMPLETE
  ========================================================= */
  async function fetchMemberSuggest(q) {
    const data = await api("members_suggest", { q: q || "", limit: 10 });
    return data?.rows || [];
  }

  /* =========================================================
 ✅ FINANCE ITEMS AUTOCOMPLETE (Khoản thu/chi)
========================================================= */
  async function fetchFinanceItems(type) {
    const data = await api("items_list", { type });
    const rows = data?.rows || [];
    // rows: [{id,name}] => list name
    return rows.map(x => x?.name || "").filter(Boolean);
  }

  /* =========================================================
 ✅ SOURCE ITEMS AUTOCOMPLETE (Nguồn chi = khoản thu)
========================================================= */
  async function fetchSourceItems() {
    const data = await api("items_list", { type: "income" });
    const rows = data?.rows || [];
    return rows
      .map((x) => ({
        id: Number(x.id || 0),
        name: String(x.name || "").trim(),
        norm: normText(x.name || ""),
      }))
      .filter((x) => x.id > 0 && x.name);
  }

  function setupSourceItemAutocomplete(root, keepId = null) {
    const input = root.querySelector("#fSourceItem");
    const hid = root.querySelector("#fSourceItemId");
    const box = root.querySelector("#sourceSug");
    if (!input || !hid || !box) return { reload: async () => { } };

    let LIST = [];
    let timer = null;

    const hide = () => {
      box.classList.add("hidden");
      box.innerHTML = "";
    };
    const show = () => box.classList.remove("hidden");

    const render = (items) => {
      if (!items.length) return hide();

      box.innerHTML = items.slice(0, 30).map((x) => {
        return `
        <button type="button"
          class="w-full text-left px-3 py-2 hover:bg-gray-50"
          data-id="${x.id}"
          data-name="${escapeHtml(x.name)}">
          <div class="font-medium text-gray-900">${escapeHtml(x.name)}</div>
        </button>
      `;
      }).join("");

      show();
    };

    const filter = () => {
      const q = input.value.trim();
      if (!q) return render(LIST);

      const qn = normText(q);
      const items = LIST.filter((x) => x.norm.includes(qn));
      render(items);
    };

    const reload = async () => {
      LIST = await fetchSourceItems();

      // ✅ nếu đang edit có keepId => set label đúng
      if (keepId) {
        const found = LIST.find((x) => x.id === Number(keepId));
        if (found) {
          input.value = found.name;
          hid.value = String(found.id);
        }
      }

      if (document.activeElement === input) render(LIST);
      else hide();
    };

    input.addEventListener("focus", () => {
      if (!LIST.length) return;
      render(LIST);
    });

    input.addEventListener("input", () => {
      // người dùng gõ lại => reset id
      hid.value = "";
      clearTimeout(timer);
      timer = setTimeout(filter, 120);
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hide();
    });

    box.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-id]");
      if (!btn) return;

      const id = Number(btn.dataset.id || 0);
      const name = btn.dataset.name || "";
      input.value = name;
      hid.value = id ? String(id) : "";
      hide();
    });

    bindOutsideClose(input, box, hide);

    reload().catch(() => { });

    return { reload };
  }

  function setupItemAutocomplete(root, type) {
    const input = root.querySelector("#fItem");
    if (!input) return { reload: async () => { } };

    // Nếu là select box (khoản thu)
    if (input.tagName === "SELECT") {
      const reload = async () => {
        const names = await fetchFinanceItems(type);
        const currentVal = input.dataset.initVal || input.value;
        input.innerHTML = `<option value="">-- Chọn khoản thu --</option>` +
          names.map(name => `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join("");
        if (currentVal) {
          input.value = currentVal;
        }
      };

      reload().catch(() => { });
      return { reload };
    }

    const box = root.querySelector("#itemSug");
    if (!box) return { reload: async () => { } };

    let LIST = [];
    let timer = null;

    const hide = () => {
      box.classList.add("hidden");
      box.innerHTML = "";
    };

    const show = () => box.classList.remove("hidden");

    const render = (items) => {
      if (!items.length) return hide();

      box.innerHTML = items.slice(0, 30).map((name) => {
        return `
        <button type="button"
          class="w-full text-left px-3 py-2 hover:bg-gray-50"
          data-name="${escapeHtml(name)}">
          <div class="font-medium text-gray-900">${escapeHtml(name)}</div>
        </button>
      `;
      }).join("");

      show();
    };

    const filter = () => {
      const q = input.value.trim();
      if (!q) return render(LIST);

      const qn = normText(q);
      const items = LIST.filter((name) => normText(name).includes(qn));
      render(items);
    };

    const reload = async () => {
      const names = await fetchFinanceItems(type);
      LIST = names;
      // nếu đang focus thì render luôn
      if (document.activeElement === input) render(LIST);
      else hide();
    };

    input.addEventListener("focus", () => {
      if (!LIST.length) return;
      render(LIST);
    });

    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(filter, 120);
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hide();
    });

    box.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-name]");
      if (!btn) return;
      input.value = btn.dataset.name || "";
      hide();
    });

    bindOutsideClose(input, box, hide);

    // load list lần đầu
    reload().catch(() => { });

    return { reload };
  }

  function setupMemberAutocomplete(root, inputId, boxId) {
    const input = root.querySelector("#" + inputId);
    const box = root.querySelector("#" + boxId);
    if (!input || !box) return;

    let timer = null;
    let CACHE = []; // nhớ list gần nhất

    const hide = () => {
      box.classList.add("hidden");
      box.innerHTML = "";
    };

    const show = () => box.classList.remove("hidden");

    const render = (items) => {
      if (!items || !items.length) return hide();

      CACHE = items;

      box.innerHTML = items
        .map((m) => {
          const name = m.name || "";
          const mssv = m.mssv || "";
          const cls = m.class_text || "";
          const sub = [mssv, cls].filter(Boolean).join(" - ");

          return `
          <button type="button"
            class="w-full text-left px-3 py-2 hover:bg-gray-50"
            data-name="${escapeHtml(name)}">
            <div class="font-medium text-gray-900">${escapeHtml(name)}</div>
            ${sub ? `<div class="text-xs text-gray-500 mt-0.5">${escapeHtml(sub)}</div>` : ""}
          </button>
        `;
        })
        .join("");

      show();
    };

    const run = async (force = false) => {
      const q = input.value.trim();

      // ✅ click vào input (dù rỗng) vẫn hiện list
      if (!q && !force) return hide();

      try {
        const items = await fetchMemberSuggest(q); // q rỗng thì server trả top 10
        render(items);
      } catch (e) {
        console.warn("members_suggest error:", e);
        hide();
      }
    };

    const schedule = (force = false, ms = 200) => {
      clearTimeout(timer);
      timer = setTimeout(() => run(force), ms);
    };

    // ✅ gõ là tìm
    input.addEventListener("input", () => schedule(false, 200));

    // ✅ focus là bung list luôn (kể cả chưa gõ)
    input.addEventListener("focus", () => {
      // nếu đã có cache thì render liền cho mượt
      if (CACHE.length) render(CACHE);
      schedule(true, 60);
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hide();
    });

    box.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-name]");
      if (!btn) return;
      input.value = btn.dataset.name || "";
      hide();
    });

    bindOutsideClose(input, box, hide);
  }


  /* =========================================================
     ✅ MODAL MANAGER: FINANCE ITEMS (Khoản thu/chi)
  ========================================================= */
  async function openItemsManager(type, onChanged) {
    const title = type === "income" ? "Quản lý Khoản thu" : "Quản lý Khoản chi";
    const isIncome = type === "income";

    const html = `
      <div id="itemsManagerRoot" class="space-y-4">
        <div class="flex gap-2">
          <input id="itmNewName" class="flex-1 border rounded-xl px-3 py-2"
            placeholder="Nhập tên danh mục...">
          
          ${isIncome ? `
            <select id="itmNewTarget" class="border rounded-xl px-3 py-2 text-sm w-[160px]">
              <option value="tat_ca">Tất cả đối tượng</option>
              <option value="doan_vien">Đoàn viên</option>
              <option value="thanh_nien">Thanh niên</option>
            </select>
          ` : ""}
          
          <button id="btnItmAdd" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700">
            Thêm
          </button>
        </div>

        <div class="border rounded-xl overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="text-left px-3 py-2">Tên</th>
                ${isIncome ? `<th class="text-left px-3 py-2 w-[180px]">Đối tượng thu</th>` : ""}
                <th class="text-right px-3 py-2 w-[160px]">Thao tác</th>
              </tr>
            </thead>
            <tbody id="itmTbody"></tbody>
          </table>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button id="btnItmClose" class="px-4 py-2 rounded-xl border hover:bg-gray-50">Đóng</button>
        </div>
      </div>
    `;

    modal(html, title, "large");
    const root = document.getElementById("itemsManagerRoot");
    if (!root) return;

    const tbody = root.querySelector("#itmTbody");
    const fireChanged = async () => {
      try {
        if (typeof onChanged === "function") await onChanged();
      } catch { }
    };

    async function reload() {
      const data = await api("items_list", { type });
      const rows = data?.rows || [];

      tbody.innerHTML = rows
        .map((r) => {
          const targetHtml = isIncome ? `
            <td class="px-3 py-2">
              <select class="w-full border rounded-lg px-2 py-1 text-sm bg-white" data-itm="target_type" data-id="${r.id}">
                <option value="tat_ca" ${r.target_type === 'tat_ca' ? 'selected' : ''}>Tất cả</option>
                <option value="doan_vien" ${r.target_type === 'doan_vien' ? 'selected' : ''}>Đoàn viên</option>
                <option value="thanh_nien" ${r.target_type === 'thanh_nien' ? 'selected' : ''}>Thanh niên</option>
              </select>
            </td>
          ` : "";

          return `
            <tr class="border-t">
              <td class="px-3 py-2">
                <input class="w-full border rounded-lg px-2 py-1" value="${escapeHtml(r.name)}"
                  data-itm="name" data-id="${r.id}">
              </td>
              ${targetHtml}
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button class="px-3 py-1.5 rounded-lg border hover:bg-gray-50"
                  data-act="save" data-id="${r.id}">Lưu</button>
                <button class="px-3 py-1.5 rounded-lg border hover:bg-gray-50 text-rose-700"
                  data-act="del" data-id="${r.id}">Xóa</button>
              </td>
            </tr>
          `;
        })
        .join("");
    }

    root.querySelector("#btnItmClose").onclick = () => closeModal();

    root.querySelector("#btnItmAdd").onclick = async () => {
      const name = root.querySelector("#itmNewName").value.trim();
      if (!name) return toast("Chưa nhập tên danh mục");
      const targetType = root.querySelector("#itmNewTarget")?.value || "tat_ca";
      try {
        await api("items_create", { type, name, target_type: targetType });
        root.querySelector("#itmNewName").value = "";
        await reload();
        await fireChanged(); // ✅ refresh list gợi ý ngoài form
        toast("Đã thêm danh mục");
      } catch (e) {
        toast(e.message);
      }
    };

    tbody.addEventListener("click", async (e) => {
      const btn = e.target.closest("button[data-act]");
      if (!btn) return;

      const act = btn.dataset.act;
      const id = Number(btn.dataset.id || 0);
      if (!id) return;

      if (act === "del") {
        if (!confirm("Xóa danh mục này?")) return;
        try {
          await api("items_delete", { id });
          await reload();
          await fireChanged(); // ✅ refresh list gợi ý ngoài form
          toast("Đã xóa");
        } catch (e2) {
          toast(e2.message);
        }
      }

      if (act === "save") {
        const inp = tbody.querySelector('input[data-itm="name"][data-id="' + id + '"]');
        const sel = tbody.querySelector('select[data-itm="target_type"][data-id="' + id + '"]');
        const name = inp ? inp.value.trim() : "";
        const targetType = sel ? sel.value : "tat_ca";
        if (!name) return toast("Tên không được trống");
        try {
          await api("items_update", { id, name, target_type: targetType });
          await reload();
          await fireChanged(); // ✅ refresh list gợi ý ngoài form
          toast("Đã cập nhật");
        } catch (e2) {
          toast(e2.message);
        }
      }
    });

    await reload().catch((e) => toast(e.message));
  }

  /* =========================================================
     ✅ MODAL MANAGER: POSITIONS (Chức vụ)
  ========================================================= */
  async function openPositionsManager(onChanged) {
    const html = `
      <div id="posManagerRoot" class="space-y-4">
        <div class="flex gap-2">
          <input id="posNewName" class="flex-1 border rounded-xl px-3 py-2"
            placeholder="Nhập tên chức vụ...">
          <button id="btnPosAdd" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700">
            Thêm
          </button>
        </div>

        <div class="border rounded-xl overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="text-left px-3 py-2">Chức vụ</th>
                <th class="text-right px-3 py-2 w-[160px]">Thao tác</th>
              </tr>
            </thead>
            <tbody id="posTbody"></tbody>
          </table>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button id="btnPosClose" class="px-4 py-2 rounded-xl border hover:bg-gray-50">Đóng</button>
        </div>
      </div>
    `;

    modal(html, "Quản lý Chức vụ", "large");
    const root = document.getElementById("posManagerRoot");
    if (!root) return;

    const tbody = root.querySelector("#posTbody");
    const fireChanged = async () => {
      try {
        if (typeof onChanged === "function") await onChanged();
      } catch { }
    };
    async function reload() {
      const data = await api("positions_list", {});
      const rows = data?.rows || [];

      tbody.innerHTML = rows
        .map((r) => {
          return `
            <tr class="border-t">
              <td class="px-3 py-2">
                <input class="w-full border rounded-lg px-2 py-1"
                  value="${escapeHtml(r.name)}" data-pos="name" data-id="${r.id}">
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button class="px-3 py-1.5 rounded-lg border hover:bg-gray-50"
                  data-act="save" data-id="${r.id}">Lưu</button>
                <button class="px-3 py-1.5 rounded-lg border hover:bg-gray-50 text-rose-700"
                  data-act="del" data-id="${r.id}">Xóa</button>
              </td>
            </tr>
          `;
        })
        .join("");
    }

    root.querySelector("#btnPosClose").onclick = () => closeModal();

    root.querySelector("#btnPosAdd").onclick = async () => {
      const name = root.querySelector("#posNewName").value.trim();
      if (!name) return toast("Chưa nhập chức vụ");
      try {
        await api("positions_create", { name });
        root.querySelector("#posNewName").value = "";
        await reload();
        await fireChanged(); // ✅ refresh select chức vụ ngoài form
        toast("Đã thêm chức vụ");
      } catch (e) {
        toast(e.message);
      }
    };

    tbody.addEventListener("click", async (e) => {
      const btn = e.target.closest("button[data-act]");
      if (!btn) return;

      const act = btn.dataset.act;
      const id = Number(btn.dataset.id || 0);
      if (!id) return;

      if (act === "del") {
        if (!confirm("Xóa chức vụ này?")) return;
        try {
          await api("positions_delete", { id });
          await reload();
          await fireChanged();
          toast("Đã xóa");
        } catch (e2) {
          toast(e2.message);
        }
      }

      if (act === "save") {
        const inp = tbody.querySelector('input[data-pos="name"][data-id="' + id + '"]');
        const name = inp ? inp.value.trim() : "";
        if (!name) return toast("Tên không được trống");
        try {
          await api("positions_update", { id, name });
          await reload();
          await fireChanged();
          toast("Đã cập nhật");
        } catch (e2) {
          toast(e2.message);
        }
      }
    });

    await reload().catch((e) => toast(e.message));
  }

  /* =========================================================
     ✅ FORM HTML
  ========================================================= */
  function buildFormHTML(type, data = {}) {
    const isIncome = type === "income";

    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, "0");
    const dd = String(today.getDate()).padStart(2, "0");
    const defaultDate = `${yyyy}-${mm}-${dd}`;

    const deptOptions = renderDeptOptionsGrouped(META.departments || [], "form");
    const courseOptions = renderCourseOptions(META.courses || [], "form");
    const schoolYearOptions = renderSchoolYearOptions(META.school_years || []);
    const semesterOptions = renderSemesterOptions(META.semesters || []);
    const canManageItems = isAdminUser();

    // footer buttons (có nút In khi edit)
    const footerBtns = `
  <div class="flex gap-2">
    ${data.id ? `
      <button id="btnExportPDF" class="px-4 py-2 rounded-xl border hover:bg-gray-50">PDF</button>
      <button id="btnExportXLSX" class="px-4 py-2 rounded-xl border hover:bg-gray-50">Excel</button>
    ` : ""}

    <button id="btnCancel"
      class="px-4 py-2 rounded-xl border hover:bg-gray-50">
      Hủy
    </button>

    <button id="btnSubmit" data-primary
      class="px-4 py-2 rounded-xl font-semibold text-white
      ${isIncome ? "bg-emerald-600 hover:bg-emerald-700"
        : "bg-rose-600 hover:bg-rose-700"}">
      ${data.id ? "Cập nhật" : "Tạo mới"}
    </button>
    
    ${!data.id ? `
      <button id="btnSubmitPrint"
        class="px-4 py-2 rounded-xl font-semibold border
        ${isIncome ? "border-emerald-600 text-emerald-700 hover:bg-emerald-50"
          : "border-rose-600 text-rose-700 hover:bg-rose-50"}">
        Tạo và In
      </button>
    ` : ""}


  </div>
`;


    // ✅ Khoản thu: có + quản lý danh mục, có khoa/khóa/lớp, người nhận = me
    if (isIncome) {
      return `
        <div class="space-y-4" id="financeFormRoot">

          <div>

              <div>
                <div class="text-sm font-semibold mb-1">Khoản thu</div>
<div class="flex gap-2">
  <div class="relative flex-1">
    <select id="fItem"
      data-init-val="${escapeHtml(data.item_name || "")}"
      class="w-full h-11 border rounded-xl px-3 py-2 bg-white">
      <option value="">-- Chọn khoản thu --</option>
    </select>
  </div>

  ${canManageItems ? `
    <button id="btnManageItems"
      class="shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-xl border hover:bg-gray-50 font-bold text-xl leading-none"
      title="Quản lý khoản thu">+</button>
  ` : ""}
</div>



          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <div class="text-sm font-semibold mb-1">Số lượng</div>
              <input id="fQty" type="number" min="1"
                class="w-full border rounded-xl px-3 py-2"
                value="${escapeHtml(data.quantity ?? 1)}"
                placeholder="VD: 10">
            </div>

            <div>
              <div class="text-sm font-semibold mb-1">Số tiền</div>
                <input id="fPrice" type="text" inputmode="numeric"
                class="w-full border rounded-xl px-3 py-2"
                value="${escapeHtml(fmtMoney(data.unit_price ?? data.amount ?? ""))}"
                step="1"
                placeholder="VD: 50000">
            </div>

            <div>
              <div class="text-sm font-semibold mb-1">Tổng tiền</div>
              <input id="fTotal" type="text" readonly
                class="w-full border rounded-xl px-3 py-2 bg-gray-50 text-gray-700 font-semibold"
                value="">
            </div>
          </div>

          <div>
            <div class="text-sm font-semibold mb-1">Ngày thu</div>
            <input id="fDate" type="date"
              class="w-full border rounded-xl px-3 py-2"
              value="${escapeHtml(data.trans_date || defaultDate)}">
          </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-sm font-medium">Năm học</label>
                    <select name="school_year_id" id="finance_school_year_id"
                    class="w-full px-3 py-2 border rounded-lg text-sm">
                    ${schoolYearOptions}
                    </select>
                </div>

                <div>
                    <label class="text-sm font-medium">Học kỳ</label>
                    <select name="semester" id="finance_semester"
                      class="w-full px-3 py-2 border rounded-lg text-sm">
                      ${semesterOptions}
                    </select>

                </div>
            </div>


          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <div class="text-sm font-semibold mb-1">Khoa</div>
              <select id="fDept" class="w-full border rounded-xl px-3 py-2">
                ${deptOptions}
              </select>
            </div>

            <div>
              <div class="text-sm font-semibold mb-1">Khóa</div>
              <select id="fCourse" class="w-full border rounded-xl px-3 py-2">
                ${courseOptions}
              </select>
            </div>

            <div>
              <div class="text-sm font-semibold mb-1">Lớp</div>
              <div class="relative">
                <input id="fClass"
                  class="w-full border rounded-xl px-3 py-2"
                  autocomplete="off"
                  placeholder="Chọn/nhập lớp theo khoa + khóa"
                  value="${escapeHtml(data.class_text || "")}">
                <div id="classSug"
                  class="absolute z-[70] w-full bg-white border rounded-xl shadow mt-1 hidden max-h-60 overflow-auto">
                </div>
              </div>
            </div>
          </div>
          <div class="mt-2 flex items-center justify-between gap-2">
            <button type="button" id="btnParticipants"
              class="px-3 py-2 rounded-xl border hover:bg-gray-50 text-sm font-semibold">
              Danh sách tham gia
            </button>

            <div class="text-xs text-gray-500">
              Đã chọn: <span id="participantsCount" class="font-semibold text-gray-800">0</span>
            </div>
          </div>

          <input type="hidden" id="fParticipantIds" value="">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <div class="text-sm font-semibold mb-1">Người nộp</div>
              <div class="relative">
                <input id="fPayer" class="w-full border rounded-xl px-3 py-2"
                  autocomplete="off"
                  value="${escapeHtml(data.payer_name || "")}"
                  placeholder="Gõ tên để chọn trong danh sách đoàn viên...">
                <div id="payerSug"
                  class="absolute z-[90] w-full bg-white border rounded-xl shadow mt-1 hidden max-h-60 overflow-auto"></div>
              </div>
            </div>

            <div>
              <div class="text-sm font-semibold mb-1">Người nhận</div>
              <input id="fReceiverRO" class="w-full border rounded-xl px-3 py-2 bg-gray-50"
                readonly value="${escapeHtml(META.me?.name || "")}">
            </div>
          </div>

          <div>
            <div class="text-sm font-semibold mb-1">Lý do / Diễn giải</div>
            <textarea id="fDesc"
              class="w-full border rounded-xl px-3 py-2 min-h-[110px]"
              placeholder="Nhập diễn giải...">${escapeHtml(data.description || "")}</textarea>
          </div>

          <div>
            <div class="text-sm font-semibold mb-1">Ghi chú</div>
            <input id="fNote" class="w-full border rounded-xl px-3 py-2"
              value="${escapeHtml(data.note || "")}"
              placeholder="VD: bổ sung 5 bạn...">
          </div>

          <div class="flex items-center justify-between pt-2">
            <div class="text-xs text-gray-500">
              Người nhập: <span class="font-semibold text-gray-800">${escapeHtml(META.me?.name || "")}</span>
            </div>
            ${footerBtns}
          </div>
        </div>
      `;
    }

    // ✅ Khoản chi: người được chi + chức vụ, người duyệt chi + chức vụ duyệt
    return `
      <div class="space-y-4" id="financeFormRoot">

        <div>

            <div>
                <div class="text-sm font-semibold mb-1">Khoản chi</div>
<div class="flex gap-2">
  <div class="relative flex-1">
    <input id="fItem"
      class="w-full h-11 border rounded-xl px-3 py-2"
      autocomplete="off"
      placeholder="VD: Mua nước / Văn phòng phẩm..."
      value="${escapeHtml(data.item_name || "")}">
    <div id="itemSug"
      class="absolute z-[80] w-full bg-white border rounded-xl shadow mt-1 hidden max-h-60 overflow-auto">
    </div>
  </div>

  ${canManageItems ? `
    <button id="btnManageItems"
      class="shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-xl border hover:bg-gray-50 font-bold text-xl leading-none"
      title="Quản lý khoản chi">+</button>
  ` : ""}
</div>

                


        <div>
          <div class="text-sm font-semibold mb-1">Số tiền</div>
            <input id="fAmount" type="text" inputmode="numeric"
            class="w-full border rounded-xl px-3 py-2"
            value="${escapeHtml(fmtMoney(data.amount || ""))}"
            step="1"
            placeholder="VD: 50000">
        </div>
<div>
  <div class="text-sm font-semibold mb-1">Nguồn chi</div>
  <div class="relative">
    <input id="fSourceItem"
      class="w-full border rounded-xl px-3 py-2"
      autocomplete="off"
      placeholder="Chọn nguồn thu (VD: Quỹ 1K / Mùa hè xanh...)"
      value="">
    <input type="hidden" id="fSourceItemId" value="${escapeHtml(data.source_item_id || "")}">
    <div id="sourceSug"
      class="absolute z-[85] w-full bg-white border rounded-xl shadow mt-1 hidden max-h-60 overflow-auto">
    </div>
  </div>
</div>

        <div>
          <div class="text-sm font-semibold mb-1">Ngày chi</div>
          <input id="fDate" type="date"
            class="w-full border rounded-xl px-3 py-2"
            value="${escapeHtml(data.trans_date || defaultDate)}">
        </div>

        <div class="grid grid-cols-2 gap-3">
  <div>
    <label class="text-sm font-medium">Năm học</label>
    <select name="school_year_id" id="finance_school_year_id"
    class="w-full px-3 py-2 border rounded-lg text-sm">
    ${schoolYearOptions}
    </select>

  </div>

  <div>
    <label class="text-sm font-medium">Học kỳ</label>
    <select name="semester" id="finance_semester"
      class="w-full px-3 py-2 border rounded-lg text-sm">
      ${semesterOptions}
    </select>
  </div>
</div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <div class="text-sm font-semibold mb-1">Người được chi</div>
            <div class="relative">
              <input id="fPayee" class="w-full border rounded-xl px-3 py-2"
                autocomplete="off"
                value="${escapeHtml(data.payee_name || "")}"
                placeholder="Gõ tên để chọn trong danh sách đoàn viên...">
              <div id="payeeSug"
                class="absolute z-[70] w-full bg-white border rounded-xl shadow mt-1 hidden max-h-60 overflow-auto"></div>
            </div>
          </div>

          <div>
            <div class="text-sm font-semibold mb-1">Chức vụ</div>
            <div class="flex gap-2">
              <select id="fPayeePos" class="flex-1 border rounded-xl px-3 py-2"></select>
              <button id="btnManagePos1"
                class="w-11 h-11 rounded-xl border hover:bg-gray-50 font-bold text-lg"
                title="Quản lý chức vụ">+</button>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <div class="text-sm font-semibold mb-1">Người duyệt chi</div>
            <input id="fApproverRO" class="w-full border rounded-xl px-3 py-2 bg-gray-50"
              readonly value="${escapeHtml(META.me?.name || "")}">
          </div>

          <div>
            <div class="text-sm font-semibold mb-1">Chức vụ </div>
            <div class="flex gap-2">
              <select id="fApprovePos" class="flex-1 border rounded-xl px-3 py-2"></select>
              <button id="btnManagePos2"
                class="w-11 h-11 rounded-xl border hover:bg-gray-50 font-bold text-lg"
                title="Quản lý chức vụ">+</button>
            </div>
          </div>
        </div>

        <div>
          <div class="text-sm font-semibold mb-1">Lý do / Diễn giải</div>
          <textarea id="fDesc"
            class="w-full border rounded-xl px-3 py-2 min-h-[110px]"
            placeholder="Nhập diễn giải...">${escapeHtml(data.description || "")}</textarea>
        </div>

        <div>
          <div class="text-sm font-semibold mb-1">Ghi chú</div>
          <input id="fNote" class="w-full border rounded-xl px-3 py-2"
            value="${escapeHtml(data.note || "")}"
            placeholder="VD: bổ sung 5 bạn...">
        </div>

        <div class="flex items-center justify-between pt-2">
          <div class="text-xs text-gray-500">
            Người nhập: <span class="font-semibold text-gray-800">${escapeHtml(META.me?.name || "")}</span>
          </div>
          ${footerBtns}
        </div>
      </div>
    `;
  }

  /* =========================================================
     ✅ THU TOTAL
  ========================================================= */
  function calcFinanceTotal(root) {
    const qtyEl = root.querySelector("#fQty");
    const priceEl = root.querySelector("#fPrice");
    const totalEl = root.querySelector("#fTotal");
    if (!qtyEl || !priceEl || !totalEl) return;

    const qty = Number(qtyEl.value || 1);
    const price = parseMoney(priceEl.value);
    const total = (qty > 0 ? qty : 1) * (price > 0 ? price : 0);

    totalEl.value = total > 0 ? fmtMoney(total) : "";
  }

  async function openParticipantsModal(root) {
    const deptId = root.querySelector("#fDept")?.value || "";
    const courseId = root.querySelector("#fCourse")?.value || "";
    const classText = root.querySelector("#fClass")?.value.trim() || "";
    if (!classText) return toast("Chọn lớp trước đã");

    const hid = root.querySelector("#fParticipantIds");
    let selected = new Set();
    try {
      const arr = JSON.parse(hid?.value || "[]");
      if (Array.isArray(arr)) arr.forEach(x => selected.add(Number(x)));
    } catch { }

    const html = `
    <div id="partRoot" class="space-y-3">
      <div class="text-sm text-gray-600">
        Lớp: <span class="font-semibold text-gray-900">${escapeHtml(classText)}</span>
      </div>
      <div class="flex gap-2">
        <input id="partQ" class="flex-1 border rounded-xl px-3 py-2"
          placeholder="Tìm theo tên / MSSV...">
        <button id="partAll" class="px-3 py-2 rounded-xl border hover:bg-gray-50 text-sm font-semibold">Chọn tất cả</button>
        <button id="partNone" class="px-3 py-2 rounded-xl border hover:bg-gray-50 text-sm font-semibold">Bỏ chọn</button>
      </div>
      <div class="border rounded-xl overflow-hidden">
        <div class="max-h-[420px] overflow-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0">
              <tr>
                <th class="px-3 py-2 w-[50px] text-center">Chọn</th>
                <th class="px-3 py-2 text-left">Thành viên</th>
                <th class="px-3 py-2 text-left w-[160px]">MSSV</th>
              </tr>
            </thead>
            <tbody id="partTbody"></tbody>
          </table>
        </div>
      </div>
      <div class="flex items-center justify-between pt-2">
        <div class="text-xs text-gray-500">
          Đang chọn: <span id="partCount" class="font-semibold text-gray-800">0</span>
        </div>
        <div class="flex gap-2">
          <button id="partCancel" class="px-4 py-2 rounded-xl border hover:bg-gray-50">Hủy</button>
          <button id="partSave" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700">Lưu</button>
        </div>
      </div>
    </div>
  `;

    modal(html, "Danh sách tham gia", "large");
    const mroot = document.getElementById("partRoot");
    const tbody = mroot.querySelector("#partTbody");
    const qEl = mroot.querySelector("#partQ");
    const cEl = mroot.querySelector("#partCount");

    const data = await api("members_by_class", {
      department_id: deptId,
      course_id: courseId,
      class_text: classText,
    });

    const ALL = (data.rows || []).map(x => ({
      id: Number(x.id || 0),
      name: String(x.name || ""),
      mssv: String(x.mssv || ""),
      norm: normText((x.name || "") + " " + (x.mssv || "")),
    })).filter(x => x.id > 0);

    const render = (list) => {
      tbody.innerHTML = list.map(m => {
        const checked = selected.has(m.id) ? "checked" : "";
        return `
            <tr class="border-t hover:bg-gray-50 cursor-pointer" data-mid="${m.id}">
              <td class="px-3 py-2 text-center">
                <input type="checkbox" data-mid="${m.id}" ${checked}>
              </td>
              <td class="px-3 py-2 font-medium text-gray-900">${escapeHtml(m.name)}</td>
              <td class="px-3 py-2">${escapeHtml(m.mssv)}</td>
            </tr>
          `;
      }).join("");
      cEl.textContent = String(selected.size);
    };

    // ==================== CLICK TOÀN BỘ DÒNG + TÊN ====================
    tbody.addEventListener("click", (e) => {
      const tr = e.target.closest("tr[data-mid]");
      if (!tr) return;

      const mid = Number(tr.dataset.mid);
      const checkbox = tr.querySelector('input[type="checkbox"]');

      if (e.target.type === "checkbox") return; // để checkbox tự xử lý

      // Toggle
      if (selected.has(mid)) {
        selected.delete(mid);
        checkbox.checked = false;
      } else {
        selected.add(mid);
        checkbox.checked = true;
      }
      cEl.textContent = String(selected.size);
    });

    // Vẫn giữ event change cho checkbox (đề phòng click trực tiếp)
    tbody.addEventListener("change", (e) => {
      const cb = e.target.closest('input[type="checkbox"][data-mid]');
      if (!cb) return;
      const id = Number(cb.dataset.mid);
      if (cb.checked) selected.add(id);
      else selected.delete(id);
      cEl.textContent = String(selected.size);
    });

    const applyFilter = () => {
      const q = qEl.value.trim();
      if (!q) return render(ALL);
      const qn = normText(q);
      render(ALL.filter(x => x.norm.includes(qn)));
    };

    render(ALL);

    let t = null;
    qEl.addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(applyFilter, 120);
    });

    mroot.querySelector("#partAll").onclick = () => {
      ALL.forEach(x => selected.add(x.id));
      applyFilter();
    };
    mroot.querySelector("#partNone").onclick = () => {
      selected = new Set();
      applyFilter();
    };
    mroot.querySelector("#partCancel").onclick = () => closeModal();
    mroot.querySelector("#partSave").onclick = () => {
      const arr = Array.from(selected.values());
      if (hid) hid.value = JSON.stringify(arr);

      // auto set số lượng
      const fQty = root.querySelector("#fQty");
      if (fQty) fQty.value = String(arr.length || 1);
      calcFinanceTotal(root);

      const outCount = root.querySelector("#participantsCount");
      if (outCount) outCount.textContent = String(arr.length);

      closeModal();
    };
  }

  /* =========================================================
     ✅ OPEN CREATE
  ========================================================= */
  async function openCreate(type) {
    const html = buildFormHTML(type, {});
    modal(html, type === "income" ? "Tạo khoản thu" : "Tạo khoản chi", "large");

    const root = document.getElementById("financeFormRoot");
    if (!root) return;
    // set default năm học/học kỳ
    const sySel = root.querySelector("#finance_school_year_id");
    const semSel = root.querySelector("#finance_semester");
    if (sySel) sySel.value = "";
    if (semSel) semSel.value = "";

    // load datalist items + bind manage btn
    const itemAuto = setupItemAutocomplete(root, type); // hoặc row.type

    const btnManageItems = root.querySelector("#btnManageItems");
    if (btnManageItems) {
      btnManageItems.onclick = async () => {
        await openItemsManager(type, () => itemAuto.reload());
      };
    }



    if (type === "income") {
      // calc total
      calcFinanceTotal(root);
      const fQty = root.querySelector("#fQty");
      const fPrice = root.querySelector("#fPrice");
      bindMoneyMask(fPrice);

      if (fQty) fQty.addEventListener("input", () => calcFinanceTotal(root));
      if (fPrice) fPrice.addEventListener("input", () => calcFinanceTotal(root));

      // dept/course/class
      const fDept = root.querySelector("#fDept");
      const fCourse = root.querySelector("#fCourse");
      if (!fDept || !fCourse) {
        toast("Form lỗi: thiếu Khoa/Khóa");
        return;
      }
      const btnPart = root.querySelector("#btnParticipants");
      const hid = root.querySelector("#fParticipantIds");
      const outCount = root.querySelector("#participantsCount");
      if (hid && !hid.value) hid.value = "[]";
      if (outCount) {
        try { outCount.textContent = String(JSON.parse(hid.value || "[]").length || 0); } catch { }
      }
      if (btnPart) btnPart.onclick = () => openParticipantsModal(root).catch(e => toast(e.message));

      setupClassAutocomplete(root, fDept, fCourse);

      // payer autocomplete
      setupMemberAutocomplete(root, "fPayer", "payerSug");
    } else {
      // expense: positions + payee autocomplete
      await loadPositionsSelect(root, "fPayeePos");
      await loadPositionsSelect(root, "fApprovePos");
      // ✅ source item autocomplete (nguồn chi)
      const srcAuto = setupSourceItemAutocomplete(root, null);

      const fAmount = root.querySelector("#fAmount");
      bindMoneyMask(fAmount);
      // set default selected from data (none)
      const payeePosSel = root.querySelector("#fPayeePos");
      const approvePosSel = root.querySelector("#fApprovePos");
      if (payeePosSel) payeePosSel.value = "";
      if (approvePosSel) approvePosSel.value = "";

      const btnPos1 = root.querySelector("#btnManagePos1");
      const btnPos2 = root.querySelector("#btnManagePos2");

      const reloadPos = async () => {
        await loadPositionsSelect(root, "fPayeePos");
        await loadPositionsSelect(root, "fApprovePos");
      };

      const openPosAndReload = () => openPositionsManager(reloadPos);

      if (btnPos1) btnPos1.onclick = openPosAndReload;
      if (btnPos2) btnPos2.onclick = openPosAndReload;


      setupMemberAutocomplete(root, "fPayee", "payeeSug");
    }

    root.querySelector("#btnCancel").onclick = () => closeModal();

    const btnSubmit = root.querySelector("#btnSubmit");
    const btnSubmitPrint = root.querySelector("#btnSubmitPrint");

    async function submit(andPrint = false) {
      try {
        let created = null;

        if (type === "income") {
          const qty = Number(root.querySelector("#fQty")?.value || 1);
          const unitPrice = parseMoney(root.querySelector("#fPrice")?.value);
          const total = (qty > 0 ? qty : 1) * (unitPrice > 0 ? unitPrice : 0);


          const payload = {
            type,
            item_name: root.querySelector("#fItem")?.value.trim() || "",

            quantity: qty,
            unit_price: unitPrice,
            amount: total,
            trans_date: root.querySelector("#fDate")?.value || "",
            school_year_id: root.querySelector("#finance_school_year_id")?.value || "",
            semester: root.querySelector("#finance_semester")?.value || "",
            department_id: root.querySelector("#fDept")?.value || "",
            course_id: root.querySelector("#fCourse")?.value || "",
            class_text: root.querySelector("#fClass")?.value.trim() || "",
            payer_name: root.querySelector("#fPayer")?.value.trim() || "",
            participant_ids: getParticipantIds(root),

            description: root.querySelector("#fDesc")?.value.trim() || "",
            note: root.querySelector("#fNote")?.value.trim() || "",
          };

          created = await api("create", payload);
          toast("Đã tạo khoản thu");
        } else {
          const sid = Number(root.querySelector("#fSourceItemId")?.value || 0);
          const total = parseMoney(root.querySelector("#fAmount")?.value);

          const payload = {
            type,
            item_name: root.querySelector("#fItem")?.value.trim() || "",
            source_item_id: sid > 0 ? sid : null, // ✅ ADD

            quantity: 1,
            unit_price: total,
            amount: total,
            trans_date: root.querySelector("#fDate")?.value || "",
            school_year_id: root.querySelector("#finance_school_year_id")?.value || "",
            semester: root.querySelector("#finance_semester")?.value || "",

            payee_name: root.querySelector("#fPayee")?.value.trim() || "",
            class_text: root.querySelector("#fPayeePos")?.value || "",
            payer_name: META.me?.name || "",
            receiver_name: root.querySelector("#fApprovePos")?.value || "",

            description: root.querySelector("#fDesc")?.value.trim() || "",
            note: root.querySelector("#fNote")?.value.trim() || "",
          };

          created = await api("create", payload);
          toast("Đã tạo khoản chi");
        }

        closeModal();
        await loadList();

        if (andPrint) {
          const newId = Number(created?.id || created?.insert_id || created?.row?.id || 0);
          if (newId) {
            setTimeout(() => doPrintNow(newId), 120);
          } else {
            toast("Tạo xong nhưng không lấy được ID để in (cần server trả về id)");
          }
        }
      } catch (e) {
        toast(e.message);
      }
    }

    if (btnSubmit) btnSubmit.onclick = () => submit(false);
    if (btnSubmitPrint) btnSubmitPrint.onclick = () => submit(true);


    // focus item
    const fItem = root.querySelector("#fItem");
    setTimeout(() => fItem && fItem.focus(), 10);
  }

  /* =========================================================
     ✅ OPEN EDIT
  ========================================================= */
  async function openEdit(row) {
    const html = buildFormHTML(row.type, row);
    modal(html, "Cập nhật khoản", "large");

    const root = document.getElementById("financeFormRoot");
    if (!root) return;
    // restore năm học/học kỳ khi edit
    const sySel = root.querySelector("#finance_school_year_id");
    const semSel = root.querySelector("#finance_semester");
    if (sySel) sySel.value = String(row.school_year_id || "");
    if (semSel) semSel.value = row.semester || "";

    const itemAuto = setupItemAutocomplete(root, row.type);

    const btnManageItems = root.querySelector("#btnManageItems");
    if (btnManageItems) {
      btnManageItems.onclick = async () => {
        await openItemsManager(row.type, () => itemAuto.reload());
      };
    }




    // print button
    const btnPDF = root.querySelector("#btnExportPDF");
    const btnXLSX = root.querySelector("#btnExportXLSX");

    if (btnPDF) {
      btnPDF.onclick = () => {
        window.open(
          API + "?action=voucher_export&id=" + encodeURIComponent(row.id) + "&format=pdf&inline=1",
          "_blank"
        );
      };
    }

    if (btnXLSX) {
      btnXLSX.onclick = () => {
        window.open(API + "?action=voucher_export&id=" + encodeURIComponent(row.id) + "&format=xlsx", "_blank");
      };
    }


    if (row.type === "income") {
      // calc total
      calcFinanceTotal(root);
      const fQty = root.querySelector("#fQty");
      const fPrice = root.querySelector("#fPrice");
      bindMoneyMask(fPrice);

      if (fQty) fQty.addEventListener("input", () => calcFinanceTotal(root));
      if (fPrice) fPrice.addEventListener("input", () => calcFinanceTotal(root));

      // set dept/course
      const fDept = root.querySelector("#fDept");
      const fCourse = root.querySelector("#fCourse");
      if (fDept) fDept.value = String(row.department_id || "");
      if (fCourse) fCourse.value = String(row.course_id || "");

      setupClassAutocomplete(root, fDept, fCourse);
      const fClassInput = root.querySelector("#fClass");
      if (fClassInput) fClassInput.value = row.class_text || "";

      // ✅ participants init + bind (EDIT)
      const hid = root.querySelector("#fParticipantIds");
      if (hid && !hid.value) {
        // nếu server có trả participant_ids thì set vào đây (nếu chưa có thì để [])
        const ids = row.participant_ids || row.participants || [];
        hid.value = JSON.stringify(Array.isArray(ids) ? ids : []);
      }
      updateParticipantsCount(root);

      const btnPart = root.querySelector("#btnParticipants");
      if (btnPart) btnPart.onclick = () => openParticipantsModal(root).catch((e) => toast(e.message));

      // payer autocomplete
      setupMemberAutocomplete(root, "fPayer", "payerSug");

    } else {
      const fAmount = root.querySelector("#fAmount");
      bindMoneyMask(fAmount);
      // expense: load positions & set value
      await loadPositionsSelect(root, "fPayeePos");
      await loadPositionsSelect(root, "fApprovePos");
      // ✅ source item autocomplete (nguồn chi) + giữ lại value khi edit
      setupSourceItemAutocomplete(root, row.source_item_id || null);


      const payeePosSel = root.querySelector("#fPayeePos");
      const approvePosSel = root.querySelector("#fApprovePos");

      if (payeePosSel) payeePosSel.value = row.class_text || "";
      if (approvePosSel) approvePosSel.value = row.receiver_name || "";

      const btnPos1 = root.querySelector("#btnManagePos1");
      const btnPos2 = root.querySelector("#btnManagePos2");

      const reloadPosKeep = async () => {
        const keep1 = payeePosSel?.value || "";
        const keep2 = approvePosSel?.value || "";

        await loadPositionsSelect(root, "fPayeePos");
        await loadPositionsSelect(root, "fApprovePos");

        if (payeePosSel) payeePosSel.value = keep1;
        if (approvePosSel) approvePosSel.value = keep2;
      };

      const openPosAndReload = () => openPositionsManager(reloadPosKeep);

      if (btnPos1) btnPos1.onclick = openPosAndReload;
      if (btnPos2) btnPos2.onclick = openPosAndReload;



      setupMemberAutocomplete(root, "fPayee", "payeeSug");
    }

    root.querySelector("#btnCancel").onclick = () => closeModal();

    root.querySelector("#btnSubmit").onclick = async () => {
      try {
        if (row.type === "income") {
          const qty = Number(root.querySelector("#fQty")?.value || 1);
          const unitPrice = parseMoney(root.querySelector("#fPrice")?.value);
          const total = (qty > 0 ? qty : 1) * (unitPrice > 0 ? unitPrice : 0);

          const payload = {
            id: row.id,
            item_name: root.querySelector("#fItem")?.value.trim() || "",

            quantity: qty,
            unit_price: unitPrice,
            amount: total,
            trans_date: root.querySelector("#fDate")?.value || "",
            school_year_id: root.querySelector("#finance_school_year_id")?.value || "",
            semester: root.querySelector("#finance_semester")?.value || "",
            department_id: root.querySelector("#fDept")?.value || "",
            course_id: root.querySelector("#fCourse")?.value || "",
            class_text: root.querySelector("#fClass")?.value.trim() || "",
            payer_name: root.querySelector("#fPayer")?.value.trim() || "",
            participant_ids: getParticipantIds(root),

            description: root.querySelector("#fDesc")?.value.trim() || "",
            note: root.querySelector("#fNote")?.value.trim() || "",
          };

          await api("update", payload);
          toast("Đã cập nhật khoản thu");
        } else {
          const sid = Number(root.querySelector("#fSourceItemId")?.value || 0);
          const total = parseMoney(root.querySelector("#fAmount")?.value);

          const payload = {
            id: row.id,
            item_name: root.querySelector("#fItem")?.value.trim() || "",
            source_item_id: sid > 0 ? sid : null, // ✅ ADD

            quantity: 1,
            unit_price: total,
            amount: total,
            trans_date: root.querySelector("#fDate")?.value || "",
            school_year_id: root.querySelector("#finance_school_year_id")?.value || "",
            semester: root.querySelector("#finance_semester")?.value || "",
            payee_name: root.querySelector("#fPayee")?.value.trim() || "",
            class_text: root.querySelector("#fPayeePos")?.value || "",
            payer_name: META.me?.name || "",
            receiver_name: root.querySelector("#fApprovePos")?.value || "",

            description: root.querySelector("#fDesc")?.value.trim() || "",
            note: root.querySelector("#fNote")?.value.trim() || "",
          };

          await api("update", payload);
          toast("Đã cập nhật khoản chi");
        }

        closeModal();
        await loadList();
      } catch (e) {
        toast(e.message);
      }
    };
  }
  function confirmModal({
    title = "Xác nhận",
    message = "Bạn có chắc chắn?",
    okText = "Xóa",
    cancelText = "Hủy",
    danger = true,
  } = {}) {
    return new Promise((resolve) => {
      const html = `
      <div id="confirmModalRoot" class="space-y-4">
        <div class="text-sm text-gray-700 leading-relaxed">
          ${escapeHtml(message)}
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button id="btnConfirmCancel"
            class="px-4 py-2 rounded-xl border hover:bg-gray-50">
            ${escapeHtml(cancelText)}
          </button>

          <button id="btnConfirmOk"
            class="px-4 py-2 rounded-xl font-semibold text-white ${danger ? "bg-rose-600 hover:bg-rose-700" : "bg-blue-600 hover:bg-blue-700"
        }">
            ${escapeHtml(okText)}
          </button>
        </div>
      </div>
    `;

      modal(html, title, "small");

      const root = document.getElementById("confirmModalRoot");
      if (!root) return resolve(false);

      const btnCancel = root.querySelector("#btnConfirmCancel");
      const btnOk = root.querySelector("#btnConfirmOk");

      const done = (val) => {
        try { closeModal(); } catch { }
        resolve(val);
      };

      btnCancel.onclick = () => done(false);
      btnOk.onclick = () => done(true);

      // click outside / esc nếu modal() của bạn có cơ chế close,
      // thì default coi như cancel (không bắt được sự kiện thì thôi).
    });
  }

  /* =========================================================
     ✅ DELETE / PRINT
  ========================================================= */
  async function doDelete(id) {
    const ok = await confirmModal({
      title: "Xóa khoản thu/chi",
      message: "Bạn có chắc chắn muốn xóa khoản này? Thao tác này không thể hoàn tác.",
      okText: "Xóa",
      cancelText: "Hủy",
      danger: true,
    });

    if (!ok) return;

    try {
      await api("delete", { id });
      toast("Đã xóa");
      await loadList();
    } catch (e) {
      toast(e.message);
    }
  }


  async function doPrint(id) {
    // Modal chọn PDF / XLSX cho lịch sự
    const html = `
    <div class="space-y-3">
      <div class="text-sm text-gray-600">Chọn định dạng xuất phiếu:</div>
      <div class="flex gap-2 justify-end pt-2">
        <button id="btnExPDF" class="px-4 py-2 rounded-xl border hover:bg-gray-50">
          PDF
        </button>
        <button id="btnExXLSX" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold hover:bg-blue-700">
          XLSX
        </button>
      </div>
    </div>
  `;
    modal(html, "Xuất Phiếu", "small");

    const uPDF = API + "?action=voucher_export&id=" + id + "&format=pdf&download=1";
    const uXLSX = API + "?action=voucher_export&id=" + encodeURIComponent(id) + "&format=xlsx";

    document.getElementById("btnExPDF").onclick = () => {
      window.open(uPDF, "_blank");
      closeModal();
    };
    document.getElementById("btnExXLSX").onclick = () => {
      window.open(uXLSX, "_blank");
      closeModal();
    };
  }

  function doDownload(id) {
    // ✅ tải về = hiện modal chọn PDF / XLSX
    return doPrint(id);
  }

  function doPrintNow(id) {
    const uPDF =
      API +
      "?action=voucher_export&id=" +
      encodeURIComponent(id) +
      "&format=pdf&inline=1";

    const iframe = document.createElement("iframe");
    iframe.style.position = "fixed";
    iframe.style.width = "1px";
    iframe.style.height = "1px";
    iframe.style.opacity = "0";
    iframe.style.pointerEvents = "none";
    iframe.style.border = "0";
    iframe.src = uPDF;

    document.body.appendChild(iframe);

    let cleaned = false;
    const cleanup = () => {
      if (cleaned) return;
      cleaned = true;
      try {
        iframe.remove();
      } catch { }
    };

    iframe.onload = () => {
      const w = iframe.contentWindow;

      // Nếu browser chặn in PDF trong iframe => fallback mở tab
      if (!w) {
        window.open(uPDF, "_blank");
        cleanup();
        return;
      }

      // ✅ chỉ xóa iframe SAU khi user bấm in / đóng dialog
      try {
        w.addEventListener("afterprint", cleanup);
      } catch { }

      try {
        w.focus();
      } catch { }

      // ✅ delay nhẹ cho PDF render ổn định
      setTimeout(() => {
        try {
          w.print();
        } catch (e) {
          window.open(uPDF, "_blank");
          cleanup();
        }
      }, 350);

      // ✅ fallback dọn rác (nếu afterprint không fire)
      setTimeout(cleanup, 60000);
    };
  }




  /* =========================================================
     ✅ TABLE ACTIONS
  ========================================================= */
  function bindTableActions() {
    els.tbody.addEventListener("click", async (e) => {
      const btn = e.target.closest("button[data-act]");
      if (!btn) return;

      const act = btn.dataset.act;
      const id = Number(btn.dataset.id || 0);
      if (!id) return;

      if (act === "unpaid_stat") {
        const row = FINANCE_CACHE.find((x) => Number(x.id) === id);
        if (row) openUnpaidClassesModal(row);
        return;
      }

      if (act === "del") return doDelete(id);

      // ✅ support kiểu cũ (nếu còn)
      if (act === "print") return doPrint(id);

      // ✅ kiểu mới Toro vừa gắn icon
      if (act === "download") return doDownload(id);
      if (act === "print_now") return doPrintNow(id);


      if (act === "edit") {
        try {
          // load lại list để lấy row hiện tại
          const data = await api("list", {
            page: STATE.page,
            page_size: STATE.page_size,
            type: STATE.type,
            department_id: STATE.department_id,
            class_text: STATE.class_text,
            from: STATE.from,
            to: STATE.to,
            q: STATE.q,
          });

          const row = (data.rows || []).find((x) => Number(x.id) === id);
          if (!row) return toast("Không tìm thấy dữ liệu dòng này");
          await openEdit(row);
        } catch (err) {
          toast(err.message);
        }
      }
    });
  }

  function bindMobileActions() {
    if (!els.mobileList) return;

    els.mobileList.addEventListener("click", async (e) => {
      const btn = e.target.closest("button[data-act]");
      if (!btn) return;

      const act = btn.dataset.act;
      const id = Number(btn.dataset.id || 0);
      if (!id) return;

      if (act === "unpaid_stat") {
        const row = FINANCE_CACHE.find((x) => Number(x.id) === id);
        if (row) openUnpaidClassesModal(row);
        return;
      }

      if (act === "del") return doDelete(id);

      // ✅ support kiểu cũ (nếu còn)
      if (act === "print") return doPrint(id);

      // ✅ kiểu mới Toro vừa gắn icon
      if (act === "download") return doDownload(id);
      if (act === "print_now") return doPrintNow(id);


      if (act === "edit") {
        try {
          const data = await api("list", {
            page: STATE.page,
            page_size: STATE.page_size,
            type: STATE.type,
            department_id: STATE.department_id,
            class_text: STATE.class_text,
            from: STATE.from,
            to: STATE.to,
            q: STATE.q,
          });

          const row = (data.rows || []).find((x) => Number(x.id) === id);
          if (!row) return toast("Không tìm thấy dữ liệu dòng này");
          await openEdit(row);
        } catch (err) {
          toast(err.message);
        }
      }
    });
  }

  function bindCreateButtons() {
    els.btnIncome.onclick = () => openCreate("income").catch((e) => toast(e.message));
    els.btnExpense.onclick = () => openCreate("expense").catch((e) => toast(e.message));
  }

  /* =========================================================
     ✅ THEO DÕI ĐÓNG TIỀN THEO LỚP (GIAO DIỆN POPUP MỚI)
  ========================================================= */
  async function openUnpaidClassesModal(row) {
    const itemName = row.item_name || "";
    const schoolYearId = row.school_year_id || "";
    const semester = row.semester || "";
    const semLabel = row.semester_label || row.semester || "--";
    const yearLabel = row.school_year_label || row.year_label || "--";

    const html = `
      <div id="unpaidModalRoot" class="space-y-4">
        <div class="bg-emerald-50 rounded-xl p-3 border border-emerald-100 flex items-center justify-between gap-4">
          <div>
            <div class="text-xs text-gray-500 font-semibold uppercase">Đang đối chiếu khoản thu</div>
            <div class="font-bold text-gray-900 text-base leading-snug">${escapeHtml(itemName)}</div>
            <div class="text-xs text-gray-600 mt-0.5 font-medium">${escapeHtml(semLabel)} • ${escapeHtml(yearLabel)}</div>
          </div>
          <div class="flex items-center gap-2">
            <button id="btnModalUnpaidExport" class="px-3 py-1.5 border rounded-lg bg-white hover:bg-gray-50 text-xs font-semibold flex items-center gap-1.5 transition-colors">
              <svg class="w-3.5 h-3.5 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 3v12" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M8 11l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M5 21h14" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Xuất lớp chưa đóng
            </button>
            <button id="btnModalPaidExport" class="px-3 py-1.5 border rounded-lg bg-white hover:bg-gray-50 text-xs font-semibold flex items-center gap-1.5 transition-colors">
              <svg class="w-3.5 h-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 3v12" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M8 11l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M5 21h14" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Xuất lớp đã đóng
            </button>
          </div>
        </div>

        <!-- Filters trong Modal -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Khoa / Phòng</label>
            <select id="mUnpaidFilterDept" class="border rounded-xl px-3 py-1.5 text-sm w-full">
              <option value="">Tất cả</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Khóa</label>
            <select id="mUnpaidFilterCourse" class="border rounded-xl px-3 py-1.5 text-sm w-full">
              <option value="">Tất cả</option>
            </select>
          </div>
        </div>

        <!-- Sub Tabs trong Modal -->
        <div class="flex border-b border-gray-200">
          <button id="mSubTabUnpaid" class="px-4 py-2 text-sm font-semibold border-b-2 border-emerald-600 text-emerald-600 focus:outline-none transition-colors flex items-center gap-2">
            Chưa đóng tiền <span id="mBadgeUnpaid" class="bg-rose-100 text-rose-700 text-xs px-2 py-0.5 rounded-full">0</span>
          </button>
          <button id="mSubTabPaid" class="px-4 py-2 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 focus:outline-none transition-colors flex items-center gap-2">
            Đã đóng tiền <span id="mBadgePaid" class="bg-emerald-100 text-emerald-700 text-xs px-2 py-0.5 rounded-full">0</span>
          </button>
        </div>

        <!-- Bảng Kết quả trong Modal -->
        <div class="border rounded-xl overflow-hidden max-h-[350px] overflow-y-auto">
          <!-- Bảng chưa đóng -->
          <div id="mUnpaidTableWrap">
            <table class="w-full text-sm">
              <thead class="bg-gray-50 border-b">
                <tr class="text-left text-xs font-semibold text-gray-600 uppercase">
                  <th class="px-4 py-2 w-[60px] text-center">STT</th>
                  <th class="px-4 py-2">Tên lớp</th>
                  <th class="px-4 py-2">Khoa / Phòng</th>
                  <th class="px-4 py-2">Khóa</th>
                </tr>
              </thead>
              <tbody id="mUnpaidTbody" class="divide-y">
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">Đang tải đối chiếu dữ liệu...</td></tr>
              </tbody>
            </table>
          </div>

          <!-- Bảng đã đóng -->
          <div id="mPaidTableWrap" class="hidden">
            <table class="w-full text-sm">
              <thead class="bg-gray-50 border-b">
                <tr class="text-left text-xs font-semibold text-gray-600 uppercase">
                  <th class="px-4 py-2 w-[60px] text-center">STT</th>
                  <th class="px-4 py-2">Tên lớp</th>
                  <th class="px-4 py-2">Người nộp</th>
                  <th class="px-4 py-2 text-right">Số tiền</th>
                  <th class="px-4 py-2 text-center">Số phiếu</th>
                  <th class="px-4 py-2 text-center">Ngày nộp</th>
                </tr>
              </thead>
              <tbody id="mPaidTbody" class="divide-y">
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Đang tải đối chiếu dữ liệu...</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="flex justify-end pt-2">
          <button type="button" class="px-4 py-1.5 border rounded-lg hover:bg-gray-50 text-sm font-semibold" onclick="closeModal()">
            Đóng
          </button>
        </div>
      </div>
    `;

    modal(html, "Thống kê đóng tiền theo lớp", "large");

    const root = document.getElementById("unpaidModalRoot");
    if (!root) return;

    // Load các filter khoa/khóa vào modal
    const selDept = root.querySelector("#mUnpaidFilterDept");
    const selCourse = root.querySelector("#mUnpaidFilterCourse");
    if (selDept) selDept.innerHTML = renderDeptOptionsGrouped(META.departments || [], "filter");
    if (selCourse) selCourse.innerHTML = renderCourseOptions(META.courses || [], "filter");

    let modalData = { unpaid: [], paid: [] };

    const loadData = async () => {
      try {
        const data = await api("unpaid_classes", {
          item_name: itemName,
          school_year_id: schoolYearId,
          semester: semester,
          department_id: selDept ? selDept.value : "",
          course_id: selCourse ? selCourse.value : ""
        });

        modalData.unpaid = data.unpaid || [];
        modalData.paid = data.paid || [];

        // Cập nhật badges
        root.querySelector("#mBadgeUnpaid").textContent = String(modalData.unpaid.length);
        root.querySelector("#mBadgePaid").textContent = String(modalData.paid.length);

        // Render bảng chưa đóng
        const unpaidTbody = root.querySelector("#mUnpaidTbody");
        if (unpaidTbody) {
          unpaidTbody.innerHTML = modalData.unpaid.length === 0
            ? `<tr><td colspan="4" class="px-4 py-6 text-center text-emerald-600 font-semibold">Tất cả các lớp đã hoàn thành đóng tiền!</td></tr>`
            : modalData.unpaid.map((r, i) => `
                <tr class="hover:bg-gray-50">
                  <td class="px-4 py-2 text-center text-gray-500">${i + 1}</td>
                  <td class="px-4 py-2 font-semibold text-gray-900">${escapeHtml(r.class_name)}</td>
                  <td class="px-4 py-2">${escapeHtml(r.department_name || "--")}</td>
                  <td class="px-4 py-2 font-mono text-xs text-gray-500">${escapeHtml(r.course_name || "--")}</td>
                </tr>
              `).join("");
        }

        // Render bảng đã đóng
        const paidTbody = root.querySelector("#mPaidTbody");
        if (paidTbody) {
          paidTbody.innerHTML = modalData.paid.length === 0
            ? `<tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Chưa có lớp nào đóng tiền.</td></tr>`
            : modalData.paid.map((r, i) => `
                <tr class="hover:bg-gray-50">
                  <td class="px-4 py-2 text-center text-gray-500">${i + 1}</td>
                  <td class="px-4 py-2 font-semibold text-gray-900">${escapeHtml(r.class_name)}</td>
                  <td class="px-4 py-2">${escapeHtml(r.payer_name || "--")}</td>
                  <td class="px-4 py-2 text-right font-bold text-emerald-700">${fmtMoney(r.amount)}</td>
                  <td class="px-4 py-2 text-center font-mono text-xs text-blue-600">${escapeHtml(r.voucher_code || "--")}</td>
                  <td class="px-4 py-2 text-center text-gray-600">${fmtDate(r.trans_date)}</td>
                </tr>
              `).join("");
        }

      } catch (e) {
        toast(e.message);
      }
    };

    // Đăng ký sự kiện đổi bộ lọc
    if (selDept) selDept.addEventListener("change", loadData);
    if (selCourse) selCourse.addEventListener("change", loadData);

    // Đăng ký sự kiện sub tabs
    const mSubTabUnpaid = root.querySelector("#mSubTabUnpaid");
    const mSubTabPaid = root.querySelector("#mSubTabPaid");
    const mUnpaidTable = root.querySelector("#mUnpaidTableWrap");
    const mPaidTable = root.querySelector("#mPaidTableWrap");

    mSubTabUnpaid.onclick = () => {
      mSubTabUnpaid.className = "px-4 py-2 text-sm font-semibold border-b-2 border-emerald-600 text-emerald-600 focus:outline-none transition-colors flex items-center gap-2";
      mSubTabPaid.className = "px-4 py-2 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 focus:outline-none transition-colors flex items-center gap-2";
      mUnpaidTable.classList.remove("hidden");
      mPaidTable.classList.add("hidden");
    };

    mSubTabPaid.onclick = () => {
      mSubTabPaid.className = "px-4 py-2 text-sm font-semibold border-b-2 border-emerald-600 text-emerald-600 focus:outline-none transition-colors flex items-center gap-2";
      mSubTabUnpaid.className = "px-4 py-2 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 focus:outline-none transition-colors flex items-center gap-2";
      mPaidTable.classList.remove("hidden");
      mUnpaidTable.classList.add("hidden");
    };

    // Sự kiện xuất Excel lớp chưa đóng
    root.querySelector("#btnModalUnpaidExport").onclick = () => {
      const params = new URLSearchParams({
        action: "export_unpaid_classes",
        item_name: itemName,
        school_year_id: schoolYearId,
        semester: semester,
        department_id: selDept ? selDept.value : "",
        course_id: selCourse ? selCourse.value : ""
      });

      const url = API + "?" + params.toString();
      window.open(url, "_blank");
    };

    // Sự kiện xuất Excel lớp đã đóng
    root.querySelector("#btnModalPaidExport").onclick = () => {
      const params = new URLSearchParams({
        action: "export_paid_classes",
        item_name: itemName,
        school_year_id: schoolYearId,
        semester: semester,
        department_id: selDept ? selDept.value : "",
        course_id: selCourse ? selCourse.value : ""
      });

      const url = API + "?" + params.toString();
      window.open(url, "_blank");
    };

    // Load data lần đầu tiên
    await loadData();
  }

  async function boot() {
    try {
      await loadMeta();
      bindVoucherSettingsButton();
      bindFilters();
      setupFilterClassAutocomplete(); // ✅ thêm dòng này

      bindPaging();
      bindCreateButtons();
      bindTableActions();
      bindMobileActions(); // ✅ thêm dòng này

      await loadList();
    } catch (e) {
      toast(e.message);
    }
  }

  boot();
})();
