let registering = false;



/* ================= API SAFE ================= */
async function apiFetch(url, options = {}) {
  const res = await api(url, {
    headers: {
      "X-Requested-With": "XMLHttpRequest",
      ...(options.headers || {})
    },
    ...options
  });

  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    console.error("API trả HTML:", text);
    throw new Error("Invalid JSON");
  }
}

/* ================= ESCAPE ================= */
function escapeHtml(str = "") {
  return str
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function parseDeadline(deadline) {
  if (!deadline) return null;
  const d = new Date(deadline.replace(" ", "T"));
  return isNaN(d.getTime()) ? null : d;
}

function isDeadlinePassed(deadline) {
  const d = parseDeadline(deadline);
  if (!d) return false;
  return Date.now() > d.getTime();
}

function formatDeadline24h(deadline) {
  if (!deadline) return "Không giới hạn";

  const d = new Date(deadline.replace(" ", "T"));
  if (isNaN(d.getTime())) return "Không hợp lệ";

  return d.toLocaleString("vi-VN", {
    hour12: false,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  });
}
function parseDbDateTime(s) {
  if (!s) return null;
  const str = String(s).trim();

  // "YYYY-MM-DD"
  if (/^\d{4}-\d{2}-\d{2}$/.test(str)) return new Date(str + "T00:00:00");

  // "YYYY-MM-DD HH:MM:SS" hoặc "YYYY-MM-DD HH:MM"
  const d = new Date(str.replace(" ", "T"));
  return isNaN(d.getTime()) ? null : d;
}

function formatEventRange(startDb, endDb) {
  const ds = parseDbDateTime(startDb);
  const de = parseDbDateTime(endDb);

  // nếu parse fail thì trả về thô
  if (!ds || !de) return `${escapeHtml(String(startDb || ""))} – ${escapeHtml(String(endDb || ""))}`;

  const pad = (n) => String(n).padStart(2, "0");
  const sameDay =
    ds.getFullYear() === de.getFullYear() &&
    ds.getMonth() === de.getMonth() &&
    ds.getDate() === de.getDate();

  const datePart = `${pad(ds.getDate())}/${pad(ds.getMonth() + 1)}/${ds.getFullYear()}`;

  if (sameDay) {
    return `${datePart} • ${pad(ds.getHours())}:${pad(ds.getMinutes())} – ${pad(de.getHours())}:${pad(de.getMinutes())}`;
  }


  const startText = `${pad(ds.getHours())}:${pad(ds.getMinutes())} ${pad(ds.getDate())}/${pad(ds.getMonth() + 1)}/${ds.getFullYear()}`;
  const endText = `${pad(de.getHours())}:${pad(de.getMinutes())} ${pad(de.getDate())}/${pad(de.getMonth() + 1)}/${de.getFullYear()}`;
  return `${startText} – ${endText}`;
}

function isRegisterExpired(deadline) {
  if (!deadline) return false;

  let d;

  // Nếu chỉ có YYYY-MM-DD → set hết ngày
  if (/^\d{4}-\d{2}-\d{2}$/.test(deadline)) {
    d = new Date(deadline + "T23:59:59");
  } else {
    d = new Date(deadline.replace(" ", "T"));
  }

  if (isNaN(d.getTime())) return false;

  return Date.now() > d.getTime();
}
function renderShareButton(cid) {
  return `
    <button
      type="button"
      class="js-share w-full inline-flex items-center justify-center gap-2
             rounded-2xl px-4 py-3 text-sm font-semibold
             bg-white hover:bg-gray-50 text-gray-700
             border border-gray-200"
      data-id="${cid}">
      <i data-lucide="share-2" class="w-4 h-4"></i>
      Chia sẻ
    </button>
  `;
}


/* ================= USER ACTION RENDER ================= */
function renderUserActions(card, campaign, userStatus) {
  const box = card.querySelector(".mt-auto.space-y-3");
  if (!box) return;

  const expired = isRegisterExpired(campaign.register_deadline);
  const canRegister = campaign.status !== "cancelled" && !expired;
  const canCancel = userStatus === "approved" && campaign.status !== "cancelled";

  const cid = campaign.id;

  // ===== ĐÃ ĐƯỢC ĐÁNH GIÁ =====
  if (["excellent", "good", "incomplete"].includes(userStatus)) {
    const map = {
      excellent: ["🏅 Hoàn thành xuất sắc", "bg-emerald-600 text-white"],
      good: ["👍 Hoàn thành tốt", "bg-blue-600 text-white"],
      incomplete: ["⚠️ Chưa hoàn thành", "bg-yellow-400 text-black"]
    };
    const [text, cls] = map[userStatus];
    box.innerHTML = `
  <button class="px-4 py-2 rounded-lg w-full text-sm cursor-default ${cls}">
    ${text}
  </button>
  ${renderShareButton(cid)}
`;
    return;
  }

  // ===== ĐÃ ĐĂNG KÝ =====
  if (userStatus === "approved") {
    box.innerHTML = `
  <button class="px-4 py-2 rounded-lg bg-green-600 text-white text-sm w-full cursor-default">
    ✅ Đã đăng ký
  </button>

  ${canCancel ? `
    <button
      class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm js-cancel w-full mb-5"
      data-id="${cid}">
      ❌ Hủy đăng ký
    </button>
  ` : ""}

  ${renderShareButton(cid)}

  ${campaign.url_zalo ? `
  <a
    href="${campaign.url_zalo}"
    target="_blank"
    rel="noopener"
    class="px-4 py-2 rounded-lg bg-blue-500 text-white text-sm w-full text-center hover:bg-blue-600">
    👉 Vào nhóm Zalo
  </a>
` : ""}

`;

    return;
  }

  // ===== CHƯA ĐĂNG KÝ =====
  if (canRegister) {
    box.innerHTML = `
  <button
    class="px-4 py-2 rounded-lg bg-primary text-white text-sm js-reg w-full"
    data-id="${cid}">
    Đăng ký
  </button>

  ${renderShareButton(cid)}
`;
    return;
  }

  // ===== KHÔNG CHO ĐĂNG KÝ =====
  box.innerHTML = `
  <button class="px-4 py-2 rounded-lg bg-gray-200 text-gray-600 text-sm w-full cursor-default">
    ${campaign.status === "cancelled"
      ? "🚫 Phong trào đã kết thúc"
      : "⛔ Hết hạn đăng ký"}
  </button>

  ${renderShareButton(cid)}
`;
  return;
}



/* ================= REGISTER ================= */
function handleRegister(btn) {

  if (registering) return;

  const card = btn.closest(".campaign-item");
  if (!card) return;

  const campaign = {
    id: btn.dataset.id,
    status: card.dataset.status
  };

  registering = true;

  modal(`
    <div class="text-center space-y-4">
      <p>Bạn có chắc chắn muốn <b>đăng ký</b>?</p>
      <div class="flex justify-center gap-3">
        <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
        <button id="confirmReg" class="px-4 py-2 bg-primary text-white rounded-lg">
          Đồng ý
        </button>
      </div>
    </div>
  `, "Xác nhận đăng ký");

  document.getElementById("confirmReg").onclick = async () => {
    closeModal();

    try {
      const fd = new FormData();
      fd.append("action", "register");
      fd.append("campaign_id", campaign.id);

      const j = await apiFetch("controllers/campaigns.php", {
        method: "POST",
        body: fd
      });

      // ===== GUEST → YÊU CẦU ĐĂNG NHẬP =====
      if (j.need_login) {
        toast(j.error || "Vui lòng đăng nhập để đăng ký phong trào", "warning");

        setTimeout(() => {
          if (typeof openLoginModal === "function") {
            openLoginModal();
          } else {
            console.error("openLoginModal() chưa được load");
          }
        }, 300);

        return;
      }


      // ===== LỖI KHÁC =====
      if (!j.ok) {
        toast(j.error || "Không thể đăng ký", "error");
        return;
      }

      // ===== THÀNH CÔNG =====
      toast("Đăng ký thành công!", "success");

      card.dataset.userStatus = "approved";

      card.dataset.userStatus = "approved";
      card.dataset.urlZalo = j.url_zalo ?? "";

      renderUserActionsWithShare(
        card,
        {
          ...campaign,
          url_zalo: j.url_zalo,
          register_deadline: card.dataset.registerDeadline // hoặc null
        },
        "approved"
      );

    } catch {
      toast("Lỗi kết nối máy chủ", "error");
    } finally {
      registering = false;
    }
  };
}

/* ================= CANCEL ================= */
function handleCancel(btn) {
  const card = btn.closest(".campaign-item");
  if (!card) return;

  const campaign = {
    id: btn.dataset.id,
    status: card.dataset.status
  };

  modal(`
    <div class="text-center space-y-4">
      <p>Bạn có chắc muốn <b>hủy đăng ký</b>?</p>
      <div class="flex justify-center gap-3">
        <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Đóng</button>
        <button id="confirmCancel" class="px-4 py-2 bg-red-600 text-white rounded-lg">
          Đồng ý
        </button>
      </div>
    </div>
  `, "Xác nhận hủy");

  document.getElementById("confirmCancel").onclick = async () => {
    closeModal();

    try {
      const fd = new FormData();
      fd.append("action", "cancel_register");
      fd.append("campaign_id", campaign.id);

      const j = await apiFetch("controllers/campaigns.php", {
        method: "POST",
        body: fd
      });

      if (!j.ok) {
        toast(j.error || "Không thể hủy", "error");
        return;
      }

      toast("Đã hủy đăng ký", "success");

      card.dataset.userStatus = "";
      card.dataset.urlZalo = "";

      renderUserActionsWithShare(
        card,
        {
          ...campaign,
          register_deadline: card.dataset.registerDeadline,
          url_zalo: ""
        },
        null
      );


    } catch {
      toast("Lỗi kết nối máy chủ", "error");
    }
  };
}
/* ==============================
   PHẦN 2 – TAB 1: LIST + FILTER
================================ */
async function loadFilterSchoolYears(selectEl, selected = "") {
  if (!selectEl) return;

  selectEl.innerHTML = `<option value="">Năm học</option>`;

  try {
    const res = await apiFetch(
      "controllers/school_years.php?action=list_active"
    );

    if (!res.ok) return;

    (res.data || []).forEach(y => {
      const opt = document.createElement("option");
      opt.value = y.id;
      opt.textContent = y.year_label;
      selectEl.appendChild(opt);
    });

    if (selected) {
      selectEl.value = selected;
    }

  } catch (e) {
    console.error("Load school year filter failed", e);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  const state = {
    page: 1,
    status: "all",
    q: "",
    school_year: "",
    semester: ""
  };

  const params = new URLSearchParams(location.search);

  state.status = params.get("status") || "all";
  state.q = params.get("q") || "";
  state.page = Math.max(1, Number(params.get("page") || 1));

  state.school_year = params.get("school_year") || "";
  state.semester = params.get("semester") || "";

  // set UI
  const statusSelect = document.getElementById("filterCampaignStatus");
  if (statusSelect) statusSelect.value = state.status;

  const searchInput = document.getElementById("searchCampaign");
  if (searchInput) searchInput.value = state.q;


  const filterYear = document.getElementById("filterSchoolYear");
  const filterSemester = document.getElementById("filterSemester");

  // 🔥 LOAD OPTION TRƯỚC
  loadFilterSchoolYears(filterYear, state.school_year);

  // 🔥 LOAD học kỳ từ DB (controllers mới)
  loadSemesters(filterSemester, state.semester, { placeholder: "Học kỳ" })
    .then(code => {
      // nếu URL đang là ID -> convert về code, cập nhật state cho đồng nhất
      if (code && code !== state.semester) {
        state.semester = code;
        syncUrl();
      }
    });



  const viewId = new URLSearchParams(location.search).get("view");




  const grid = document.getElementById("campaignGrid");
  const pagerWrap = document.getElementById("campaignPager");

  filterYear?.addEventListener("change", e => {
    state.school_year = e.target.value;
    state.page = 1;
    syncUrl();
    loadTab1();
  });

  filterSemester?.addEventListener("change", e => {
    state.semester = e.target.value;
    state.page = 1;
    syncUrl();
    loadTab1();
  });

  /* ===== RENDER CARD ===== */
  function renderTab1Cards(items) {
    if (!grid) return;

    grid.innerHTML = "";

    if (!items.length) {
      grid.innerHTML = `
        <div class="col-span-full text-center text-gray-500 py-10">
          Không có phong trào phù hợp
        </div>`;
      return;
    }

    items.forEach(c => {

      const statusClass =
        c.status === "active"
          ? "bg-green-100 text-green-700"
          : c.status === "hidden"
            ? "bg-yellow-100 text-yellow-800"
            : "bg-gray-200 text-gray-600";

      const badgeCls =
        c.status === "active"
          ? "bg-emerald-50 text-emerald-700 border-emerald-200"
          : c.status === "hidden"
            ? "bg-amber-50 text-amber-700 border-amber-200"
            : "bg-gray-100 text-gray-600 border-gray-200";

      const statusUpper = String(c.status_text || "").toUpperCase();

      const reg = Number(c.reg || 0);
      const target = Number(c.target || 0);
      const countText = target > 0 ? `${reg}/${target}` : `${reg}`;

      const imgSrc = c.image
        ? `uploads/campaigns/${encodeURIComponent(String(c.image).split("/").pop())}`
        : "";

      const deadlineHtml = c.register_deadline
        ? (() => {
          const timeText = escapeHtml(formatDeadline24h(c.register_deadline));

          if (isRegisterExpired(c.register_deadline)) {
            return `
          <span class="text-gray-500">
            ${timeText}
            <span class="text-rose-600 font-semibold">
              (Đã hết hạn đăng ký)
            </span>
          </span>
        `;
          }

          return timeText;
        })()
        : "Không giới hạn";


      const placeScope = (() => {
        const p = (c.place || "").trim();
        const s = (c.scope || "").trim();
        if (p && s) return `${escapeHtml(p)} • ${escapeHtml(s)}`;
        if (p) return escapeHtml(p);
        if (s) return escapeHtml(s);
        return "—";
      })();

      const semCode = c.semester_code || c.semester || ""; // fallback nếu API chưa đồng bộ
      const semesterLabel =
        c.semester_label ||
        (semCode === "HK1"
          ? "Học kỳ I"
          : semCode === "HK2"
            ? "Học kỳ II"
            : (semCode || ""));


      const yearSemHtml =
        c.school_year_label && semesterLabel
          ? `
          <div class="flex items-start gap-2">
            <i data-lucide="graduation-cap" class="w-4 h-4 mt-0.5 text-gray-400"></i>
            <div>${escapeHtml(c.school_year_label)} • ${escapeHtml(semesterLabel)}</div>
          </div>
        `
          : "";
      const desc = (c.description || "").trim();

      const descHtml = desc
        ? `
    <div class="mt-2 text-sm font-medium text-gray-700 leading-relaxed">
      ${escapeHtml(desc)}
    </div>
  `
        : "";
      const supLabelRaw = String(c.supervisor_name || "").trim();
      const supName = supLabelRaw ? supLabelRaw.split(" - ")[0].trim() : "—";

      const supPhoneRaw = String(c.supervisor_phone || "").trim();
      const supPhone = supPhoneRaw && supPhoneRaw !== "null" ? supPhoneRaw : "";

      const supervisorHtml = `
  <div class="flex items-start gap-2">
    <i data-lucide="user-check" class="w-4 h-4 mt-0.5 text-gray-400"></i>
    <div class="min-w-0">
      Người phụ trách:
      <span class="font-semibold text-amber-700">
        ${escapeHtml(supName)}
      </span>
      ${supPhone ? `
        <span class="text-gray-500"> • ${escapeHtml(supPhone)}</span>
      ` : ``}
    </div>
  </div>
`;


      grid.insertAdjacentHTML("beforeend", `
<div class="campaign-item bg-white rounded-3xl border border-gray-200 shadow-sm hover:shadow-md transition-all flex flex-col overflow-hidden"
     data-status="${escapeHtml(c.status)}"
     data-user-status="${escapeHtml(c.user_status ?? "")}"
     data-url-zalo="${escapeHtml(c.url_zalo ?? "")}"
     data-register-deadline="${escapeHtml(c.register_deadline ?? "")}">

  <div class="p-5 flex-1 flex flex-col">

    <!-- BADGE ROW (TRÊN ẢNH) -->
    <div class="flex items-center justify-between mb-4">
      <span class="inline-flex items-center px-3 py-1 rounded-full border text-[11px] font-bold tracking-wider ${badgeCls}">
        ${escapeHtml(statusUpper)}
      </span>

      <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border bg-white text-gray-700 border-gray-200 text-xs font-semibold">
        <i data-lucide="target" class="w-4 h-4 text-rose-500"></i>
        ${escapeHtml(countText)}
      </span>
    </div>

    <!-- ẢNH (XUỐNG DƯỚI + BÓP THEO PADDING) -->
    ${imgSrc ? `
      <img src="${imgSrc}"
           class="w-full h-52 object-cover rounded-2xl mb-4"
           loading="lazy"
           onerror="this.style.display='none';" />
    ` : ""}

<h3 class="font-heading text-lg md:text-xl font-extrabold uppercase text-gray-900 leading-snug">
  ${escapeHtml(c.title)}
</h3>

${descHtml}

<div class="campaign-info mt-4 text-sm text-gray-600 space-y-2">
  ${supervisorHtml}

<div class="flex items-start gap-2">
  <i data-lucide="calendar" class="w-4 h-4 mt-0.5 text-gray-400"></i>
  <div>
    <span class="0">Thời gian thực hiện:</span>
    ${(c.start_date && c.end_date)
          ? formatEventRange(c.start_date, c.end_date)
          : `${escapeHtml(c.start_fmt)} – ${escapeHtml(c.end_fmt)}`
        }
  </div>
</div>



      <div class="flex items-start gap-2">
        <i data-lucide="clock-3" class="w-4 h-4 mt-0.5 text-gray-400"></i>
        <div>Hạn đăng ký: ${deadlineHtml}</div>
      </div>

      <div class="flex items-start gap-2">
        <i data-lucide="map-pin" class="w-4 h-4 mt-0.5 text-gray-400"></i>
        <div>${placeScope}</div>
      </div>

      ${yearSemHtml}
    </div>

    <div class="mt-4">
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border bg-amber-50 text-amber-700 border-amber-200 text-xs font-semibold">
        <i data-lucide="star" class="w-4 h-4"></i>
        ${escapeHtml(String(c.score ?? 0))} điểm tích lũy
      </span>
    </div>

${c.note ? `
  <div class="campaign-note mt-4 px-3 py-2 rounded-xl bg-yellow-50 text-yellow-900 text-sm border border-yellow-200">
    <strong>Ghi chú:</strong> ${escapeHtml(c.note)}
  </div>` : ""}


    ${window.CAN_CAMPAIGN_UPDATE ? `
      <button class="w-full mt-3 px-3 py-2 rounded-xl border border-dashed
                     text-sm text-gray-600 hover:bg-gray-50 js-note"
              data-id="${c.id}"
              data-note="${escapeHtml(c.note ?? "")}">
        Ghi chú phong trào
      </button>` : ""}

    <div class="mt-auto space-y-3 pt-5"></div>
  </div>
</div>
`);



      const card = grid.lastElementChild;
      if (window.CAN_CAMPAIGN_UPDATE || window.CAN_CAMPAIGN_DELETE) {
        renderAdminActions(card, c);
      } else {
        renderUserActionsWithShare(
          card,
          { ...c, register_deadline: c.register_deadline },
          c.user_status
        );
      }
      // ✅ Gọi cho cả admin lẫn user (bí thư có thể là admin)
      renderBithuClassButton(card, c);
    });
    if (window.lucide) lucide.createIcons();

  }

  function renderPager(page, totalPages) {
    if (!pagerWrap || totalPages < 1) {
      pagerWrap.innerHTML = "";
      return;
    }

    pagerWrap.innerHTML = `
    <div class="flex items-center gap-2 justify-center select-none">

      <!-- FIRST -->
      <button
        class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
        data-page="1"
        title="Trang đầu">
        &laquo;
      </button>

      <!-- PREV -->
      <button
        class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
        data-page="${page - 1}"
        title="Trang trước">
        &lsaquo;
      </button>

      <!-- INPUT -->
      <div class="flex items-center gap-1 text-sm">
        <input
          id="pagerInput"
          type="number"
          min="1"
          max="${totalPages}"
          value="${page}"
          class="w-12 px-2 py-1 border rounded-lg text-center focus:ring-2 focus:ring-primary"
        />
        <span class="text-gray-500">/ ${totalPages}</span>
      </div>

      <!-- NEXT -->
      <button
        class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
        data-page="${page + 1}"
        title="Trang sau">
        &rsaquo;
      </button>

      <!-- LAST -->
      <button
        class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
        data-page="${totalPages}"
        title="Trang cuối">
        &raquo;
      </button>

    </div>
  `;

    /* CLICK BUTTONS */
    pagerWrap.querySelectorAll("[data-page]").forEach(btn => {
      btn.onclick = () => {
        const p = Number(btn.dataset.page);
        if (p < 1 || p > totalPages) return;
        state.page = p;
        syncUrl();
        loadTab1();
      };
    });

    /* INPUT ENTER */
    const input = document.getElementById("pagerInput");
    input.addEventListener("keydown", e => {
      if (e.key === "Enter") {
        let p = Number(input.value);
        if (isNaN(p)) return;

        p = Math.max(1, Math.min(totalPages, p));
        state.page = p;
        loadTab1();
      }
    });

  }

  function syncUrl() {
    const url = new URL(window.location.href);

    url.searchParams.set("p", "campaigns");
    url.searchParams.set("tab", "all");

    if (state.school_year) {
      url.searchParams.set("school_year", state.school_year);
    } else {
      url.searchParams.delete("school_year");
    }

    if (state.semester) {
      url.searchParams.set("semester", state.semester);
    } else {
      url.searchParams.delete("semester");
    }

    if (state.status && state.status !== "all") {
      url.searchParams.set("status", state.status);
    } else {
      url.searchParams.delete("status");
    }

    if (state.q) {
      url.searchParams.set("q", state.q);
    } else {
      url.searchParams.delete("q");
    }

    url.searchParams.set("page", state.page);

    // ⚠️ nếu đang view 1 phong trào → bỏ view
    url.searchParams.delete("view");

    history.replaceState({}, "", url);
  }

  /* ===== LOAD DATA ===== */
  async function loadTab1() {
    const params = new URLSearchParams({
      action: "list_tab1",
      page: state.page,
      status: state.status,
      q: state.q
    });

    if (state.school_year) {
      params.set("school_year", state.school_year);
    }

    if (state.semester) {
      params.set("semester", state.semester);
    }

    const viewId = new URLSearchParams(location.search).get("view");
    if (viewId) {
      params.set("view", viewId);
    }

    const res = await apiFetch(`controllers/campaigns.php?${params}`);
    if (!res || !res.ok) return;

    renderTab1Cards(res.items || []);
    renderPager(res.page, res.totalPages);
  }

  /* ===== FILTER EVENTS ===== */
  document.getElementById("searchCampaign")?.addEventListener("input", e => {
    state.q = e.target.value.trim();
    state.page = 1;
    syncUrl();
    loadTab1();
  });

  document.getElementById("filterCampaignStatus")?.addEventListener("change", e => {
    state.status = e.target.value;
    state.page = 1;
    syncUrl();
    loadTab1();
  });

  loadTab1();
});

/* ===============================
   CAMPAIGNS TAB URL (NO LEAK)
================================ */

// param nào thuộc tab nào
const CAMPAIGN_TAB_KEYS = {
  all: ["status", "q", "school_year", "semester", "page", "view"],
  registered: ["campaign_id", "page"],
  class_score: ["campaign_id"]
};

function getCampaignTab() {
  return new URL(location.href).searchParams.get("tab") || "all";
}

function pickParams(url, keys) {
  const out = {};
  keys.forEach(k => {
    const v = url.searchParams.get(k);
    if (v !== null && v !== "") out[k] = v;
  });
  return out;
}

function buildCleanTabUrl(tab, params = {}) {
  const cur = new URL(location.href);
  const base = new URL(cur.origin + cur.pathname); // sạch hết query
  base.searchParams.set("p", "campaigns");
  base.searchParams.set("tab", tab);

  const allow = CAMPAIGN_TAB_KEYS[tab] || [];
  allow.forEach(k => {
    const v = params[k];
    if (v !== undefined && v !== null && String(v).trim() !== "" && String(v) !== "0") {
      base.searchParams.set(k, String(v));
    }
  });

  return base;
}

// lưu state tab hiện tại vào sessionStorage
function saveTabState(tab) {
  const url = new URL(location.href);
  const allow = (CAMPAIGN_TAB_KEYS[tab] || []).filter(k => k !== "view"); // không lưu view (share)
  const state = pickParams(url, allow);
  sessionStorage.setItem("campaigns:tab:" + tab, JSON.stringify(state));
}

function loadTabState(tab) {
  try {
    return JSON.parse(sessionStorage.getItem("campaigns:tab:" + tab) || "{}") || {};
  } catch {
    return {};
  }
}

// chuyển tab: lưu tab cũ, rồi set URL tab mới chỉ với params của tab đó
function switchCampaignTab(tab) {
  const curTab = getCampaignTab();
  saveTabState(curTab);

  const state = loadTabState(tab); // params riêng của tab đó (nếu có)
  const u = buildCleanTabUrl(tab, state);
  history.replaceState({}, "", u);
}

// update params trong cùng tab (vd chọn campaign_id, page...)
function setCampaignTabParams(tab, patch = {}) {
  const old = loadTabState(tab);
  const next = { ...old, ...patch };

  // dọn rác
  Object.keys(next).forEach(k => {
    if (next[k] == null || String(next[k]).trim() === "" || String(next[k]) === "0") delete next[k];
  });

  sessionStorage.setItem("campaigns:tab:" + tab, JSON.stringify(next));

  const u = buildCleanTabUrl(tab, next);
  history.replaceState({}, "", u);
}

/* ==============================
   PHẦN 3 – TAB SWITCH + URL
================================ */

(function () {

  const tabAll = document.getElementById("tabAll");
  const tabReg = document.getElementById("tabRegistered");
  const tabClass = document.getElementById("tabClassScore");

  const tabAllC = document.getElementById("tabAllContent");
  const tabRegC = document.getElementById("tabRegisteredContent");
  const tabClassC = document.getElementById("tabClassScoreContent");


  if (!tabAll) return;

  function activateTab(target) {
    // reset tab UI...
    [tabAll, tabReg, tabClass].forEach(t => {
      if (!t) return;
      t.classList.remove("text-blue-600", "border-blue-600", "font-semibold", "bg-blue-50");
    });

    [tabAllC, tabRegC, tabClassC].forEach(c => {
      if (c) c.classList.add("hidden");
    });

    // ✅ đổi tab + dọn URL theo tab (không leak filter)
    switchCampaignTab(
      target === "registered" ? "registered" :
        target === "class_score" ? "class_score" : "all"
    );

    if (target === "registered") {
      tabReg?.classList.add("text-blue-600", "border-blue-600", "font-semibold", "bg-blue-50");
      tabRegC?.classList.remove("hidden");
      // (nếu tab registered có loader riêng thì gọi ở đây)
      // loadRegistrations?.(1);

    } else if (target === "class_score") {
      tabClass?.classList.add("text-blue-600", "border-blue-600", "font-semibold", "bg-blue-50");
      tabClassC?.classList.remove("hidden");

      // nếu tab class_score đã có campaign_id trong URL state riêng → set lại select
      const cid = new URLSearchParams(location.search).get("campaign_id");
      if (cid) {
        const select = document.getElementById("classScoreCampaign");
        if (select) select.value = cid;
      }

      loadClassScores();

    } else {
      tabAll?.classList.add("text-blue-600", "border-blue-600", "font-semibold", "bg-blue-50");
      tabAllC?.classList.remove("hidden");
    }
  }



  tabAll?.addEventListener("click", () => activateTab("all"));
  tabReg?.addEventListener("click", () => activateTab("registered"));
  tabClass?.addEventListener("click", () => activateTab("class_score"));


  // 🔥 USER hoặc guest → LUÔN ACTIVE TAB ALL
  if (!tabReg) {
    activateTab("all");
    return;
  }
  const initTab = new URLSearchParams(location.search).get("tab");
  activateTab(
    initTab === "registered"
      ? "registered"
      : initTab === "class_score"
        ? "class_score"
        : "all"
  );


})();

/* ==============================
   PHẦN 4.1 – ADMIN NOTE
================================ */

document.addEventListener("click", (e) => {
  const btn = e.target.closest(".js-note");
  if (!btn) return;

  const campaignId = btn.dataset.id;
  const oldNote = btn.dataset.note || "";

  openNoteModal(campaignId, oldNote, btn);
});

function openNoteModal(campaignId, oldNote, btnRef) {
  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <form id="formCampaignNote" class="space-y-4" onsubmit="return false">
      <div>
        <label class="block text-sm font-medium mb-1">
          Ghi chú phong trào
        </label>
        <textarea
          name="note"
          rows="4"
          class="w-full px-3 py-2 border rounded-lg text-sm"
          placeholder="Nhập ghi chú nội bộ...">${oldNote}</textarea>
      </div>

      <div class="flex justify-end gap-3 pt-2">
        <button
          type="button"
          onclick="closeModal()"
          class="px-4 py-2 border rounded-lg text-sm">
          Hủy
        </button>

        <button
          type="submit"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">
          Lưu ghi chú
        </button>
      </div>
    </form>
  `;

  modal(wrap, "Ghi chú phong trào", "small");


  const form = wrap.querySelector("#formCampaignNote");

  form.addEventListener("submit", async (e) => {
    e.preventDefault(); // ⛔ CỰC KỲ QUAN TRỌNG

    const fd = new FormData(form);
    fd.append("action", "save_note");
    fd.append("campaign_id", campaignId);

    try {
      const res = await api("controllers/campaigns.php", {
        method: "POST",
        body: fd
      });

      const json = await res.json();

      if (!json.ok) {
        toast(json.error || "Không thể lưu ghi chú", "error");
        return;
      }

      toast("✅ Đã lưu ghi chú", "success");
      closeModal();

      applyNoteToCard(btnRef, fd.get("note"));

    } catch (err) {
      toast("Lỗi kết nối máy chủ", "error");
      console.error(err);
    }
  });
}


function applyNoteToCard(btn, noteText) {
  const card = btn.closest(".campaign-item");
  if (!card) return;

  let noteBox = card.querySelector(".campaign-note");

  if (!noteText.trim()) {
    noteBox?.remove();
    btn.dataset.note = "";
    return;
  }

  if (!noteBox) {
    noteBox = document.createElement("div");
    noteBox.className =
      "campaign-note mb-3 px-3 py-2 rounded-lg bg-yellow-50 text-yellow-900 text-sm";

    const info = card.querySelector(".campaign-info");
    info?.after(noteBox);

  }

  noteBox.innerHTML = `<strong>Ghi chú:</strong> ${escapeHtml(noteText)}`;
  btn.dataset.note = noteText;
}
/* ==============================
   PHẦN 4.2 – ADMIN CRUD
================================ */
async function loadSemesters(selectEl, selected = "", opts = {}) {
  if (!selectEl) return "";

  const {
    placeholder = "-- Chọn học kỳ --",
    includePlaceholder = true
  } = opts;

  selectEl.innerHTML = includePlaceholder
    ? `<option value="">${placeholder}</option>`
    : "";

  try {
    const res = await apiFetch("controllers/campaigns.php?action=semesters");
    if (!res?.ok) return "";

    const items = Array.isArray(res.items) ? res.items : [];

    items.forEach(s => {
      const opt = document.createElement("option");
      opt.value = s.code;                 // HK1/HK2
      opt.textContent = s.label || s.code;
      selectEl.appendChild(opt);
    });

    // ✅ set value theo code
    if (selected) selectEl.value = String(selected);

    return String(selected || "");
  } catch (e) {
    console.error("Load semesters failed", e);
    return "";
  }
}
let CAMPAIGN_USERS = [];
let CAMPAIGN_USERS_LOADED = false;

function normVN(str = "") {
  return String(str)
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/g, "d")
    .trim();
}

async function loadCampaignUsers() {
  if (CAMPAIGN_USERS_LOADED) return CAMPAIGN_USERS;
  const res = await apiFetch("controllers/campaigns.php?action=users");
  if (res?.ok) {
    CAMPAIGN_USERS = Array.isArray(res.items) ? res.items : [];
    CAMPAIGN_USERS_LOADED = true;
  }
  return CAMPAIGN_USERS;
}

function filterCampaignUsers(q) {
  const users = CAMPAIGN_USERS || [];
  const nq = normVN(q);
  if (!nq) return users.slice(0, 12);

  const scored = [];
  for (const u of users) {
    const name = u.fullname || u.username || "";
    const n = normVN(name);
    let score = 0;
    if (n.startsWith(nq)) score = 2;
    else if (n.includes(nq)) score = 1;
    if (score) scored.push({ u, score, name });
  }
  scored.sort((a, b) => (b.score - a.score) || String(a.name).localeCompare(String(b.name), "vi"));
  return scored.slice(0, 12).map(x => x.u);
}

function renderCampaignSupervisorSuggest(list, pickedId = 0) {
  const box = document.getElementById("campSupervisorSuggest");
  if (!box) return;

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
        class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm flex items-center justify-between gap-3 ${isPicked ? "opacity-60 pointer-events-none" : ""}"
        data-pick-sup-id="${id}"
        data-pick-sup-name="${escapeHtml(name)}">
        <div class="min-w-0">
          <div class="font-medium text-gray-800 truncate">${escapeHtml(name)}</div>
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

function hideCampaignSupervisorSuggest() {
  const box = document.getElementById("campSupervisorSuggest");
  if (!box) return;
  box.classList.add("hidden");
  box.innerHTML = "";
}

function bindCampaignSupervisorPicker() {
  const ip = document.getElementById("campSupervisorInput");
  const hid = document.getElementById("campSupervisorId");
  const box = document.getElementById("campSupervisorSuggest");
  if (!ip || !hid || !box) return;

  const show = (qOverride = null) => {
    const q = qOverride !== null ? String(qOverride) : ip.value.trim();
    const list = filterCampaignUsers(q);
    renderCampaignSupervisorSuggest(list, Number(hid.value || 0));
  };

  ip.addEventListener("input", () => {
    clearTimeout(ip._tSup);
    ip._tSup = setTimeout(() => {
      if (!ip.value.trim()) hid.value = "";
      show();
    }, 120);
  });

  const showAll = () => show("");
  ip.addEventListener("focus", showAll);
  ip.addEventListener("click", showAll);
  ip.addEventListener("mousedown", showAll);

  box.addEventListener("mousedown", (e) => {
    const btn = e.target.closest("button[data-pick-sup-id]");
    if (!btn) return;
    e.preventDefault();

    hid.value = String(btn.getAttribute("data-pick-sup-id") || "");
    ip.value = btn.getAttribute("data-pick-sup-name") || "";
    hideCampaignSupervisorSuggest();
    ip.focus();
  });

  ip.addEventListener("blur", () => setTimeout(hideCampaignSupervisorSuggest, 150));
}


function openCampaignModal(id = null) {
  const wrap = document.createElement("div");
  const newCode = "PT" + Math.floor(1000 + Math.random() * 9000);

  wrap.innerHTML = `
    <form id="campForm" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <input type="hidden" name="action" value="${id ? "update" : "create"}">
      <input type="hidden" name="code" value="${id ? "" : newCode}">
      ${id ? `<input type="hidden" name="id" value="${id}">` : ""}

      <div>
        <label class="block text-sm">Tiêu đề</label>
        <input name="title" required class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Địa điểm</label>
        <input name="place" class="w-full px-3 py-2 border rounded-lg">
      </div>

<div>
  <label class="block text-sm">Bắt đầu</label>

  <!-- input hiển thị -->
  <input
    id="startDateText"
    type="text"
    placeholder="HH:mm DD/MM/YYYY"
    class="w-full px-3 py-2 border rounded-lg"
    required
  />

  <!-- input gửi về server -->
  <input name="start_date" id="startDateValue" type="hidden" required>
</div>

<div>
  <label class="block text-sm">Kết thúc</label>

  <!-- input hiển thị -->
  <input
    id="endDateText"
    type="text"
    placeholder="HH:mm DD/MM/YYYY"
    class="w-full px-3 py-2 border rounded-lg"
    required
  />

  <!-- input gửi về server -->
  <input name="end_date" id="endDateValue" type="hidden" required>
</div>

<!-- NGƯỜI PHỤ TRÁCH -->
<div class="relative md:col-span-2">
  <label class="block text-sm">Người phụ trách</label>

  <div class="relative mt-1">
    <input
      id="campSupervisorInput"
      type="text"
      placeholder="Gõ tên để tìm..."
      class="w-full px-3 py-2 border rounded-lg"
      autocomplete="off"
    />

    <div id="campSupervisorSuggest"
      class="absolute left-0 top-full mt-1 w-full bg-white border rounded-xl shadow-lg hidden max-h-[260px] overflow-auto z-50">
    </div>
  </div>

  <input type="hidden" name="supervisor_id" id="campSupervisorId" value="">
</div>

      <!-- NĂM HỌC + HỌC KỲ -->
<div>
  <label class="block text-sm">Năm học</label>

  <select
    name="school_year_id"
    id="schoolYearSelect"
    class="w-full px-3 py-2 border rounded-lg text-sm"
    required
  >
    <!-- options sẽ được JS fill -->
  </select>
</div>


<div>
  <label class="block text-sm">Học kỳ</label>
<select
  name="semester_code"
  id="semesterSelect"
  class="w-full px-3 py-2 border rounded-lg text-sm"
  required
>

  <option value="">-- Đang tải học kỳ --</option>
</select>

</div>


      <div>
        <label class="block text-sm">Thời hạn đăng ký</label>
        <input
          name="register_deadline"
          type="datetime-local"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Chỉ tiêu</label>
        <input name="target" type="number" value="100"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Phạm vi</label>
        <input name="scope" class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div>
        <label class="block text-sm">Điểm số</label>
        <input name="score" type="number" value="0"
          class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm">Ghi chú</label>
        <input name="note" class="w-full px-3 py-2 border rounded-lg">
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm">Mô tả</label>
        <textarea name="description"
          class="w-full px-3 py-2 border rounded-lg"></textarea>
      </div>

      <div class="md:col-span-2">
  <label class="block text-sm">Link nhóm Zalo</label>
  <input
    name="url_zalo"
    placeholder="https://zalo.me/g/xxxxxx"
    class="w-full px-3 py-2 border rounded-lg">
</div>

      <div class="md:col-span-2">
        <label class="block text-sm">Hình ảnh</label>
<input
  name="image"
  type="file"
  accept="image/png,image/jpeg,image/jpg,image/webp"
  class="w-full px-3 py-2 border rounded-lg">
        <input type="hidden" name="old_image">
      </div>

      <div class="md:col-span-2 flex justify-end gap-2 mt-2">
        <button type="button" class="px-6 py-2 border rounded-lg"
          onclick="closeModal()">Hủy</button>
        <button class="px-6 py-2 bg-secondary text-white rounded-lg">Lưu</button>
      </div>
    </form>
  `;

  modal(wrap, id ? "Sửa phong trào" : "Thêm phong trào", "large");
  loadCampaignUsers().then(() => {
    bindCampaignSupervisorPicker();
  });

  const form = wrap.querySelector("#campForm");
  const yearSelect = wrap.querySelector("#schoolYearSelect");
  const semSelect = wrap.querySelector("#semesterSelect");
  // ===== DATETIME PICKER (HH:mm DD/MM/YYYY) =====
  const startText = wrap.querySelector("#startDateText");
  const endText = wrap.querySelector("#endDateText");
  const startVal = wrap.querySelector("#startDateValue");
  const endVal = wrap.querySelector("#endDateValue");

  // helper: parse chuỗi DB -> Date
  function parseDbDateTime(s) {
    if (!s) return null;
    const str = String(s).trim();

    // hỗ trợ "YYYY-MM-DD" hoặc "YYYY-MM-DD HH:MM:SS"
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      return new Date(str + "T00:00:00");
    }
    // "YYYY-MM-DD HH:MM:SS" hoặc "YYYY-MM-DD HH:MM"
    const iso = str.replace(" ", "T");
    const d = new Date(iso);
    return isNaN(d.getTime()) ? null : d;
  }

  // helper: format Date -> "Y-m-d H:i:s" (server-friendly)
  function formatDbDateTime(d) {
    if (!(d instanceof Date) || isNaN(d.getTime())) return "";
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
  }

  const fpStart = flatpickr(startText, {
    enableTime: true,
    time_24hr: true,
    dateFormat: "H:i d/m/Y", // HIỂN THỊ
    allowInput: true,
    onChange: (selectedDates) => {
      startVal.value = selectedDates[0] ? formatDbDateTime(selectedDates[0]) : "";
    },
    onClose: (selectedDates) => {
      startVal.value = selectedDates[0] ? formatDbDateTime(selectedDates[0]) : "";
    }
  });

  const fpEnd = flatpickr(endText, {
    enableTime: true,
    time_24hr: true,
    dateFormat: "H:i d/m/Y",
    allowInput: true,
    onChange: (selectedDates) => {
      endVal.value = selectedDates[0] ? formatDbDateTime(selectedDates[0]) : "";
    },
    onClose: (selectedDates) => {
      endVal.value = selectedDates[0] ? formatDbDateTime(selectedDates[0]) : "";
    }
  });


  if (id) {
    apiFetch(`controllers/campaigns.php?action=get&id=${id}`)
      .then(d => {
        // supervisor
        wrap.querySelector("#campSupervisorId").value = String(d.supervisor_id || "");
        wrap.querySelector("#campSupervisorInput").value = String(d.supervisor_name || "");

        if (!d || d.error) {
          toast("Không tìm thấy phong trào", "error");
          closeModal();
          return;
        }

        // ==== GÁN GIÁ TRỊ FORM ====
        form.title.value = d.title ?? "";
        form.place.value = d.place ?? "";
        // set start/end qua flatpickr
        const dStart = parseDbDateTime(d.start_date);
        const dEnd = parseDbDateTime(d.end_date);

        if (dStart) {
          fpStart.setDate(dStart, true);
          startVal.value = formatDbDateTime(dStart);
        }

        if (dEnd) {
          fpEnd.setDate(dEnd, true);
          endVal.value = formatDbDateTime(dEnd);
        }

        form.target.value = d.target ?? 0;
        form.scope.value = d.scope ?? "";
        form.score.value = d.score ?? 0;
        form.note.value = d.note ?? "";
        form.description.value = d.description ?? "";
        loadSemesters(semSelect, d.semester_code || d.semester || "");
        form.url_zalo.value = d.url_zalo ?? "";
        form.old_image.value = d.image ?? "";

        // ==== DEADLINE ====
        if (d.register_deadline) {
          form.register_deadline.value =
            d.register_deadline.replace(" ", "T").slice(0, 16);
        }

        // ==== LOAD NĂM HỌC + SELECT ĐÚNG ====
        loadSchoolYears(yearSelect, d.school_year_id);

      })
      .catch(err => {
        console.error(err);
        toast("Lỗi tải dữ liệu phong trào", "error");
        closeModal();
      });
  } else {
    // THÊM MỚI → LOAD NĂM HỌC 1 LẦN
    loadSchoolYears(yearSelect);
    loadSemesters(semSelect, "");

    // ✅ set mặc định giờ hiện tại cho Bắt đầu / Kết thúc
    const now = new Date();

    fpStart.setDate(now, true);
    startVal.value = formatDbDateTime(now);

    // tuỳ bạn: kết thúc = +2 giờ (hoặc = now)
    const end = new Date(now.getTime() + 2 * 60 * 60 * 1000);
    fpEnd.setDate(end, true);
    endVal.value = formatDbDateTime(end);
  }



  form.addEventListener("submit", async (e) => {
    const file = form.image.files[0];
    if (file) {
      const okTypes = ["image/jpeg", "image/png", "image/webp"];
      if (!okTypes.includes(file.type)) {
        toast("Chỉ được upload ảnh JPG, PNG, WEBP", "error");
        return;
      }
    }

    e.preventDefault();
    const fd = new FormData(form);

    // lấy semester_code từ form
    const semCode = String(fd.get("semester_code") || "").trim();

    // tương thích nếu controller cũ vẫn đọc $_POST['semester']
    if (semCode) fd.set("semester", semCode);

    const res = await api("controllers/campaigns.php", {
      method: "POST",
      body: fd
    });

    const json = await res.json();
    if (json.ok) {
      toast("Lưu thành công", "success");
      setTimeout(() => location.reload(), 600);
    } else toast(json.error || "Lỗi lưu phong trào", "error");
  });
}

async function loadSchoolYears(selectEl, selectedId = null) {
  if (!selectEl) return;

  selectEl.innerHTML = `<option value="">-- Đang tải --</option>`;

  try {
    const res = await apiFetch(
      "controllers/school_years.php?action=list_active"
    );

    if (!res.ok) throw new Error(res.error || "Load failed");

    const rows = res.data || [];

    selectEl.innerHTML =
      `<option value="">-- Chọn năm học --</option>` +
      rows.map(r =>
        `<option value="${r.id}">${r.year_label}</option>`
      ).join("");

    if (selectedId) {
      selectEl.value = selectedId;
    }
    return true;

  } catch (err) {
    console.error(err);
    selectEl.innerHTML =
      `<option value="">Không tải được năm học</option>`;
  }
}


async function renderSchoolYearManagerList(container) {
  const res = await apiFetch(
    "controllers/school_years.php?action=list"
  );

  if (!res.ok) {
    container.innerHTML =
      `<p class="text-red-500">Không tải được dữ liệu</p>`;
    return;
  }

  container.innerHTML = res.data.map(r => `
    <div class="flex items-center gap-2">
      <input
        type="text"
        class="flex-1 px-3 py-2 border rounded-lg text-sm"
value="${escapeHtml(r.year_label)}"
        data-id="${r.id}"
      />

      <button
        class="px-3 py-2 bg-red-100 text-red-600 rounded-lg text-sm js-del-year"
        data-id="${r.id}">
        Xóa
      </button>
    </div>
  `).join("");
}

function openSchoolYearManager(selectRef) {
  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Thêm năm học mới</label>
        <div class="flex gap-2">
          <input
            id="newYearInput"
            placeholder="Ví dụ: 2025-2026"
            class="flex-1 px-3 py-2 border rounded-lg text-sm"
          />
          <button
            id="btnAddYearConfirm"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">
            Thêm
          </button>
        </div>
      </div>

      <hr>

      <div id="yearList" class="space-y-2"></div>

      <div class="flex justify-end gap-2 pt-2">
        <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">
          Đóng
        </button>
      </div>
    </div>
  `;

  modal(wrap, "Quản lý năm học", "medium");

  const listBox = wrap.querySelector("#yearList");

  // LOAD LIST
  renderSchoolYearManagerList(listBox);

  // ADD
  wrap.querySelector("#btnAddYearConfirm").onclick = async () => {
    const input = wrap.querySelector("#newYearInput");
    const val = input.value.trim();

    if (!/^\d{4}-\d{4}$/.test(val)) {
      toast("❌ Sai định dạng (vd: 2025-2026)", "error");
      return;
    }

    const res = await apiFetch("controllers/school_years.php", {
      method: "POST",
      body: new URLSearchParams({
        action: "create",
        year_label: val
      })
    });

    if (!res.ok) {
      toast(res.error || "Không thêm được năm học", "error");
      return;
    }

    input.value = "";
    toast("✅ Đã thêm năm học", "success");

    renderSchoolYearManagerList(listBox);

    // reload select + auto select năm vừa thêm
    loadSchoolYears(selectRef).then(() => {
      const options = [...selectRef.options];
      const last = options[options.length - 1];
      if (last) selectRef.value = last.value;
    });

    // 👉 QUAY LẠI FORM PHONG TRÀO
    closeModal();

  };

  // DELETE
  wrap.addEventListener("click", async e => {
    const btn = e.target.closest(".js-del-year");
    if (!btn) return;

    if (!confirm("Xóa năm học này?")) return;

    const id = btn.dataset.id;

    const res = await apiFetch("controllers/school_years.php", {
      method: "POST",
      body: new URLSearchParams({
        action: "delete",
        id
      })
    });

    if (!res.ok) {
      toast(res.error || "Không xóa được", "error");
      return;
    }

    toast("🗑️ Đã xóa năm học", "success");
    renderSchoolYearManagerList(listBox);
    loadSchoolYears(selectRef);
  });
}

/* ==============================
   PHẦN 4.3 – ADMIN DELETE
================================ */

function delCampaign(id) {
  modal(`
    <div class="text-center space-y-3">
      <p>Bạn có chắc muốn <b>xóa</b> phong trào này?</p>
      <div class="flex justify-center gap-3">
        <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
        <button id="confirmDel" class="px-4 py-2 bg-accent-red text-white rounded-lg">Xóa</button>
      </div>
    </div>
  `, "Xác nhận xóa");

  document.getElementById("confirmDel").onclick = async () => {
    const fd = new FormData();
    fd.append("action", "delete");
    fd.append("id", id);

    const res = await api("controllers/campaigns.php", { method: "POST", body: fd });
    const json = await res.json();

    if (json.ok) {
      toast("🗑️ Đã xóa", "success");
      setTimeout(() => location.reload(), 500);
    } else toast(json.error || "Không thể xóa", "error");
  };
}
/* ==============================
   PHẦN 4.4 – ADMIN CANCEL USER
================================ */

document.querySelectorAll(".js-admin-cancel").forEach(btn => {
  btn.onclick = () => {
    const id = btn.dataset.id;

    modal(`
      <div class="text-center space-y-3">
        <p>Bạn có chắc muốn <b>hủy đăng ký</b> của đoàn viên?</p>
        <div class="flex justify-center gap-3">
          <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
          <button id="confirmAdminCancel"
            class="px-4 py-2 bg-red-600 text-white rounded-lg">Đồng ý</button>
        </div>
      </div>
    `, "Xác nhận");

    document.getElementById("confirmAdminCancel").onclick = async () => {
      const fd = new FormData();
      fd.append("action", "admin_cancel_register");
      fd.append("reg_id", id);

      const res = await api("controllers/campaigns.php", { method: "POST", body: fd });
      const json = await res.json();

      if (json.ok) {
        toast("Đã hủy đăng ký", "success");
        setTimeout(() => location.reload(), 500);
      } else toast(json.error || "Không thể hủy", "error");
    };
  };
});

function renderAdminActions(card, c) {
  const box = card.querySelector(".mt-auto.space-y-3");
  if (!box) return;

  let html = `<div class="flex gap-3">`;

  if (window.CAN_CAMPAIGN_UPDATE) {
    html += `
      <button class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm js-edit"
        data-id="${c.id}">Sửa</button>
    `;
  }

  if (window.CAN_CAMPAIGN_DELETE) {
    html += `
      <button class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 text-sm js-del"
        data-id="${c.id}">Xóa</button>
    `;
  }

  html += `
      <a class="flex-1 text-center px-4 py-2 rounded-lg border hover:bg-gray-50 text-sm"
        href="index.php?p=campaigns&tab=registered&campaign_id=${c.id}">
        Xem đăng ký
      </a>
    </div>
  `;

  if (window.CAN_CAMPAIGN_UPDATE) {
    html += `
      <div class="flex gap-3">
        <a href="index.php?p=campaigns_qr&campaign_id=${c.id}"
          class="px-3 py-2 text-center rounded-lg bg-blue-600 text-white text-sm">
          QR
        </a>

        <a href="index.php?p=attend_list&campaign_id=${c.id}"
          class="flex-1 text-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm">
          Danh sách điểm danh
        </a>
      </div>
    `;
  }
  // ✅ NÚT CHIA SẺ (ADMIN)
  html += `
    <button
      class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-sm js-share w-full flex items-center justify-center gap-2"
      data-id="${c.id}">
      <svg width="16" height="16" viewBox="0 0 24 24"
           fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="18" cy="5" r="3"></circle>
        <circle cx="6" cy="12" r="3"></circle>
        <circle cx="18" cy="19" r="3"></circle>
        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
      </svg>
      Chia sẻ
    </button>
  `;
  box.innerHTML = html;
}


document.addEventListener("click", (e) => {
  const zaloBtn = e.target.closest(".js-zalo");
  if (zaloBtn) {
    const url = zaloBtn.dataset.url?.trim();

    if (!url) {
      toast("⚠️ Chưa có nhóm Zalo cho phong trào này", "warning");
      return;
    }

    window.open(url, "_blank", "noopener");
    return;
  }

  const shareBtn = e.target.closest(".js-share");
  if (shareBtn) {
    handleShare(shareBtn);
    return;
  }

  // ===== ADMIN: SỬA =====
  const editBtn = e.target.closest(".js-edit");
  if (editBtn) {
    const id = editBtn.dataset.id;
    openCampaignModal(id);
    return;
  }

  // ===== ADMIN: XÓA =====
  const delBtn = e.target.closest(".js-del");
  if (delBtn) {
    const id = delBtn.dataset.id;
    delCampaign(id);
    return;
  }

  // ===== USER: ĐĂNG KÝ =====
  const regBtn = e.target.closest(".js-reg");
  if (regBtn) {
    handleRegister(regBtn);
    return;
  }

  // ===== USER: HỦY ĐĂNG KÝ =====
  const cancelBtn = e.target.closest(".js-cancel");
  if (cancelBtn) {
    handleCancel(cancelBtn);
    return;
  }
  // ===== BÍ THƯ ĐĂNG KÝ CHO LỚP – FORM TICK CHỌN (ĐÃ CHECK HẾT HẠN + KẾT THÚC) =====
  const regClassBtn = e.target.closest(".js-register-class");
  if (regClassBtn) {
    const cid = regClassBtn.dataset.id;
    const className = regClassBtn.dataset.className;

    // Lấy card để check status + deadline
    const card = regClassBtn.closest(".campaign-item");
    const campStatus = card ? card.dataset.status : "";
    const deadline = card ? card.dataset.registerDeadline : "";

    // ===== CHECK 1: PHONG TRÀO ĐÃ KẾT THÚC HOẶC HẾT HẠN =====
    if (campStatus === "cancelled" || isRegisterExpired(deadline)) {
      const reason = campStatus === "cancelled"
        ? "Phong trào đã kết thúc"
        : "Đã hết hạn đăng ký";
      toast(`❌ ${reason} – Không thể đăng ký cho lớp`, "error");
      return;
    }

    (async () => {
      try {
        const res = await apiFetch(`controllers/campaigns.php?action=get_class_members&campaign_id=${cid}`);
        if (!res.ok) {
          toast(res.error || "Không lấy được danh sách", "error");
          return;
        }

        let html = `<p class="font-semibold mb-3">Lớp: ${escapeHtml(res.class_name)}</p>`;
        html += `<div class="max-h-80 overflow-auto border rounded-xl p-2">`;
        res.members.forEach(m => {
          const checked = m.already_registered ? 'checked disabled' : '';
          html += `
            <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 rounded-lg cursor-pointer">
              <input type="checkbox" class="member-checkbox w-5 h-5" 
                     value="${m.user_id}" ${checked}>
              <div>
                <div class="font-medium">${escapeHtml(m.fullname)}</div>
                <div class="text-xs text-gray-500">${m.mssv || '—'}</div>
              </div>
              ${m.already_registered ? `<span class="ml-auto text-emerald-600 text-xs">✓ Đã đăng ký</span>` : ''}
            </label>`;
        });
        html += `</div>`;

        modal(`
          <div class="space-y-4">
            ${html}
            <div class="flex justify-between pt-4">
              <button onclick="toggleAllCheckboxes()" class="text-amber-600 text-sm font-medium">✓ Tick tất cả</button>
              <div class="flex gap-3">
                <button class="px-4 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
                <button id="confirmSelectedReg" class="px-6 py-2 bg-amber-600 text-white rounded-lg">Đăng ký những người đã tick</button>
              </div>
            </div>
          </div>
        `, "Chọn đoàn viên đăng ký");

        // Submit
        document.getElementById("confirmSelectedReg").onclick = async () => {
          const checkedBoxes = document.querySelectorAll('.member-checkbox:checked:not(:disabled)');
          const userIds = Array.from(checkedBoxes).map(cb => cb.value);
          if (userIds.length === 0) {
            toast("Vui lòng tick ít nhất 1 người", "warning");
            return;
          }
          closeModal();

          const fd = new FormData();
          fd.append("action", "register_selected");
          fd.append("campaign_id", cid);
          userIds.forEach(id => fd.append("user_ids[]", id));

          const j = await apiFetch("controllers/campaigns.php", { method: "POST", body: fd });
          if (j.ok) {
            toast(j.message, "success");
            setTimeout(() => location.reload(), 800);
          } else {
            toast(j.error || "Không thể đăng ký", "error");
          }
        };
      } catch (err) {
        console.error(err);
        toast("Lỗi kết nối máy chủ", "error");
      }
    })();
    return;
  }
  // ===== ADMIN: GHI CHÚ =====
  const noteBtn = e.target.closest(".js-note");
  if (noteBtn) {
    openNoteModal(
      noteBtn.dataset.id,
      noteBtn.dataset.note || "",
      noteBtn
    );
    return;
  }

});

document.addEventListener("DOMContentLoaded", () => {
  const btnAdd = document.getElementById("btnAddCampaign");
  if (btnAdd) {
    btnAdd.addEventListener("click", () => {
      openCampaignModal(null); // null = thêm mới
    });
  }
});

/* ================= SHARE CAMPAIGN ================= */
function handleShare(btn) {
  const card = btn.closest(".campaign-item");
  if (!card) return;

  const campaignId = btn.dataset.id;
  const campaignTitle = card.querySelector(".font-heading").textContent.trim();

  // Tạo link chia sẻ
  const base = new URL(window.location.origin + window.location.pathname);
  base.searchParams.set("p", "campaigns");
  base.searchParams.set("tab", "all");
  base.searchParams.set("view", campaignId);
  const shareUrl = base.toString();

  // Tạo modal chia sẻ
  const modalContent = `
    <div class="space-y-4">
      <!-- Link Box -->
      <div>
        <label class="block text-sm font-medium mb-2">Đường link phong trào:</label>
        <div class="flex gap-2">
          <input 
            id="shareUrlInput" 
            type="text" 
            value="${shareUrl}" 
            readonly
            class="flex-1 px-3 py-2 border rounded-lg bg-gray-50 text-sm"
          />
          <button 
            id="btnCopyLink"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm flex items-center gap-2"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
              <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
            </svg>
            Sao chép
          </button>
        </div>
        <p id="copyStatus" class="text-xs text-green-600 mt-1 hidden">✓ Đã sao chép!</p>
      </div>

      <!-- QR Code -->
      <div class="text-center">
        <p class="text-sm font-medium mb-2">Hoặc quét mã QR:</p>
        <div id="qrCodeContainer" class="inline-block p-4 bg-white border rounded-lg"></div>
      </div>

      <!-- Social Share Buttons -->
      <div>
        <p class="text-sm font-medium mb-2">Chia sẻ qua:</p>
        <div class="flex gap-2 justify-center flex-wrap">
          <!-- Facebook -->
          <button 
            class="js-share-social px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm flex items-center gap-2"
            data-platform="facebook"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
            Facebook
          </button>

          <!-- Zalo -->
          <button 
            class="js-share-social px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm flex items-center gap-2"
            data-platform="zalo"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/>
              <path d="M11 7h2v6h-2zm0 8h2v2h-2z"/>
            </svg>
            Zalo
          </button>

          <!-- Email -->
          <button 
            class="js-share-social px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm flex items-center gap-2"
            data-platform="email"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
              <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
            Email
          </button>
        </div>
      </div>

      <!-- Close Button -->
      <div class="flex justify-end pt-2">
        <button 
          class="px-6 py-2 border rounded-lg hover:bg-gray-50"
          onclick="closeModal()"
        >
          Đóng
        </button>
      </div>
    </div>
  `;

  modal(modalContent, `Chia sẻ: ${campaignTitle}`, "medium");

  // Copy link functionality
  document.getElementById("btnCopyLink").onclick = async () => {
    const input = document.getElementById("shareUrlInput");
    const status = document.getElementById("copyStatus");

    try {
      await navigator.clipboard.writeText(shareUrl);
      input.select();
      status.classList.remove("hidden");

      setTimeout(() => {
        status.classList.add("hidden");
      }, 2000);

      toast("📋 Đã sao chép link!", "success");
    } catch (err) {
      // Fallback for older browsers
      input.select();
      document.execCommand("copy");
      toast("📋 Đã sao chép link!", "success");
    }
  };

  // Generate QR Code
  generateQRCode(shareUrl, "qrCodeContainer");

  // Social share handlers
  document.querySelectorAll(".js-share-social").forEach(btn => {
    btn.onclick = () => {
      const platform = btn.dataset.platform;
      shareToSocial(platform, shareUrl, campaignTitle);
    };
  });
}

/* ================= GENERATE QR CODE ================= */
function generateQRCode(text, containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  // Simple QR code generation using external service
  const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(text)}`;

  container.innerHTML = `
    <img src="${qrUrl}" alt="QR Code" class="w-48 h-48 mx-auto" />
    <p class="text-xs text-gray-500 mt-2">Quét mã để truy cập</p>
  `;
}

/* ================= SHARE TO SOCIAL ================= */
function shareToSocial(platform, url, title) {
  const encodedUrl = encodeURIComponent(url);
  const encodedTitle = encodeURIComponent(title);

  let shareUrl = "";

  switch (platform) {
    case "facebook":
      shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
      break;

    case "zalo":
      shareUrl = `https://zalo.me/share?url=${encodedUrl}&title=${encodedTitle}`;
      break;

    case "email":
      shareUrl = `mailto:?subject=${encodedTitle}&body=Xem phong trào: ${encodedUrl}`;
      break;
  }

  if (shareUrl) {
    window.open(shareUrl, "_blank", "width=600,height=400");
  }
}

/* ================= UPDATE RENDER FUNCTIONS ================= */
// Cập nhật hàm renderUserActions để thêm nút chia sẻ
function renderUserActionsWithShare(card, campaign, userStatus) {
  campaign.url_zalo ||= card.dataset.urlZalo;
  campaign.register_deadline ||= card.dataset.registerDeadline;

  const box = card.querySelector(".mt-auto.space-y-3");
  if (!box) return;

  const expired = isRegisterExpired(campaign.register_deadline);
  const isEnded = campaign.status === "cancelled";
  const canRegister = !isEnded && !expired;
  const canCancel = userStatus === "approved" && !isEnded;

  const cid = campaign.id;

  // ===== ĐÃ ĐƯỢC ĐÁNH GIÁ =====
  if (["excellent", "good", "incomplete"].includes(userStatus)) {
    const map = {
      excellent: ["Hoàn thành xuất sắc", "award", "bg-emerald-600 text-white"],
      good: ["Hoàn thành tốt", "thumbs-up", "bg-blue-600 text-white"],
      incomplete: ["Chưa hoàn thành", "alert-triangle", "bg-amber-500 text-white"]
    };
    const [text, icon, cls] = map[userStatus];

    box.innerHTML = `
      <button type="button"
        class="w-full inline-flex items-center justify-center gap-2
               rounded-2xl px-4 py-3 text-sm font-semibold cursor-default ${cls}">
        <i data-lucide="${icon}" class="w-4 h-4"></i>
        ${text}
      </button>
      ${renderShareButton(cid)}
    `;

    if (window.lucide) lucide.createIcons();
    return;
  }

  // ===== ĐÃ ĐĂNG KÝ =====
  if (userStatus === "approved") {
    const zaloUrl = (campaign.url_zalo ?? "").trim();

    box.innerHTML = `
      <button type="button"
        class="w-full inline-flex items-center justify-center gap-2
               rounded-2xl px-4 py-3 text-sm font-semibold
               bg-emerald-600 text-white cursor-default">
        <i data-lucide="check-circle-2" class="w-4 h-4"></i>
        Đã đăng ký
      </button>

      ${canCancel ? `
        <button type="button"
          class="js-cancel w-full inline-flex items-center justify-center gap-2
                 rounded-2xl px-4 py-3 text-sm font-semibold
                 bg-rose-600 hover:bg-rose-700 text-white"
          data-id="${cid}">
          <i data-lucide="x-circle" class="w-4 h-4"></i>
          Hủy đăng ký
        </button>
      ` : ""}

      ${renderShareButton(cid)}

      <button type="button"
        class="js-zalo w-full inline-flex items-center justify-center gap-2
               rounded-2xl px-4 py-3 text-sm font-semibold
               bg-blue-600 hover:bg-blue-700 text-white"
        data-url="${escapeHtml(zaloUrl)}">
        <i data-lucide="users" class="w-4 h-4"></i>
        Vào nhóm Zalo
      </button>
    `;

    if (window.lucide) lucide.createIcons();
    return;
  }

  // ===== CHƯA ĐĂNG KÝ (CÒN HẠN) =====
  if (canRegister) {
    box.innerHTML = `
      <button type="button"
        class="js-reg w-full inline-flex items-center justify-center gap-2
               rounded-2xl px-4 py-3 text-sm font-semibold
               bg-primary hover:bg-blue-700 text-white"
        data-id="${cid}">
        <i data-lucide="log-in" class="w-4 h-4"></i>
        Tham gia ngay
      </button>

      ${renderShareButton(cid)}
    `;

    if (window.lucide) lucide.createIcons();
    return;
  }

  // ===== HẾT HẠN / KẾT THÚC =====
  const reason = isEnded ? "Phong trào đã kết thúc" : "Đã hết hạn đăng ký";
  const icon = isEnded ? "ban" : "clock-3";

  box.innerHTML = `
    <button type="button"
      class="w-full inline-flex items-center justify-center gap-2
             rounded-2xl px-4 py-3 text-sm font-semibold
             bg-gray-100 text-gray-500 border border-gray-200 cursor-default"
      disabled>
      <i data-lucide="${icon}" class="w-4 h-4"></i>
      ${reason}
    </button>

    ${renderShareButton(cid)}
  `;

  if (window.lucide) lucide.createIcons();
}
/* ==================== NÚT ĐĂNG KÝ CHO LỚP (BÍ THƯ) ==================== */
function renderBithuClassButton(card, campaign) {
  if (!campaign.is_bithu || !campaign.bithu_class_id) return;
  const box = card.querySelector(".mt-auto.space-y-3");
  if (!box) return;
  const html = `
    <button class="js-register-class w-full inline-flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold bg-amber-600 hover:bg-amber-700 text-white" data-id="${campaign.id}" data-class-id="${campaign.bithu_class_id}" data-class-name="${escapeHtml(campaign.bithu_class_name)}">
      <i data-lucide="users" class="w-4 h-4"></i>
      📋 Đăng ký cho lớp ${escapeHtml(campaign.bithu_class_name)}
    </button>
  `;
  box.insertAdjacentHTML("beforeend", html);
}
function toggleAllCheckboxes() {
  const boxes = document.querySelectorAll('.member-checkbox:not(:disabled)');
  const firstChecked = boxes[0] && boxes[0].checked;
  boxes.forEach(box => box.checked = !firstChecked);
}

async function loadClassScores() {
  const select = document.getElementById("classScoreCampaign");
  const tbody = document.getElementById("tbodyClassScore");

  if (!select || !tbody) return;

  const campaignId = Number(select.value);
  tbody.innerHTML = "";

  const checkAll = document.getElementById("checkAllClassScores");
  if (checkAll) checkAll.checked = false;
  const exportBtn = document.getElementById("btnExportClassScore");
  if (exportBtn) exportBtn.classList.add("hidden");

  if (!campaignId) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="px-3 py-6 text-center text-gray-500">
          Vui lòng chọn phong trào
        </td>
      </tr>`;
    return;
  }

  try {
    const res = await apiFetch(
      `controllers/campaign_class_scores.php?action=list&campaign_id=${campaignId}`
    );

    if (!res.ok || !Array.isArray(res.rows)) {
      throw new Error("Bad data");
    }

    if (!res.rows.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="px-3 py-6 text-center text-gray-500">
            Chưa có lớp phát sinh từ đoàn viên đã chấm
          </td>
        </tr>`;
      return;
    }

    const filterClassScoreYear = document.getElementById("filterClassScoreSchoolYear");
    let displayRows = res.rows;
    if (filterClassScoreYear && filterClassScoreYear.value) {
      const selectedYearId = Number(filterClassScoreYear.value);
      displayRows = res.rows.filter(r => Number(r.school_year_id) === selectedYearId);
    }

    if (!displayRows.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="px-3 py-6 text-center text-gray-500">
            Không có lớp phát sinh thuộc năm học đã chọn
          </td>
        </tr>`;
      return;
    }

    // ================= LOCK STATE =================
    const locked = Number(res.rows[0]?.locked || 0);

    const btnCalc = document.getElementById("btnCalcClassScore");
    const btnLock = document.getElementById("btnLockClassScore");
    const btnUnlock = document.getElementById("btnUnlockClassScore");

    // disable khi đã chốt
    if (btnCalc) btnCalc.disabled = locked === 1;

    // toggle nút
    if (btnLock) btnLock.classList.toggle("hidden", locked === 1);
    if (btnUnlock) btnUnlock.classList.toggle("hidden", locked === 0);

    displayRows.forEach(r => {
      const scoreText =
        r.score !== null && r.score !== undefined
          ? `<span class="text-emerald-600 font-bold">${r.score}</span>`
          : `<span class="text-gray-400">—</span>`;

      tbody.insertAdjacentHTML("beforeend", `
        <tr class="border-t">
          <td class="px-3 py-2 text-center w-[44px]">
            <input type="checkbox" class="class-score-check align-middle cursor-pointer w-4 h-4" value="${r.class_id}">
          </td>

          <td class="px-3 py-2 font-medium">
            ${r.class_name}
          </td>

          <td class="px-3 py-2 text-center text-gray-600">
            ${r.school_year_label || '—'}
          </td>

<td class="px-3 py-2 text-center">
  ${r.joined_quantity} / ${r.class_size}
</td>

          <td class="px-3 py-2 text-center">
            <input
  type="number"
  min="1"
  class="w-24 px-2 py-1 border rounded text-center"
  value="${r.target_quantity ?? ""}"
  data-class-id="${r.class_id}"
  placeholder="Nhập chỉ tiêu"
  ${r.locked == 1 ? "disabled" : ""}
/>
          </td>

    <td class="px-3 py-2 text-center font-semibold">
      ${scoreText}
    </td>
        </tr>
      `);
    });

  } catch (err) {
    console.error(err);
    tbody.innerHTML = `
      <tr>
        <td colspan="6" class="px-3 py-6 text-center text-red-500">
          Không tải được dữ liệu
        </td>
      </tr>`;
  }
}

document.getElementById("classScoreCampaign")?.addEventListener("change", () => {
  const select = document.getElementById("classScoreCampaign");
  const campaignId = select?.value || "0";

  // ✅ chỉ set params của tab class_score
  setCampaignTabParams("class_score", {
    campaign_id: campaignId && campaignId !== "0" ? campaignId : ""
  });

  loadClassScores();
});



document
  .getElementById("tabClassScoreContent")
  ?.addEventListener("change", e => {

    const input = e.target;
    if (!input.matches("input[data-class-id]")) return;

    const campaignId =
      document.getElementById("classScoreCampaign")?.value;

    if (!campaignId || !input.value) return;

    apiFetch("controllers/campaign_class_scores.php?action=save", {
      method: "POST",
      body: new URLSearchParams({
        campaign_id: campaignId,
        class_id: input.dataset.classId,
        target_quantity: input.value
      })
    }).catch(() => {
      toast("Không lưu được chỉ tiêu", "error");
    });
  });

document
  .getElementById("btnCalcClassScore")
  ?.addEventListener("click", async () => {

    const campaignId =
      document.getElementById("classScoreCampaign")?.value;

    if (!campaignId) {
      toast("Vui lòng chọn phong trào", "warning");
      return;
    }

    const res = await apiFetch(
      "controllers/campaign_class_scores.php?action=calculate",
      {
        method: "POST",
        body: new URLSearchParams({ campaign_id: campaignId })
      }
    );

    if (!res.ok) {
      toast(res.error || "Không thể tính điểm", "error");
      return;
    }

    toast("✅ Đã tính điểm lớp", "success");

    // reload lại để hiển thị kết quả
    loadClassScores();
  });

document
  .getElementById("btnLockClassScore")
  ?.addEventListener("click", () => {

    const campaignId =
      document.getElementById("classScoreCampaign")?.value;

    if (!campaignId) {
      toast("Vui lòng chọn phong trào", "warning");
      return;
    }

    openLockConfirmModal({
      title: "Chốt điểm lớp",
      message: "Sau khi chốt, bạn <b>không thể chỉnh sửa chỉ tiêu và điểm</b>.",
      confirmText: "🔒 Chốt điểm",
      confirmClass: "bg-red-600 hover:bg-red-700",
      action: "lock",
      campaignId
    });
  });
document
  .getElementById("btnUnlockClassScore")
  ?.addEventListener("click", () => {

    const campaignId =
      document.getElementById("classScoreCampaign")?.value;

    if (!campaignId) {
      toast("Vui lòng chọn phong trào", "warning");
      return;
    }

    openLockConfirmModal({
      title: "Mở chốt điểm",
      message: "⚠️ Thao tác này <b>chỉ dành cho quản trị viên</b>.",
      confirmText: "🔓 Mở chốt",
      confirmClass: "bg-emerald-600 hover:bg-emerald-700",
      action: "unlock",
      campaignId
    });
  });

function toggleClassScoreExportBtn() {
  const btn = document.getElementById("btnExportClassScore");
  if (!btn) return;
  const checked = document.querySelectorAll(".class-score-check:checked");
  if (checked.length > 0) {
    btn.classList.remove("hidden");
  } else {
    btn.classList.add("hidden");
  }
}

document.addEventListener("change", e => {
  if (e.target && (e.target.id === "checkAllClassScores" || e.target.classList.contains("class-score-check"))) {
    if (e.target.id === "checkAllClassScores") {
      document.querySelectorAll(".class-score-check").forEach(cb => {
        cb.checked = e.target.checked;
      });
    }
    toggleClassScoreExportBtn();
  }
});

document
  .getElementById("btnExportClassScore")
  ?.addEventListener("click", () => {
    const campaignId =
      document.getElementById("classScoreCampaign")?.value;

    if (!campaignId || campaignId === "0") {
      toast("⚠️ Vui lòng chọn phong trào để xuất Excel", "warning");
      return;
    }

    const checked = [...document.querySelectorAll(".class-score-check:checked")].map(cb => cb.value);
    if (checked.length === 0) return;

    const filterYear = document.getElementById("filterClassScoreSchoolYear");
    const yearId = filterYear?.value || "";

    window.location.href = `controllers/campaign_class_scores.php?action=export&campaign_id=${campaignId}&school_year_id=${yearId}&class_ids=${checked.join(",")}`;
  });

function openLockConfirmModal({
  title,
  message,
  confirmText,
  confirmClass,
  action,          // "lock" | "unlock"
  campaignId
}) {
  const wrap = document.createElement("div");

  wrap.innerHTML = `
    <div class="space-y-4 text-center">
      <p class="text-gray-700">${message}</p>

      <div class="flex justify-center gap-3 pt-2">
        <button
          class="px-4 py-2 border rounded-lg"
          onclick="closeModal()">
          Hủy
        </button>

        <button
          id="confirmLockAction"
          class="px-4 py-2 rounded-lg text-white ${confirmClass}">
          ${confirmText}
        </button>
      </div>
    </div>
  `;

  modal(wrap, title, "small");

  wrap.querySelector("#confirmLockAction").onclick = async () => {
    try {
      const res = await apiFetch(
        `controllers/campaign_class_scores.php?action=${action}`,
        {
          method: "POST",
          body: new URLSearchParams({ campaign_id: campaignId })
        }
      );

      if (!res.ok) {
        toast(res.error || "Không thể thực hiện thao tác", "error");
        return;
      }

      toast(
        action === "lock"
          ? "🔒 Đã chốt điểm lớp"
          : "🔓 Đã mở chốt điểm",
        "success"
      );

      closeModal();
      loadClassScores();

    } catch (err) {
      toast("Lỗi kết nối máy chủ", "error");
      console.error(err);
    }
  };
}

(function initCampaignPickers_RegAndClassScore_V2() {
  if (window.__CAMPAIGN_PICKERS_RC_READY__) return;
  window.__CAMPAIGN_PICKERS_RC_READY__ = true;

  const options = Array.isArray(window.CAMPAIGN_OPTIONS) ? window.CAMPAIGN_OPTIONS : [];

  const esc = (s = "") =>
    String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  function titleById(id, zeroTitle) {
    const n = Number(id || 0);
    if (!n) return zeroTitle || "";
    const found = options.find(x => Number(x.id) === n);
    return found?.title || "";
  }

  function getUrlTab() {
    return new URL(location.href).searchParams.get("tab") || "all";
  }

  function getUrlCampaignId() {
    const v = new URL(location.href).searchParams.get("campaign_id");
    const n = Number(v || 0);
    return Number.isFinite(n) ? n : 0;
  }

  // ===== shared close manager (1 lần) =====
  const pickers = [];
  document.addEventListener("click", (e) => {
    pickers.forEach(p => {
      if (!p.dropdown) return;
      if (p.dropdown.contains(e.target) || p.input === e.target) return;
      p.close();
    });
  });

  function createPicker({
    inputId,
    dropdownId,
    listId,
    getItems,
    onSelect,
    onTyping,
    getCurrentQueryText, // optional: để focus sync lại text trước khi render
  }) {
    const input = document.getElementById(inputId);
    const dropdown = document.getElementById(dropdownId);
    const list = document.getElementById(listId);
    if (!input || !dropdown || !list) return;

    let lastRendered = [];

    const open = () => dropdown.classList.remove("hidden");
    const close = () => dropdown.classList.add("hidden");

    function render(queryText) {
      const q = (queryText || "").trim().toLowerCase();
      const items = getItems();

      const filtered = q
        ? items.filter(it => (it.title || "").toLowerCase().includes(q))
        : items;

      lastRendered = filtered.slice(0, 50);

      list.innerHTML =
        lastRendered.map(it => `
          <button type="button"
            class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
            data-id="${it.id}"
            data-title="${esc(it.title)}">
            ${esc(it.title)}
          </button>
        `).join("") ||
        `<div class="px-3 py-2 text-sm text-gray-500">Không tìm thấy phong trào</div>`;

      open();
    }

    // ✅ focus luôn show full list (và sync lại text nếu cần)
    input.addEventListener("focus", () => {
      const t = getCurrentQueryText?.();
      if (typeof t === "string") input.value = t;
      render(""); // full list
    });

    input.addEventListener("input", () => {
      onTyping?.(input.value);
      render(input.value);
    });

    list.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-id]");
      if (!btn) return;
      const id = Number(btn.dataset.id || 0);
      const title = btn.dataset.title || "";
      onSelect(id, title);
      close();
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") close();

      if (e.key === "Enter" && !dropdown.classList.contains("hidden")) {
        e.preventDefault();
        const first = lastRendered?.[0];
        if (first) {
          onSelect(Number(first.id || 0), first.title || "");
          close();
        }
      }
    });

    pickers.push({ input, dropdown, close });
  }

  // =========================================================
  // A) TAB REGISTERED (lọc phong trào đăng ký)
  // holder: #filterCampaign (select hoặc hidden/input)
  // input : #filterCampaignSearch
  // URL   : tab=registered&campaign_id=...&page=...
  // =========================================================
  const holder = document.getElementById("filterCampaign");
  const inputFilter = document.getElementById("filterCampaignSearch");

  function setHolderValue(el, id, triggerChange) {
    if (!el) return;
    const val = String(id || 0);

    if (el.tagName === "SELECT") {
      el.value = val;
      if (triggerChange) el.dispatchEvent(new Event("change"));
    } else {
      el.value = val;
    }
  }

  function syncRegisteredInputFromHolder() {
    if (!holder || !inputFilter) return;
    const id = Number(holder.value || 0);
    const t = titleById(id, "Tất cả phong trào");
    inputFilter.value = id ? (t || "") : "";
  }

  function updateRegisteredUrl(id) {
    if (typeof window.setCampaignTabParams === "function") {
      window.setCampaignTabParams("registered", {
        campaign_id: id ? String(id) : "",
        page: 1
      });
    }
  }

  if (holder && inputFilter) {
    // init theo URL nếu đang ở tab registered, còn không thì theo holder hiện tại
    const tab = getUrlTab();
    if (tab === "registered") {
      const cid = getUrlCampaignId();
      if (cid) setHolderValue(holder, cid, false);
    }
    syncRegisteredInputFromHolder();

    const regItems = () => [{ id: 0, title: "Tất cả phong trào" }, ...options];

    createPicker({
      inputId: "filterCampaignSearch",
      dropdownId: "filterCampaignDropdown",
      listId: "filterCampaignList",
      getItems: regItems,

      // focus: luôn sync text theo holder trước
      getCurrentQueryText: () => {
        const id = Number(holder.value || 0);
        const t = titleById(id, "Tất cả phong trào");
        return id ? (t || "") : "";
      },

      onSelect: (id, title) => {
        // set holder
        setHolderValue(holder, id, true);

        // set input text (id=0 => rỗng)
        inputFilter.value = id ? (title || "") : "";

        // ✅ update URL tab registered (không leak)
        updateRegisteredUrl(id);

        // reload registrations (chỉ khi holder là hidden/input và có hàm)
        if (holder.tagName !== "SELECT" && typeof window.loadRegistrations === "function") {
          window.loadRegistrations(1);
        }
      },

      onTyping: () => {
        // chỉ reset id khi holder là hidden/input (tránh reset select khi gõ)
        if (holder.tagName !== "SELECT") {
          setHolderValue(holder, 0, false);
        }
        // nếu bạn muốn gõ là coi như bỏ lọc ngay trên URL:
        // updateRegisteredUrl(0);
      }
    });
  }

  // =========================================================
  // B) TAB CLASS SCORE (chấm điểm lớp)
  // holder: #classScoreCampaign
  // input : #classScoreCampaignSearch
  // URL   : tab=class_score&campaign_id=...
  // =========================================================
  const classHolder = document.getElementById("classScoreCampaign");
  const classInput = document.getElementById("classScoreCampaignSearch");

  function syncClassScoreInputFromHolder() {
    if (!classHolder || !classInput) return;
    const id = Number(classHolder.value || 0);
    const t = titleById(id, "-- Chọn phong trào --");
    classInput.value = id ? (t || "") : "";
  }

  function updateClassScoreUrl(id) {
    if (typeof window.setCampaignTabParams === "function") {
      window.setCampaignTabParams("class_score", {
        campaign_id: id ? String(id) : ""
      });
    }
  }

  if (classHolder && classInput) {
    const filterClassScoreYear = document.getElementById("filterClassScoreSchoolYear");
    if (filterClassScoreYear && typeof loadFilterSchoolYears === "function") {
      loadFilterSchoolYears(filterClassScoreYear);

      // Lắng nghe sự kiện change trên dropdown năm học để reload bảng điểm lớp
      filterClassScoreYear.addEventListener("change", () => {
        loadClassScores();
      });
    }

    // init theo URL nếu đang ở tab class_score
    const tab = getUrlTab();
    if (tab === "class_score") {
      const cid = getUrlCampaignId();
      if (cid) classHolder.value = String(cid);
    }
    syncClassScoreInputFromHolder();

    const classItems = () => [{ id: 0, title: "-- Chọn phong trào --" }, ...options];

    createPicker({
      inputId: "classScoreCampaignSearch",
      dropdownId: "classScoreCampaignDropdown",
      listId: "classScoreCampaignList",
      getItems: classItems,

      getCurrentQueryText: () => {
        const id = Number(classHolder.value || 0);
        const t = titleById(id, "-- Chọn phong trào --");
        return id ? (t || "") : "";
      },

      onSelect: (id, title) => {
        classHolder.value = String(id || 0);
        classInput.value = id ? (title || "") : "";

        // ✅ update URL tab class_score (không leak)
        updateClassScoreUrl(id);

        // giữ flow cũ: trigger change để code loadClassScores đang bind chạy
        classHolder.dispatchEvent(new Event("change"));
      },

      onTyping: () => {
        classHolder.value = "0";
        // nếu muốn gõ là bỏ campaign_id trên URL ngay:
        // updateClassScoreUrl(0);
      }
    });
  }
})();
// Autocomplete cho #searchCampaign (TAB ALL)
// - Focus: luôn hiện FULL danh sách
// - Gõ: lọc dropdown
// - Chọn item: set input.value rồi dispatch "input" để kích hoạt code filter/loadTab1 của bạn
(function initSearchCampaignDropdown() {
  const input = document.getElementById("searchCampaign");
  const dropdown = document.getElementById("searchCampaignDropdown");
  const list = document.getElementById("searchCampaignList");
  if (!input || !dropdown || !list) return;

  const options = Array.isArray(window.CAMPAIGN_OPTIONS) ? window.CAMPAIGN_OPTIONS : [];

  const esc = (s = "") =>
    String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  const open = () => dropdown.classList.remove("hidden");
  const close = () => dropdown.classList.add("hidden");

  let lastRendered = [];

  function render(qText) {
    const q = (qText || "").trim().toLowerCase();
    const items = [{ id: 0, title: "Tất cả phong trào" }, ...options];

    const filtered = q
      ? items.filter(it => (it.title || "").toLowerCase().includes(q))
      : items;

    lastRendered = filtered.slice(0, 50);

    list.innerHTML =
      lastRendered.map(it => `
        <button type="button"
          class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"
          data-id="${it.id}"
          data-title="${esc(it.title)}">
          ${esc(it.title)}
        </button>
      `).join("") ||
      `<div class="px-3 py-2 text-sm text-gray-500">Không tìm thấy phong trào</div>`;

    open();
  }

  function applySelection(id, title) {
    // id=0 => reset search (q="") => load full
    input.value = Number(id) === 0 ? "" : (title || "");

    // ✅ quan trọng: kích hoạt lại code filter/loadTab1 đang lắng nghe input của bạn
    input.dispatchEvent(new Event("input", { bubbles: true }));

    close();
  }

  // ✅ bấm vào input: luôn hiện full list để chọn lại phong trào khác
  input.addEventListener("focus", () => render(""));

  // gõ: lọc dropdown (đồng thời code filter/loadTab1 của bạn cũng chạy)
  input.addEventListener("input", () => render(input.value));

  // click chọn item
  list.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-id]");
    if (!btn) return;
    applySelection(Number(btn.dataset.id || 0), btn.dataset.title || "");
  });

  // Enter: chọn item đầu tiên đang render
  input.addEventListener("keydown", (e) => {
    if (e.key === "Escape") return close();

    if (e.key === "Enter" && !dropdown.classList.contains("hidden")) {
      e.preventDefault();
      const first = lastRendered?.[0];
      if (first) applySelection(Number(first.id || 0), first.title || "");
    }
  });

  // click ra ngoài: đóng
  document.addEventListener("click", (e) => {
    if (dropdown.contains(e.target) || e.target === input) return;
    close();
  });
})();
