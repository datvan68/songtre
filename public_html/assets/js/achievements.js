(() => {
  const cfg = window.ACHV || {};
  const ctrl = cfg.ctrl || "/controllers/achievements.php";
  const caps = cfg.caps || {};

  // ===== DOM: tabs + panels =====
  const tabList = document.getElementById("tabList");
  const tabReview = document.getElementById("tabReview");
  const panelList = document.getElementById("panelList");
  const panelReview = document.getElementById("panelReview");

  // stats
  const statTotal = document.getElementById("statTotal");
  const statPending = document.getElementById("statPending");
  const statApproved = document.getElementById("statApproved");
  const statRejected = document.getElementById("statRejected");
  const tabPendingBadge = document.getElementById("tabPendingBadge");

  // list filters
  const fKeyword = document.getElementById("fKeyword");
  const fRecipientType = document.getElementById("fRecipientType");
  const fSchoolYear = document.getElementById("fSchoolYear");
  const fAwardLevel = document.getElementById("fAwardLevel");
  const fVisibility = document.getElementById("fVisibility");
  const btnReset = document.getElementById("btnReset");

  // review filters
  const rKeyword = document.getElementById("rKeyword");
  const rStatus = document.getElementById("rStatus");
  const btnReviewReset = document.getElementById("btnReviewReset");

  // tables
  const tbody = document.getElementById("tbodyAchievements");
  const pagination = document.getElementById("pagination");

  const tbodyReview = document.getElementById("tbodyReview");
  const paginationReview = document.getElementById("paginationReview");

  // buttons
  const btnAdd = document.getElementById("btnAddAchievement");
  const btnExportPdf = document.getElementById("btnExportPdf");
  const btnExportXlsx = document.getElementById("btnExportXlsx");

  // ===== State =====
  const stateList = { page: 1, per_page: 10, total_pages: 1 };
  const stateReview = { page: 1, per_page: 10, total_pages: 1 };

  // ===== Helpers =====
  function debounce(fn, wait = 300) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  }

  function esc(s) {
    return String(s ?? "").replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    }[m]));
  }
  function setFormBusy(form, busy, text = "Đang lưu...") {
    if (!form) return;

    // overlay
    let ov = form.querySelector(".achOverlay");
    if (!ov) {
      form.classList.add("relative");
      ov = document.createElement("div");
      ov.className =
        "achOverlay absolute inset-0 bg-white/60 backdrop-blur-[1px] rounded-2xl " +
        "flex items-center justify-center z-50";
      ov.innerHTML = `
      <div class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border bg-white shadow-sm text-sm text-slate-700">
        <i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>
        <span class="achOverlayText">${esc(text)}</span>
      </div>
    `;
      form.appendChild(ov);
    }
    ov.querySelector(".achOverlayText").textContent = text;

    const submitBtn = form.querySelector('button[type="submit"]');

    if (busy) {
      if (form.dataset.busy === "1") return;
      form.dataset.busy = "1";

      // disable toàn bộ input/select/textarea/button
      form.querySelectorAll("input,select,textarea,button").forEach((el) => {
        el.dataset.wasDisabled = el.disabled ? "1" : "0";
        el.disabled = true;
      });

      // giữ lại nút submit text cũ + set text đang lưu
      if (submitBtn) {
        submitBtn.dataset.oldHtml = submitBtn.innerHTML;
        submitBtn.innerHTML = `
        <span class="inline-flex items-center gap-2">
          <i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>
          ${esc(text)}
        </span>
      `;
        submitBtn.disabled = true;
      }

      ov.classList.remove("hidden");
      ensureLucide();
      return;
    }

    // busy = false (khôi phục)
    form.dataset.busy = "0";
    if (ov) ov.classList.add("hidden");

    form.querySelectorAll("input,select,textarea,button").forEach((el) => {
      const was = el.dataset.wasDisabled === "1";
      el.disabled = was;
      delete el.dataset.wasDisabled;
    });

    if (submitBtn && submitBtn.dataset.oldHtml) {
      submitBtn.innerHTML = submitBtn.dataset.oldHtml;
      delete submitBtn.dataset.oldHtml;
    }
  }

  function ensureLucide() {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons();
    }
  }

  // ✅ Dùng toast chung của bạn
  function notify(message, type = "info", duration = 2500, opts = {}) {
    if (typeof window.toast === "function") window.toast(message, type, duration, opts);
    else alert(message);
  }
  function getTabFromUrl() {
    const u = new URL(window.location.href);
    const tab = (u.searchParams.get("tab") || "").toLowerCase();

    if (tab === "review" && caps.can_review === 1) return "review";
    return "list";
  }

  function setTabToUrl(which, { replace = false } = {}) {
    const u = new URL(window.location.href);
    u.searchParams.set("tab", which);

    // giữ nguyên hash nếu bạn dùng nơi khác
    const nextUrl = u.toString();

    const st = { tab: which };
    if (replace) history.replaceState(st, "", nextUrl);
    else history.pushState(st, "", nextUrl);
  }

  function badge(text, cls) {
    return `<span class="px-2 py-1 rounded-full text-xs ${cls}">${esc(text)}</span>`;
  }

  function iconBtn({ actAttr = "data-act", act, id, title, icon, cls = "" }) {
    return `
      <button
        type="button"
        ${actAttr}="${esc(act)}"
        data-id="${esc(id)}"
        title="${esc(title)}"
        class="w-9 h-9 inline-flex items-center justify-center rounded-lg border bg-white hover:bg-slate-50 ${cls}">
        <i data-lucide="${icon}" class="w-4 h-4"></i>
      </button>
    `;
  }

  function statusText(st) {
    return { draft: "Nháp", submitted: "Chờ duyệt", approved: "Đã duyệt", rejected: "Từ chối" }[st] || st;
  }

  function statusBadge(st) {
    return (st === "approved")
      ? badge("Đã duyệt", "bg-emerald-50 text-emerald-700")
      : (st === "submitted")
        ? badge("Chờ duyệt", "bg-amber-50 text-amber-700")
        : (st === "rejected")
          ? badge("Từ chối", "bg-rose-50 text-rose-700")
          : badge("Nháp", "bg-slate-100 text-slate-700");
  }

  function recipientText(r) {
    if (r.recipient_type === "individual") {
      const name = r.member_fullname || "(Chưa gắn member)";
      const mssv = r.member_mssv ? ` - ${r.member_mssv}` : "";
      const cls = r.member_class ? ` (${r.member_class})` : "";
      return `${name}${mssv}${cls}`;
    }
    return r.recipient_name || "(Tập thể)";
  }

  function formatFileSize(bytes) {
    const b = Number(bytes || 0);
    if (b < 1024) return `${b} B`;
    const kb = b / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    const mb = kb / 1024;
    if (mb < 1024) return `${mb.toFixed(1)} MB`;
    const gb = mb / 1024;
    return `${gb.toFixed(1)} GB`;
  }

  async function api(action, method = "GET", data = null) {
    let url = `${ctrl}?action=${encodeURIComponent(action)}`;
    const opt = { method };

    if (method === "GET" && data) {
      const qs = new URLSearchParams(data);
      url += `&${qs.toString()}`;
    } else if (data instanceof FormData) {
      opt.body = data;
    } else if (data) {
      const fd = new FormData();
      Object.keys(data).forEach((k) => fd.append(k, data[k]));
      opt.body = fd;
    }

    const res = await fetch(url, opt);
    const json = await res.json().catch(() => ({}));
    if (!json.ok) throw new Error(json.error || "Request failed");
    return json;
  }

  // ===== Confirm Modal =====
  function confirmModal(message, opts = {}) {
    const {
      title = "Xác nhận",
      confirmText = "Đồng ý",
      cancelText = "Hủy",
      tone = "danger", // danger | primary
    } = opts;

    return new Promise((resolve) => {
      const node = document.createElement("div");
      node.className = "space-y-3";

      const btnCls = (tone === "danger")
        ? "bg-red-600 hover:bg-red-700 text-white"
        : "bg-slate-900 hover:bg-slate-800 text-white";

      node.innerHTML = `
        <div class="rounded-2xl border bg-slate-50 p-4 text-sm text-slate-700">
          ${esc(message)}
        </div>
        <div class="flex items-center justify-end gap-2 pt-2 border-t">
          <button type="button" class="btnCancel px-3 py-2 rounded-lg border text-sm">${esc(cancelText)}</button>
          <button type="button" data-primary class="btnOk px-3 py-2 rounded-lg text-sm ${btnCls}">
            ${esc(confirmText)}
          </button>
        </div>
      `;

      modal(node, title, "medium");
      const btnCancel = node.querySelector(".btnCancel");
      const btnOk = node.querySelector(".btnOk");

      btnCancel.addEventListener("click", () => { closeModal(); resolve(false); });
      btnOk.addEventListener("click", () => { closeModal(); resolve(true); });
    });
  }

  // ===== Tabs =====
  function setActiveTab(which, opts = {}) {
    const { pushUrl = true, replaceUrl = false } = opts;

    // chặn trường hợp user không có can_review nhưng url cố tình tab=review
    if (which === "review" && caps.can_review !== 1) which = "list";

    if (pushUrl) setTabToUrl(which, { replace: replaceUrl });

    tabList.className =
      "px-3 py-2 text-sm font-medium rounded-t-lg border border-b-0 " +
      (which === "list" ? "bg-white" : "bg-slate-50 text-slate-700");
    panelList?.classList.toggle("hidden", which !== "list");

    if (caps.can_review === 1 && tabReview && panelReview) {
      tabReview.className =
        "px-3 py-2 text-sm font-medium rounded-t-lg border border-b-0 " +
        (which === "review" ? "bg-white" : "bg-slate-50 text-slate-700");
      panelReview.classList.toggle("hidden", which !== "review");
    }

    if (which === "list") loadList();
    if (which === "review") loadReview();
  }


  tabList?.addEventListener("click", () => setActiveTab("list", { pushUrl: true }));
  tabReview?.addEventListener("click", () => setActiveTab("review", { pushUrl: true }));


  // ===== Stats =====
  async function loadStats() {
    try {
      const j = await api("stats", "GET");
      const s = j.stats || {};
      statTotal.textContent = s.total ?? 0;
      statPending.textContent = s.pending ?? 0;
      statApproved.textContent = s.approved ?? 0;
      statRejected.textContent = s.rejected ?? 0;
      if (tabPendingBadge) tabPendingBadge.textContent = s.pending ?? 0;
    } catch (_) { }
  }

  // ===== Pagination helper =====
  function renderPagination(container, page, totalPages, onGo) {
    if (!container) return;
    container.innerHTML = "";
    if (totalPages <= 1) return;

    const mkBtn = (label, p, disabled = false) => {
      const btn = document.createElement("button");
      btn.textContent = label;
      btn.className = `px-3 py-2 rounded-lg border text-sm ${disabled ? "opacity-50 cursor-not-allowed" : ""}`;
      btn.disabled = disabled;
      btn.addEventListener("click", () => onGo(p));
      return btn;
    };

    container.appendChild(mkBtn("◀", Math.max(1, page - 1), page <= 1));

    const info = document.createElement("div");
    info.className = "text-sm text-slate-600 px-2";
    info.textContent = `Trang ${page}/${totalPages}`;
    container.appendChild(info);

    container.appendChild(mkBtn("▶", Math.min(totalPages, page + 1), page >= totalPages));
  }

  // ===== Filters =====
  function listFilters() {
    return {
      mode: "list", // ✅ thêm dòng này
      page: stateList.page,
      per_page: stateList.per_page,
      keyword: fKeyword?.value.trim() || "",
      recipient_type: fRecipientType?.value || "",
      school_year: fSchoolYear?.value.trim() || "",
      award_level: fAwardLevel?.value.trim() || "",
      visibility: fVisibility?.value || "",
    };
  }


  function reviewFilters() {
    return {
      mode: "review", // ✅ thêm dòng này
      page: stateReview.page,
      per_page: stateReview.per_page,
      keyword: rKeyword?.value.trim() || "",
      status: rStatus?.value || "", // ✅ "" = tất cả
    };
  }


  function buildExportUrl(format) {
    const params = new URLSearchParams();
    const isReviewTab = (caps.can_review === 1 && panelReview && !panelReview.classList.contains("hidden"));
    const data = isReviewTab ? reviewFilters() : listFilters();

    Object.keys(data).forEach((k) => {
      if (data[k] !== undefined && data[k] !== null && String(data[k]).trim() !== "") {
        params.set(k, data[k]);
      }
    });

    params.delete("page");
    params.delete("per_page");

    const action = (format === "pdf") ? "export_pdf" : "export_xlsx";
    return `${ctrl}?action=${action}&${params.toString()}`;
  }

  // ===== List =====
  async function loadList() {
    if (!tbody) return;

    // ✅ đã thêm 1 cột "Ghi chú" => colspan = 10
    tbody.innerHTML = `<tr><td colspan="10" class="px-3 py-4 text-slate-500">Đang tải...</td></tr>`;

    try {
      const j = await api("list", "GET", listFilters());
      const rows = j.rows || [];
      const pg = j.pagination || {};
      stateList.total_pages = pg.total_pages || 1;

      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="10" class="px-3 py-4 text-slate-500">Không có dữ liệu</td></tr>`;
        renderPagination(pagination, pg.page || 1, pg.total_pages || 1, (p) => { stateList.page = p; loadList(); });
        return;
      }

      tbody.innerHTML = rows.map((r) => {
        const vis = (r.visibility === "public")
          ? badge("Công khai", "bg-emerald-50 text-emerald-700")
          : badge("Ẩn", "bg-slate-100 text-slate-700");

        const stBadge = statusBadge(r.status);

        // ✅ NOTE: để trong map mới có r
        const noteText = String(r.review_note || "").trim();
        const noteCell = noteText
          ? `<div class="text-xs text-slate-700 line-clamp-2">${esc(noteText)}</div>`
          : `<div class="text-xs text-slate-400">-</div>`;

        const actions = [];
        actions.push(iconBtn({ act: "view", id: r.id, title: "Xem", icon: "eye" }));

        if (caps.can_update === 1 && (r.status !== "approved" || caps.can_review === 1)) {
          actions.push(iconBtn({ act: "edit", id: r.id, title: "Sửa", icon: "pencil" }));
        }

        if (caps.can_delete === 1) {
          actions.push(iconBtn({
            act: "delete",
            id: r.id,
            title: "Xóa",
            icon: "trash-2",
            cls: "border-red-200 text-red-600 hover:bg-red-50"
          }));
        }

        return `
        <tr class="border-t">
          <td class="px-3 py-2">${esc(r.id)}</td>
          <td class="px-3 py-2">
            <div class="font-semibold text-slate-900">${esc(r.title)}</div>
            <div class="text-xs text-slate-500">
              ${esc(r.awarding_agency || "")}
              ${r.decision_no ? " • Số QĐ: " + esc(r.decision_no) : ""}
            </div>
          </td>
          <td class="px-3 py-2">${esc(recipientText(r))}</td>
          <td class="px-3 py-2">
            <div>${esc(r.award_level || "")}</div>
            <div class="text-xs text-slate-500">${esc(r.award_form || "")}</div>
          </td>
          <td class="px-3 py-2">${esc(r.school_year || "")}</td>
          <td class="px-3 py-2">${esc((r.achieved_at || "").slice(0, 10))}</td>
          <td class="px-3 py-2 text-center">${vis}</td>
          <td class="px-3 py-2 text-center">${stBadge}</td>
          <td class="px-3 py-2">${noteCell}</td>
          <td class="px-3 py-2 sticky right-0 z-20 bg-gray-50 border-l shadow-[-8px_0_12px_-12px_rgba(0,0,0,0.35)]">
            <div class="flex flex-wrap gap-2 justify-center">${actions.join("")}</div>
          </td>
        </tr>
      `;
      }).join("");

      ensureLucide();
      renderPagination(pagination, pg.page || 1, pg.total_pages || 1, (p) => { stateList.page = p; loadList(); });
    } catch (e) {
      // ✅ colspan = 10
      tbody.innerHTML = `<tr><td colspan="10" class="px-3 py-4 text-red-600">Lỗi: ${esc(e.message)}</td></tr>`;
      notify(e.message || "Có lỗi xảy ra", "error");
    }
  }


  // ===== Review =====
  async function loadReview() {
    if (caps.can_review !== 1 || !tbodyReview) return;

    tbodyReview.innerHTML = `<tr><td colspan="8" class="px-3 py-4 text-slate-500">Đang tải...</td></tr>`;

    try {
      const j = await api("list", "GET", reviewFilters());
      const rows = j.rows || [];
      const pg = j.pagination || {};
      stateReview.total_pages = pg.total_pages || 1;

      if (!rows.length) {
        tbodyReview.innerHTML = `<tr><td colspan="8" class="px-3 py-4 text-slate-500">Không có dữ liệu</td></tr>`;
        renderPagination(paginationReview, pg.page || 1, pg.total_pages || 1, (p) => { stateReview.page = p; loadReview(); });
        return;
      }

      tbodyReview.innerHTML = rows.map((r) => {
        const stBadge = statusBadge(r.status);

        const noteText = String(r.review_note || "").trim();
        const noteCell = noteText
          ? `<div class="text-xs text-slate-700 line-clamp-2">${esc(noteText)}</div>`
          : `<div class="text-xs text-slate-400">-</div>`;

        const actions = [];
        actions.push(iconBtn({ actAttr: "data-ract", act: "view", id: r.id, title: "Xem", icon: "eye" }));

        if (r.status === "submitted") {
          actions.push(iconBtn({
            actAttr: "data-ract",
            act: "review",
            id: r.id,
            title: "Duyệt",
            icon: "check-circle",
            cls: "border-green-200 text-green-700 hover:bg-green-50"
          }));
        }

        return `
    <tr class="border-t">
      <td class="px-3 py-2">${esc(r.id)}</td>
      <td class="px-3 py-2">
        <div class="font-semibold text-slate-900">${esc(r.title)}</div>
        <div class="text-xs text-slate-500">${esc(r.award_level || "")} • ${esc(r.award_form || "")}</div>
      </td>
      <td class="px-3 py-2">${esc(recipientText(r))}</td>
      <td class="px-3 py-2">${esc(r.school_year || "")}</td>
      <td class="px-3 py-2 text-center">${stBadge}</td>
      <td class="px-3 py-2">${esc(r.creator_name || "")}</td>

      <!-- ✅ thêm cột ghi chú để khớp thead -->
      <td class="px-3 py-2">${noteCell}</td>

      <td class="px-3 py-2 sticky right-0 z-20 bg-gray-50 border-l shadow-[-8px_0_12px_-12px_rgba(0,0,0,0.35)]">
        <div class="flex flex-wrap gap-2 justify-center">${actions.join("")}</div>
      </td>
    </tr>
  `;
      }).join("");


      ensureLucide();
      renderPagination(paginationReview, pg.page || 1, pg.total_pages || 1, (p) => { stateReview.page = p; loadReview(); });
    } catch (e) {
      tbodyReview.innerHTML = `<tr><td colspan="8" class="px-3 py-4 text-red-600">Lỗi: ${esc(e.message)}</td></tr>`;
      notify(e.message || "Có lỗi xảy ra", "error");
    }
  }

  // ===== View Modal =====
  async function openViewModal(id) {
    const j = await api("get", "GET", { id });
    const r = j.row;
    const fs = j.files || [];

    const node = document.createElement("div");
    node.className = "space-y-3";

    const filesHtml = fs.length
      ? `<div class="space-y-2">` + fs.map((f) => {
        const name = esc(f.file_name || "file");
        const mime = esc(f.mime_type || "");
        const sizeText = f.file_size ? ` • ${esc(formatFileSize(f.file_size))}` : "";

        // ✅ Ưu tiên field mới từ controller
        const viewUrl = f.view_url || f.drive_web_view_link || "";
        const downloadUrl = f.download_url || "";

        const viewBtn = viewUrl
          ? `<a class="px-3 py-1.5 rounded-lg border text-sm hover:bg-slate-50"
              href="${esc(viewUrl)}" target="_blank" rel="noreferrer">
              Xem
           </a>`
          : "";

        const dlBtn = downloadUrl
          ? `<a class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-sm hover:bg-slate-800"
              href="${esc(downloadUrl)}" rel="noreferrer">
              Tải về
           </a>`
          : "";

        return `
        <div class="flex items-center justify-between gap-2 p-3 rounded-xl border bg-white">
          <div class="min-w-0">
            <div class="text-sm font-medium truncate">${name}</div>
            <div class="text-xs text-slate-500">${mime}${sizeText}</div>
          </div>
          <div class="shrink-0 flex gap-2">
            ${viewBtn}
            ${dlBtn}
          </div>
        </div>
      `;
      }).join("") + `</div>`
      : `<div class="text-sm text-slate-500">Không có file</div>`;


    node.innerHTML = `
      <div class="rounded-2xl border bg-slate-50 p-4">
        <div class="text-lg font-semibold">${esc(r.title)}</div>
        <div class="mt-2 flex flex-wrap gap-2">
          ${badge(statusText(r.status), "bg-white border text-slate-700")}
          ${badge(r.visibility === "public" ? "Công khai" : "Ẩn", "bg-white border text-slate-700")}
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
        <div><span class="text-slate-500">Đơn vị:</span> ${esc(recipientText(r))}</div>
        <div><span class="text-slate-500">Năm học:</span> ${esc(r.school_year || "")}</div>
        <div><span class="text-slate-500">Cấp khen:</span> ${esc(r.award_level || "")}</div>
        <div><span class="text-slate-500">Hình thức:</span> ${esc(r.award_form || "")}</div>
        <div class="sm:col-span-2"><span class="text-slate-500">Cơ quan khen:</span> ${esc(r.awarding_agency || "")}</div>
        <div><span class="text-slate-500">Số quyết định:</span> ${esc(r.decision_no || "")}</div>
        <div><span class="text-slate-500">Ngày đạt:</span> ${esc((r.achieved_at || "").slice(0, 10))}</div>
      </div>

      <div class="rounded-2xl border p-4">
        <div class="text-sm font-semibold mb-1">Mô tả</div>
        <div class="text-sm whitespace-pre-wrap">${esc(r.content || "")}</div>
      </div>

      <div class="rounded-2xl border p-4">
        <div class="text-sm font-semibold mb-2">Minh chứng</div>
        ${filesHtml}
      </div>

      <div class="text-xs text-slate-500">
        Người nhập: ${esc(r.creator_name || "")}
        ${r.reviewer_name ? ` • Người duyệt: ${esc(r.reviewer_name)}` : ""}
        ${r.review_note ? ` • Ghi chú: ${esc(r.review_note)}` : ""}
      </div>
    `;

    modal(node, "Chi tiết thành tích", "large");
  }

  // ===== Form builder =====
  function buildFormNode() {
    const node = document.createElement("div");
    node.innerHTML = `
      <form class="space-y-4" enctype="multipart/form-data">
        <input type="hidden" name="id" value="0">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="text-sm text-slate-600">Tên thành tích <span class="text-red-600">*</span></label>
            <input name="title" class="w-full px-3 py-2 border rounded-lg" required>
          </div>

        ${caps.can_review === 1 ? `
          <div>
            <label class="text-sm text-slate-600">Trạng thái hiển thị</label>
            <select name="visibility" class="w-full px-3 py-2 border rounded-lg">
              <option value="hidden">Ẩn</option>
              <option value="public">Công khai</option>
            </select>
          </div>
        ` : `
          <!-- ✅ user không can_review: không cho chọn, mặc định public -->
          <input type="hidden" name="visibility" value="public">
        `}


          <div>
            <label class="text-sm text-slate-600">Đơn vị đạt</label>
            <select name="recipient_type" class="w-full px-3 py-2 border rounded-lg">
              <option value="individual">Cá nhân</option>
              <option value="collective">Tập thể</option>
            </select>
          </div>

          <div class="collective hidden">
            <label class="text-sm text-slate-600">Tên tập thể <span class="text-red-600">*</span></label>
            <input name="recipient_name" class="w-full px-3 py-2 border rounded-lg">
          </div>

          ${caps.can_review === 1 ? `
  <div class="individual sm:col-span-2">
    <label class="text-sm text-slate-600">Chọn cá nhân (members) <span class="text-red-600">*</span></label>
    <div class="flex flex-wrap gap-2 items-center">
      <input class="memberSearch flex-1 min-w-[220px] px-3 py-2 border rounded-lg" placeholder="Nhập tên / MSSV / lớp...">
      <input type="hidden" name="member_id" class="member_id" value="">
      <span class="memberPicked hidden px-3 py-2 rounded-lg bg-slate-100 text-sm"></span>
      <button type="button" class="btnClearMember px-3 py-2 rounded-lg border text-sm">Bỏ chọn</button>
    </div>
    <div class="memberResults mt-2 hidden rounded-xl border bg-white p-3 max-h-56 overflow-auto"></div>
  </div>
` : `
  <div class="individual sm:col-span-2">
    <label class="text-sm text-slate-600">Cá nhân</label>
    <div class="flex items-center gap-2">
      <div class="selfName w-full px-3 py-2 border rounded-lg bg-slate-50 text-slate-700 text-sm">
        (Đang tải...)
      </div>
      <input type="hidden" name="member_id" class="member_id" value="">
    </div>
    <div class="text-xs text-slate-500 mt-1">Mặc định theo tài khoản đăng nhập (không chọn người khác).</div>
  </div>
`}


          <div>
            <label class="text-sm text-slate-600">Cấp khen thưởng</label>
            <input name="award_level" class="w-full px-3 py-2 border rounded-lg" placeholder="Trường / Xã-Phường / Tỉnh...">
          </div>

          <div>
            <label class="text-sm text-slate-600">Hình thức khen thưởng</label>
            <input name="award_form" class="w-full px-3 py-2 border rounded-lg" placeholder="Giấy khen / Bằng khen / ...">
          </div>

          <div>
            <label class="text-sm text-slate-600">Năm học</label>
            <input name="school_year" class="w-full px-3 py-2 border rounded-lg" placeholder="VD 2025-2026">
          </div>

          <div>
            <label class="text-sm text-slate-600">Ngày đạt</label>
            <input name="achieved_at" type="date" class="w-full px-3 py-2 border rounded-lg">
          </div>

          <div class="sm:col-span-2">
            <label class="text-sm text-slate-600">Cơ quan khen thưởng</label>
            <input name="awarding_agency" class="w-full px-3 py-2 border rounded-lg" placeholder="Đơn vị ra quyết định / cơ quan...">
          </div>
<div class="sm:col-span-2">
  <label class="text-sm text-slate-600">Số quyết định</label>
  <input name="decision_no" class="w-full px-3 py-2 border rounded-lg" placeholder="VD: 123/QĐ-ĐTN">
</div>

          <div class="sm:col-span-2">
            <label class="text-sm text-slate-600">Mô tả chi tiết <span class="text-red-600">*</span></label>
            <textarea name="content" class="w-full px-3 py-2 border rounded-lg min-h-[130px]" required></textarea>
          </div>

          <div class="sm:col-span-2">
            <label class="text-sm text-slate-600">File đính kèm (nhiều file)</label>
            <input class="fileInput" name="files[]" type="file" multiple class="block w-full text-sm">
            <div class="selectedFiles mt-2 space-y-2"></div>
            <div class="existingFiles mt-2 space-y-2"></div>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t">
          <button type="button" class="btnCancel px-3 py-2 rounded-lg border text-sm">Hủy</button>
          <button type="submit" data-primary class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm">Lưu</button>
        </div>
      </form>
    `;
    return node;
  }

  function bindRecipientMode(node) {
    const form = node.querySelector("form");
    const sel = form.querySelector('select[name="recipient_type"]');
    const blockCollective = node.querySelector(".collective");
    const blockIndividual = node.querySelector(".individual");
    const memberId = node.querySelector(".member_id");
    const memberPicked = node.querySelector(".memberPicked"); // có thể null (user thường)

    const apply = () => {
      const type = sel.value;

      if (type === "collective") {
        blockCollective?.classList.remove("hidden");
        blockIndividual?.classList.add("hidden");

        if (memberId) memberId.value = "";
        if (memberPicked) {
          memberPicked.textContent = "";
          memberPicked.classList.add("hidden");
        }
      } else {
        blockCollective?.classList.add("hidden");
        blockIndividual?.classList.remove("hidden");
        const rn = form.querySelector('input[name="recipient_name"]');
        if (rn) rn.value = "";
      }
    };

    sel.addEventListener("change", apply);
    apply();
  }



  function bindMemberSearch(node) {
    const ms = node.querySelector(".memberSearch");
    const results = node.querySelector(".memberResults");
    const memberId = node.querySelector(".member_id");
    const memberPicked = node.querySelector(".memberPicked");
    const btnClear = node.querySelector(".btnClearMember");
    // ✅ user thường: không có UI search -> bỏ qua luôn
    if (!ms || !results || !memberId) return;
    let timer = null;

    ms.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const q = ms.value.trim();
        if (!q) {
          results.classList.add("hidden");
          results.innerHTML = "";
          return;
        }
        try {
          const j = await api("members_search", "GET", { q });
          const rows = j.rows || [];
          results.classList.remove("hidden");

          if (!rows.length) {
            results.innerHTML = `<div class="text-sm text-slate-500">Không tìm thấy</div>`;
            return;
          }

          results.innerHTML = rows.map((r) => `
            <button type="button"
              data-pick="1"
              data-id="${esc(r.id)}"
              data-name="${esc(r.fullname)}"
              data-mssv="${esc(r.mssv || "")}"
              data-class="${esc(r.class_text || "")}"
              class="w-full text-left flex items-center justify-between gap-2 p-2 rounded-lg border mb-2
                    hover:bg-slate-50 transition">
              <div>
                <div class="text-sm font-semibold">
                  ${esc(r.fullname)}
                  <span class="text-xs text-slate-500">${esc(r.mssv || "")}</span>
                </div>
                <div class="text-xs text-slate-500">${esc(r.class_text || "")}</div>
              </div>

            </button>
          `).join("");

        } catch (e) {
          results.classList.remove("hidden");
          results.innerHTML = `<div class="text-sm text-red-600">Lỗi: ${esc(e.message)}</div>`;
        }
      }, 250);
    });

    results.addEventListener("click", (ev) => {
      const btn = ev.target.closest("[data-pick='1']");
      if (!btn) return;
      memberId.value = btn.dataset.id;
      const name = btn.dataset.name || "";
      const mssv = btn.dataset.mssv ? (" - " + btn.dataset.mssv) : "";
      const cls = btn.dataset.class ? (" (" + btn.dataset.class + ")") : "";
      memberPicked.textContent = `${name}${mssv}${cls}`;
      memberPicked.classList.remove("hidden");
      results.classList.add("hidden");
      results.innerHTML = "";
    });

    btnClear.addEventListener("click", () => {
      memberId.value = "";
      memberPicked.textContent = "";
      memberPicked.classList.add("hidden");
    });
  }

  // ===== File picker: merge selections + list + remove =====
  function bindFilePicker(node) {
    const input = node.querySelector(".fileInput");
    const list = node.querySelector(".selectedFiles");
    if (!input || !list) return;

    const dt = new DataTransfer();
    const keyOf = (f) => `${f.name}|${f.size}|${f.lastModified}`;

    const syncToInput = () => {
      // Một số browser có thể chặn set input.files, nên try/catch cho chắc
      try { input.files = dt.files; } catch (_) { }
    };

    const render = () => {
      const files = Array.from(dt.files || []);
      if (!files.length) {
        list.innerHTML = "";
        return;
      }

      list.innerHTML = files.map((f, idx) => `
      <div class="flex items-center justify-between gap-2 p-3 rounded-xl border bg-white">
        <div class="min-w-0">
          <div class="text-sm font-medium truncate">${esc(f.name)}</div>
          <div class="text-xs text-slate-500">${esc(formatFileSize(f.size))}</div>
        </div>
        <button type="button" data-remove-file="${idx}"
          class="w-9 h-9 inline-flex items-center justify-center rounded-lg border hover:bg-slate-50"
          title="Xóa file">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>
    `).join("");

      ensureLucide();
    };

    // ✅ Clear trước khi chọn để chọn lại cùng file vẫn trigger change
    input.addEventListener("click", () => {
      input.value = "";
    });

    input.addEventListener("change", () => {
      const incoming = Array.from(input.files || []);
      if (!incoming.length) return;

      const exists = new Set(Array.from(dt.files).map(keyOf));
      incoming.forEach((f) => {
        const k = keyOf(f);
        if (!exists.has(k)) {
          dt.items.add(f);
          exists.add(k);
        }
      });

      syncToInput();
      render();

      // ❌ KHÔNG được input.value="" ở đây nữa (nó sẽ làm mất file khi submit)
    });

    list.addEventListener("click", (ev) => {
      const btn = ev.target.closest("button[data-remove-file]");
      if (!btn) return;

      const idx = Number(btn.dataset.removeFile);
      const files = Array.from(dt.files || []);
      if (Number.isNaN(idx) || idx < 0 || idx >= files.length) return;

      const next = new DataTransfer();
      files.forEach((f, i) => { if (i !== idx) next.items.add(f); });

      while (dt.items.length) dt.items.remove(0);
      Array.from(next.files).forEach((f) => dt.items.add(f));

      syncToInput();
      render();
    });

    render();
  }

  function applyPickedMember(node, r) {
    const memberId = node.querySelector(".member_id");
    if (!memberId || !r) return;

    memberId.value = r.id || "";

    const name = r.fullname || "";
    const mssv = r.mssv ? (" - " + r.mssv) : "";
    const cls = r.class_text ? (" (" + r.class_text + ")") : "";
    const text = `${name}${mssv}${cls}`.trim();

    // Reviewer UI
    const memberPicked = node.querySelector(".memberPicked");
    if (memberPicked) {
      memberPicked.textContent = text;
      memberPicked.classList.toggle("hidden", !text);
      const results = node.querySelector(".memberResults");
      if (results) { results.classList.add("hidden"); results.innerHTML = ""; }
      return;
    }

    // User thường UI
    const selfName = node.querySelector(".selfName");
    if (selfName) selfName.textContent = text || "(Không tìm thấy)";
  }


  async function ensureDefaultMemberForLoggedInUser(node) {
    // ✅ Reviewer: KHÔNG auto pick tài khoản đăng nhập
    if (caps.can_review === 1) return;

    const form = node.querySelector("form");
    const rt = form?.querySelector('select[name="recipient_type"]')?.value || "individual";
    if (rt !== "individual") return;

    const memberId = node.querySelector(".member_id");
    if (!memberId || memberId.value) return;

    const selfName = node.querySelector(".selfName");

    let me = cfg.me_member || null;
    let userFullname = (cfg.user_fullname || "").trim();

    try {
      if (!me) {
        const j = await api("me_member", "GET");
        me = j.member || null;
        if (!userFullname) userFullname = (j.user_fullname || "").trim();
      }
    } catch (_) { }

    if (me && me.id) {
      applyPickedMember(node, me); // sẽ set member_id + selfName
      return;
    }

    // fallback: không có member -> hiện fullname user (đỡ “Đang tải...”)
    if (selfName) selfName.textContent = userFullname || "(Chưa gắn đoàn viên)";
  }



  // ===== Form Modal =====
  async function openFormModal(id = 0) {
    const node = buildFormNode();
    bindRecipientMode(node);
    bindMemberSearch(node);
    bindFilePicker(node);

    const form = node.querySelector("form");
    const btnCancel = node.querySelector(".btnCancel");
    const existingFiles = node.querySelector(".existingFiles");
    const setVisibilityValue = (val) => {
      const sel = form.querySelector('select[name="visibility"]');
      const hid = form.querySelector('input[name="visibility"]');

      // can_review: default hidden (hoặc tùy bạn)
      if (sel) sel.value = val || "hidden";

      // không can_review: default public
      if (hid) hid.value = val || "public";
    };

    if (id > 0) {
      const j = await api("get", "GET", { id });
      const r = j.row;
      const fs = j.files || [];

      // UI-guard: approved + không can_review -> chặn
      if (r.status === "approved" && caps.can_review !== 1) {
        notify("Thành tích đã duyệt: bạn không có quyền sửa.", "error");
        return;
      }

      form.querySelector('input[name="id"]').value = r.id;
      form.querySelector('input[name="title"]').value = r.title || "";
      setVisibilityValue(r.visibility || (caps.can_review === 1 ? "hidden" : "public"));
      form.querySelector('select[name="recipient_type"]').value = r.recipient_type || "individual";
      form.querySelector('input[name="award_level"]').value = r.award_level || "";
      form.querySelector('input[name="award_form"]').value = r.award_form || "";
      form.querySelector('input[name="school_year"]').value = r.school_year || "";
      form.querySelector('input[name="achieved_at"]').value = (r.achieved_at || "").slice(0, 10);
      form.querySelector('input[name="awarding_agency"]').value = r.awarding_agency || "";
      form.querySelector('input[name="decision_no"]').value = r.decision_no || "";
      form.querySelector('textarea[name="content"]').value = r.content || "";

      form.querySelector('select[name="recipient_type"]').dispatchEvent(new Event("change"));

      if (r.recipient_type === "collective") {
        form.querySelector('input[name="recipient_name"]').value = r.recipient_name || "";
      } else {
        const mid = node.querySelector(".member_id");
        const mp = node.querySelector(".memberPicked");
        mid.value = r.member_id || "";
        if (r.member_fullname) {
          mp.textContent = `${r.member_fullname}${r.member_mssv ? " - " + r.member_mssv : ""}${r.member_class ? " (" + r.member_class + ")" : ""}`;
          mp.classList.remove("hidden");
        }
      }

      existingFiles.innerHTML = fs.length
        ? fs.map((f) => {
          const name = esc(f.file_name || "file");
          const mime = esc(f.mime_type || "");
          const sizeText = f.file_size ? ` • ${esc(formatFileSize(f.file_size))}` : "";

          const viewUrl = f.view_url || f.drive_web_view_link || "";
          const downloadUrl = f.download_url || "";

          const viewBtn = viewUrl
            ? `<a class="px-3 py-1.5 rounded-lg border text-sm hover:bg-slate-50"
              href="${esc(viewUrl)}" target="_blank" rel="noreferrer">
              Xem
           </a>`
            : "";

          const dlBtn = downloadUrl
            ? `<a class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-sm hover:bg-slate-800"
              href="${esc(downloadUrl)}" rel="noreferrer">
              Tải về
           </a>`
            : "";

          const delBtn = (caps.can_update === 1)
            ? `
          <button type="button" data-del-file="1" data-file-id="${esc(f.id)}"
            class="px-3 py-1.5 rounded-lg border text-sm hover:bg-slate-50">
            Xóa
          </button>
        `
            : "";

          return `
        <div class="flex items-center justify-between gap-2 p-3 rounded-xl border bg-white">
          <div class="min-w-0">
            <div class="text-sm font-medium truncate">${name}</div>
            <div class="text-xs text-slate-500">${mime}${sizeText}</div>
          </div>
          <div class="shrink-0 flex gap-2">
            ${viewBtn}
            ${dlBtn}
            ${delBtn}
          </div>
        </div>
      `;
        }).join("")
        : `<div class="text-sm text-slate-500">Chưa có file.</div>`;


      existingFiles.addEventListener("click", async (ev) => {
        const btn = ev.target.closest("button[data-del-file='1']");
        if (!btn) return;

        const ok = await confirmModal("Xóa file này?", { title: "Xác nhận xóa", confirmText: "Xóa", tone: "danger" });
        if (!ok) return;

        try {
          await api("delete_file", "POST", { file_id: btn.dataset.fileId });
          notify("Đã xóa file.", "success");
          closeModal();
          openFormModal(id);
        } catch (e) {
          notify(e.message || "Không thể xóa file", "error");
        }
      });
    }


    modal(node, id > 0 ? "Sửa thành tích" : "Thêm thành tích", "large");
    ensureLucide();
    if (id === 0) {
      const rtSel = form.querySelector('select[name="recipient_type"]');
      rtSel?.addEventListener("change", () => {
        if (rtSel.value === "individual") ensureDefaultMemberForLoggedInUser(node);
      });
    }

    // ✅ form thêm của user thường: mặc định hiển thị
    if (id === 0 && caps.can_review !== 1) {
      setVisibilityValue("public");
    }

    // ✅ AUTO PICK MEMBER = tài khoản đang đăng nhập (khi thêm mới)
    if (id === 0 && caps.can_review !== 1) {
      await ensureDefaultMemberForLoggedInUser(node);
    }



    btnCancel.addEventListener("click", () => closeModal());

    form.addEventListener("submit", async (ev) => {
      ev.preventDefault();

      if (form.dataset.busy === "1") return; // ✅ chặn bấm nhiều lần

      const rt = form.querySelector('select[name="recipient_type"]').value;

      if (rt === "individual" && !node.querySelector(".member_id").value) {
        notify("Bạn phải chọn cá nhân (members).", "error");
        return;
      }
      if (rt === "collective" && !form.querySelector('input[name="recipient_name"]').value.trim()) {
        notify("Bạn phải nhập tên tập thể.", "error");
        return;
      }

      const fd = new FormData(form);
      fd.append("action", "save");

      // ✅ UI: đang lưu + khóa form
      setFormBusy(form, true, "Đang lưu...");

      try {
        await api("save", "POST", fd);

        closeModal();
        await loadStats();
        await loadList();
        if (caps.can_review === 1 && panelReview && !panelReview.classList.contains("hidden")) {
          await loadReview();
        }

        notify(caps.can_review === 1 ? "Đã lưu." : "Đã gửi duyệt.", "success");
      } catch (e) {
        notify(e.message || "Không thể lưu", "error");
      } finally {
        // nếu modal đã đóng thì form không còn, nên check nhẹ
        if (form && form.isConnected) setFormBusy(form, false);
      }
    });

  }

  // ===== Review Modal =====
  function openReviewModal(id) {
    const node = document.createElement("div");
    node.className = "space-y-3";
    node.innerHTML = `
      <div class="rounded-2xl border bg-slate-50 p-4 text-sm text-slate-700">
        Bạn đang duyệt thành tích ID <b>${esc(id)}</b>.
      </div>

      <div>
        <label class="text-sm text-slate-600">Ghi chú duyệt / từ chối</label>
        <textarea class="note w-full px-3 py-2 border rounded-lg min-h-[120px]"></textarea>
      </div>

      <div class="flex items-center justify-end gap-2 pt-2 border-t">
        <button type="button" class="btnReject px-3 py-2 rounded-lg bg-red-600 text-white text-sm">Từ chối</button>
        <button type="button" data-primary class="btnApprove px-3 py-2 rounded-lg bg-green-600 text-white text-sm">Duyệt</button>
      </div>
    `;

    modal(node, "Duyệt thành tích", "medium");

    const note = node.querySelector(".note");
    const btnApprove = node.querySelector(".btnApprove");
    const btnReject = node.querySelector(".btnReject");

    btnApprove.addEventListener("click", async () => {
      try {
        await api("review", "POST", { id, decision: "approve", note: note.value.trim() });
        closeModal();
        await loadStats();
        await loadReview();
        await loadList();
        notify("Đã duyệt.", "success");
      } catch (e) {
        notify(e.message || "Không thể duyệt", "error");
      }
    });

    btnReject.addEventListener("click", async () => {
      const reason = note.value.trim();
      if (!reason) {
        notify("Bạn phải nhập lý do từ chối.", "error");
        return;
      }

      const ok = await confirmModal("Từ chối thành tích này?", { title: "Xác nhận từ chối", confirmText: "Từ chối", tone: "danger" });
      if (!ok) return;

      try {
        await api("review", "POST", { id, decision: "reject", note: reason });
        closeModal();
        await loadStats();
        await loadReview();
        await loadList();
        notify("Đã từ chối.", "success");
      } catch (e) {
        notify(e.message || "Không thể từ chối", "error");
      }
    });

  }

  // ===== Events =====
  btnAdd?.addEventListener("click", () => openFormModal(0));

  // ===== AUTO FILTER: LIST =====
  const triggerListReload = () => {
    stateList.page = 1;
    loadList();
  };
  const triggerListReloadDebounced = debounce(triggerListReload, 300);

  fKeyword?.addEventListener("input", triggerListReloadDebounced);

  [fRecipientType, fSchoolYear, fAwardLevel, fVisibility].forEach((el) => {
    if (!el) return;
    el.addEventListener("change", triggerListReload);
    if (el.tagName === "INPUT") el.addEventListener("input", triggerListReloadDebounced);
  });

  btnReset?.addEventListener("click", () => {
    if (fKeyword) fKeyword.value = "";
    if (fRecipientType) fRecipientType.value = "";
    if (fSchoolYear) fSchoolYear.value = "";
    if (fAwardLevel) fAwardLevel.value = "";
    if (fVisibility) fVisibility.value = "";
    triggerListReload();
  });

  // ===== AUTO FILTER: REVIEW =====
  const triggerReviewReload = () => {
    stateReview.page = 1;
    loadReview();
  };
  const triggerReviewReloadDebounced = debounce(triggerReviewReload, 300);

  rKeyword?.addEventListener("input", triggerReviewReloadDebounced);
  rStatus?.addEventListener("change", triggerReviewReload);

  btnReviewReset?.addEventListener("click", () => {
    if (rKeyword) rKeyword.value = "";
    if (rStatus) rStatus.value = "";
    triggerReviewReload();
  });

  // ===== Export =====
  btnExportPdf?.addEventListener("click", () => {
    window.open(buildExportUrl("pdf"), "_blank");
  });

  btnExportXlsx?.addEventListener("click", () => {
    window.location.href = buildExportUrl("xlsx");
  });

  // ===== Table actions: LIST =====
  tbody?.addEventListener("click", async (ev) => {
    const btn = ev.target.closest("button[data-act]");
    if (!btn) return;
    const act = btn.dataset.act;
    const id = btn.dataset.id;

    if (act === "view") return openViewModal(id);
    if (act === "edit") return openFormModal(Number(id));

    if (act === "delete") {
      if (caps.can_delete !== 1) return notify("Bạn không có quyền xóa", "error");

      const ok = await confirmModal("Xóa thành tích này?", { title: "Xác nhận xóa", confirmText: "Xóa", tone: "danger" });
      if (!ok) return;

      try {
        await api("delete", "POST", { id });
        await loadStats();
        await loadList();
        if (caps.can_review === 1) await loadReview();
        notify("Đã xóa.", "success");
      } catch (e) {
        notify(e.message || "Không thể xóa", "error");
      }
    }
  });

  // ===== Table actions: REVIEW =====
  tbodyReview?.addEventListener("click", (ev) => {
    const btn = ev.target.closest("button[data-ract]");
    if (!btn) return;
    const act = btn.dataset.ract;
    const id = btn.dataset.id;

    if (act === "view") return openViewModal(id);
    if (act === "review") return openReviewModal(id);
  });
  window.addEventListener("popstate", () => {
    const tab = getTabFromUrl();
    setActiveTab(tab, { pushUrl: false }); // không push nữa kẻo loop
  });

  // ===== init =====
  (async () => {
    await loadStats();

    const tab = getTabFromUrl();
    // replaceUrl để nếu url chưa có ?tab=... thì nó thêm vào mà không tạo history mới
    setActiveTab(tab, { pushUrl: true, replaceUrl: true });
  })();

})();
